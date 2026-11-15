<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Sahil\Mail\Attachment;
use Sahil\Mail\FakeMailer;
use Sahil\Mail\InvalidMessageException;
use Sahil\Mail\Jobs\SendMailJob;
use Sahil\Mail\Mail;
use Sahil\Mail\Mailable;
use Sahil\Mail\MailManager;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Message;
use Sahil\Mail\Transports\SmtpTransport;
use Symfony\Component\Mime\Email;

final class WelcomeMailStub extends Mailable
{
    public function __construct(private readonly string $name = 'Ada')
    {
    }

    public function build(): void
    {
        $this->subject('Welcome, '.$this->name.'!')
            ->html('<h1>Hello '.$this->name.'</h1>')
            ->text('Hello '.$this->name);
    }
}

final class MailFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mail::flushState();
    }

    private function fake(): FakeMailer
    {
        return Mail::fake();
    }

    public function test_fluent_send_records_the_message(): void
    {
        $this->fake();

        $receipt = Mail::to('customer@example.com', 'Customer')
            ->subject('Welcome')
            ->html('<h1>Welcome!</h1>')
            ->text('Welcome!')
            ->send();

        self::assertFalse($receipt->status->isFailure());

        $sent = Mail::sent();
        self::assertCount(1, $sent);
        $message = $sent[0]->message;
        self::assertSame('Welcome', $message->subjectLine());
        self::assertSame('customer@example.com', $message->toAddresses()[0]->email);
        self::assertSame('Welcome!', $message->textBody());
        self::assertSame('<h1>Welcome!</h1>', $message->htmlBody());
    }

    public function test_send_with_mailable_merges_chained_recipients(): void
    {
        $this->fake();

        Mail::to('ada@example.com')->cc('cc@example.com')->send(new WelcomeMailStub('Ada'));

        $message = Mail::sent()[0]->message;
        self::assertSame('Welcome, Ada!', $message->subjectLine());
        self::assertSame('ada@example.com', $message->toAddresses()[0]->email);
        self::assertSame('cc@example.com', $message->ccAddresses()[0]->email);

        Mail::assertSent(WelcomeMailStub::class);
    }

    public function test_queue_records_without_sending(): void
    {
        $this->fake();

        $receipt = Mail::to('queued@example.com')->queue(new WelcomeMailStub('Grace'), delay: 30);

        self::assertSame(MailStatus::Queued, $receipt->status);
        self::assertSame([], Mail::sent());
        $queued = Mail::queued();
        self::assertCount(1, $queued);
        self::assertSame(30, $queued[0]->delay);
        self::assertSame(WelcomeMailStub::class, $queued[0]->mailable);

        Mail::assertQueued(WelcomeMailStub::class);
    }

    public function test_mailer_selects_a_named_transport(): void
    {
        $this->fake();

        Mail::mailer('smtp')->to('ops@example.com')
            ->subject('Alert')->text('paging')->send();

        self::assertSame('smtp', Mail::sent()[0]->transport);
    }

    public function test_send_accepts_plain_messages_and_mailables(): void
    {
        $this->fake();

        $message = Message::make()
            ->to('a@b.c')->subject('s')->text('t')
            ->from('f@x.y');
        Mail::send($message);
        Mail::to('lin@example.com')->send(new WelcomeMailStub('Lin'));

        self::assertCount(2, Mail::sent());
        Mail::assertSent(WelcomeMailStub::class);
    }

    public function test_assertions_match_predicates_and_counts(): void
    {
        $this->fake();

        Mail::to('one@example.com')->subject('A')->text('t')->send();
        Mail::to('two@example.com')->subject('B')->text('t')->send();

        Mail::assertSent();                              // anything
        Mail::assertSent(null, 2);                       // exact count
        Mail::assertSent(fn ($r) => $r->message->subjectLine() === 'B');
    }

    public function test_assertions_fail_descriptively(): void
    {
        $this->fake();

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        Mail::assertSent(WelcomeMailStub::class);
    }

    public function test_assert_nothing_sent_fails_after_a_send(): void
    {
        $this->fake();
        Mail::to('x@example.com')->subject('s')->text('t')->send();

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        Mail::assertNothingSent();
    }

    public function test_fake_still_validates_the_message(): void
    {
        $this->fake();

        $this->expectException(InvalidMessageException::class);
        Mail::pending()->subject('no recipients')->text('t')->send();
    }

    public function test_fake_state_is_isolated_after_flush(): void
    {
        $fake = $this->fake();
        Mail::to('x@example.com')->subject('s')->text('t')->send();
        self::assertCount(1, $fake->sent());

        $fake->flush();
        self::assertCount(0, Mail::sent());
    }

    /* ---------- attachments ---------- */

    public function test_attachments_are_recorded_with_names_and_bodies(): void
    {
        $this->fake();
        $path = tempnam(sys_get_temp_dir(), 'attach');
        file_put_contents($path, 'PDF-BYTES');

        Mail::to('x@example.com')->subject('s')->text('t')
            ->attach($path, 'report.pdf', 'application/pdf')
            ->attachData('csv-body', 'data.csv', 'text/csv')
            ->send();

        $attachments = Mail::sent()[0]->message->attachments();
        self::assertCount(2, $attachments);
        self::assertSame('report.pdf', $attachments[0]->name);
        self::assertSame('application/pdf', $attachments[0]->mime);
        self::assertSame('PDF-BYTES', $attachments[0]->contents());
        self::assertSame('csv-body', $attachments[1]->contents());
        self::assertFalse($attachments[0]->inline);

        unlink($path);
    }

    public function test_embed_returns_a_stable_content_id(): void
    {
        $message = Message::make();
        $cid = $message->embedData('PNG', 'logo.png', 'image/png');

        self::assertSame('attach-1@sahil.mail', $cid);
        self::assertTrue($message->attachments()[0]->inline);
        self::assertSame($cid, $message->attachments()[0]->contentId);
    }

    public function test_missing_attachment_path_fails_with_a_clear_error(): void
    {
        $attachment = Attachment::fromPath('/nonexistent/file.pdf');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageMatches('/could not be read/');
        $attachment->contents();
    }

    public function test_smtp_transport_maps_attachments_onto_the_mime_email(): void
    {
        $symfony = new RecordingSymfonyTransport();
        $transport = new SmtpTransport('smtp', ['host' => 'unused', 'auth' => []], $symfony);

        $message = Message::make()
            ->from('sender@example.com')->to('rcpt@example.com')
            ->subject('Report')->text('attached')
            ->attachData('PDF', 'r.pdf', 'application/pdf');
        $message->embedData('PNG', 'logo.png', 'image/png');

        $receipt = $transport->send($message);
        self::assertFalse($receipt->status->isFailure());

        /** @var Email $email */
        $email = $symfony->messages[0];
        $parts = $email->getAttachments();
        self::assertCount(2, $parts);
        self::assertSame('r.pdf', $parts[0]->getFilename());
        self::assertSame('application/pdf', $parts[0]->getContentType());
        self::assertSame('attachment', $parts[0]->getDisposition());
        self::assertSame('logo.png', $parts[1]->getFilename());
        self::assertSame('inline', $parts[1]->getDisposition());
        self::assertSame('attach-1@sahil.mail', $parts[1]->getContentId());
    }

    /* ---------- queue serialization ---------- */

    public function test_queued_message_with_attachments_serializes_safely(): void
    {
        $message = Message::make()
            ->from('s@x.y')->to('r@x.y')
            ->subject('s')->text('t')
            ->attach('/tmp/report.pdf')
            ->attachData('body', 'd.csv');
        $message->embedData('img', 'i.png');

        $job = new SendMailJob($message, 'smtp');
        /** @var SendMailJob $restored */
        $restored = unserialize(serialize($job));

        $restoredMessage = $restored->message();
        self::assertSame('s', $restoredMessage->subjectLine());
        self::assertCount(3, $restoredMessage->attachments());
        self::assertSame('/tmp/report.pdf', $restoredMessage->attachments()[0]->path);
        self::assertSame('body', $restoredMessage->attachments()[1]->contents());
        self::assertSame('smtp', $restored->transportName());
    }

    public function test_mail_manager_end_to_end_through_fake_transport(): void
    {
        $transport = new RecordingTransport('primary');
        $manager = new MailManager(
            transports: ['primary' => ['driver' => 'smtp']],
            defaultTransport: 'primary',
            defaultFrom: ['address' => 'no-reply@app.test', 'name' => 'App'],
        );
        $manager->extend('primary', $transport);
        Mail::swap($manager);

        $receipt = Mail::to('user@app.test')->subject('Hi')->text('body')->send();

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertCount(1, $transport->messages);
        self::assertSame('no-reply@app.test', $transport->messages[0]->sender()->email);
    }
}
