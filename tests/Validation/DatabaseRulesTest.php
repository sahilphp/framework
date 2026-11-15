<?php

declare(strict_types=1);

namespace Sahil\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionFactory;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;
use Sahil\Validation\Exists;
use Sahil\Validation\Unique;
use Sahil\Validation\Validator;

/**
 * `unique` / `exists` against a real SQLite database — parameterized
 * lookups, record exclusion, and identifier whitelisting.
 */
final class DatabaseRulesTest extends TestCase
{
    private string $path;

    private Connection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The pdo_sqlite extension is not installed on this machine.');
        }

        $this->path = sys_get_temp_dir().'/sahil-val-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->db = (new ConnectionFactory())->make(['driver' => 'sqlite', 'database' => $this->path]);

        (new Schema($this->db))->create('users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->integer('active')->default(1);
        });

        $this->db->table('users')->insert(['email' => 'taken@example.com', 'active' => 1]);
        $this->db->table('users')->insert(['email' => 'inactive@example.com', 'active' => 0]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_unique_rejects_taken_values(): void
    {
        $validator = new Validator([
            'email' => ['required', 'email', new Unique($this->db, 'users', 'email')],
        ]);

        self::assertTrue($validator->validate(['email' => 'taken@example.com'])->fails());
        self::assertTrue($validator->validate(['email' => 'fresh@example.com'])->passes());
    }

    public function test_unique_ignores_the_record_being_updated(): void
    {
        $rule = (new Unique($this->db, 'users', 'email'))->ignore(1, 'id');
        $validator = new Validator(['email' => [$rule]]);

        self::assertTrue($validator->validate(['email' => 'taken@example.com'])->passes());
        self::assertTrue($validator->validate(['email' => 'inactive@example.com'])->fails());
    }

    public function test_exists_accepts_present_and_rejects_absent_rows(): void
    {
        $validator = new Validator([
            'email' => ['required', 'email', new Exists($this->db, 'users', 'email')],
        ]);

        self::assertTrue($validator->validate(['email' => 'taken@example.com'])->passes());
        self::assertTrue($validator->validate(['email' => 'ghost@example.com'])->fails());
    }

    public function test_exists_supports_additional_constraints(): void
    {
        $rule = (new Exists($this->db, 'users', 'email'))->where('active', 1);
        $validator = new Validator(['email' => [$rule]]);

        self::assertTrue($validator->validate(['email' => 'taken@example.com'])->passes());
        self::assertTrue($validator->validate(['email' => 'inactive@example.com'])->fails());
    }

    public function test_identifiers_are_whitelisted_against_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Exists($this->db, 'users; DROP TABLE users', 'email');
    }
}
