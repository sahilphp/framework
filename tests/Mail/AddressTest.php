<?php

declare(strict_types=1);

namespace Sahil\Tests\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sahil\Mail\Address;
use Sahil\Mail\InvalidMessageException;

final class AddressTest extends TestCase
{
    public function test_builds_an_address_with_a_display_name(): void
    {
        $address = Address::from('ada@example.com', 'Ada Lovelace');

        self::assertSame('ada@example.com', $address->email);
        self::assertSame('Ada Lovelace', $address->name);
        self::assertSame('"Ada Lovelace" <ada@example.com>', $address->display());
        self::assertSame('example.com', $address->domain());
    }

    public function test_a_bare_address_has_no_display_name(): void
    {
        $address = Address::from('ada@example.com');

        self::assertSame('', $address->name);
        self::assertSame('ada@example.com', $address->display());
        self::assertSame('ada@example.com', (string) $address);
    }

    public function test_parses_the_name_address_form(): void
    {
        $address = Address::parse('Ada Lovelace <ada@example.com>');

        self::assertSame('ada@example.com', $address->email);
        self::assertSame('Ada Lovelace', $address->name);
    }

    public function test_parses_a_quoted_display_name(): void
    {
        $address = Address::parse('"Ada, Countess" <ada@example.com>');

        self::assertSame('Ada, Countess', $address->name);
    }

    public function test_parses_a_bare_address(): void
    {
        $address = Address::parse('ada@example.com');

        self::assertSame('ada@example.com', $address->email);
        self::assertSame('', $address->name);
    }

    public function test_display_name_quotes_are_escaped(): void
    {
        $address = Address::from('ada@example.com', 'Ada "The Countess"');

        self::assertSame('"Ada \\"The Countess\\"" <ada@example.com>', $address->display());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'no at sign' => ['ada.example.com'];
        yield 'missing domain' => ['ada@'];
        yield 'space' => ['ada lovelace@example.com'];
        yield 'multiple addresses' => ['ada@example.com, evil@example.com'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidEmails')]
    public function test_rejects_invalid_addresses(string $email): void
    {
        $this->expectException(InvalidMessageException::class);

        Address::from($email);
    }

    public function test_rejects_line_breaks_in_an_address(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('control characters');

        Address::from("ada@example.com\r\nBcc: evil@example.com");
    }

    public function test_rejects_line_breaks_in_a_display_name(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('display name');

        Address::from('ada@example.com', "Ada\r\nBcc: evil@example.com");
    }

    public function test_rejects_an_overlong_address(): void
    {
        $local = str_repeat('a', Address::MAX_EMAIL_LENGTH);

        $this->expectException(InvalidMessageException::class);

        Address::from($local.'@example.com');
    }

    public function test_rejects_an_overlong_display_name(): void
    {
        $this->expectException(InvalidMessageException::class);

        Address::from('ada@example.com', str_repeat('a', Address::MAX_NAME_LENGTH + 1));
    }
}
