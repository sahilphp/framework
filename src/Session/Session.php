<?php

declare(strict_types=1);

namespace Sahil\Session;

use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Session\SessionDriverContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Session manager with security features.
 *
 * Provides a high-level session interface with:
 * - Secure cookie attributes (SameSite, Secure, HttpOnly)
 * - Session ID rotation to prevent fixation attacks
 * - Session expiration and garbage collection
 * - CSRF token generation and validation
 * - Protection against session leakage in long-running workers
 *
 * Security posture:
 * - Session IDs are regenerated on privilege escalation
 * - CSRF tokens are cryptographically random and per-session
 * - Cookie attributes are set to prevent theft
 * - Session data is isolated per request scope
 */
final class Session implements SessionContract
{
    private const CSRF_KEY = '_token';

    /**
     * Bounded ring of previously issued CSRF tokens that remain valid.
     * {@see issueToken()} rotates the current token and pushes the retired
     * one here, so forms rendered moments ago — other tabs, the back
     * button, a second form on the page — keep working instead of being
     * invalidated by the newest token.
     */
    private const CSRF_HISTORY_KEY = '_token_history';

    private const CSRF_HISTORY_LIMIT = 8;

    private const FLASH_KEY = '_flash';

    private const FLASH_NEW_KEY = '_flash_new';

    private const FLASH_AGED_KEY = '_flash_aged';

    private const LAST_ACTIVITY_KEY = '_last_activity';

    private bool $started = false;

    private bool $issuedThisRequest = false;

    private bool $saved = false;

    private ?string $id = null;

    private string $name = 'sahil_session';

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * The request this session was last started for, held weakly.
     *
     * The session object may outlive a single request in long-running
     * workers and in-process test harnesses. Calling
     * {@see startFromRequest()} with a different request re-initializes
     * from that request's cookie instead of leaking the previous
     * request's data; repeated calls with the same request remain a
     * no-op so in-request mutations are never reloaded away.
     */
    /** @var ?\WeakReference<Request> */
    private ?\WeakReference $request = null;

    public function __construct(
        private readonly SessionDriverContract $driver,
        private readonly int $lifetime = 7200, // 2 hours default
        private readonly bool $secure = false,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'lax',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $this->data[$key] = $value;
        $this->saved = false;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();

        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        $this->ensureStarted();

        unset($this->data[$key]);
        $this->saved = false;
    }

    public function all(): array
    {
        $this->ensureStarted();

        return $this->data;
    }

    public function flush(): void
    {
        $this->ensureStarted();

        // Preserve CSRF token and its validity ring
        $token = $this->data[self::CSRF_KEY] ?? null;
        $history = $this->data[self::CSRF_HISTORY_KEY] ?? null;

        $this->data = [];

        if ($token !== null) {
            $this->data[self::CSRF_KEY] = $token;
        }

        if ($history !== null) {
            $this->data[self::CSRF_HISTORY_KEY] = $history;
        }

        $this->saved = false;
    }

    public function id(): string
    {
        $this->ensureStarted();

        return $this->id;
    }

    public function regenerate(bool $destroy = false): void
    {
        $this->ensureStarted();

        $oldId = $this->id;
        $newId = $this->driver->generateId();

        if ($destroy) {
            $this->driver->destroy($oldId);
            $this->data = [];
        }

        $this->id = $newId;
        $this->saved = false; // Force write with new ID
    }

    public function save(): void
    {
        if (!$this->started || $this->saved) {
            return;
        }

        // Update last activity timestamp
        $this->data[self::LAST_ACTIVITY_KEY] = time();

        // Flash aging: entries flashed by the PREVIOUS request and not
        // re-flashed during this one have served their purpose — drop them
        // so flashed data lives exactly one request.
        $this->purgeAgedFlash();

        // Ensure CSRF token exists
        if (!isset($this->data[self::CSRF_KEY])) {
            $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        }

        $this->driver->write($this->id, $this->data);
        $this->saved = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function token(): string
    {
        $this->ensureStarted();

        if (!isset($this->data[self::CSRF_KEY])) {
            $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        }

        return $this->data[self::CSRF_KEY];
    }

    /**
     * The CSRF token issued for this request — minted once, then stable.
     *
     * The first call inside a request rotates the token: the outgoing one
     * is retired into the bounded history ring and stays valid until the
     * ring rotates past it. Every later call in the same request — other
     * `@csrf` fields, `csrf_token()`, the shared view data — returns that
     * same token, so all forms on one page agree and the ring measures
     * page loads (roughly CSRF_HISTORY_LIMIT renders of back-compat),
     * not individual fields.
     */
    public function issueToken(): string
    {
        $this->ensureStarted();

        $current = $this->data[self::CSRF_KEY] ?? null;

        if ($this->issuedThisRequest && is_string($current)) {
            return $current;
        }

        return $this->rotateToken();
    }

    public function validateToken(string $token): bool
    {
        $this->ensureStarted();

        $stored = $this->data[self::CSRF_KEY] ?? null;

        if (is_string($stored) && hash_equals($stored, $token)) {
            return true;
        }

        // Recently issued tokens stay valid while they are in the ring;
        // compare every candidate in constant time.
        /** @var list<string> $history */
        $history = is_array($this->data[self::CSRF_HISTORY_KEY] ?? null)
            ? $this->data[self::CSRF_HISTORY_KEY]
            : [];

        foreach ($history as $previous) {
            if (is_string($previous) && hash_equals($previous, $token)) {
                return true;
            }
        }

        return false;
    }

    public function regenerateToken(): void
    {
        // Unlike issueToken() this ALWAYS rotates — it runs after a
        // successful unsafe-method submission, where forcing a fresh
        // token shortens the replay window while the outgoing token
        // keeps working inside the ring for other tabs/back navigation.
        $this->ensureStarted();
        $this->rotateToken();
    }

    /**
     * Retire the current token into the bounded history ring and mint a
     * fresh one.
     */
    private function rotateToken(): string
    {
        $current = $this->data[self::CSRF_KEY] ?? null;

        /** @var list<string> $history */
        $history = is_array($this->data[self::CSRF_HISTORY_KEY] ?? null)
            ? $this->data[self::CSRF_HISTORY_KEY]
            : [];

        if (is_string($current)) {
            $history[] = $current;
            $history = array_values(array_unique($history));

            if (count($history) > self::CSRF_HISTORY_LIMIT) {
                $history = array_slice($history, -self::CSRF_HISTORY_LIMIT);
            }
        }

        $this->data[self::CSRF_HISTORY_KEY] = $history;
        $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        $this->issuedThisRequest = true;
        $this->saved = false;

        return $this->data[self::CSRF_KEY];
    }

    /* ---------- flash data ---------- */

    /**
     * Store a value that survives exactly one subsequent request.
     * Flashed in request N → readable during request N+1 → gone by N+2,
     * unless re-flashed. Used for validation errors and old input.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();

        /** @var array<string, mixed> $flash */
        $flash = is_array($this->data[self::FLASH_KEY] ?? null)
            ? $this->data[self::FLASH_KEY]
            : [];

        $flash[$key] = $value;
        $this->data[self::FLASH_KEY] = $flash;

        /** @var list<string> $new */
        $new = is_array($this->data[self::FLASH_NEW_KEY] ?? null)
            ? $this->data[self::FLASH_NEW_KEY]
            : [];

        if (!in_array($key, $new, true)) {
            $new[] = $key;
        }

        $this->data[self::FLASH_NEW_KEY] = $new;
        $this->saved = false;
    }

