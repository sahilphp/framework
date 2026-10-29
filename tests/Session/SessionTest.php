<?php

declare(strict_types=1);

namespace Sahil\Tests\Session;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionDriverContract;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Session\Session;

final class SessionTest extends TestCase
{
    private string $tempPath;

    private SessionDriverContract $driver;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir().'/sahil_session_test_'.bin2hex(random_bytes(8));

        if (!mkdir($this->tempPath, 0o700, true) && !is_dir($this->tempPath)) {
            throw new \RuntimeException("Failed to create temp directory: {$this->tempPath}");
        }

        $this->driver = new \Sahil\Session\FileSessionDriver($this->tempPath);
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->tempPath);
    }

    public function test_session_gets_and_sets_values(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');

        $this->assertSame('value', $session->get('key'));
    }

    public function test_session_returns_default_for_missing_key(): void
    {
        $session = new Session($this->driver);

        $this->assertSame('default', $session->get('missing', 'default'));
        $this->assertNull($session->get('missing'));
    }

    public function test_session_checks_key_existence(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');

        $this->assertTrue($session->has('key'));
        $this->assertFalse($session->has('missing'));
    }

    public function test_session_forgets_key(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');
        $session->forget('key');

        $this->assertFalse($session->has('key'));
        $this->assertNull($session->get('key'));
    }

    public function test_session_returns_all_data(): void
    {
        $session = new Session($this->driver);

        $session->put('key1', 'value1');
        $session->put('key2', 'value2');

        $all = $session->all();

        $this->assertCount(2, $all);
        $this->assertSame('value1', $all['key1']);
        $this->assertSame('value2', $all['key2']);
    }

    public function test_session_flush_clears_data_but_preserves_csrf_token(): void
    {
        $session = new Session($this->driver);

        $session->put('key1', 'value1');
        $session->put('key2', 'value2');
        $token = $session->token();

        $session->flush();

        $this->assertFalse($session->has('key1'));
        $this->assertFalse($session->has('key2'));
        $this->assertSame($token, $session->token());
    }

    public function test_session_regenerates_id(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');
        $oldId = $session->id();

        $session->regenerate();

        $newId = $session->id();

        $this->assertNotSame($oldId, $newId);
        $this->assertSame('value', $session->get('key'));
    }

    public function test_session_regenerate_destroys_old_data(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');
        $oldId = $session->id();

        $session->regenerate(true);

        $newId = $session->id();

        $this->assertNotSame($oldId, $newId);
        $this->assertNull($session->get('key'));
    }

    public function test_session_generates_csrf_token(): void
    {
        $session = new Session($this->driver);

        $token = $session->token();

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token)); // 32 bytes * 2 (hex)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_session_validates_csrf_token(): void
    {
        $session = new Session($this->driver);

        $token = $session->token();

        $this->assertTrue($session->validateToken($token));
        $this->assertFalse($session->validateToken('invalid'));
    }

    public function test_session_regenerates_csrf_token(): void
    {
        $session = new Session($this->driver);

        $oldToken = $session->token();
        $session->regenerateToken();
        $newToken = $session->token();

        $this->assertNotSame($oldToken, $newToken);
    }

    public function test_session_sets_and_gets_name(): void
    {
        $session = new Session($this->driver);

        $session->setName('custom_session');

        $this->assertSame('custom_session', $session->getName());
    }

    public function test_session_starts_from_request(): void
    {
        $session = new Session($this->driver);
        $session->setName('test_session');

        // Create a session first
        $session->put('key', 'value');
        $session->save();
        $id = $session->id();

        // Start a new session instance from a request with the cookie
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            cookies: ['test_session' => $id],
        );

        $newSession = new Session($this->driver);
        $newSession->setName('test_session');
        $newSession->startFromRequest($request);

        $this->assertSame($id, $newSession->id());
        $this->assertSame('value', $newSession->get('key'));
    }

    public function test_session_creates_new_id_when_cookie_invalid(): void
    {
        $session = new Session($this->driver);
        $session->setName('test_session');

        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            cookies: ['test_session' => 'invalid-id'],
        );

        $session->startFromRequest($request);

        $this->assertNotSame('invalid-id', $session->id());
        $this->assertTrue($this->driver->isValidId($session->id()));
    }

    public function test_session_handles_expired_session(): void
    {
        $session = new Session($this->driver, lifetime: 60); // 1 minute
        $session->setName('test_session');

        // Create and save a session
        $session->put('key', 'value');
        $session->save();
        $id = $session->id();

        // Manually expire the session by modifying the last activity
        $data = $this->driver->read($id);
        $data['_last_activity'] = time() - 120; // 2 minutes ago
        $this->driver->write($id, $data);

        // Start from request with expired session
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            cookies: ['test_session' => $id],
        );

        $newSession = new Session($this->driver, lifetime: 60);
        $newSession->setName('test_session');
        $newSession->startFromRequest($request);

        // Should have regenerated with new ID and empty data
        $this->assertNotSame($id, $newSession->id());
        $this->assertNull($newSession->get('key'));
    }

    public function test_session_applies_cookie_to_response(): void
    {
        $session = new Session($this->driver);
        $session->setName('test_session');
        $session->startFromRequest(Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
        ));

        $response = Response::text('test');
        $response = $session->applyCookieToResponse($response);

        $cookie = $response->header('Set-Cookie');

        $this->assertIsString($cookie);
        $this->assertStringContainsString('test_session=', $cookie);
        $this->assertStringContainsString('Path=/', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=lax', $cookie);
    }

    public function test_session_applies_secure_cookie_when_configured(): void
    {
        $session = new Session($this->driver, secure: true);
        $session->setName('test_session');
        $session->startFromRequest(Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
        ));

        $response = Response::text('test');
        $response = $session->applyCookieToResponse($response);

        $cookie = $response->header('Set-Cookie');

        $this->assertStringContainsString('Secure', $cookie);
    }

    public function test_session_performs_garbage_collection(): void
    {
        $session = new Session($this->driver, lifetime: 60);

        // Create an old session file directly
        $oldId = $this->driver->generateId();
        $oldFile = $this->tempPath.'/'.$oldId;
        file_put_contents($oldFile, json_encode(['old' => 'data', '_last_activity' => time() - 120]));
        touch($oldFile, time() - 120);

        // Create a new session file directly
        $newId = $this->driver->generateId();
        $newFile = $this->tempPath.'/'.$newId;
        file_put_contents($newFile, json_encode(['new' => 'data', '_last_activity' => time()]));

        // Run garbage collection
        $session->gc();

        // Old session should be gone
        $this->assertSame([], $this->driver->read($oldId));

        // New session should still exist
        $this->assertArrayHasKey('new', $this->driver->read($newId));
    }

    public function test_session_does_not_start_automatically(): void
    {
        $session = new Session($this->driver);

        $this->assertFalse($session->isStarted());
    }

    public function test_session_starts_on_first_access(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');

        $this->assertTrue($session->isStarted());
    }

    public function test_session_saves_data(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');
        $id = $session->id();

        $session->save();

        // Create new session instance and verify data persisted
        $newSession = new Session($this->driver);
        $newSession->startFromRequest(Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            cookies: ['sahil_session' => $id],
        ));

        $this->assertSame('value', $newSession->get('key'));
    }

    public function test_session_updates_last_activity_on_save(): void
    {
        $session = new Session($this->driver);

        $session->put('key', 'value');
        $id = $session->id();

        $session->save();

        $data = $this->driver->read($id);

        $this->assertArrayHasKey('_last_activity', $data);
        $this->assertIsInt($data['_last_activity']);
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = glob($path.'/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($path);
    }
}
