<?php

declare(strict_types=1);

namespace Meridian\Pricing;

use Meridian\Support\ClockInterface;
use Meridian\Support\SystemClock;

/**
 * Canonical APR pricing engine for Meridian Trust Financial.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS
 * ---------------------------------------------------------------------------
 * LoanApp currently contains three independent APR implementations that
 * disagree with one another:
 *
 *   1. `tier_to_apr()`   in public/apply.php          (2013, never updated)
 *   2. `recompute_apr()` in public/admin.php          (LOAN-1341, 2019)
 *   3. `calc_apr()`      in batch/nightly_reconcile.pl (2016, partially patched)
 *
 * The differences are not documented anywhere in the application. They were
 * reconstructed by reading the three implementations side by side during the
 * discovery phase of the 2024 modernization engagement. See
 * lib/Pricing/README.md for the divergence table.
 *
 * This class is the fourth implementation. It is *believed* to encode what the
 * Risk team actually intends, based on a description of a rate-card email
 * ("Revised tier surcharge schedule", reportedly circulated 2019-03 by the
 * then-Head of Credit Risk) that we were told exists but which nobody was able
 * to locate during the engagement. Two members of the Risk team independently
 * described the tiered surcharge bands below from memory and their
 * descriptions agreed, so we implemented them. This has NOT been confirmed
 * against a primary source, and the engagement ended before Risk sign-off.
 *
 * ---------------------------------------------------------------------------
 * !! BLAST RADIUS !!
 * ---------------------------------------------------------------------------
 * This engine is gated behind the `FLAG_USE_RATE_ENGINE` feature flag in
 * conf/feature_flags.php, which is OFF. Turning it on would change the quoted
 * APR on **every loan in the system**, not just edge cases:
 *
 *   - The surcharge bands are step functions, not the legacy per-12-month
 *     accrual, so any term above 36 months prices differently.
 *   - The large-loan schedule has three bands rather than one threshold, so
 *     both the $25k-$40k and the >$40k ranges move.
 *   - Results are rounded half-up to three decimals. The legacy PHP paths do
 *     not round at all, so even a plain 36-month Tier A personal loan shifts
 *     from 6.49% to 6.500%.
 *
 * Consequently this must not be enabled without (a) Risk sign-off on the
 * schedule, (b) a decision about already-booked loans, and (c) a repricing
 * plan for in-flight applications. None of those exist.
 *
 * @package   Meridian\Pricing
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-19
 *
 * @internal Not yet wired up — LOAN-3002.
 */
final class RateEngine
{
    /**
     * Engine revision. Bumped by hand; used only in PriceResult breakdowns so
     * that a stored quote can be traced back to the code that produced it.
     */
    public const ENGINE_VERSION = '1.0.0-rc4';

    /**
     * Number of decimal places the final APR is rounded to.
     *
     * Three decimals on a decimal rate == one tenth of a basis point of
     * precision on the displayed percentage (0.065 => 6.500%). Risk asked for
     * "three decimals like the rate card"; the legacy paths round nowhere.
     */
    public const APR_SCALE = 3;

    /**
     * Term surcharge bands, in ascending order of `maxTermMonths`.
     *
     * A `null` upper bound means "and everything above". Note this is a step
     * function keyed on the *band*, not the legacy
     * `floor(max(0, term - 36) / 12) * 0.0025` accrual: a 49-month term takes
     * the whole 0.0050 band here, where apply.php charges 0.0025.
     *
     * @var list<array{maxTermMonths: int|null, surcharge: float}>
     */
    private const TERM_SURCHARGE_BANDS = [
        ['maxTermMonths' => 36,   'surcharge' => 0.0],
        ['maxTermMonths' => 48,   'surcharge' => 0.0025],
        ['maxTermMonths' => 60,   'surcharge' => 0.0050],
        ['maxTermMonths' => null, 'surcharge' => 0.0075],
    ];

