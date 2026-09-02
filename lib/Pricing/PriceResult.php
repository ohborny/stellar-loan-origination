<?php

declare(strict_types=1);

namespace Meridian\Pricing;

/**
 * Immutable result of {@see RateEngine::price()}.
 *
 * Carries not just the APR but every component that produced it. This is
 * deliberate: the single hardest part of the 2024 discovery work was that the
 * legacy code stores only the final `loans.apr` value, with no record of which
 * implementation computed it or what surcharges were applied. Two loans with
 * the same stored APR can have been priced by different code paths, and there
 * is no way to tell them apart after the fact.
 *
 * @package   Meridian\Pricing
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-19
 *
 * @internal Not yet wired up — LOAN-3002.
 */
final class PriceResult
{
    /**
     * @param float                $apr                Final APR as a decimal
     *                                                 rate (0.065 == 6.500%),
     *                                                 rounded half-up to
     *                                                 {@see RateEngine::APR_SCALE}.
     * @param float                $baseRate           Tier base rate before
     *                                                 surcharges.
     * @param float                $termSurcharge      Term band surcharge.
     * @param float                $largeLoanSurcharge Principal band surcharge.
     * @param array<string, mixed> $breakdown          Provenance of the quote:
     *                                                 engine version, band
     *                                                 labels, rate-table
     *                                                 source, unrounded APR.
     */
    public function __construct(
        public readonly float $apr,
        public readonly float $baseRate,
        public readonly float $termSurcharge,
        public readonly float $largeLoanSurcharge,
        public readonly array $breakdown = [],
    ) {
    }

    /**
     * Total surcharge applied on top of the base rate.
     */
    public function totalSurcharge(): float
    {
        return $this->termSurcharge + $this->largeLoanSurcharge;
    }

    /**
     * The APR before rounding, if the engine recorded it.
     *
     * Falls back to the reconstructed sum, which will differ from the stored
     * value only if a caller built this object by hand.
     */
    public function unroundedApr(): float
    {
        $recorded = $this->breakdown['apr_unrounded'] ?? null;

        return is_numeric($recorded)
            ? (float) $recorded
            : $this->baseRate + $this->totalSurcharge();
    }

    /**
     * How much the half-up rounding moved the rate, in basis points.
     *
     * Worth logging: for a plain 36-month Tier A loan this is +0.1 bp, which
     * is small but nonzero, and it is the reason enabling the engine changes
     * every loan rather than only large or long ones.
     */
    public function roundingDeltaBps(): float
    {
        return ($this->apr - $this->unroundedApr()) * 10000.0;
    }

    /**
     * APR formatted as a percentage string, e.g. "6.500%".
     */
    public function aprAsPercentage(int $decimals = 3): string
    {
        return number_format($this->apr * 100.0, $decimals) . '%';
    }

    /**
     * True when the quote came from the legacy `default` tier branch, i.e. the
     * application was declined and priced at the 99.99% sentinel.
     */
    public function isDeclineSentinel(): bool
    {
        return (bool) ($this->breakdown['is_decline_sentinel'] ?? false);
    }

    /**
     * Flat representation suitable for persistence or JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'apr'                  => $this->apr,
            'apr_percentage'       => $this->aprAsPercentage(),
            'base_rate'            => $this->baseRate,
            'term_surcharge'       => $this->termSurcharge,
            'large_loan_surcharge' => $this->largeLoanSurcharge,
            'total_surcharge'      => $this->totalSurcharge(),
            'apr_unrounded'        => $this->unroundedApr(),
            'rounding_delta_bps'   => $this->roundingDeltaBps(),
            'decline_sentinel'     => $this->isDeclineSentinel(),
            'breakdown'            => $this->breakdown,
        ];
    }

    /**
     * Human-readable pricing breakdown.
     *
     * Intended for the adverse-action letter appendix and for the underwriter
     * detail screen, neither of which currently shows any pricing detail at
     * all. Plain text, no HTML: the caller escapes.
     */
    public function explain(): string
    {
        $tier    = (string) ($this->breakdown['tier'] ?? '?');
        $product = (string) ($this->breakdown['product_code'] ?? '?');
        $amount  = (float) ($this->breakdown['amount'] ?? 0.0);
        $term    = (int) ($this->breakdown['term_months'] ?? 0);

        $lines = [];
        $lines[] = sprintf(
            'Pricing for %s %s, $%s over %d months (as of %s)',
            $product,
            $tier,
            number_format($amount, 2),
            $term,
            (string) ($this->breakdown['as_of'] ?? 'unknown date'),
        );
        $lines[] = str_repeat('-', 64);
        $lines[] = $this->component(
            sprintf(
                'Base rate (tier %s, %s)',
                $tier,
                (string) ($this->breakdown['base_rate_origin'] ?? 'unknown source'),
            ),
            $this->baseRate,
        );
        $lines[] = $this->component(
            sprintf('Term surcharge (band %s)', (string) ($this->breakdown['term_band'] ?? '?')),
            $this->termSurcharge,
        );
        $lines[] = $this->component(
            sprintf('Large-loan surcharge (band %s)', (string) ($this->breakdown['large_loan_band'] ?? '?')),
            $this->largeLoanSurcharge,
        );
        $lines[] = str_repeat('-', 64);
        $lines[] = $this->component('APR before rounding', $this->unroundedApr());
        $lines[] = $this->component(
            sprintf('APR (half-up, %d dp)', (int) ($this->breakdown['apr_scale'] ?? 3)),
            $this->apr,
        ) . '  = ' . $this->aprAsPercentage();

        if ($this->isDeclineSentinel()) {
            $lines[] = '';
            $lines[] = '  NOTE: tier is not a priced tier; the 99.99% decline';
            $lines[] = '  sentinel was applied. This is not a real offer.';
        }

        $lines[] = '';
        $lines[] = sprintf(
            '  Engine %s / rate table %s',
            (string) ($this->breakdown['engine_version'] ?? '?'),
            (string) ($this->breakdown['rate_table_source'] ?? '?'),
        );

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * One label/rate row of {@see explain()}, with the rates column aligned.
     */
    private function component(string $label, float $rate): string
    {
        return sprintf('  %-52s %8.4f', $label, $rate);
    }
}
