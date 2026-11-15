<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\Routing\Link;
use Sahil\Testing\TestResponse;
use Sahil\Tests\FrameworkTestCase;
use Sahil\Validation\ValidationException;

/**
 * End-to-end coverage for native request validation, error bags, old
 * input, automatic CSRF and the named-route link() API — driven through
 * the real kernel, session middleware and template engine of the fixture
 * application.
 */
final class RequestValidationFlowTest extends FrameworkTestCase
{
    /* ---------- routes ---------- */

    private function registerFlowRoutes(): void
    {
        $this->withRoutes(function (Router $router): void {
            $router->register(Link::get('/', static fn (): Response => Response::text('home'))
                ->name('home'));

            $router->register(Link::get('/register', static function (): Response {
                $html = app()->container()->make(ViewContract::class)->render('pages.register');

                return Response::html($html);
            })->name('register.form'));

            $router->register(Link::post('/register', static function (Request $request): Response {
                $data = $request->form()->validate([
                    'name' => 'required|string|min:2|max:80',
                    'email' => 'required|email|max:190',
                    'password' => 'required|string|password|confirmed',
                    'nickname' => 'sometimes|string|max:30',
                ], bag: 'register');

                return Response::json(['validated' => $data], 201);
            })->name('register.submit'));

            $router->register(Link::post('/api/register', static function (Request $request): Response {
                $data = $request->validate([
                    'email' => 'required|email',
                    'age' => 'required|integer|between:18,120',
                ]);

                return Response::json(['validated' => $data]);
            })->name('api.register'));

            $router->register(Link::post('/taken', static function (Request $request): Response {
                $request->form()->validate(['email' => 'required|email']);

                throw ValidationException::withMessages(
                    ['email' => 'That email is already taken.'],
                    bag: 'taken',
                    input: $request->form()->all(),
                );
            })->name('taken.submit'));

            $router->register(Link::post('/custom-redirect', static function (Request $request): Response {
                try {
                    $request->form()->validate(['email' => 'required|email']);
                } catch (ValidationException $e) {
                    throw ValidationException::forResult(
                        $e->result(),
                        input: $e->input(),
                        redirectTo: '/elsewhere',
                    );
                }
            }));
        });
    }

    /* ---------- helpers ---------- */

    /**
     * Persist a session like a browser visit would have produced, and
     * return its cookie jar entry.
     *
     * @return array<string, string>
     */
    private function sessionCookies(): array
    {
        /** @var SessionContract $session */
        $session = $this->container()->make(SessionContract::class);
        $session->token(); // ensure started + token exists
        $session->save();

        return [$session->getName() => $session->id()];
    }

    /**
     * A second browser: a fresh session with its own id, persisted through
     * the same driver. Requests carrying this cookie see only their own
     * session's data.
     *
     * @return array<string, string>
     */
    private function freshBrowserCookies(): array
    {
        /** @var \Sahil\Contracts\Session\SessionDriverContract $driver */
        $driver = $this->container()->make(\Sahil\Contracts\Session\SessionDriverContract::class);
        /** @var SessionContract $primary */
        $primary = $this->container()->make(SessionContract::class);

        $other = new \Sahil\Session\Session($driver);
        $other->setName($primary->getName());
        $other->token();
        $other->save();

        return [$other->getName() => $other->id()];
    }

    private static function sessionCookieOf(TestResponse $response): string
    {
        $header = (string) $response->header('Set-Cookie');
        self::assertStringContainsString('sahil_session=', $header);
        preg_match('/sahil_session=([^;]+)/', $header, $m);

        return $m[1];
    }

    private static function csrfFieldValue(TestResponse $response): string
    {
        self::assertMatchesRegularExpression(
            '/name="_token" value="([a-f0-9]{64})"/',
            $response->body,
        );
        preg_match('/name="_token" value="([a-f0-9]{64})"/', $response->body, $m);

        return $m[1];
    }

    /* ---------- request validation ---------- */