    /**
     * Large-loan surcharge bands, in ascending order of `maxAmount`.
     *
     * The legacy code has a single threshold: apply.php charges 0.0040 above
     * $25,000; admin.php (post LOAN-1341) charges 0.0055 above $40,000. This
     * schedule keeps both steps, which is what makes the 2019 hotfix look like
     * an incomplete edit rather than a deliberate change.
     *
     * @var list<array{maxAmount: float|null, surcharge: float}>
     */
    private const LARGE_LOAN_BANDS = [
        ['maxAmount' => 25000.0, 'surcharge' => 0.0],
        ['maxAmount' => 40000.0, 'surcharge' => 0.0040],
        ['maxAmount' => null,    'surcharge' => 0.0055],
    ];

    /**
     * Base rates used when conf/rates.xml has no entry for the tier/product.
     *
     * These are the literals hardcoded in all three legacy implementations.
     * They are duplicated here on purpose: rates.xml is stale and has no
     * effective-dated coverage before 2023-04-01 (see XmlRateTableLoader), so
     * repricing an older application returns nothing from the table. Falling
     * back to the value the rest of the system already believes is better than
     * failing.
     *
     * @var array<string, float>
     */
    public const FALLBACK_BASE_RATES = [
        'A' => 0.0649,
        'B' => 0.0899,
        'C' => 0.1249,
        'D' => 0.1899,
    ];

    /**
     * The legacy `default` branch of the tier switch.
     *
     * Any tier code that is not A/B/C/D falls here. In practice the value that
     * reaches it is the literal string "DECLINE", written by run_decision(),
     * which is why declined applications render a 99.99% APR in the UI.
     *
     * We reproduce the sentinel rather than throwing, because downstream
     * reporting joins on it. Be aware that rounding it to APR_SCALE yields
     * 1.000 (100.000%), not 0.9999 — one more reason the flag stays off.
     */
    public const DECLINE_SENTINEL_RATE = 0.9999;

