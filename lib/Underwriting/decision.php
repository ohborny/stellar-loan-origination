<?php

declare(strict_types=1);

namespace Meridian\Underwriting;

/**
 * The three states an underwriting decision can end in.
 *
 * The legacy code has no such type. It writes a free-text value into
 * `loans.status`, and the set of values actually present in the demo database
 * is {APPROVED, DECLINED, REVIEW, review, PENDING, ''} — six spellings for
 * three states, plus a null-ish one. {@see Decision::legacyStatus()} maps back
 * to the two spellings the screens actually match on.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
enum Outcome: string
{
    case Approved = 'APPROVED';
    case Declined = 'DECLINED';
    case ManualReview = 'REVIEW';

    /**
     * Whether this outcome ends the workflow or hands it to a human.
     */
    public function isTerminal(): bool
    {
        return $this !== self::ManualReview;
    }

    /**
     * Label for the underwriter queue and applicant-facing screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::Approved     => 'Approved',
            self::Declined     => 'Declined',
            self::ManualReview => 'Referred for manual review',
        };
    }
}

/**
 * Immutable underwriting decision.
 *
 * Reason codes are the machine-readable "why". They are required on a decline
 * because the adverse-action notice needs them; the legacy path composes that
 * notice from the free-text `loans.notes` column instead, which is why some
 * historical letters cite no reason at all.
 *
 * @package   Meridian\Underwriting
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-09-03
 *
 * @internal Not yet wired up — LOAN-3002.
 */
final class Decision
{
    public const REASON_CREDIT_SCORE_BELOW_FLOOR = 'CREDIT_SCORE_BELOW_FLOOR';
    public const REASON_DTI_ABOVE_CEILING        = 'DTI_ABOVE_CEILING';
    public const REASON_DTI_BORDERLINE           = 'DTI_BORDERLINE';
    public const REASON_NO_VERIFIABLE_INCOME     = 'NO_VERIFIABLE_INCOME';
    public const REASON_NO_BUREAU_SCORE          = 'NO_BUREAU_SCORE';
    public const REASON_AMOUNT_ABOVE_TIER_LIMIT  = 'AMOUNT_ABOVE_TIER_LIMIT';
    public const REASON_WITHIN_POLICY            = 'WITHIN_POLICY';

    public const STIP_PROOF_OF_INCOME     = 'Provide two most recent pay stubs';
    public const STIP_PROOF_OF_RESIDENCE  = 'Provide proof of current address';
    public const STIP_MANUAL_BUREAU_PULL  = 'Underwriter to pull bureau manually';

    /**
     * @param Outcome      $outcome      Resulting state.
     * @param string       $tier         Pricing tier assigned, or "DECLINE".
     * @param list<string> $reasonCodes  Machine-readable reasons, most
     *                                   significant first. Never empty.
     * @param list<string> $stipulations Conditions the applicant or the
     *                                   underwriter must satisfy.
     */
    private function __construct(
        public readonly Outcome $outcome,
        public readonly string $tier,
        public readonly array $reasonCodes,
        public readonly array $stipulations,
    ) {
    }

    /**
     * @param list<string> $stipulations
     * @param list<string> $reasonCodes
     */
    public static function approved(
        string $tier,
        array $stipulations = [],
        array $reasonCodes = [self::REASON_WITHIN_POLICY],
    ): self {
        return new self(Outcome::Approved, $tier, $reasonCodes, $stipulations);
    }

    /**
     * @param list<string> $reasonCodes
     */
    public static function declined(array $reasonCodes): self
    {
        return new self(
            Outcome::Declined,
            'DECLINE',
            $reasonCodes === [] ? [self::REASON_DTI_ABOVE_CEILING] : $reasonCodes,
            [],
        );
    }

    /**
     * @param list<string> $reasonCodes
     * @param list<string> $stipulations
     */
    public static function manualReview(
        string $tier,
        array $reasonCodes,
        array $stipulations = [],
    ): self {
        return new self(Outcome::ManualReview, $tier, $reasonCodes, $stipulations);
    }

    public function isApproved(): bool
    {
        return $this->outcome === Outcome::Approved;
    }

    public function requiresHuman(): bool
    {
        return !$this->outcome->isTerminal();
    }

    public function hasReason(string $reasonCode): bool
    {
        return in_array($reasonCode, $this->reasonCodes, true);
    }

    /**
     * The `loans.status` spelling the legacy screens match on.
     *
     * `admin.php` compares with `==` against 'APPROVED' and 'DECLINED' and
     * treats everything else as pending, so REVIEW lands in the right queue by
     * accident rather than by design.
     */
    public function legacyStatus(): string
    {
        return $this->outcome->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome'      => $this->outcome->value,
            'outcome_label' => $this->outcome->label(),
            'tier'         => $this->tier,
            'reason_codes' => $this->reasonCodes,
            'stipulations' => $this->stipulations,
            'legacy_status' => $this->legacyStatus(),
        ];
    }
}

/*
 * 2025-avaldez -- filename collision, please read before renaming anything.
 *
 * This file is lib/Underwriting/Decision.php. Until 2024 there was also a
 * lib/Underwriting/decision.php: the legacy procedural file holding
 * run_decision(), the actual production decision orchestrator.
 *
 * On a case-insensitive filesystem (macOS, Windows) those are one path. A
 * merge resolved on such a checkout committed this file's contents over the
 * legacy one, and run_decision() was lost from the repository. Nothing broke
 * loudly, because nothing under lib/ has test coverage.
 *
 * The legacy file was recovered from a colleague's working copy and now
 * lives at lib/Underwriting/decision_engine.php. Read the header comment
 * there before touching either file -- it also records that the recovered
 * copy is not provably identical to what was in production.
 *
 * Do not add another lib/Underwriting file whose name differs from an
 * existing one only by case.
 */