    public function test_form_validate_returns_only_validated_data(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/register', [
            'name' => 'Amina',
            'email' => 'amina@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
            'nickname' => 'Ami',
            'unrelated' => 'dropped',
        ], cookies: $this->sessionCookies());

        $response->assertStatus(201)
            ->assertJson([
                'validated' => [
                    'name' => 'Amina',
                    'email' => 'amina@example.com',
                    'password' => 'Str0ng!Pass',
                    'nickname' => 'Ami',
                ],
            ]);
    }

    public function test_request_validate_reads_json_body(): void
    {
        $this->registerFlowRoutes();

        $response = $this->jsonRequest('POST', '/api/register', [
            'email' => 'juma@example.com',
            'age' => 30,
            'extra' => 'not-returned',
        ], cookies: $this->sessionCookies());

        $response->assertOk()
            ->assertJsonPath('validated.email', 'juma@example.com')
            ->assertJsonPath('validated.age', 30);

        self::assertArrayNotHasKey('extra', $response->json()['validated']);
    }

    public function test_request_validate_merges_query_and_body(): void
    {
        $this->registerFlowRoutes();

        $response = $this->jsonRequest('POST', '/api/register?age=44', [
            'email' => 'query@example.com',
        ], cookies: $this->sessionCookies());

        $response->assertOk()
            ->assertJsonPath('validated.age', 44)
            ->assertJsonPath('validated.email', 'query@example.com');
    }

    /* ---------- HTML failure handling ---------- */

    public function test_browser_validation_failure_redirects_back_with_flash(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/register', [
            'name' => 'Amina',
            'email' => 'not-an-email',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], headers: ['Referer' => 'http://localhost/register'], cookies: $this->sessionCookies());

        $response->assertStatus(303)->assertHeader('Location', '/register');

        // The flashed errors and safe old input render on the next request.
        $page = $this->get('/register', cookies: [
            'sahil_session' => self::sessionCookieOf($response),
        ]);

        $page->assertOk()
            ->assertSee('value="Amina"')   // old input restored
            ->assertSee('register-error') // named-bag error rendered
            ->assertDontSee('Str0ng!Pass'); // password never flashed
    }

    public function test_json_validation_failure_returns_422_structure(): void
    {
        $this->registerFlowRoutes();

        $response = $this->jsonRequest('POST', '/api/register', [
            'email' => 'bad',
            'age' => 12,
        ], headers: ['Accept' => 'application/json'], cookies: $this->sessionCookies());

        $response->assertStatus(422);
        $payload = $response->json();

        self::assertArrayHasKey('message', $payload);
        self::assertArrayHasKey('errors', $payload);
        self::assertArrayHasKey('email', $payload['errors']);
        self::assertArrayHasKey('age', $payload['errors']);
        self::assertNotEmpty($payload['errors']['email'][0]);
    }

    public function test_named_bag_keeps_errors_out_of_default_bag(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/register', [
            'name' => 'Amina',
            'email' => 'bad',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], cookies: $this->sessionCookies());

        $page = $this->get('/register', cookies: [
            'sahil_session' => self::sessionCookieOf($response),
        ]);

        $page->assertSee('register-error')
            ->assertDontSee('default-error');
    }

    public function test_business_validation_exception_uses_same_pipeline(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/taken', ['email' => 'taken@example.com'], cookies: $this->sessionCookies());

        $response->assertStatus(303);

        /** @var SessionContract $session */
        $session = $this->container()->make(SessionContract::class);
        $errors = $session->getFlash('errors', []);

        self::assertSame('That email is already taken.', $errors['taken']['email'][0] ?? null);
    }

    public function test_explicit_redirect_target_is_honored(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/custom-redirect', ['email' => 'bad'], cookies: $this->sessionCookies());

        $response->assertStatus(303)->assertHeader('Location', '/elsewhere');
    }

    public function test_referer_host_is_stripped_from_redirect(): void
    {
        $this->registerFlowRoutes();

        $response = $this->post('/register', [
            'name' => 'Amina', 'email' => 'bad', 'password' => 'x', 'password_confirmation' => 'x',
        ], headers: ['Referer' => 'https://evil.example.com/register?x=1'], cookies: $this->sessionCookies());

        $response->assertHeader('Location', '/register?x=1');
    }

    public function test_flashed_errors_age_out_after_one_request(): void
    {
        $this->registerFlowRoutes();

        $failure = $this->post('/register', [
            'name' => 'Amina', 'email' => 'bad', 'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], cookies: $this->sessionCookies());

        $jar = ['sahil_session' => self::sessionCookieOf($failure)];

        $this->get('/register', cookies: $jar)->assertSee('register-error');
        $this->get('/register', cookies: $jar)->assertDontSee('register-error');
    }

    /* ---------- CSRF ---------- */

    public function test_csrf_directive_renders_hidden_token_field(): void
    {
        $this->registerFlowRoutes();

        $page = $this->get('/register', cookies: $this->sessionCookies());

        $page->assertOk();
        self::assertNotSame('', self::csrfFieldValue($page));
        // All token consumers on one page agree on the same token.
        self::assertStringContainsString('value="'.self::csrfFieldValue($page).'"', $page->body);
    }

    public function test_rendered_form_token_submits_successfully(): void
    {
        $this->registerFlowRoutes();
        $cookies = $this->sessionCookies();

        $token = self::csrfFieldValue($this->get('/register', cookies: $cookies));

        // The body `_token` field is what a browser submits.
        $response = $this->call('POST', '/register', [
            '_token' => $token,
            'name' => 'Amina',
            'email' => 'amina@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], headers: ['X-CSRF-TOKEN' => ''], cookies: $cookies);

        $response->assertStatus(201);
    }

    public function test_post_without_token_is_rejected(): void
    {
        $this->registerFlowRoutes();

        $response = $this->call('POST', '/register', [
            'name' => 'Amina', 'email' => 'a@b.c',
            'password' => 'x', 'password_confirmation' => 'x',
        ], headers: ['X-CSRF-TOKEN' => ''], cookies: $this->sessionCookies());

        $response->assertStatus(400);
    }

    public function test_post_with_garbage_token_is_rejected(): void
    {
        $this->registerFlowRoutes();

        $response = $this->call('POST', '/register', [
            'name' => 'Amina', 'email' => 'a@b.c',
            'password' => 'x', 'password_confirmation' => 'x',
        ], headers: ['X-CSRF-TOKEN' => str_repeat('f', 64)], cookies: $this->sessionCookies());

        $response->assertStatus(400);
    }

    public function test_previous_page_token_survives_refresh(): void
    {
        $this->registerFlowRoutes();
        $cookies = $this->sessionCookies();

        $tokenA = self::csrfFieldValue($this->get('/register', cookies: $cookies));
        $tokenB = self::csrfFieldValue($this->get('/register', cookies: $cookies));

        self::assertNotSame($tokenA, $tokenB); // page refresh mints a new token

        // …but the form rendered BEFORE the refresh still submits.
        $response = $this->call('POST', '/register', [
            '_token' => $tokenA,
            'name' => 'Amina',
            'email' => 'amina@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], headers: ['X-CSRF-TOKEN' => ''], cookies: $cookies);

        $response->assertStatus(201);
    }

    public function test_successful_submission_rotates_token(): void
    {
        $this->registerFlowRoutes();
        $cookies = $this->sessionCookies();

        $token = self::csrfFieldValue($this->get('/register', cookies: $cookies));

        $this->call('POST', '/register', [
            '_token' => $token,
            'name' => 'Amina',
            'email' => 'amina@example.com',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], headers: ['X-CSRF-TOKEN' => ''], cookies: $cookies)->assertStatus(201);

        /** @var SessionContract $session */
        $session = $this->container()->make(SessionContract::class);
        self::assertNotSame($token, $session->token()); // rotated after success
        self::assertTrue($session->validateToken($token)); // but still ring-valid
    }

    /* ---------- link() ---------- */

    public function test_link_generates_named_route_urls_in_php(): void
    {
        $this->registerFlowRoutes();

        self::assertSame('/register', \Sahil\link('register.form'));
        self::assertSame('/api/register', \Sahil\link('api.register'));
    }

    public function test_link_generates_parameterized_urls(): void
    {
        $this->withRoutes(function (Router $router): void {
            $router->register(Link::get('/users/{id}', static fn (Request $r, array $p): Response
                => Response::text('user'))->name('users.show'));

            $router->register(Link::get('/files/{name?}', static fn (Request $r, array $p): Response
                => Response::text('files'))->name('files.show'));
        });

        self::assertSame('/users/42', \Sahil\link('users.show', ['id' => 42]));
        self::assertSame('/users/a%20b', \Sahil\link('users.show', ['id' => 'a b']));
        self::assertSame('/files', \Sahil\link('files.show'));
    }

    public function test_link_unknown_name_raises_error(): void
    {
        $this->registerFlowRoutes();

        $this->expectException(\Throwable::class);
        \Sahil\link('no.such.route');
    }

    /* ---------- isolation ---------- */

    public function test_sequential_requests_do_not_leak_flash_or_input(): void
    {
        $this->registerFlowRoutes();

        // Request 1: validation failure flashes errors+old input.
        $failure = $this->post('/register', [
            'name' => 'Leaked', 'email' => 'bad', 'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ], cookies: $this->sessionCookies());

        // Request 2 (different session): a fresh page has no errors/old.
        $other = $this->get('/register', cookies: $this->freshBrowserCookies());
        $other->assertDontSee('register-error')->assertDontSee('Leaked');

        // Request 3 (the failed session): still sees its own flashed data.
        $jar = ['sahil_session' => self::sessionCookieOf($failure)];
        $this->get('/register', cookies: $jar)
            ->assertSee('register-error')
            ->assertSee('Leaked');
    }
}
