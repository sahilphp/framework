<?php

declare(strict_types=1);

namespace Sahil\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\I18n\LangLoader;
use Sahil\I18n\Locale;
use Sahil\I18n\LocaleFormatter;
use Sahil\I18n\LocaleNegotiator;
use Sahil\I18n\PluralRules;
use Sahil\I18n\RequestLocale;
use Sahil\I18n\Translator;

/**
 * Unit coverage for the i18n foundation: catalogs, fallback, replacements,
 * pluralization, negotiation, and formatting. App-context behaviour (helpers,
 * validation, scope isolation, middleware) lives in I18nApplicationTest.
 */
final class I18nTest extends TestCase
{
    private const string LANG = __DIR__ . '/../Fixtures/i18n-lang';
    private const string PACKAGE_LANG = __DIR__ . '/../../resources/lang';

    private static function locale(array $app = []): Locale
    {
        $container = new Container();
        $container->scoped(RequestLocale::class);

        return new Locale($container, new Repository(['app' => $app]));
    }

    private static function translator(?Locale $locale = null, ?string $langDir = null, ?\Closure $reportMissing = null): Translator
    {
        return new Translator(
            new LangLoader([$langDir ?? self::LANG]),
            $locale ?? self::locale(),
            $reportMissing,
        );
    }

    // ---------------------------------------------------------------
    // Translator
    // ---------------------------------------------------------------

    public function test_translates_flat_and_nested_keys(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        self::assertSame('Habari :name', $t->get('messages.greeting'));
        self::assertSame('kina', $t->get('messages.nested.deep.leaf'));
    }

    public function test_translates_with_locale_override(): void
    {
        $t = self::translator();

        self::assertSame('Hello :name', $t->get('messages.greeting', [], 'en'));
        self::assertSame('Bonjour :name', $t->get('messages.greeting', [], 'fr'));
    }

    public function test_replaces_named_placeholders(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        self::assertSame('Habari Idrisa', $t->get('messages.greeting', ['name' => 'Idrisa']));
        self::assertSame('Hello Idrisa', $t->get('messages.greeting', ['name' => 'Idrisa'], 'en'));
    }

    public function test_repeated_placeholders_all_replaced(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        self::assertSame('A na A', $t->get('messages.repeat', ['x' => 'A']));
    }

    public function test_missing_placeholders_remain_visible(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        self::assertSame('Habari :name', $t->get('messages.greeting'));
    }

    public function test_missing_keys_fall_back_to_fallback_locale_then_raw_key(): void
    {
        $t = self::translator(self::locale(['locale' => 'en', 'fallback_locale' => 'sw']));

        // `en` has no `only_swahili` key; the sw line is the fallback.
        self::assertSame('kya kiswahili tu', $t->get('messages.only_swahili'));

        // Absent in every locale → documented raw-key return.
        self::assertSame('messages.absent_key', $t->get('messages.absent_key'));
    }

    public function test_missing_key_is_reported_once_through_the_hook(): void
    {
        $reported = [];
        $t = self::translator(
            self::locale(['locale' => 'sw']),
            null,
            static function (string $key, string $locale) use (&$reported): void {
                $reported[] = [$key, $locale];
            },
        );

        $t->get('messages.absent_key');
        $t->get('messages.absent_key');
        $t->get('messages.absent_key', [], 'en');

        self::assertSame([['messages.absent_key', 'sw'], ['messages.absent_key', 'en']], $reported);
    }

    public function test_malformed_catalog_fails_safely(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']), self::LANG . '/broken');

        self::assertSame('messages.anything', $t->get('messages.anything'));
    }

    public function test_application_path_overrides_framework_catalog_per_key(): void
    {
        $loader = new LangLoader([self::LANG . '/override', self::LANG]);
        $t = new Translator($loader, self::locale(['locale' => 'sw']));

        // Overridden in the first path.
        self::assertSame('HABARI :name', $t->get('messages.greeting'));
        // Only defined in the second path — still reachable.
        self::assertSame('kina', $t->get('messages.nested.deep.leaf'));
    }

    // ---------------------------------------------------------------
    // PluralRules + choice
    // ---------------------------------------------------------------

    public function test_swahili_plural_categories(): void
    {
        // Swahili treats 0 and 1 as the singular ("one") form.
        self::assertSame('one', PluralRules::category('sw', 0));
        self::assertSame('one', PluralRules::category('sw', 1));
        self::assertSame('other', PluralRules::category('sw', 2));
        self::assertSame('other', PluralRules::category('sw', 5));
    }

