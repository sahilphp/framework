<?php

declare(strict_types=1);

namespace Sahil\Tests\I18n;

use Sahil\Foundation\Application;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Middleware\SetLocale;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\I18n\Locale;
use Sahil\Routing\Link;
use Sahil\Tests\FrameworkTestCase;
use Sahil\Validation\Validator;

/**
 * Application-context i18n coverage: helpers, runtime locale changes,
 * validation localization, the SetLocale middleware, request scope
 * isolation, and localized error rendering.
 */
final class I18nApplicationTest extends FrameworkTestCase
{
    private const string FIXTURE_LANG = __DIR__ . '/../Fixtures/i18n-lang';

    protected function configurationOverrides(): array
    {
        return [
            'app.available_locales' => ['sw', 'en'],
        ];
    }

    public function test_default_locale_is_swahili(): void
    {
        self::assertSame('sw', $this->app()->getLocale());
        self::assertSame('Haijapatikana', __('errors.not_found'));
        self::assertSame('Karibu kwenye programu yetu.', __('messages.welcome'));
    }

    public function test_runtime_locale_switch_through_the_application(): void
    {
        $this->app()->setLocale('en');

        self::assertSame('en', $this->app()->getLocale());
        self::assertSame('Not found', __('errors.not_found'));
        self::assertSame('Not found', trans('errors.not_found'));

        $this->app()->setLocale('sw');
        self::assertSame('Haijapatikana', __('errors.not_found'));
    }

