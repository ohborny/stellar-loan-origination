<?php

declare(strict_types=1);

namespace Meridian\Underwriting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Meridian\Support\ClockInterface;
use Meridian\Support\SystemClock;

/**
 * Assigns a pricing tier from a credit score and a debt-to-income ratio.
 *
 * The concrete implementation was to be extracted from the tier bands in
 * `lib/Underwriting/tier_rules.php`. That extraction was not completed, so no
 * class in this repository implements this interface and
 * {@see DecisionService} cannot currently be constructed.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
interface TierPolicy
{
    /**
     * Tier code for a scored applicant, or null when no tier fits and the
     * application must be declined.
     */
    public function tierFor(int $creditScore, float $dtiRatio): ?string;

    /**
     * Minimum bureau score the program will consider at all.
     */
    public function creditScoreFloor(): int;

    /**
     * Maximum acceptable debt-to-income ratio, as a fraction (0.43 == 43%).
     */
    public function dtiCeiling(): float;

    /**
     * Maximum principal the tier may be approved for without referral.
     */
    public function amountLimitForTier(string $tier): float;
}

/**
 * Computes a debt-to-income ratio from an application snapshot.
 *
 * Abstracted because the legacy calculation is ambiguous about units: the
 * `applicants` table stores `annual_income` and `existing_debt`, and nothing
 * records whether `existing_debt` is a monthly obligation or an outstanding
 * balance. Both readings are present in the codebase.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
interface DtiCalculator
{
    /**
     * @return float DTI as a fraction of monthly income. Never negative.
     */
    public function debtToIncomeRatio(ApplicationSnapshot $snapshot): float;
}

/**
 * Immutable view of everything the decision flow reads.
 *
 * Built once, at the edge, from an `applicants`/`loans` join. Holding it as a
 * value object is what makes the decision flow a pure function — the legacy
 * `run_decision()` reads `$GLOBALS['applicant']` and re-queries the database
 * twice mid-flow, so its result depends on when it is called.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
final class ApplicationSnapshot
{
    /**
     * @param int|null $creditScore         Bureau score, or null when no pull
     *                                      succeeded. The legacy code
     *                                      substitutes 0 here, which reads as
     *                                      "very bad credit" rather than
     *                                      "unknown".
     * @param float    $annualIncome        Stated annual income, dollars.
     * @param float    $existingMonthlyDebt Monthly obligations, dollars.
     */
    public function __construct(
        public readonly int $applicantId,
        public readonly ?int $creditScore,
        public readonly float $annualIncome,
        public readonly float $existingMonthlyDebt,
        public readonly float $requestedAmount,
        public readonly int $termMonths,
        public readonly string $productCode,
        public readonly DateTimeImmutable $submittedAt,
    ) {
        if ($this->annualIncome < 0.0) {
            throw new InvalidArgumentException('annualIncome must not be negative');
        }

        if ($this->existingMonthlyDebt < 0.0) {
            throw new InvalidArgumentException('existingMonthlyDebt must not be negative');
        }

        if ($this->requestedAmount <= 0.0) {
            throw new InvalidArgumentException('requestedAmount must be positive');
        }
    }

    /**
     * Monthly income, or 0.0 when no income was stated.
     */
    public function monthlyIncome(): float
    {
        return $this->annualIncome / 12.0;
    }

    public function hasVerifiableIncome(): bool
    {
        return $this->annualIncome > 0.0;
    }

    public function hasBureauScore(): bool
    {
        return $this->creditScore !== null && $this->creditScore > 0;
    }

    /**
     * @param array<string, mixed> $row An `applicants` row joined to `loans`.
     */
    public static function fromRow(array $row): self
    {
        $score = $row['credit_score'] ?? null;

        return new self(
            applicantId: (int) ($row['applicant_id'] ?? $row['id'] ?? 0),
            creditScore: ($score === null || (int) $score <= 0) ? null : (int) $score,
            annualIncome: (float) ($row['annual_income'] ?? 0.0),
            existingMonthlyDebt: (float) ($row['existing_debt'] ?? 0.0),
            requestedAmount: (float) ($row['amount'] ?? 0.0),
            termMonths: (int) ($row['term_months'] ?? 0),
            productCode: strtoupper((string) ($row['product_code'] ?? 'PERSONAL')),
            submittedAt: new DateTimeImmutable(
                (string) ($row['created_at'] ?? 'now'),
                new DateTimeZone('UTC'),
            ),
        );
    }
}

