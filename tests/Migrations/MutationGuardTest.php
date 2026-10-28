<?php

declare(strict_types=1);

namespace Sahil\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Sahil\Migrations\ConfirmationContract;
use Sahil\Migrations\DestructiveOperationException;
use Sahil\Migrations\MutationGuard;
use Sahil\Migrations\RefusingConfirmation;

/**
 * The destructive-operation policy, asserted as behaviour rather than as
 * prose: what an operator must do to get past the gate, per environment.
 */
final class MutationGuardTest extends TestCase
{
    private function confirming(): ConfirmationContract
    {
        return new class () implements ConfirmationContract {
            public int $asked = 0;

            public function confirm(string $question): bool
            {
                ++$this->asked;

                return true;
            }
        };
    }

    private function refusing(): ConfirmationContract
    {
        return new RefusingConfirmation();
    }

    public function test_production_refuses_without_the_force_flag(): void
    {
        $guard = new MutationGuard('production', $this->confirming());

        $this->expectException(DestructiveOperationException::class);
        $this->expectExceptionMessage('--force');

        $guard->authorise('migrate', false, 'Apply migrations?');
    }

    public function test_production_allows_the_force_flag(): void
    {
        $guard = new MutationGuard('production', $this->refusing());

        $guard->authorise('migrate', true, 'Apply migrations?');

        self::assertTrue($guard->isProduction());
    }

    public function test_production_does_not_accept_an_interactive_yes(): void
    {
        // Even a confirming operator must use the recorded flag in production.
        $confirmation = $this->confirming();
        $guard = new MutationGuard('production', $confirmation);

        try {
            $guard->authorise('migrate:rollback', false, 'Roll back?');
            self::fail('production must require --force');
        } catch (DestructiveOperationException) {
            self::assertSame(0, $confirmation->asked, 'production must not even prompt');
        }
    }

    public function test_non_production_accepts_the_force_flag_without_prompting(): void
    {
        $confirmation = $this->confirming();
        $guard = new MutationGuard('development', $confirmation);

        $guard->authorise('db:seed', true, 'Seed?');

        self::assertSame(0, $confirmation->asked);
    }

    public function test_non_production_prompts_and_honours_a_yes(): void
    {
        $confirmation = $this->confirming();
        $guard = new MutationGuard('staging', $confirmation);

        $guard->authorise('db:seed', false, 'Seed?');

        self::assertSame(1, $confirmation->asked);
    }

    public function test_non_production_refuses_when_the_operator_declines(): void
    {
        $guard = new MutationGuard('development', $this->refusing());

        $this->expectException(DestructiveOperationException::class);
        $this->expectExceptionMessage('not confirmed');

        $guard->authorise('db:seed', false, 'Seed?');
    }

    public function test_an_unattended_run_cannot_confirm_by_accident(): void
    {
        // RefusingConfirmation is what a non-terminal STDIN resolves to.
        $guard = new MutationGuard('development', new RefusingConfirmation());

        $this->expectException(DestructiveOperationException::class);

        $guard->authorise('migrate:rollback', false, 'Roll back?');
    }

    public function test_forward_only_migrations_require_the_force_flag(): void
    {
        $guard = new MutationGuard('development', $this->confirming());

        try {
            $guard->authoriseIrreversible('migrate:rollback', ['2026_01_02_000000_forward_only'], false);
            self::fail('forward-only migrations must not be rolled back implicitly');
        } catch (DestructiveOperationException $refusal) {
            self::assertStringContainsString('2026_01_02_000000_forward_only', $refusal->getMessage());
            self::assertStringContainsString('forward-only', $refusal->getMessage());
        }

        // With the force flag the operator has explicitly accepted the skip.
        $guard->authoriseIrreversible('migrate:rollback', ['2026_01_02_000000_forward_only'], true);
    }

    public function test_no_irreversible_migrations_needs_no_authorisation(): void
    {
        (new MutationGuard('production', $this->refusing()))
            ->authoriseIrreversible('migrate:rollback', [], false);

        self::assertTrue(true);
    }
}