    public function test_locale_switch_to_unsupported_locale_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app()->setLocale('de');
    }

    public function test_application_catalog_overrides_framework_per_key(): void
    {
        // Point translations at the override fixture before the loader is resolved.
        $this->app()->config()->set('app.translations_path', self::FIXTURE_LANG . '/override');
        $this->app()->container()->make(\Sahil\I18n\LangLoader::class)->flush();

        self::assertSame('HABARI Idrisa', __('messages.greeting', ['name' => 'Idrisa']));
        // Keys only in the bundled catalog still resolve.
        self::assertSame('Haijapatikana', __('errors.not_found'));
    }

    public function test_trans_choice_uses_swahili_plural_rules(): void
    {
        // Point the loader at the fixture catalog holding `items` forms.
        $this->app()->config()->set('app.translations_path', self::FIXTURE_LANG);
        $this->app()->container()->make(\Sahil\I18n\LangLoader::class)->flush();

        // sw: 0 and 1 → the "one" form; en: only 1.
        self::assertSame('kitu 0', trans_choice('messages.items', 0));
        self::assertSame('kitu 1', trans_choice('messages.items', 1));
        self::assertSame('vitu 5', trans_choice('messages.items', 5));

        $this->app()->setLocale('en');
        self::assertSame('0 items', trans_choice('messages.items', 0));
        self::assertSame('1 item', trans_choice('messages.items', 1));
        self::assertSame('5 items', trans_choice('messages.items', 5));
    }

    public function test_missing_keys_degrade_to_the_key_without_throwing(): void
    {
        $this->app();

        self::assertSame('messages.absent_key', __('messages.absent_key'));
        self::assertSame('totally.missing', __('totally.missing'));
    }

    public function test_validation_messages_follow_the_active_locale(): void
    {
        $this->app();

        $result = (new Validator(['email' => 'required']))->validate(['email' => '']);

        self::assertTrue($result->fails());
        self::assertSame('Sehemu ya email inahitajika.', $result->errors()['email'][0]);

        $this->app()->setLocale('en');
        $result = (new Validator(['email' => 'required']))->validate(['email' => '']);

        self::assertTrue($result->fails());
        self::assertSame('The email field is required.', $result->errors()['email'][0]);
    }

    public function test_explicit_validator_messages_win_over_translations(): void
    {
        $this->app();

        $result = (new Validator(
            ['email' => 'required'],
            ['email' => 'Tunahitaji barua pepe yako.'],
        ))->validate(['email' => '']);

        self::assertSame('Tunahitaji barua pepe yako.', $result->errors()['email'][0]);
    }

    public function test_validation_outside_an_application_keeps_english_defaults(): void
    {
        Application::free(); // simulate a standalone validator

        $result = (new Validator(['name' => 'required']))->validate(['name' => '']);
        self::assertSame('This field is required.', $result->errors()['name'][0]);
    }

    // ---------------------------------------------------------------
    // SetLocale middleware over real HTTP requests
    // ---------------------------------------------------------------

    private function withLocaleRoutes(): void
    {
        $this->withRoutes(static function ($router): void {
            $router->register(
                Link::get('/locale', static fn (): Response => Response::json(['locale' => app()->getLocale()])),
                Link::get('/greet', static fn (): Response => Response::json(['text' => __('messages.welcome')])),
            );
        });
        $this->withGlobalMiddleware($this->container()->make(SetLocale::class));
    }

    public function test_query_selection_wins_and_is_validated(): void
    {
        $this->withLocaleRoutes();

        // Unsupported values are ignored — never trusted, never persisted.
        self::assertSame('sw', $this->get('/locale?lang=de')->json()['locale']);
        self::assertSame('sw', $this->get('/locale?lang=../../etc')->json()['locale']);

        self::assertSame('en', $this->get('/locale?lang=en')->json()['locale']);
    }

    public function test_explicit_selection_persists_through_the_session(): void
    {
        $this->withLocaleRoutes();

        $first = $this->get('/locale?lang=en');
        self::assertSame('en', $first->json()['locale']);

        // The stored preference applies to the SAME session — carry the
        // cookie the response set, the way a browser would.
        preg_match('/sahil_session=([^;]+)/', (string) $first->header('Set-Cookie'), $m);

        // No new selection — the stored session preference applies.
        self::assertSame('en', $this->get('/locale', cookies: ['sahil_session' => $m[1]])->json()['locale']);
        // A different session (no cookie) correctly falls back to 'sw'.
        self::assertSame('sw', $this->get('/locale')->json()['locale']);
    }

    public function test_accept_language_negotiation(): void
    {
        $this->withLocaleRoutes();

        self::assertSame('en', $this->get('/locale', ['Accept-Language' => 'en-US,en;q=0.9'])->json()['locale']);
        self::assertSame('sw', $this->get('/locale', ['Accept-Language' => 'de-DE,de;q=0.9'])->json()['locale']);
        // Explicit query selection beats the header.
        self::assertSame('en', $this->get('/locale?lang=en', ['Accept-Language' => 'sw;q=1.0'])->json()['locale']);
    }

    public function test_negotiation_can_be_disabled(): void
    {
        $this->app()->config()->set('app.locale_negotiate', false);
        $this->withLocaleRoutes();

        self::assertSame('sw', $this->get('/locale', ['Accept-Language' => 'en-US,en;q=0.9'])->json()['locale']);
    }

    public function test_locale_never_leaks_into_the_next_request(): void
    {
        $this->withLocaleRoutes();

        // Request A negotiates English via Accept-Language — never persisted.
        self::assertSame('en', $this->get('/locale', ['Accept-Language' => 'en-US,en;q=0.9'])->json()['locale']);

        // Request B has no signal at all — must resolve to the default.
        self::assertSame('sw', $this->get('/locale')->json()['locale']);
        self::assertSame('Karibu kwenye programu yetu.', $this->get('/greet')->json()['text']);
    }

    public function test_locale_selection_survives_across_the_same_request(): void
    {
        $this->withLocaleRoutes();

        // The selected locale applies to the whole request — greet reads en.
        $this->get('/locale?lang=en');
        // ...but only within that request (proved above); re-verify via greet param.
        self::assertSame('Welcome to our application.', $this->get('/greet?lang=en')->json()['text']);
    }

    // ---------------------------------------------------------------
    // Worker-style scope isolation without HTTP
    // ---------------------------------------------------------------

    public function test_request_scope_isolates_and_drops_locale_state(): void
    {
        $app = $this->app();

        // Out-of-scope process locale (a CLI command chose English).
        $app->setLocale('en');
        self::assertSame('en', $app->getLocale());

        // Inside a request the process slot must not bleed through...
        $app->isolatedRequest(function () use ($app): void {
            self::assertSame('sw', $app->getLocale());
            $app->setLocale('en');
            self::assertSame('en', $app->getLocale());
            self::assertSame('Not found', __('errors.not_found'));
        });

        // ...and the request's own selection dies with the scope: a fresh
        // request starts from the configured default, not "en".
        $app->isolatedRequest(function () use ($app): void {
            self::assertSame('sw', $app->getLocale());
            self::assertSame('Haijapatikana', __('errors.not_found'));
        });
    }

    // ---------------------------------------------------------------
    // Localized error rendering
    // ---------------------------------------------------------------

    public function test_production_error_page_is_localized(): void
    {
        $this->app()->config()->set('app.debug', false);

        $this->withRoutes(static function ($router): void {
            $router->register(
                Link::get('/missing-page', static fn (): Response => throw new NotFoundHttpException()),
            );
        });
        $this->withGlobalMiddleware($this->container()->make(SetLocale::class));

        $this->get('/missing-page')
            ->assertStatus(404)
            ->assertSee('Ukurasa huu haujapatikana');

        $this->get('/missing-page?lang=en')
            ->assertStatus(404)
            ->assertSee('This page could not be found');
    }

    public function test_error_page_for_unmatched_path_follows_the_selected_locale(): void
    {
        $this->app()->config()->set('app.debug', false);
        $this->withGlobalMiddleware($this->container()->make(SetLocale::class));

        // No route is registered for this path at all: global middleware must
        // still run so the selection applies to the error response too.
        $this->get('/nothing-here?lang=en')
            ->assertStatus(404)
            ->assertSee('This page could not be found');

        $this->get('/nothing-here', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertStatus(404)
            ->assertSee('This page could not be found');
    }
}
