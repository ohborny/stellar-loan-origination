<?php

declare(strict_types=1);

namespace Meridian\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SimpleXMLElement;

/**
 * Source of effective-dated tier base rates.
 *
 * Abstracted so that {@see RateEngine} does not depend on the rate card living
 * in an XML file. The intended successor is a `rate_history`-backed
 * implementation (that table already exists and is already maintained by the
 * rate-change procedure), at which point the XML loader can be deleted.
 *
 * @package   Meridian\Pricing
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-19
 *
 * @internal Not yet wired up — LOAN-3002.
 */
interface RateTableSource
{
    /**
     * Base rates in force on a given date, keyed by tier code.
     *
     * Product-agnostic: where several products define the same tier, the most
     * recently effective entry wins. That is safe only while the rate card
     * describes a single product, which is the situation today.
     *
     * @return array<string, float>
     */
    public function effectiveOn(DateTimeImmutable $on): array;

    /**
     * Base rate for one tier and product on a given date, or null when the
     * table has no applicable entry.
     */
    public function baseRateFor(string $tierCode, string $productCode, DateTimeImmutable $on): ?float;

    /**
     * Short description of where the rates came from, for audit breadcrumbs.
     */
    public function describeSource(): string;
}

/**
 * Reads the rate card from `conf/rates.xml`.
 *
 * ---------------------------------------------------------------------------
 * KNOWN DATA PROBLEMS WITH conf/rates.xml
 * ---------------------------------------------------------------------------
 *  - The file was added in 2019 to replace the rates hardcoded in apply.php
 *    and admin.php. That replacement never happened; this class is the only
 *    reader in the repository.
 *  - Its most recent effective date is **2023-04-01**. Nothing has been
 *    written to it since. The documented rate-change procedure in
 *    docs/ describes editing a different file.
 *  - It has no notion of product at all. There is no entry for the **AUTO**
 *    product added in 2019 or for HOMEIMP (2021), and no product scoping on
 *    the entries that are there, so {@see self::availableProducts()} returns
 *    an empty list. The practical consequence is worse than a missing entry:
 *    the unscoped 2013 personal-loan rate card is applied verbatim to $75,000
 *    auto and home-improvement loans, because an unscoped entry matches every
 *    product. Nobody has decided whether that is intended.
 *  - There is no schema, no XSD and no validation on write. The parser below
 *    is deliberately tolerant about element nesting and attribute naming for
 *    that reason.
 *
 * Parsing is lazy and the result is cached for the lifetime of the instance;
 * the file is small and never changes during a request.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
final class XmlRateTableLoader implements RateTableSource
{
    /**
     * Effective date assumed for entries that do not carry one. Chosen as the
     * date the file was introduced so that undated entries never shadow a
     * properly dated one.
     */
    public const ASSUMED_EFFECTIVE_DATE = '2019-01-01';

    /**
     * Attribute names that have been observed to hold a rate.
     *
     * @var list<string>
     */
    private const RATE_ATTRIBUTES = ['base', 'base_rate', 'rate', 'apr', 'value'];

    /**
     * Attribute names that have been observed to hold an effective date.
     *
     * @var list<string>
     */
    private const DATE_ATTRIBUTES = ['effective', 'effective_date', 'effectiveOn', 'date', 'from'];

    /**
     * Parsed rate rows, or null while unparsed.
     *
     * @var list<array{tier: string, product: string|null, rate: float, effective: DateTimeImmutable}>|null
     */
    private ?array $rows = null;

    /**
     * @param string $path Absolute path to conf/rates.xml.
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Convenience factory resolving conf/rates.xml relative to the repo root.
     */
    public static function fromRepositoryRoot(string $repositoryRoot): self
    {
        return new self(rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . '/conf/rates.xml');
    }

    public function effectiveOn(DateTimeImmutable $on): array
    {
        $best  = [];
        $dates = [];

        foreach ($this->rows() as $row) {
            if ($row['effective'] > $on) {
                continue;
            }

            $tier = $row['tier'];

            if (!isset($dates[$tier]) || $row['effective'] >= $dates[$tier]) {
                $best[$tier]  = $row['rate'];
                $dates[$tier] = $row['effective'];
            }
        }

        ksort($best);

        return $best;
    }

    public function baseRateFor(string $tierCode, string $productCode, DateTimeImmutable $on): ?float
    {
        $tierCode    = strtoupper(trim($tierCode));
        $productCode = strtoupper(trim($productCode));

        $exact    = null;
        $exactOn  = null;
        $wild     = null;
        $wildOn   = null;

        foreach ($this->rows() as $row) {
            if ($row['tier'] !== $tierCode || $row['effective'] > $on) {
                continue;
            }

            if ($row['product'] === $productCode) {
                if ($exactOn === null || $row['effective'] >= $exactOn) {
                    $exact   = $row['rate'];
                    $exactOn = $row['effective'];
                }
                continue;
            }

            if ($row['product'] === null) {
                if ($wildOn === null || $row['effective'] >= $wildOn) {
                    $wild   = $row['rate'];
                    $wildOn = $row['effective'];
                }
            }
        }

        // A product-specific entry always beats an unscoped one, regardless of
        // which is more recent.
        return $exact ?? $wild;
    }

    /**
     * Product codes the rate card actually scopes an entry to.
     *
     * As of the 2024 engagement this returns an empty list: every entry in
     * conf/rates.xml is unscoped and therefore applies to every product.
     *
     * @return list<string>
     */
    public function availableProducts(): array
    {
        $seen = [];

        foreach ($this->rows() as $row) {
            if ($row['product'] !== null) {
                $seen[$row['product']] = true;
            }
        }

        $products = array_keys($seen);
        sort($products);

        return $products;
    }

    /**
     * Most recent effective date present in the file, or null when empty.
     */
    public function newestEffectiveDate(): ?DateTimeImmutable
    {
        $newest = null;

        foreach ($this->rows() as $row) {
            if ($newest === null || $row['effective'] > $newest) {
                $newest = $row['effective'];
            }
        }

        return $newest;
    }

    public function describeSource(): string
    {
        $newest = $this->newestEffectiveDate();

        return sprintf(
            'conf/rates.xml (%d entries, newest effective %s)',
            count($this->rows()),
            $newest?->format('Y-m-d') ?? 'n/a',
        );
    }

    /**
     * Parse the file once and cache the rows.
     *
     * @return list<array{tier: string, product: string|null, rate: float, effective: DateTimeImmutable}>
     *
     * @throws RuntimeException when the file is missing or not well formed.
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (!is_readable($this->path)) {
            throw new RuntimeException(sprintf('rate table not readable: %s', $this->path));
        }

        $raw = file_get_contents($this->path);

        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException(sprintf('rate table is empty: %s', $this->path));
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($raw);
        } catch (\Exception $e) {
            throw new RuntimeException(
                sprintf('rate table is not well-formed XML: %s', $this->path),
                0,
                $e,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $rows = [];
        $this->collect($xml, null, null, $rows);

        return $this->rows = $rows;
    }

    /**
     * Recursively walk the document collecting tier entries.
     *
     * Product scope and effective date are inherited from whichever ancestor
     * declared them, so both `<product code="X"><tier .../></product>` and a
     * flat `<tier product="X" .../>` layout parse correctly.
     *
     * @param list<array{tier: string, product: string|null, rate: float, effective: DateTimeImmutable}> $rows
     */
    private function collect(
        SimpleXMLElement $node,
        ?string $product,
        ?DateTimeImmutable $effective,
        array &$rows,
    ): void {
        $product   = $this->readProduct($node) ?? $product;
        $effective = $this->readEffectiveDate($node) ?? $effective;

        if (strcasecmp($node->getName(), 'tier') === 0) {
            $tier = $this->readAttribute($node, ['code', 'tier', 'name', 'id']);
            $rate = $this->readRate($node);

            if ($tier !== null && $rate !== null) {
                $rows[] = [
                    'tier'      => strtoupper($tier),
                    'product'   => $product,
                    'rate'      => $rate,
                    'effective' => $effective ?? $this->assumedEffectiveDate(),
                ];
            }
        }

        foreach ($node->children() as $child) {
            $this->collect($child, $product, $effective, $rows);
        }
    }

    /**
     * Product code declared on this element, if any.
     */
    private function readProduct(SimpleXMLElement $node): ?string
    {
        $isProductElement = strcasecmp($node->getName(), 'product') === 0;
        $candidate        = $this->readAttribute(
            $node,
            $isProductElement ? ['code', 'name', 'id', 'product'] : ['product', 'product_code'],
        );

        if ($candidate === null) {
            return null;
        }

        $candidate = strtoupper(trim($candidate));

        // "*" and "ALL" are both used in the file to mean "any product".
        return ($candidate === '' || $candidate === '*' || $candidate === 'ALL')
            ? null
            : $candidate;
    }

    /**
     * Effective date declared on this element, if any.
     */
    private function readEffectiveDate(SimpleXMLElement $node): ?DateTimeImmutable
    {
        $raw = $this->readAttribute($node, self::DATE_ATTRIBUTES);

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parsed = date_create_immutable(trim($raw), new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->setTime(0, 0, 0);
    }

    /**
     * Rate declared on this element, from an attribute or its text content.
     */
    private function readRate(SimpleXMLElement $node): ?float
    {
        $raw = $this->readAttribute($node, self::RATE_ATTRIBUTES) ?? trim((string) $node);

        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $rate = (float) $raw;

        // Some entries are written as percentages ("6.49") rather than decimal
        // rates ("0.0649"). Anything above 1.0 is assumed to be a percentage,
        // which is safe because no tier base rate reaches 100%.
        return $rate > 1.0 ? $rate / 100.0 : $rate;
    }

    /**
     * First present attribute from a list of candidate names.
     *
     * @param list<string> $names
     */
    private function readAttribute(SimpleXMLElement $node, array $names): ?string
    {
        foreach ($names as $name) {
            if (isset($node[$name])) {
                return (string) $node[$name];
            }
        }

        return null;
    }

    private function assumedEffectiveDate(): DateTimeImmutable
    {
        $date = date_create_immutable(self::ASSUMED_EFFECTIVE_DATE, new DateTimeZone('UTC'));

        if ($date === false) {
            throw new RuntimeException('ASSUMED_EFFECTIVE_DATE is not a valid date');
        }

        return $date->setTime(0, 0, 0);
    }
}
