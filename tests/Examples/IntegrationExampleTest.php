<?php

declare(strict_types=1);

namespace Sahil\Tests\Examples;

use Sahil\Contracts\Mail\MailerContract;
use Sahil\Database\Connection;
use Sahil\Mail\Message;
use Sahil\Mail\MailStatus;
use Sahil\Mail\Transports\ArrayTransport;
use Sahil\Testing\ApplicationTestCase;
use Sahil\Testing\TestLogger;

/**
 * Example INTEGRATION test: the real provider graph, real services, no
 * network and no persistent state.
 *
 * Mail uses the `array` transport, logs go to an in-memory replacement, and
 * database writes run inside a transaction that is rolled back afterwards.
 */
final class IntegrationExampleTest extends ApplicationTestCase
{
    protected function configurationOverrides(): array
    {
        $temporary = $this->tempDirectory('sahil-integration-example');

        return [
            'app.debug' => true,
            'mail.default' => 'array',
            'mail.from.address' => 'tests@example.test',
            'mail.from.name' => 'SahilPHP Tests',
            'session.path' => $temporary.'/sessions',
            'view.cache' => $temporary.'/views',
        ];
    }

    public function test_mail_uses_the_configured_array_transport(): void
    {
        /** @var MailerContract $mailer */
        $mailer = $this->container()->make(MailerContract::class);

        $receipt = $mailer->send(
            Message::make()
                ->to('buffy@example.test')
                ->subject('Integration example')
                ->text('Sent through the real provider graph.'),
        );

        self::assertSame(MailStatus::Accepted, $receipt->status);
        self::assertSame('array', $receipt->transport);

        $transport = $mailer->transport('array');
        self::assertInstanceOf(ArrayTransport::class, $transport);
        self::assertCount(1, $transport->messages());
        self::assertSame('Integration example', $transport->last()?->subjectLine());
    }

    public function test_dependencies_can_be_replaced_and_asserted(): void
    {
        /** @var TestLogger $logger */
        $logger = $this->replaceLogger();

        /** @var MailerContract $mailer */
        $mailer = $this->container()->make(MailerContract::class);
        $mailer->send(
            Message::make()->to('buffy@example.test')->subject('Logged')->text('Body'),
        );

        $logger->assertLogged('info', 'mail.accepted');

        // The replacement is observable through the container too.
        self::assertSame($logger, $this->container()->make(\Sahil\Support\Logging\LoggerContract::class));
    }

    public function test_database_writes_can_be_rolled_back(): void
    {
        $this->requireTestDatabase();

        $this->usingDatabaseTransactions();

        /** @var Connection $connection */
        $connection = $this->container()->make(Connection::class);
        $connection->execute(
            'CREATE TEMPORARY TABLE sahil_example_items (id INT PRIMARY KEY, label VARCHAR(50) NOT NULL)'
        );

        $connection->execute('INSERT INTO sahil_example_items (id, label) VALUES (?, ?)', [1, 'first']);

        self::assertCount(1, $connection->execute('SELECT * FROM sahil_example_items')->rows());
        self::assertSame(1, $connection->transactionDepth());

        $connection->rollBack();

        self::assertCount(0, $connection->execute('SELECT * FROM sahil_example_items')->rows());
        self::assertSame(0, $connection->transactionDepth());
    }
}
