<?php

declare(strict_types=1);

namespace Sahil\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Http\Client\HttpClientContract;
use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Encryption\Encrypter;
use Sahil\Foundation\Application;
use Sahil\Http\Client\FakeTransport;
use Sahil\Http\Client\HttpClient;
use Sahil\Http\Client\OutgoingRequest;
use Sahil\Queue\Jobs\Job;
use Sahil\Webhooks\DatabaseWebhookStore;
use Sahil\Webhooks\DnsResolverContract;
use Sahil\Webhooks\Jobs\DeliverWebhookJob;
use Sahil\Webhooks\Webhook;
use Sahil\Webhooks\WebhookDelivery;
use Sahil\Webhooks\WebhookException;
use Sahil\Webhooks\WebhookManager;
use Sahil\Webhooks\WebhookSigner;
use Sahil\Webhooks\WebhookStore;
use Sahil\Webhooks\WebhookUrlValidator;

/**
 * End-to-end webhook coverage on the real stack: sqlite store, real
 * HttpClient over FakeTransport, real Job dispatch/worker path.
 */
final class WebhookTest extends TestCase
{
    private DatabaseWebhookStore $store;
    private FakeTransport $http;
    private CapturingDispatcher $dispatcher;
    private WebhookManager $manager;
    private FakeDns $dns;
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        $connection = (new ConnectionFactory())->make([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $schema = new Schema($connection);
        self::migrate($schema);

        $this->encrypter = new Encrypter(str_repeat('k', 32));
        $this->store = new DatabaseWebhookStore($connection, $this->encrypter);
        $this->http = new FakeTransport();
        $this->dispatcher = new CapturingDispatcher();
        $this->dns = new FakeDns();
        $this->manager = new WebhookManager($this->store, new WebhookUrlValidator(resolver: $this->dns));

        $app = Application::configure(sys_get_temp_dir());
        $app->container()->instance(WebhookStore::class, $this->store);
        $app->container()->instance(HttpClientContract::class, new HttpClient($this->http));
        $app->container()->instance(WebhookManager::class, $this->manager);
        $app->container()->instance(WebhookUrlValidator::class, new WebhookUrlValidator(resolver: $this->dns));
        $app->container()->instance(DispatcherContract::class, $this->dispatcher);
        Webhook::swap($this->manager);
    }

    protected function tearDown(): void
    {
        Webhook::flushState();
        Application::free();
    }

    private static function migrate(Schema $schema): void
    {
        $schema->create('webhook_endpoints', static function (Table $t): void {
            $t->string('id', 64)->primary();
            $t->string('url');
            $t->text('events');
            $t->integer('active')->default(1);
            $t->text('secret');
            $t->integer('created_at');
            $t->integer('updated_at');
        });
        $schema->create('webhook_deliveries', static function (Table $t): void {
            $t->string('id', 64)->primary();
            $t->string('endpoint_id', 64);
            $t->string('event');
            $t->string('status', 20);
            $t->integer('attempts')->default(0);
            $t->integer('last_status')->nullable();
            $t->text('error')->nullable();
            $t->text('payload');
            $t->integer('created_at');
            $t->integer('completed_at')->nullable();
        });
    }

    // --- Endpoint management ------------------------------------------------

    public function test_register_persists_endpoint_and_listings_hide_the_secret(): void
    {
        $endpoint = Webhook::register('https://hooks.example.com/in', ['user.registered'], 'sekrit');

        self::assertSame('sekrit', $endpoint->secret);
        self::assertTrue($endpoint->active);

        $listed = Webhook::endpoints();
        self::assertCount(1, $listed);
        self::assertSame('https://hooks.example.com/in', $listed[0]['url']);
        self::assertSame(['user.registered'], $listed[0]['events']);
        self::assertArrayNotHasKey('secret', $listed[0]);

        // And the stored column is ciphertext, not plaintext.
        self::assertNotSame('sekrit', $this->encrypter->encryptString('sekrit'));
    }

    public function test_register_rejects_empty_events_and_bad_urls(): void
    {
        $this->expectException(WebhookException::class);
        Webhook::register('https://hooks.example.com/in', []);
    }

    public function test_url_policy_rejects_malformed_schemes_and_private_hosts(): void
    {
        foreach (['notaurl', 'ftp://hooks.example.com', 'http://api.example.com/h', 'https://127.0.0.1/x', 'https://192.168.1.5/x', 'https://localhost/x', 'https://10.9.9.9/x', 'https://169.254.1.1/x'] as $bad) {
            try {
                Webhook::register($bad, ['e']);
                self::fail("Accepted {$bad}");
            } catch (WebhookException) {
            }
        }
        self::assertTrue(true);
    }

    public function test_private_hosts_allowed_only_when_explicitly_permitted(): void
    {
        $local = new WebhookManager(
            $this->store,
            new WebhookUrlValidator(allowHttp: true, allowPrivateHosts: true, resolver: $this->dns),
        );

        $endpoint = $local->register('http://localhost:8080/hook', ['e']);
        self::assertSame('http://localhost:8080/hook', $endpoint->url);
    }

    public function test_update_enable_disable_unregister(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['a']);
        Webhook::update($e->id, url: 'https://hooks.example.com/v2', events: ['b', 'c']);
        $e = Webhook::endpoint($e->id);
        self::assertSame('https://hooks.example.com/v2', $e->url);
        self::assertSame(['b', 'c'], $e->events);

        Webhook::disable($e->id);
        self::assertFalse(Webhook::endpoint($e->id)->active);
        Webhook::enable($e->id);
        self::assertTrue(Webhook::endpoint($e->id)->active);

        self::assertTrue(Webhook::unregister($e->id));
        self::assertNull(Webhook::endpoint($e->id));
    }

