<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Mail\MailerContract;
use Sahil\Foundation\Application;
use Sahil\Foundation\ServiceProvider;
use Sahil\Mail\Jobs\SendMailJob;
use Sahil\Mail\MailManager;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\TransportException;
use Sahil\Mail\Transports\ArrayTransport;

final class SendMailJobTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/sahil_mail_job_'.bin2hex(random_bytes(6));

        if (!mkdir($this->basePath, 0o700, true) && !is_dir($this->basePath)) {
            self::fail('Unable to create a temporary application root.');
        }

        Application::free();
    }

    protected function tearDown(): void
    {
        Application::free();
        @rmdir($this->basePath);
    }

    private function application(MailManager $mailer): Application
    {
        $application = Application::configure($this->basePath);

        $application->register(new class ($application, $mailer) extends ServiceProvider {
            public function __construct(ApplicationContract $app, private readonly MailManager $mailer)
            {
                parent::__construct($app);
            }

            public function register(): void
            {
                $this->app()->container()->instance(MailerContract::class, $this->mailer);
            }
        });

        $application->boot();

        return $application;
    }

    private function mailer(): MailManager
    {
        return new MailManager(
            ['array' => ['driver' => 'array'], 'log' => ['driver' => 'log']],
            'array',
            ['address' => 'no-reply@example.com', 'name' => 'SahilPHP'],
        );
    }

    private function message(): Message
    {
        return Message::make()
            ->to('ada@example.com', 'Ada')
            ->subject('Your account is ready')
            ->text('Welcome aboard.');
    }

    public function test_a_job_sends_the_message_through_the_mailer(): void
    {
        $mailer = $this->mailer();
        $this->application($mailer);

        $receipt = (new SendMailJob($this->message(), 'array'))->handle();

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertSame('array', $receipt->transport);

        $transport = $mailer->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->messages());
        self::assertSame('Your account is ready', $transport->messages()[0]->subjectLine());
        self::assertSame('no-reply@example.com', $transport->messages()[0]->sender()?->email);
    }

    public function test_a_serialized_job_still_carries_the_message(): void
    {
        $mailer = $this->mailer();
        $this->application($mailer);

        $job = new SendMailJob($this->message(), 'array');
        $restored = unserialize(serialize($job));

        self::assertInstanceOf(SendMailJob::class, $restored);

        $restored->handle();

        $transport = $mailer->transport('array');

        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->messages());
        self::assertSame('Your account is ready', $transport->messages()[0]->subjectLine());
    }

    public function test_a_transport_failure_propagates_so_the_worker_can_retry(): void
    {
        $mailer = $this->mailer();
        $mailer->extend('recording', new RecordingTransport('recording', new TransportException('connection refused')));
        $this->application($mailer);

        $this->expectException(TransportException::class);

        (new SendMailJob($this->message(), 'recording'))->handle();
    }
}
