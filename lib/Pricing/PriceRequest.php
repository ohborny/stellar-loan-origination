<?php

declare(strict_types=1);

namespace Meridian\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable pricing inputs for {@see RateEngine::price()}.
 *
 * Everything the engine needs and nothing it does not: notably no applicant
 * identity, no credit score and no decision state, so a request can be
 * constructed for a hypothetical quote without touching the `applicants`
 * table.
 *
 * Validation happens once, in the constructor. Any instance that exists is
 * therefore a valid pricing input, which is what lets RateEngine be free of
 * defensive checks.
 *
 * @package   Meridian\Pricing
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-19
 *
 * @internal Not yet wired up — LOAN-3002.
 */
final class PriceRequest
{
    /**
     * Terms the origination workflow will actually accept. Enforced here
     * because the legacy `apply.php` form posts a free-text `term_months`
     * field with no server-side bound at all.
     */
    public const MIN_TERM_MONTHS = 6;
    public const MAX_TERM_MONTHS = 84;

    /**
     * Product ceilings, per the product history:
     *   PERSONAL — $25,000 (2013)
     *   AUTO     — $75,000 (2019)
     *   HOMEIMP  — $75,000 (2021)
     *
     * @var array<string, float>
     */
    public const PRODUCT_MAX_AMOUNT = [
        'PERSONAL' => 25000.0,
        'AUTO'     => 75000.0,
        'HOMEIMP'  => 75000.0,
    ];

    /**
     * @param string            $tier        Tier code. Normally A/B/C/D; the
     *                                       literal "DECLINE" is also valid
     *                                       and prices at the sentinel rate.
     * @param float             $amount      Principal, in dollars.
     * @param int               $termMonths  Term in whole months.
     * @param string            $productCode One of PRODUCT_MAX_AMOUNT's keys.
     * @param DateTimeImmutable $asOf        Effective date for rate selection.
     *
     * @throws InvalidArgumentException when any input is outside its domain.
     */
    public function __construct(
        public readonly string $tier,
        public readonly float $amount,
        public readonly int $termMonths,
        public readonly string $productCode,
        public readonly DateTimeImmutable $asOf,
    ) {
        if ($this->tier === '') {
            throw new InvalidArgumentException('tier must not be empty');
        }

        if ($this->tier !== strtoupper($this->tier)) {
            throw new InvalidArgumentException(
                sprintf('tier must be upper case, got "%s"', $this->tier),
            );
        }

        if (!is_finite($this->amount)) {
            throw new InvalidArgumentException('amount must be a finite number');
        }

        if ($this->amount <= 0.0) {
            throw new InvalidArgumentException(
                sprintf('amount must be positive, got %F', $this->amount),
            );
        }

        if ($this->termMonths < self::MIN_TERM_MONTHS || $this->termMonths > self::MAX_TERM_MONTHS) {
            throw new InvalidArgumentException(sprintf(
                'termMonths must be between %d and %d, got %d',
                self::MIN_TERM_MONTHS,
                self::MAX_TERM_MONTHS,
                $this->termMonths,
            ));
        }

        if (!array_key_exists($this->productCode, self::PRODUCT_MAX_AMOUNT)) {
            throw new InvalidArgumentException(
                sprintf('unknown productCode "%s"', $this->productCode),
            );
        }

        $ceiling = self::PRODUCT_MAX_AMOUNT[$this->productCode];

        if ($this->amount > $ceiling) {
            throw new InvalidArgumentException(sprintf(
                'amount %F exceeds the %s ceiling of %F',
                $this->amount,
                $this->productCode,
                $ceiling,
            ));
        }
    }

    /**
     * Build a request from a `loans` row (optionally joined to `applicants`).
     *
     * The legacy schema has no product column: product is inferred from the
     * `notes` free-text field, which is where the origination screens write
     * "AUTO -" / "HOMEIMP -" prefixes. Rows predating 2019 have neither, so
     * they are treated as PERSONAL.
     *
     * `asOf` falls back to `created_at`, and then to today. Note that
     * `created_at` is stored in server-local time with no offset (LOAN-2811),
     * so a row created after 19:00 Eastern parses to the following UTC day.
     * {@see \Meridian\Support\ClockInterface} for the intended fix.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException when required columns are missing.
     */
    public static function fromLoanRow(array $row): self
    {
        foreach (['amount', 'term_months'] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new InvalidArgumentException(
                    sprintf('loan row is missing required column "%s"', $required),
                );
            }
        }

        $tier = strtoupper(trim((string) ($row['tier'] ?? 'DECLINE')));

        return new self(
            tier: $tier === '' ? 'DECLINE' : $tier,
            amount: (float) $row['amount'],
            termMonths: (int) $row['term_months'],
            productCode: self::inferProductCode((string) ($row['notes'] ?? '')),
            asOf: self::parseAsOf($row['created_at'] ?? null),
        );
    }

    /**
     * Infer the product code from the `loans.notes` prefix convention.
     */
    private static function inferProductCode(string $notes): string
    {
        $upper = strtoupper($notes);

        return match (true) {
            str_contains($upper, 'AUTO')    => 'AUTO',
            str_contains($upper, 'HOMEIMP') => 'HOMEIMP',
            default                         => 'PERSONAL',
        };
    }

    /**
     * Parse a stored date string, normalising to UTC midnight.
     */
    private static function parseAsOf(mixed $raw): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if (!is_string($raw) || trim($raw) === '') {
            return new DateTimeImmutable('today', $utc);
        }

        $parsed = date_create_immutable(trim($raw), $utc);

        if ($parsed === false) {
            throw new InvalidArgumentException(
                sprintf('unparseable created_at value "%s"', $raw),
            );
        }

        return $parsed->setTimezone($utc)->setTime(0, 0, 0);
    }

    /**
     * Copy of this request with a different effective date.
     *
     * Used by the (unbuilt) repricing report to run the same application
     * against several rate-table vintages.
     */
    public function withAsOf(DateTimeImmutable $asOf): self
    {
        return new self(
            tier: $this->tier,
            amount: $this->amount,
            termMonths: $this->termMonths,
            productCode: $this->productCode,
            asOf: $asOf,
        );
    }

    /**
     * @return array<string, string|float|int>
     */
    public function toArray(): array
    {
        return [
            'tier'         => $this->tier,
            'amount'       => $this->amount,
            'term_months'  => $this->termMonths,
            'product_code' => $this->productCode,
            'as_of'        => $this->asOf->format('Y-m-d'),
        ];
    }
}