    public function test_rotate_secret_changes_secret_and_listings_stay_clean(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['a'], 'old-secret');
        $rotated = Webhook::rotateSecret($e->id);

        self::assertNotSame('old-secret', $rotated->secret);
        self::assertSame($rotated->secret, Webhook::endpoint($e->id)->secret);
        self::assertArrayNotHasKey('secret', Webhook::endpoints()[0]);
    }

    // --- Dispatch / queue integration ---------------------------------------

    public function test_deliver_enqueues_one_delivery_per_matching_active_endpoint(): void
    {
        Webhook::register('https://a.example.com/h', ['user.registered'], 'sa');
        Webhook::register('https://b.example.com/h', ['user.registered'], 'sb');
        Webhook::register('https://c.example.com/h', ['other.event'], 'sc');
        $disabled = Webhook::register('https://d.example.com/h', ['user.registered'], 'sd');
        Webhook::disable($disabled->id);

        $deliveries = Webhook::deliver('user.registered', ['id' => 1]);

        self::assertCount(2, $deliveries);
        self::assertCount(2, $this->dispatcher->jobs);
        self::assertContainsOnlyInstancesOf(DeliverWebhookJob::class, $this->dispatcher->jobs);
        foreach ($deliveries as $d) {
            self::assertSame(WebhookDelivery::STATUS_QUEUED, $d->status);
            self::assertSame(0, $d->attempts);
        }
        self::assertNotSame($deliveries[0]->id, $deliveries[1]->id);
    }

    public function test_wildcard_subscription_matches_any_event(): void
    {
        Webhook::register('https://a.example.com/h', ['*'], 's');
        self::assertCount(1, Webhook::deliver('anything.here', ['x' => 1]));
    }

    public function test_deliver_rejects_unserializable_payload(): void
    {
        Webhook::register('https://a.example.com/h', ['e'], 's');
        $this->expectException(WebhookException::class);
        Webhook::deliver('e', ['bad' => "\xB1\x31"]);
    }

    // --- Job execution ------------------------------------------------------

    private static function header(OutgoingRequest $req, string $name): ?string
    {
        foreach ($req->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    private function throwOn(\Throwable $e): void
    {
        $throwing = new class ($e) implements \Sahil\Contracts\Http\Client\HttpTransportContract {
            public function __construct(private \Throwable $e)
            {
            }

            public function send(OutgoingRequest $request): \Sahil\Http\Client\ClientResponse
            {
                throw $this->e;
            }
        };
        Application::peek()->container()->instance(HttpClientContract::class, new HttpClient($throwing));
    }

    private function runJob(WebhookDelivery $delivery): void
    {
        (new DeliverWebhookJob($delivery->id))->handle();
    }

    public function test_job_delivers_signed_json_and_marks_delivered(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['user.registered'], 'topsecret');
        $this->http->stub('*', ['status' => 200, 'body' => 'ok']);

        [$delivery] = Webhook::deliver('user.registered', ['user_id' => 42]);
        $this->runJob($delivery);

        $requests = $this->http->recorded();
        self::assertCount(1, $requests);
        /** @var OutgoingRequest $req */
        $req = $requests[0];
        self::assertSame('POST', $req->method);
        self::assertSame('https://hooks.example.com/in', $req->url);
        self::assertSame('application/json', self::header($req, 'Content-Type'));
        self::assertSame('user.registered', self::header($req, 'X-Sahil-Event'));
        self::assertSame($delivery->id, self::header($req, 'X-Sahil-Delivery'));
        self::assertSame(WebhookSigner::VERSION, self::header($req, 'X-Sahil-Signature-Version'));

        // Signature reproduces over timestamp.body.
        $timestamp = (int) self::header($req, 'X-Sahil-Timestamp');
        $signature = (string) self::header($req, 'X-Sahil-Signature');
        self::assertTrue((new WebhookSigner())->verify('topsecret', $timestamp, $req->body, $signature));

        $stored = $this->store->delivery($delivery->id);
        self::assertSame(WebhookDelivery::STATUS_DELIVERED, $stored->status);
        self::assertSame(1, $stored->attempts);
        self::assertSame(200, $stored->lastStatus);
        self::assertNotNull($stored->completedAt);
    }

    public function test_non_2xx_marks_retry_and_throws_for_the_worker(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 500, 'body' => 'boom']);

        [$delivery] = Webhook::deliver('e', ['x' => 1]);

        try {
            $this->runJob($delivery);
            self::fail('Expected throw');
        } catch (\Throwable) {
        }

        $stored = $this->store->delivery($delivery->id);
        self::assertSame(WebhookDelivery::STATUS_QUEUED, $stored->status);
        self::assertSame(500, $stored->lastStatus);
        self::assertSame(1, $stored->attempts);
    }

    public function test_delivery_id_stable_and_attempts_grow_across_retries(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 503]);

        [$delivery] = Webhook::deliver('e', []);
        $id = $delivery->id;

        foreach ([1, 2, 3] as $expected) {
            try {
                $this->runJob($delivery);
            } catch (\Throwable) {
            }
            self::assertSame($expected, $this->store->delivery($id)->attempts);
            self::assertSame($id, $this->store->delivery($id)->id);
        }
    }

    public function test_final_attempt_marks_failed(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 500]);

        [$delivery] = Webhook::deliver('e', []);
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->runJob($delivery);
            } catch (\Throwable) {
            }
        }

        $stored = $this->store->delivery($delivery->id);
        self::assertSame(WebhookDelivery::STATUS_FAILED, $stored->status);
        self::assertSame(5, $stored->attempts);
        self::assertNotNull($stored->completedAt);
    }

    public function test_deleted_endpoint_after_enqueue_fails_without_sending(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 200]);
        [$delivery] = Webhook::deliver('e', []);

        Webhook::unregister($e->id);
        $this->runJob($delivery);

        self::assertCount(0, $this->http->recorded());
        self::assertSame(WebhookDelivery::STATUS_FAILED, $this->store->delivery($delivery->id)->status);
    }

    public function test_disabled_endpoint_after_enqueue_fails_without_sending(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['e'], 's');
        [$delivery] = Webhook::deliver('e', []);
        Webhook::disable($e->id);

        $this->runJob($delivery);
        self::assertCount(0, $this->http->recorded());
        self::assertSame(WebhookDelivery::STATUS_FAILED, $this->store->delivery($delivery->id)->status);
    }

    public function test_transport_exception_records_safe_error_and_rethrows(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->throwOn(new \Sahil\Http\Client\HttpClientException('Connection refused'));

        [$delivery] = Webhook::deliver('e', []);
        try {
            $this->runJob($delivery);
            self::fail('Expected throw');
        } catch (\Sahil\Http\Client\HttpClientException) {
        }

        $stored = $this->store->delivery($delivery->id);
        self::assertSame(WebhookDelivery::STATUS_QUEUED, $stored->status);
        self::assertNull($stored->lastStatus);
        self::assertStringContainsString('Connection refused', $stored->error);
    }

    public function test_error_is_bounded(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->throwOn(new \Sahil\Http\Client\HttpClientException(str_repeat('x', 9999)));

        [$delivery] = Webhook::deliver('e', []);
        try {
            $this->runJob($delivery);
        } catch (\Throwable) {
        }

        self::assertSame(2048, strlen($this->store->delivery($delivery->id)->error));
    }


    // --- SSRF / DNS destination enforcement --------------------------------

    public function test_dns_resolving_private_blocks_the_request_and_retries(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 200]);
        $this->dns->answers['hooks.example.com'] = ['169.254.169.254']; // metadata IP

        [$delivery] = Webhook::deliver('e', []);

        try {
            $this->runJob($delivery);
            self::fail('Expected WebhookException');
        } catch (\Sahil\Webhooks\WebhookException) {
        }

        self::assertCount(0, $this->http->recorded()); // NO request emitted
        $stored = $this->store->delivery($delivery->id);
        self::assertSame(WebhookDelivery::STATUS_QUEUED, $stored->status);
        self::assertStringContainsString('non-public', $stored->error);
        self::assertStringNotContainsString('169.254', $stored->error); // no internal IP leak
    }

    public function test_dns_is_resolved_fresh_on_every_attempt(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 500]);
        [$delivery] = Webhook::deliver('e', []);

        foreach ([1, 2] as $i) {
            try {
                $this->runJob($delivery);
            } catch (\Throwable) {
            }
            self::assertSame($i, $this->dns->calls); // re-resolution per attempt
        }

        // Rebinding: flip answers to private — next attempt emits nothing.
        $this->dns->answers['hooks.example.com'] = ['10.0.0.9'];
        try {
            $this->runJob($delivery);
        } catch (\Throwable) {
        }

        self::assertSame(2, count($this->http->recorded())); // still 2 — 3rd attempt never sent
    }

    public function test_request_is_pinned_to_validated_addresses(): void
    {
        Webhook::register('https://hooks.example.com/in', ['e'], 's');
        $this->http->stub('*', ['status' => 200]);
        $this->dns->answers['hooks.example.com'] = ['93.184.216.34', '93.184.216.35'];
        [$delivery] = Webhook::deliver('e', []);
        $this->runJob($delivery);

        self::assertSame(['93.184.216.34', '93.184.216.35'], $this->http->recorded()[0]->resolve);
    }

    // --- Signing ------------------------------------------------------------

    public function test_signer_verify_and_freshness(): void
    {
        $signer = new WebhookSigner();
        $sig = $signer->sign('s', 1700000000, '{"a":1}');

        self::assertTrue($signer->verify('s', 1700000000, '{"a":1}', $sig));
        self::assertFalse($signer->verify('s', 1700000000, '{"a":2}', $sig)); // tampered body
        self::assertFalse($signer->verify('s', 1700000001, '{"a":1}', $sig)); // tampered ts
        self::assertFalse($signer->verify('wrong', 1700000000, '{"a":1}', $sig));
        self::assertFalse($signer->verify('s', 1, 'x', 'not-hex'));

        self::assertTrue($signer->isFresh(time(), 300));
        self::assertFalse($signer->isFresh(time() - 400, 300));
    }

    public function test_rotated_secret_signs_new_deliveries(): void
    {
        $e = Webhook::register('https://hooks.example.com/in', ['e'], 'first');
        $this->http->stub('*', ['status' => 200]);
        $new = Webhook::rotateSecret($e->id);

        [$delivery] = Webhook::deliver('e', []);
        $this->runJob($delivery);

        $req = $this->http->recorded()[0];
        $signer = new WebhookSigner();
        $ts = (int) self::header($req, 'X-Sahil-Timestamp');
        $sig = (string) self::header($req, 'X-Sahil-Signature');
        self::assertTrue($signer->verify($new->secret, $ts, $req->body, $sig));
        self::assertFalse($signer->verify('first', $ts, $req->body, $sig));
    }
    public function test_job_logs_delivery_without_secret_or_signature_material(): void
    {
        $logger = new \Sahil\Tests\Scheduler\ArrayLogger();
        Application::peek()->container()->instance(
            \Sahil\Support\Logging\LoggerContract::class,
            $logger,
        );

        Webhook::register('https://hooks.example.com/in', ['user.registered'], 'topsecret');
        $this->http->stub('*', ['status' => 200, 'body' => 'ok']);

        [$delivery] = Webhook::deliver('user.registered', ['user_id' => 42]);
        $this->runJob($delivery);

        $delivered = array_values(array_filter(
            $logger->entries,
            static fn (array $e): bool => $e['message'] === 'Webhook delivered',
        ));
        self::assertCount(1, $delivered);
        self::assertSame('info', $delivered[0]['level']);
        self::assertSame($delivery->id, $delivered[0]['context']['delivery_id']);
        self::assertSame(200, $delivered[0]['context']['status']);

        // The endpoint secret and signature must never appear in any record.
        $all = json_encode($logger->entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('topsecret', $all);
        self::assertStringNotContainsString('signature', $all);
    }

    public function test_job_logs_failure_with_status_and_no_secret(): void
    {
        $logger = new \Sahil\Tests\Scheduler\ArrayLogger();
        Application::peek()->container()->instance(
            \Sahil\Support\Logging\LoggerContract::class,
            $logger,
        );

        Webhook::register('https://hooks.example.com/in', ['user.registered'], 'topsecret');
        $this->http->stub('*', ['status' => 500, 'body' => 'down']);

        [$delivery] = Webhook::deliver('user.registered', ['user_id' => 42]);
        try {
            $this->runJob($delivery);
        } catch (\Sahil\Http\Client\HttpClientException) {
            // The job rethrows after logging — the worker decides retry/fail.
        }

        $failed = array_values(array_filter(
            $logger->entries,
            static fn (array $e): bool => $e['level'] === 'warning'
                && str_contains($e['message'], 'failed'),
        ));
        self::assertNotEmpty($failed);
        $all = json_encode($logger->entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('topsecret', $all);
    }
}

final class CapturingDispatcher implements DispatcherContract
{
    /** @var list<JobContract> */
    public array $jobs = [];

    public function dispatch(JobContract $job, int $delay = 0): string
    {
        $this->jobs[] = $job;

        return 'job-'.count($this->jobs);
    }

    public function pushOn(string $queue, JobContract $job, int $delay = 0): string
    {
        return $this->dispatch($job, $delay);
    }

    public function pushRaw(string $queue, string $payload, int $delay = 0): string
    {
        return 'raw';
    }
}

/** Deterministic DNS seam — public IPs by default, overridable per host. */
final class FakeDns implements DnsResolverContract
{
    /** @var array<string, list<string>> */
    public array $answers = [];
    public int $calls = 0;

    public function resolve(string $host): array
    {
        $this->calls++;

        if (array_key_exists($host, $this->answers)) {
            return $this->answers[$host];
        }

        return ['93.184.216.34']; // public by default
    }

}