    public function test_english_plural_categories(): void
    {
        self::assertSame('one', PluralRules::category('en', 1));
        self::assertSame('other', PluralRules::category('en', 0));
        self::assertSame('other', PluralRules::category('en', 2));
    }

    public function test_unlisted_locales_use_the_english_rule(): void
    {
        self::assertSame('one', PluralRules::category('de', 1));
        self::assertSame('other', PluralRules::category('de', 2));
        self::assertSame('other', PluralRules::category('ja', 1)); // one-form language
    }

    public function test_choice_selects_category_form_and_injects_count(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        // Swahili: 0 and 1 → "one" form.
        self::assertSame('kitu 0', $t->choice('messages.items', 0));
        self::assertSame('kitu 1', $t->choice('messages.items', 1));
        self::assertSame('vitu 9', $t->choice('messages.items', 9));

        // English: only 1 → "one".
        self::assertSame('0 items', $t->choice('messages.items', 0, [], 'en'));
        self::assertSame('1 item', $t->choice('messages.items', 1, [], 'en'));
        self::assertSame('9 items', $t->choice('messages.items', 9, [], 'en'));
    }

    public function test_choice_supports_interval_and_pipe_formats(): void
    {
        $t = self::translator(self::locale(['locale' => 'sw']));

        self::assertSame('Hakuna tufaha', $t->choice('messages.apples', 0));
        self::assertSame('No apples', $t->choice('messages.apples', 0, [], 'en'));
        self::assertSame('One apple', $t->choice('messages.apples', 1, [], 'en'));
        self::assertSame('7 apples', $t->choice('messages.apples', 7, [], 'en'));

        // Plain two-part pipe strings: first segment = "one", second = "other".
        self::assertSame('moja', $t->choice('messages.pipe', 1));
        self::assertSame('nyingi', $t->choice('messages.pipe', 4));
        self::assertSame('one', $t->choice('messages.pipe', 1, [], 'en'));
        self::assertSame('many', $t->choice('messages.pipe', 4, [], 'en'));
    }

    // ---------------------------------------------------------------
    // Locale (config-driven)
    // ---------------------------------------------------------------

    public function test_locale_defaults_to_swahili(): void
    {
        $locale = self::locale(); // empty config

        self::assertSame('sw', $locale->default());
        self::assertSame('sw', $locale->current());
        self::assertSame('sw', $locale->fallback());
    }

    public function test_supported_list_includes_default_and_fallback(): void
    {
        $locale = self::locale([
            'locale' => 'sw',
            'fallback_locale' => 'en',
            'available_locales' => ['sw', 'fr'],
        ]);

        self::assertSame(['sw', 'fr', 'en'], $locale->supported());
        self::assertTrue($locale->isSupported('EN'));
        self::assertFalse($locale->isSupported('en-GB'));
        self::assertFalse($locale->isSupported('de'));
    }

    public function test_supported_accepts_comma_string_config(): void
    {
        $locale = self::locale(['locale' => 'sw', 'available_locales' => 'sw, en,fr']);

        self::assertSame(['sw', 'en', 'fr'], $locale->supported());
    }

    public function test_set_get_and_clear(): void
    {
        $locale = self::locale(['locale' => 'sw', 'available_locales' => ['sw', 'en']]);

        $locale->set('EN');
        self::assertSame('en', $locale->current());
        $locale->clear();
        self::assertSame('sw', $locale->current());
    }

    public function test_set_rejects_unsupported_locales(): void
    {
        $locale = self::locale(['locale' => 'sw', 'available_locales' => ['sw', 'en']]);

        $this->expectException(\InvalidArgumentException::class);
        $locale->set('de');
    }

    public function test_set_if_supported_silently_declines(): void
    {
        $locale = self::locale(['locale' => 'sw', 'available_locales' => ['sw', 'en']]);

        self::assertFalse($locale->setIfSupported('de'));
        self::assertSame('sw', $locale->current());
        self::assertTrue($locale->setIfSupported('en'));
        self::assertSame('en', $locale->current());
    }

    public function test_unsupported_configured_locale_still_resolves(): void
    {
        // Even a misconfigured default is read as-is by the app but the
        // supported list keeps it; normalization lowercases it.
        $locale = self::locale(['locale' => 'XX-Invalid']);

        self::assertSame('xx-invalid', $locale->default());
        self::assertContains('xx-invalid', $locale->supported());
    }

    // ---------------------------------------------------------------
    // LocaleNegotiator
    // ---------------------------------------------------------------

    public function test_negotiator_picks_best_supported_language(): void
    {
        $n = new LocaleNegotiator();

        self::assertSame('en', $n->negotiate('en-GB,en;q=0.9,sw;q=0.8', ['sw', 'en']));
        self::assertSame('sw', $n->negotiate('de-DE,de;q=0.9,sw-KE;q=0.7', ['sw', 'en']));
    }