    /**
     * Read a flashed value written by this or the previous request.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        /** @var array<string, mixed> $flash */
        $flash = is_array($this->data[self::FLASH_KEY] ?? null)
            ? $this->data[self::FLASH_KEY]
            : [];

        return $flash[$key] ?? $default;
    }

    /**
     * Read a key and remove it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        $value = $this->data[$key] ?? $default;
        unset($this->data[$key]);
        $this->saved = false;

        return $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Start the session from a request.
     *
     * Reads the session ID from the cookie and loads session data.
     */
    public function startFromRequest(Request $request): void
    {
        if ($this->started && $this->request?->get() === $request) {
            return;
        }

        $sessionId = $request->cookie($this->name);

        if ($sessionId !== null && $this->driver->isValidId($sessionId)) {
            $this->id = $sessionId;
            $this->data = $this->driver->read($sessionId);

            // Check if session has expired
            $lastActivity = $this->data[self::LAST_ACTIVITY_KEY] ?? null;

            if ($lastActivity !== null && (time() - $lastActivity) > $this->lifetime) {
                // Session expired, regenerate
                $this->regenerate(true);
            }
        } else {
            // No valid session, create new
            $this->id = $this->driver->generateId();
            $this->data = [];
        }

        // Everything flashed by the previous request is now "aged": still
        // readable throughout this request, dropped on save unless
        // something flashes it again.
        /** @var list<string> $new */
        $new = is_array($this->data[self::FLASH_NEW_KEY] ?? null)
            ? $this->data[self::FLASH_NEW_KEY]
            : [];

        $this->data[self::FLASH_AGED_KEY] = $new;
        $this->data[self::FLASH_NEW_KEY] = [];

        $this->started = true;
        $this->saved = false;
        $this->issuedThisRequest = false;
        $this->request = \WeakReference::create($request);
    }

    /**
     * Drop flashed entries that have aged past their one-request lifetime:
     * anything in the aged list that this request did not re-flash.
     */
    private function purgeAgedFlash(): void
    {
        /** @var list<string> $aged */
        $aged = is_array($this->data[self::FLASH_AGED_KEY] ?? null)
            ? $this->data[self::FLASH_AGED_KEY]
            : [];

        if ($aged === []) {
            return;
        }

        /** @var list<string> $new */
        $new = is_array($this->data[self::FLASH_NEW_KEY] ?? null)
            ? $this->data[self::FLASH_NEW_KEY]
            : [];

        /** @var array<string, mixed> $flash */
        $flash = is_array($this->data[self::FLASH_KEY] ?? null)
            ? $this->data[self::FLASH_KEY]
            : [];

        foreach ($aged as $key) {
            if (!in_array($key, $new, true)) {
                unset($flash[$key]);
            }
        }

        $this->data[self::FLASH_KEY] = $flash;
        $this->data[self::FLASH_AGED_KEY] = [];
    }

    /**
     * Apply the session cookie to a response.
     */
    public function applyCookieToResponse(Response $response): Response
    {
        if (!$this->started) {
            return $response;
        }

        $cookie = sprintf(
            '%s=%s; Path=/%s; SameSite=%s%s; Max-Age=%d',
            $this->name,
            $this->id,
            $this->httpOnly ? '; HttpOnly' : '',
            $this->sameSite,
            $this->secure ? '; Secure' : '',
            $this->lifetime,
        );

        return $response->withHeader('Set-Cookie', $cookie);
    }

    /**
     * Perform garbage collection on expired sessions.
     *
     * Should be called periodically (e.g., on a random subset of requests).
     */
    public function gc(): void
    {
        $this->driver->gc($this->lifetime);
    }

    /**
     * Generate a cryptographically secure CSRF token.
     */
    private function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Ensure the session is started by generating an ID if needed.
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->id = $this->driver->generateId();
            $this->data = [];
            $this->started = true;
            $this->issuedThisRequest = false;
        }
    }
}
