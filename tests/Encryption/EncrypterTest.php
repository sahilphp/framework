<?php

declare(strict_types=1);

namespace Sahil\Tests\Encryption;

use PHPUnit\Framework\TestCase;
use Sahil\Encryption\Encrypter;
use Sahil\Encryption\EncryptionException;
use Sahil\Encryption\KeyGenerator;

/**
 * @covers \Sahil\Encryption\Encrypter
 * @covers \Sahil\Encryption\KeyGenerator
 * @covers \Sahil\Encryption\EncryptionException
 */
final class EncrypterTest extends TestCase
{
    private function encrypter(): Encrypter
    {
        return new Encrypter(KeyGenerator::generate());
    }

    public function test_round_trip_preserves_arbitrary_text(): void
    {
        $encrypter = $this->encrypter();

        foreach (['', 'plain', 'unicode ✅ é', str_repeat('a', 4096), "binary\x00\xff"] as $plaintext) {
            $payload = $encrypter->encryptString($plaintext);

            self::assertNotSame($plaintext, $payload);
            self::assertSame($plaintext, $encrypter->decryptString($payload));
        }
    }

    public function test_each_encryption_uses_a_fresh_iv(): void
    {
        $encrypter = $this->encrypter();

        self::assertNotSame(
            $encrypter->encryptString('same input'),
            $encrypter->encryptString('same input'),
        );
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $encrypter = $this->encrypter();
        $payload = $encrypter->encryptString('transfer 10');
        $raw = base64_decode($payload, true);
        self::assertIsString($raw);

        // Flip one byte of the ciphertext, keeping the payload well-formed.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('wrong key or tampered data');
        $encrypter->decryptString(base64_encode($raw));
    }

    public function test_a_different_key_cannot_decrypt(): void
    {
        $payload = $this->encrypter()->encryptString('secret');

        $this->expectException(EncryptionException::class);
        $this->encrypter()->decryptString($payload);
    }

    public function test_malformed_payloads_are_rejected(): void
    {
        foreach (['not-base64!', base64_encode('short'), ''] as $payload) {
            try {
                $this->encrypter()->decryptString($payload);
                self::fail('Expected a malformed payload to be rejected.');
            } catch (EncryptionException $exception) {
                self::assertSame('The encrypted payload is malformed.', $exception->getMessage());
            }
        }
    }

    public function test_raw_32_byte_keys_are_accepted(): void
    {
        $key = random_bytes(Encrypter::KEY_BYTES);
        $first = new Encrypter($key);
        $second = new Encrypter($key);

        self::assertSame('value', $second->decryptString($first->encryptString('value')));
    }

    public function test_keys_of_the_wrong_shape_are_rejected(): void
    {
        foreach (['', 'too-short', base64_encode('not-32-bytes'), 'base64:not base64!!'] as $key) {
            try {
                new Encrypter($key);
                self::fail('Expected an invalid key to be rejected.');
            } catch (EncryptionException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_missing_configuration_is_reported_clearly(): void
    {
        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('No application key is configured');

        Encrypter::fromConfiguration(null);
    }

    public function test_signatures_are_stable_and_verifiable(): void
    {
        $encrypter = $this->encrypter();

        $signature = $encrypter->sign('user=42');

        self::assertSame($signature, $encrypter->sign('user=42'));
        self::assertTrue($encrypter->verify('user=42', $signature));
        self::assertFalse($encrypter->verify('user=43', $signature));
        self::assertFalse($encrypter->verify('user=42', 'deadbeef'));
        self::assertFalse($this->encrypter()->verify('user=42', $signature));
    }

    public function test_generated_keys_are_base64_prefixed_32_bytes(): void
    {
        $key = KeyGenerator::generate();

        self::assertStringStartsWith(KeyGenerator::PREFIX, $key);
        self::assertSame(Encrypter::KEY_BYTES, strlen((string) base64_decode(substr($key, strlen(KeyGenerator::PREFIX)), true)));
        self::assertNotSame($key, KeyGenerator::generate());
        self::assertTrue(KeyGenerator::isValid($key));
        self::assertFalse(KeyGenerator::isValid('nope'));
    }
}