/**
 * Modern reimplementation of the LoanApp underwriting decision flow.
 *
 * A replacement for `run_decision()` in `includes/decision.php`: same inputs,
 * same three outcomes, but pure, injected and testable. Nothing calls it.
 *
 * ---------------------------------------------------------------------------
 * BEHAVIORAL DIFFERENCES FROM run_decision()
 * ---------------------------------------------------------------------------
 * These are not bugs in this class. They are deliberate choices made during
 * the 2024 engagement, and each one needs a decision from Credit Risk before
 * this service can replace the legacy flow. They were raised at the 2024-10-10
 * checkpoint and were still open when the engagement ended.
 *
 *   1. **No Tier D branch.** `run_decision()` has a fourth branch that assigns
 *      Tier D (base rate 18.99%) to applicants who fall below the Tier C
 *      score floor. That branch is the 2018 "near-prime pilot". We were told
 *      the pilot had ended and did not reproduce it, so this service declines
 *      those applicants instead of pricing them at 18.99%. The pilot's sunset
 *      ticket, LOAN-1502, is still open and unassigned, `FLAG_TIER_D_ENABLED`
 *      is still on, and Tier D loans are still being booked — so the
 *      assumption appears to have been wrong. Do not adopt this service until
 *      LOAN-1502 is resolved one way or the other.
 *
 *   2. **Zero income is a hard decline.** When `annual_income` is 0,
 *      `run_decision()` sets the DTI to the sentinel value 999 and carries on.
 *      Because the tier comparison is written as `$dti < 43`, and 999 fails
 *      that, the flow falls through to the branch that assigns Tier A — which
 *      is how LOAN-1188 ($0-income application auto-approved, 2019) happens.
 *      This service treats absent income as an absence of information and
 *      declines with NO_VERIFIABLE_INCOME. That is the correct outcome, but it
 *      will decline a population that is currently being approved.
 *
 *   3. **Borderline DTI is referred, not approved.** `run_decision()` casts
 *      the ratio to an integer percentage before comparing it to the 43%
 *      ceiling, so 43.9% passes as 43. This service compares the unrounded
 *      ratio and refers anything within {@see self::DTI_BORDERLINE_BAND} of
 *      the ceiling to a human. Applications between 41% and 43.9% that are
 *      approved today would be referred.
 *
 * @package   Meridian\Underwriting
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-09-03
 *
 * @internal Not yet wired up — LOAN-3002.
 */
final class DecisionService
{
    /**
     * How close to the DTI ceiling counts as borderline. 0.02 == 2 points.
     */
    public const DTI_BORDERLINE_BAND = 0.02;

    /**
     * Principal above which proof of income is always a stipulation.
     */
    public const PROOF_OF_INCOME_THRESHOLD = 15000.0;

    public function __construct(
        private readonly TierPolicy $tierPolicy,
        private readonly DtiCalculator $dtiCalculator,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Decide a single application.
     *
     * Pure: reads nothing but the snapshot and its injected collaborators,
     * writes nothing, and returns the same Decision for the same snapshot.
     * Persisting the outcome is the caller's job.
     */
    public function decide(ApplicationSnapshot $snapshot): Decision
    {
        if (!$snapshot->hasVerifiableIncome()) {
            // Difference 2 above. The legacy flow reaches Tier A here.
            return Decision::declined([Decision::REASON_NO_VERIFIABLE_INCOME]);
        }

        if (!$snapshot->hasBureauScore()) {
            return Decision::manualReview(
                'DECLINE',
                [Decision::REASON_NO_BUREAU_SCORE],
                [Decision::STIP_MANUAL_BUREAU_PULL],
            );
        }

        $creditScore = (int) $snapshot->creditScore;

        if ($creditScore < $this->tierPolicy->creditScoreFloor()) {
            return Decision::declined([Decision::REASON_CREDIT_SCORE_BELOW_FLOOR]);
        }

        $dti     = $this->dtiCalculator->debtToIncomeRatio($snapshot);
        $ceiling = $this->tierPolicy->dtiCeiling();

        if ($dti > $ceiling) {
            return Decision::declined([Decision::REASON_DTI_ABOVE_CEILING]);
        }

        $tier = $this->tierPolicy->tierFor($creditScore, $dti);

        if ($tier === null) {
            // Difference 1 above: with the Tier D branch absent, applicants
            // the near-prime pilot would have priced at 18.99% arrive here.
            // They are above the program floor but below the lowest tier the
            // policy will assign, so the score is the reason.
            return Decision::declined([Decision::REASON_CREDIT_SCORE_BELOW_FLOOR]);
        }

        $stipulations = $this->stipulationsFor($snapshot);

        if ($dti > $ceiling - self::DTI_BORDERLINE_BAND) {
            // Difference 3 above.
            return Decision::manualReview(
                $tier,
                [Decision::REASON_DTI_BORDERLINE],
                $stipulations,
            );
        }

        if ($snapshot->requestedAmount > $this->tierPolicy->amountLimitForTier($tier)) {
            return Decision::manualReview(
                $tier,
                [Decision::REASON_AMOUNT_ABOVE_TIER_LIMIT],
                $stipulations,
            );
        }

        return Decision::approved($tier, $stipulations);
    }

    /**
     * Decide a batch, preserving input keys.
     *
     * @param  iterable<array-key, ApplicationSnapshot> $snapshots
     * @return array<array-key, Decision>
     */
    public function decideAll(iterable $snapshots): array
    {
        $decisions = [];

        foreach ($snapshots as $key => $snapshot) {
            $decisions[$key] = $this->decide($snapshot);
        }

        return $decisions;
    }

    /**
     * Whether a snapshot is stale enough that the bureau score must be
     * re-pulled before the decision can be relied on.
     *
     * The legacy flow reuses a cached `bureau_pulls` row indefinitely; the
     * `cached_until` column is written but never read.
     */
    public function isStale(ApplicationSnapshot $snapshot, int $maxAgeDays = 30): bool
    {
        $age = $this->clock->now()->getTimestamp() - $snapshot->submittedAt->getTimestamp();

        return $age > $maxAgeDays * 86400;
    }

    /**
     * Conditions attached to an approval or a referral.
     *
     * @return list<string>
     */
    private function stipulationsFor(ApplicationSnapshot $snapshot): array
    {
        $stipulations = [];

        if ($snapshot->requestedAmount > self::PROOF_OF_INCOME_THRESHOLD) {
            $stipulations[] = Decision::STIP_PROOF_OF_INCOME;
        }

        if ($snapshot->productCode === 'HOMEIMP') {
            $stipulations[] = Decision::STIP_PROOF_OF_RESIDENCE;
        }

        return $stipulations;
    }
}
