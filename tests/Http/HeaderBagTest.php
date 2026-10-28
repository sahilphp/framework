<?php

declare(strict_types=1);

namespace Sahil\Tests\Http;

use PHPUnit\Framework\TestCase;
use Sahil\Http\HeaderBag;

final class HeaderBagTest extends TestCase
{
    public function test_assigns_and_retrieves_case_insensitively(): void
    {
        $bag = new HeaderBag();

        $bag->set('Content-Type', 'application/json');

        self::assertTrue($bag->has('content-type'));
        self::assertTrue($bag->has('CONTENT-TYPE'));
        self::assertSame('application/json', $bag->first('Content-Type'));
        self::assertSame('application/json', $bag->line('content-type'));
    }

    public function test_add_appends_without_replacing(): void
    {
        $bag = new HeaderBag();
        $bag->set('Accept', 'text/html');
        $bag->add('Accept', 'application/json');

        self::assertSame(['text/html', 'application/json'], $bag->get('accept'));
        self::assertSame('text/html, application/json', $bag->line('Accept'));
    }

    public function test_scalar_lists_and_null_values_normalise(): void
    {
        $bag = new HeaderBag([
            'X-Correlation-Id' => 'abc',
            'X-Numbers' => [1, 2],
            'X-Bool' => true,
            'X-Null' => null,
        ]);

        self::assertSame(['abc'], $bag->get('X-Correlation-Id'));
        self::assertSame(['1', '2'], $bag->get('X-Numbers'));
        self::assertSame(['true'], $bag->get('X-Bool'));
        self::assertSame([], $bag->get('X-Null'));
        self::assertFalse($bag->has('X-Null'));
        self::assertCount(3, $bag);
    }

    public function test_canonical_form_of_header_names(): void
    {
        self::assertSame('Content-Type', HeaderBag::canonical('content-type'));
        self::assertSame('X-Correlation-Id', HeaderBag::canonical('x-correlation-ID'));
        self::assertSame('Allow', HeaderBag::canonical('ALLOW'));
    }

    public function test_rejects_illegal_header_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HeaderBag(['content type' => 'x']);
    }

    public function test_rejects_cr_lf_in_header_names_and_values(): void
    {
        $bag = new HeaderBag();

        self::expectException(\InvalidArgumentException::class);

        $bag->set('X-Unsafe', "value\r\nSet-Cookie: injected=1");
    }

    public function test_rejects_lf_only_and_nul_values(): void
    {
        $bag = new HeaderBag();

        try {
            $bag->set('X-Unsafe', "line\nbreak");
            self::fail('LF must be rejected');
        } catch (\InvalidArgumentException) {
        }

        try {
            $bag->set('X-Unsafe-2', "nul\0byte");
            self::fail('NUL must be rejected');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse($bag->has('X-Unsafe'));
    }

    public function test_all_returns_sorted_canonical_map(): void
    {
        $bag = new HeaderBag(['content-type' => 'x', 'Accept' => 'y']);

        $all = $bag->all();

        self::assertSame(['Accept' => ['y'], 'Content-Type' => ['x']], $all);
    }

    public function test_remove(): void
    {
        $bag = new HeaderBag(['X-Tmp' => 'x']);
        $bag->remove('x-tmp');

        self::assertFalse($bag->has('X-Tmp'));
    }

    public function test_is_iterable_with_canonical_keys(): void
    {
        $bag = new HeaderBag(['x-one' => '1']);

        foreach ($bag as $name => $values) {
            self::assertSame('X-One', $name);
            self::assertSame(['1'], $values);
        }
    }
}