    public function test_negotiator_respects_quality_weights(): void
    {
        $n = new LocaleNegotiator();

        self::assertSame('sw', $n->negotiate('en;q=0.4,sw;q=0.9', ['sw', 'en']));
    }

    public function test_negotiator_matches_base_language_both_ways(): void
    {
        $n = new LocaleNegotiator();

        // en-GB requested, only en supported.
        self::assertSame('en', $n->negotiate('en-GB', ['sw', 'en']));
        // en requested, only en-GB supported.
        self::assertSame('en-GB', $n->negotiate('en', ['sw', 'en-GB']));
    }

    public function test_negotiator_returns_null_for_unsupported_or_missing(): void
    {
        $n = new LocaleNegotiator();

        self::assertNull($n->negotiate('de-DE,de;q=0.9', ['sw', 'en']));
        self::assertNull($n->negotiate(null, ['sw', 'en']));
        self::assertNull($n->negotiate('', ['sw', 'en']));
        self::assertNull($n->negotiate('en', []));
    }

    // ---------------------------------------------------------------
    // LocaleFormatter
    // ---------------------------------------------------------------

    public function test_formats_numbers_decimals_and_percentages(): void
    {
        $f = new LocaleFormatter(self::locale(['locale' => 'en']));

        self::assertSame('1,234.5', $f->number(1234.5));
        self::assertSame('1,234', $f->number(1234.49, null, 0));
    }

    public function test_formats_currency_with_explicit_code(): void
    {
        $f = new LocaleFormatter(self::locale(['locale' => 'en']));

        self::assertStringContainsString('1,500.00', $f->currency(1500, 'USD'));
    }

    public function test_currency_is_never_inferred_from_the_locale(): void
    {
        $f = new LocaleFormatter(self::locale(['locale' => 'sw']));

        // sw locale + USD must not silently become TZS.
        $formatted = $f->currency(1500, 'USD');
        self::assertStringContainsString('1,500.00', $formatted);
        self::assertStringNotContainsString('TZS', $formatted);
        self::assertStringNotContainsString('TSh', $formatted);
    }

    public function test_formats_dates_times_and_datetimes(): void
    {
        $f = new LocaleFormatter(self::locale(['locale' => 'en']));
        $ts = 1700000000;

        self::assertSame('Nov 14, 2023', $f->date($ts));
        self::assertNotSame('', $f->time($ts));
        self::assertNotSame('', $f->datetime($ts));
        self::assertSame('Nov 14, 2023', $f->date('2023-11-14'));
    }

    public function test_formatter_uses_the_active_locale_by_default(): void
    {
        $locale = self::locale(['locale' => 'sw', 'available_locales' => ['sw', 'en']]);
        $locale->set('en');
        $f = new LocaleFormatter($locale);

        self::assertSame('1,234.5', $f->number(1234.5));
    }

    // ---------------------------------------------------------------
    // Framework-owned catalogs
    // ---------------------------------------------------------------

    public function test_framework_catalogs_ship_swahili_and_english(): void
    {
        foreach (['messages', 'validation', 'auth', 'pagination', 'errors'] as $group) {
            foreach (['sw', 'en'] as $locale) {
                $file = self::PACKAGE_LANG . "/{$locale}/{$group}.php";
                self::assertFileExists($file);
                $catalog = require $file;
                self::assertIsArray($catalog, "{$locale}/{$group} must return an array");
                self::assertNotEmpty($catalog);
            }
        }
    }

    public function test_swahili_framework_messages_are_natural(): void
    {
        $t = new Translator(new LangLoader([self::PACKAGE_LANG]), self::locale(['locale' => 'sw']));

        self::assertSame('Kuna jambo lililokwenda mrama upande wetu', $t->get('errors.server_error_title'));
        self::assertSame('Haijapatikana', $t->get('errors.not_found'));
        self::assertSame('Sehemu ya :attribute inahitajika.', $t->get('validation.required'));
        self::assertSame('Taarifa za kuingia hazilingani na rekodi zetu.', $t->get('auth.failed'));
        self::assertSame('« Iliyotangulia', $t->get('pagination.previous'));
    }

    public function test_every_validation_rule_has_swahili_and_english(): void
    {
        $sw = require self::PACKAGE_LANG . '/sw/validation.php';
        $en = require self::PACKAGE_LANG . '/en/validation.php';

        foreach (array_keys($en) as $rule) {
            self::assertArrayHasKey($rule, $sw, "missing swahili translation for '{$rule}'");
        }
    }
}