    /**
     * @param RateTableSource $rateTable Source of effective-dated base rates.
     *                                   Injected so the XML loader can be
     *                                   swapped for a DB-backed table without
     *                                   touching pricing logic (LOAN-2210).
     * @param ClockInterface  $clock     Used only to stamp the result. All
     *                                   rate selection uses PriceRequest::asOf
     *                                   so that repricing an old application
     *                                   is deterministic.
     */
    public function __construct(
        private readonly RateTableSource $rateTable,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Price a single application.
     *
     * @param  PriceRequest $req Validated, immutable pricing inputs.
     * @return PriceResult       Immutable result carrying a full breakdown.
     */
    public function price(PriceRequest $req): PriceResult
    {
        $baseRate      = $this->resolveBaseRate($req);
        $termSurcharge = $this->termSurchargeFor($req->termMonths);
        $largeLoan     = $this->largeLoanSurchargeFor($req->amount);

        $unrounded = $baseRate + $termSurcharge + $largeLoan;
        $apr       = $this->roundHalfUp($unrounded, self::APR_SCALE);

        return new PriceResult(
            apr: $apr,
            baseRate: $baseRate,
            termSurcharge: $termSurcharge,
            largeLoanSurcharge: $largeLoan,
            breakdown: [
                'engine_version'         => self::ENGINE_VERSION,
                'tier'                   => $req->tier,
                'product_code'           => $req->productCode,
                'amount'                 => $req->amount,
                'term_months'            => $req->termMonths,
                'as_of'                  => $req->asOf->format('Y-m-d'),
                'rate_table_source'      => $this->rateTable->describeSource(),
                'base_rate_origin'       => $this->baseRateOrigin($req),
                'term_band'              => $this->termBandLabel($req->termMonths),
                'large_loan_band'        => $this->largeLoanBandLabel($req->amount),
                'apr_unrounded'          => $unrounded,
                'apr_rounding_mode'      => 'half-up',
                'apr_scale'              => self::APR_SCALE,
                'priced_at'              => $this->clock->now()->format(DATE_ATOM),
                'is_decline_sentinel'    => $this->isDeclineTier($req->tier),
            ],
        );
    }

    /**
     * Convenience wrapper for callers holding a raw `loans`/`applicants` join.
     *
     * @param  array<string, mixed> $row
     * @return PriceResult
     */
    public function priceLoanRow(array $row): PriceResult
    {
        return $this->price(PriceRequest::fromLoanRow($row));
    }

    /**
     * Resolve the base rate for the request's tier and product.
     *
     * Order of precedence:
     *   1. conf/rates.xml, effective-dated on the request's `asOf`
     *   2. the hardcoded FALLBACK_BASE_RATES map
     *   3. DECLINE_SENTINEL_RATE (the legacy `default` branch)
     */
    private function resolveBaseRate(PriceRequest $req): float
    {
        if ($this->isDeclineTier($req->tier)) {
            return self::DECLINE_SENTINEL_RATE;
        }

        $fromTable = $this->rateTable->baseRateFor(
            $req->tier,
            $req->productCode,
            $req->asOf,
        );

        if ($fromTable !== null) {
            return $fromTable;
        }

        return self::FALLBACK_BASE_RATES[$req->tier] ?? self::DECLINE_SENTINEL_RATE;
    }

    /**
     * Where the base rate actually came from, for the breakdown.
     */
    private function baseRateOrigin(PriceRequest $req): string
    {
        if ($this->isDeclineTier($req->tier)) {
            return 'decline-sentinel';
        }

        if ($this->rateTable->baseRateFor($req->tier, $req->productCode, $req->asOf) !== null) {
            return 'rate-table';
        }

        return isset(self::FALLBACK_BASE_RATES[$req->tier])
            ? 'hardcoded-fallback'
            : 'decline-sentinel';
    }

    /**
     * A tier is "decline" when it is not one of the four priced tiers.
     */
    private function isDeclineTier(string $tier): bool
    {
        return !array_key_exists($tier, self::FALLBACK_BASE_RATES);
    }

    /**
     * Term surcharge for a term, from the band schedule.
     */
    private function termSurchargeFor(int $termMonths): float
    {
        foreach (self::TERM_SURCHARGE_BANDS as $band) {
            if ($band['maxTermMonths'] === null || $termMonths <= $band['maxTermMonths']) {
                return $band['surcharge'];
            }
        }

        // Unreachable: the last band is open-ended. Kept so static analysis
        // does not have to trust that invariant.
        return 0.0;
    }

    /**
     * Large-loan surcharge for a principal, from the band schedule.
     */
    private function largeLoanSurchargeFor(float $amount): float
    {
        foreach (self::LARGE_LOAN_BANDS as $band) {
            if ($band['maxAmount'] === null || $amount <= $band['maxAmount']) {
                return $band['surcharge'];
            }
        }

        return 0.0;
    }

    /**
     * Human-readable label for the term band that applied.
     */
    private function termBandLabel(int $termMonths): string
    {
        return match (true) {
            $termMonths <= 36 => '0-36',
            $termMonths <= 48 => '37-48',
            $termMonths <= 60 => '49-60',
            default           => '61+',
        };
    }

    /**
     * Human-readable label for the large-loan band that applied.
     */
    private function largeLoanBandLabel(float $amount): string
    {
        return match (true) {
            $amount <= 25000.0 => '<=25000',
            $amount <= 40000.0 => '25001-40000',
            default            => '>40000',
        };
    }

    /**
     * Round half-up to `$scale` decimal places.
     *
     * PHP's round() with PHP_ROUND_HALF_UP is half-away-from-zero, which is
     * half-up for the non-negative rates this engine produces. We use it in
     * preference to a floor($v * 10^n + 0.5) construction because round()
     * applies a pre-rounding correction for values that are not exactly
     * representable in binary floating point (0.0625 being the case that
     * matters here).
     */
    private function roundHalfUp(float $value, int $scale): float
    {
        return round($value, $scale, PHP_ROUND_HALF_UP);
    }
}
