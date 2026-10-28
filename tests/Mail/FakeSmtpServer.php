<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use RuntimeException;

/**
 * A tiny, real SMTP server in a child process.
 *
 * It binds an ephemeral port on 127.0.0.1, speaks just enough ESMTP for the
 * transport (EHLO, AUTH PLAIN, MAIL, RCPT, DATA, QUIT) and records the whole
 * dialogue. That lets the tests exercise a genuine socket conversation —
 * including authentication and rejection paths — without an external relay.
 *
 * Modes:
 * - `accept`       accept the message and answer `250 … queued as TESTID123`
 * - `reject_rcpt`  refuse the recipient with `550`
 * - `reject_auth`  refuse the credentials with `535`
 */
final class FakeSmtpServer
{
    private const SCRIPT = <<<'PHP'
        <?php
        [$_, $portFile, $transcriptFile, $mode] = $argv;
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            file_put_contents($portFile.'.error', $errstr ?? 'bind failed');
            exit(1);
        }
        file_put_contents($portFile, stream_socket_get_name($server, false));
        $client = @stream_socket_accept($server, 20);
        if ($client === false) {
            exit(1);
        }
        stream_set_timeout($client, 20);
        $log = static function (string $line) use ($transcriptFile): void {
            file_put_contents($transcriptFile, $line, FILE_APPEND);
        };
        $send = static function (string $line) use ($client, $log): void {
            $log('S: '.$line);
            fwrite($client, $line);
        };
        $send("220 sahilphp.test ESMTP\r\n");
        $inData = false;
        while (($line = fgets($client)) !== false) {
            $log('C: '.$line);
            $trimmed = rtrim($line, "\r\n");
            if ($inData) {
                if ($trimmed === '.') {
                    $inData = false;
                    $send("250 2.0.0 Ok: queued as TESTID123\r\n");
                    break;
                }
                continue;
            }
            $upper = strtoupper($trimmed);
            if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
                $send("250-sahilphp.test\r\n250-AUTH PLAIN\r\n250 SIZE 10485760\r\n");
            } elseif (str_starts_with($upper, 'AUTH PLAIN')) {
                if ($mode === 'reject_auth') {
                    $send("535 5.7.8 Authentication credentials invalid\r\n");
                } else {
                    $send("235 2.7.0 Authentication successful\r\n");
                }
            } elseif (str_starts_with($upper, 'MAIL FROM')) {
                $send("250 2.1.0 Ok\r\n");
            } elseif (str_starts_with($upper, 'RCPT TO')) {
                $send($mode === 'reject_rcpt' ? "550 5.1.1 No such user\r\n" : "250 2.1.5 Ok\r\n");
            } elseif ($upper === 'DATA') {
                $inData = true;
                $send("354 End data with <CR><LF>.<CR><LF>\r\n");
            } elseif ($upper === 'QUIT') {
                $send("221 2.0.0 Bye\r\n");
                break;
            } elseif ($upper === 'RSET' || $upper === 'NOOP') {
                $send("250 2.0.0 Ok\r\n");
            } else {
                $send("500 5.5.2 Command not recognized\r\n");
            }
        }
        fclose($client);
        fclose($server);
        PHP;

    /**
     * @var resource|null
     */
    private $process = null;

    private string $capturedTranscript = '';

    private function __construct(
        private readonly string $directory,
        private readonly string $transcriptFile,
        private readonly string $host,
        private readonly int $port,
    ) {
    }

    public static function start(string $mode = 'accept'): self
    {
        $directory = sys_get_temp_dir().'/sahil_smtp_'.bin2hex(random_bytes(6));

        if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create [%s].', $directory));
        }

        $script = $directory.'/server.php';
        $portFile = $directory.'/port';
        $transcriptFile = $directory.'/transcript.log';

        file_put_contents($script, self::SCRIPT);

        $process = proc_open(
            [PHP_BINARY, $script, $portFile, $transcriptFile, $mode],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory.'/stdout.log', 'a'],
                2 => ['file', $directory.'/stderr.log', 'a'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the fake SMTP server.');
        }

        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (is_file($portFile) && filesize($portFile) > 0) {
                break;
            }

            if (is_file($portFile.'.error')) {
                throw new RuntimeException(trim((string) file_get_contents($portFile.'.error')));
            }

            usleep(20_000);
        }

        $address = is_file($portFile) ? trim((string) file_get_contents($portFile)) : '';

        if ($address === '') {
            proc_terminate($process);
            throw new RuntimeException('The fake SMTP server did not report a port.');
        }

        [$host, $port] = explode(':', $address, 2);

        return new self($directory, $transcriptFile, $host, (int) $port);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Transport configuration pointing at this server, without TLS.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function configuration(array $overrides = []): array
    {
        return array_replace_recursive([
            'driver' => 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'security' => 'none',
            'timeout' => 5,
            'auth' => [
                'username' => '',
                'password' => '',
            ],
        ], $overrides);
    }

    public function transcript(): string
    {
        if ($this->capturedTranscript !== '') {
            return $this->capturedTranscript;
        }

        return is_file($this->transcriptFile) ? (string) file_get_contents($this->transcriptFile) : '';
    }

    public function stop(): void
    {
        $this->capturedTranscript = $this->transcript();

        if (is_resource($this->process)) {
            proc_terminate($this->process);

            for ($attempt = 0; $attempt < 50; $attempt++) {
                $status = proc_get_status($this->process);

                if (!$status['running']) {
                    break;
                }

                usleep(20_000);
            }

            proc_close($this->process);
            $this->process = null;
        }

        self::removeDirectory($this->directory);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory.'/'.$entry;

                is_dir($path) ? self::removeDirectory($path) : @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
