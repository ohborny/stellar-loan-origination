<?php

declare(strict_types=1);

/**
 * PSR-4-style autoloader for the `Meridian\` namespace.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE IS REGISTERED NOWHERE.
 * ---------------------------------------------------------------------------
 * Nothing in the application requires it. Every entry point under `public/`,
 * `partner/` and `tools/` builds its dependencies with a `require_once` chain
 * rooted at `public/db_config.php` instead, so no class in `lib/` is ever
 * loaded and no code in `lib/` is ever executed.
 *
 * Step 1 of the integration plan (see lib/Pricing/README.md) was to require
 * this file from `public/db_config.php`, which is the one include every entry
 * point already has. That was attempted in staging on 2024-10-21 and reverted
 * the same afternoon:
 *
 *   `admin.php` includes `lib/Underwriting/tier_rules.php` — a 2018 file of
 *   plain global functions, not classes — by the relative path
 *   `../lib/Underwriting/tier_rules.php`. When the autoloader resolved
 *   `Meridian\Underwriting\TierRules` it required the same file by its
 *   canonical absolute path. `require_once` deduplicates on resolved path and
 *   the staging deploy directory is a symlink, so the two paths were treated
 *   as different files and PHP fataled with
 *   `Cannot redeclare hsg_tier_for_score()`.
 *
 * The fix is to rename the functions in `tier_rules.php`, or to stop calling
 * a class `TierRules` when a global function of the same name exists. Neither
 * was done before the engagement ended, so the `spl_autoload_register()` call
 * at the bottom of this file stays commented out.
 *
 * Also note this loader is redundant with Composer's: `composer.json` at the
 * repository root declares the same PSR-4 mapping. Composer has never been
 * run here (there is no `vendor/` and no `composer.lock`), which is why this
 * hand-rolled version exists at all.
 *
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-14
 *
 * @internal Not yet wired up — LOAN-3002.
 */

namespace Meridian;

/**
 * Root namespace prefix this loader is responsible for. Trailing separator
 * included so that `Meridiana\Foo` cannot match `Meridian`.
 */
const AUTOLOAD_PREFIX = 'Meridian\\';

/**
 * Directory the prefix maps to: this file's own directory, i.e. `lib/`.
 */
const AUTOLOAD_BASE_DIR = __DIR__;

/**
 * Build the autoload callable.
 *
 * Returned rather than registered so that the caller decides when — and
 * whether — to install it. Also makes it testable without polluting the
 * global autoload stack.
 *
 * @param  string $baseDir Directory the namespace prefix maps to.
 * @return callable(string): void
 */
function makeAutoloader(string $baseDir = AUTOLOAD_BASE_DIR): callable
{
    $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);

    return static function (string $class) use ($baseDir): void {
        if (!str_starts_with($class, AUTOLOAD_PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(AUTOLOAD_PREFIX));
        $path     = $baseDir . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($path)) {
            require $path;

            return;
        }

        // Several `lib/` files declare more than one type (the interface and
        // its implementation live together, e.g. RateTableSource and
        // XmlRateTableLoader in Pricing/RateTableLoader.php, and the three
        // clocks in Support/Clock.php). Those need a second lookup against
        // the aliases below. Splitting them into one-type-per-file was left
        // for the cutover and never happened.
        foreach (classFileAliases() as $alias => $file) {
            if ($alias === $class) {
                $aliasPath = $baseDir . DIRECTORY_SEPARATOR . $file;

                if (is_file($aliasPath)) {
                    require $aliasPath;
                }

                return;
            }
        }
    };
}

/**
 * Types that do not live in a file named after them.
 *
 * @return array<string, string> fully-qualified class name => path under lib/
 */
function classFileAliases(): array
{
    return [
        'Meridian\\Pricing\\RateTableSource'        => 'Pricing/RateTableLoader.php',
        'Meridian\\Pricing\\XmlRateTableLoader'     => 'Pricing/RateTableLoader.php',
        'Meridian\\Support\\ClockInterface'         => 'Support/Clock.php',
        'Meridian\\Support\\SystemClock'            => 'Support/Clock.php',
        'Meridian\\Support\\FrozenClock'            => 'Support/Clock.php',
        'Meridian\\Underwriting\\Outcome'           => 'Underwriting/Decision.php',
        'Meridian\\Underwriting\\TierPolicy'        => 'Underwriting/DecisionService.php',
        'Meridian\\Underwriting\\DtiCalculator'     => 'Underwriting/DecisionService.php',
        'Meridian\\Underwriting\\ApplicationSnapshot' => 'Underwriting/DecisionService.php',
    ];
}

// -----------------------------------------------------------------------------
// Registration. Reverted 2024-10-21 — see the note at the top of this file.
// Do not uncomment without renaming the globals in tier_rules.php first.
// -----------------------------------------------------------------------------
// spl_autoload_register(makeAutoloader(), true, false);
