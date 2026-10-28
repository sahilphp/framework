<?php

declare(strict_types=1);

namespace Sahil\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Sahil\Http\Request;
use Sahil\Validation\FormInput;
use Sahil\Validation\Presets;
use Sahil\Validation\RuleContract;
use Sahil\Validation\ValidationException;
use Sahil\Validation\ValidationResult;
use Sahil\Validation\Validator;

/**
 * Phase-6 exhaustive validator tests: valid, invalid, missing, null,
 * nested, wildcards, malicious inputs, casts, custom rules, presets,
 * request payloads and raw-vs-validated semantics.
 */
final class ValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Presets::reset();
    }

    private static function validator(array $rules, array $messages = []): Validator
    {
        return new Validator($rules, $messages);
    }

    /* ---------- happy paths ---------- */

    public function test_valid_input_validates_into_data_with_documented_casts(): void
    {
        $validator = self::validator([
            'email' => 'required|email',
            'age' => 'nullable|integer|min:0|max:130',
            'accept' => 'required|boolean',
            'price' => 'numeric|min:0',
            'role' => 'in:admin,editor',
            'code' => 'regex:/^[A-Z]{3}$/',
            'site' => 'url',
            'tags' => 'array',
        ]);

        $result = $validator->validate([
            'email' => 'a@b.co',
            'age' => '42',
            'accept' => 'true',
            'price' => '12.5',
            'role' => 'editor',
            'code' => 'ABC',
            'site' => 'https://example.test/sub?q=1',
            'tags' => ['alpha', 'beta'],
        ]);

        self::assertTrue($result->passes());

        self::assertSame([
            'email' => 'a@b.co',
            'age' => 42,
            'accept' => true,
            'price' => 12.5,
            'role' => 'editor',
            'code' => 'ABC',
            'site' => 'https://example.test/sub?q=1',
            'tags' => ['alpha', 'beta'],
        ], $result->data());
    }

    public function test_missing_and_nullable_fields_validate_to_nothing(): void
    {
        $validator = self::validator([
            'optional' => 'nullable|integer',
            'unknown' => 'integer',
        ]);

        $result = $validator->validate([]);

        self::assertTrue($result->passes());
        self::assertSame([], $result->data(), 'absent fields must not appear in validated data');
    }

    public function test_unique_null_passes_only_with_nullable(): void
    {
        $validator = self::validator(['note' => 'nullable|string']);
        self::assertTrue($validator->validate(['note' => null])->passes());

        $strict = self::validator(['note' => 'string']);
        $strictResult = $strict->validate(['note' => null]);
        self::assertTrue($strictResult->passes(), 'no required → null fields pass silently, validate to nothing');
        self::assertSame([], $strictResult->data());
    }

    /* ---------- failure paths ---------- */

    public function test_required_fails_missing_null_and_empty_string(): void
    {
        $validator = self::validator(['name' => 'required|string']);

        foreach ([[], ['name' => null], ['name' => '']] as $input) {
            $result = $validator->validate($input);
            self::assertTrue($result->fails());
            self::assertSame(['This field is required.'], $result->errors()['name']);
        }
    }

    public function test_all_collected_errors_map_by_dotted_path(): void
    {
        $validator = self::validator([
            'email' => 'required|email',
            'age' => 'integer|min:0',
            'user.name' => 'required|string',
        ]);

        $errors = $validator->validate([
            'email' => 'bad',
            'age' => -5,
            'user.name' => null,
        ])->errors();

        self::assertSame(['This field must be a valid email address.'], $errors['email']);
        self::assertSame(['This field must be greater than or equal to 0.'], $errors['age']);
        self::assertSame(['This field is required.'], $errors['user.name']);
    }

    public function test_first_failure_stops_the_field_chain(): void
    {
        $validator = self::validator(['name' => 'string|integer|min:0']);

        self::assertSame(
            ['This field must be an integer.'],
            $validator->validate(['name' => 'abc'])->errors()['name']
        );
    }

    /* ---------- type rules & coercion policy ---------- */

    public function test_integer_cast_is_lossless_only(): void
    {
        $validator = self::validator(['n' => 'integer']);
        self::assertSame(['n' => 9], $validator->validate(['n' => '9'])->data());
        self::assertSame(['n' => -3], $validator->validate(['n' => '-3'])->data());
        self::assertSame(['n' => 0], $validator->validate(['n' => '0'])->data());

        self::assertTrue($validator->validate(['n' => '007'])->fails(), 'digit strings with padding are rejected by integer');
        self::assertTrue($validator->validate(['n' => 1.0])->fails());
        self::assertTrue($validator->validate(['n' => '1e2'])->fails());
        self::assertTrue($validator->validate(['n' => true])->fails());
    }

    public function test_numeric_casts_numeric_text_but_not_garbage(): void
    {
        $validator = self::validator(['p' => 'numeric']);
        self::assertSame(['p' => 4], $validator->validate(['p' => '4'])->data());
        self::assertSame(['p' => 7.5], $validator->validate(['p' => '7.5'])->data());
        self::assertTrue($validator->validate(['p' => '12px'])->fails());
        self::assertTrue($validator->validate(['p' => 'NAN'])->fails());
        self::assertTrue($validator->validate(['p' => '0x1A'])->fails());

        $empty = $validator->validate(['p' => '']);
        self::assertTrue($empty->fails(), 'empty string is a present value: the numeric rule rejects it');
        self::assertSame([], $empty->data(), 'failed fields validate to nothing');
    }

    public function test_boolean_accepts_true_and_false_strings_only(): void
    {
        $validator = self::validator(['ok' => 'boolean']);
        self::assertSame(['ok' => true], $validator->validate(['ok' => 'true'])->data());
        self::assertSame(['ok' => false], $validator->validate(['ok' => 'false'])->data());
        self::assertTrue($validator->validate(['ok' => 'yes'])->fails());
        self::assertTrue($validator->validate(['ok' => 1])->fails(), 'no 0/1 coercion: safety over convenience');
        self::assertTrue($validator->validate(['ok' => ''])->fails());
    }

    public function test_string_rule_rejects_nonstrings_and_silent_trimming(): void
    {
        $validator = self::validator(['v' => 'string']);
        self::assertTrue($validator->validate(['v' => 4])->fails());
        self::assertSame(['v' => 'ok'], $validator->validate(['v' => 'ok'])->data(), 'string values kept verbatim');
    }

    public function test_length_rule_for_strings_and_arrays(): void
    {
        $validator = self::validator([
            'username' => 'required|string|length:3,20',
            'roles' => 'array|length:1,5',
        ]);

        self::assertTrue($validator->validate(['username' => 'ab', 'roles' => []])->fails());
        self::assertTrue($validator->validate(['username' => 'abcdefgh', 'roles' => [1, 2, 3, 4, 5, 6]])->fails());
        self::assertTrue($validator->validate(['username' => 'abc', 'roles' => ['r']])->passes());
        self::assertTrue($validator->validate(['username' => '✔✔✔✔', 'roles' => ['r']])->passes(), 'mb-aware lengths');

        $lengthError = $validator->validate(['username' => 'a'])->errors()['username'][0];
        self::assertStringContainsString('at least 3 characters', $lengthError);
    }

    public function test_range_bounds_only_apply_to_numbers(): void
    {
        $validator = self::validator(['qty' => 'integer|min:1|max:99']);
        self::assertTrue($validator->validate(['qty' => 0])->fails());
        self::assertTrue($validator->validate(['qty' => 100])->fails());
        self::assertTrue($validator->validate(['qty' => 99])->passes());

        $floats = self::validator(['rate' => 'numeric|min:0.5']);
        self::assertTrue($floats->validate(['rate' => 0.4])->fails());
        self::assertTrue($floats->validate(['rate' => 0.5])->passes());
    }

    public function test_inclusion_and_exclusion(): void
    {
        $validator = self::validator([
            'status' => 'required|in:draft,published',
            'blacklist' => 'notIn:root,admin',
        ]);

        self::assertTrue($validator->validate(['status' => 'published', 'blacklist' => 'guest'])->passes());
        self::assertSame(
            ['This field must be one of: draft, published.'],
            $validator->validate(['status' => 'archived', 'blacklist' => 'guest'])->errors()['status']
        );
        self::assertSame(
            ['This field must not be one of: root, admin.'],
            $validator->validate(['status' => 'draft', 'blacklist' => 'admin'])->errors()['blacklist']
        );
    }

    public function test_pattern_rule_and_forbidden_e_modifier(): void
    {
        $validator = self::validator(['sku' => 'regex:/^[A-Z]{2}\d{4}$/']);
        self::assertTrue($validator->validate(['sku' => 'AB1234'])->passes());
        self::assertTrue($validator->validate(['sku' => 'ab1234'])->fails());

        $this->expectException(\InvalidArgumentException::class);

        self::validator(['sku' => 'regex:/abc/e'])->validate(['sku' => 'abc']);
    }

    public function test_unknown_rule_names_fail_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::validator(['x' => 'magnetic'])->validate(['x' => '1']);
    }

    public function test_bad_rule_arguments_fail_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numeric argument');

        self::validator(['x' => 'min:'])->validate(['x' => 5]);
    }

    /* ---------- nested data & wildcards ---------- */

    public function test_nested_paths_validate_down_the_tree(): void
    {
        $validator = self::validator([
            'user.name' => 'required|string',
            'user.age' => 'integer|min:18',
            'meta.tags.code' => 'integer',
        ]);

        $result = $validator->validate([
            'user' => ['name' => 'Ada', 'age' => 36],
            'meta' => ['tags' => ['code' => 7]],
        ]);

        self::assertTrue($result->passes());

        self::assertSame([
            'user' => ['name' => 'Ada', 'age' => 36],
            'meta' => ['tags' => ['code' => 7]],
        ], $result->data());
    }

    public function test_scalar_parent_makes_nested_field_absent(): void
    {
        $validator = self::validator(['user.name' => 'required|string']);

        $result = $validator->validate(['user' => 'just-a-string']);

        self::assertTrue($result->fails(), 'scalar parent cannot carry nested fields');
        self::assertSame(['This field is required.'], $result->errors()['user.name']);
    }

    public function test_wildcard_rules_reach_every_element(): void
    {
        $validator = self::validator(['orders.*.sku' => 'required|string']);

        $result = $validator->validate(['orders' => [
            ['sku' => 'ONE'], ['sku' => ''], ['no-sku' => true],
        ]]);

        self::assertFalse($result->passes());
        self::assertSame(['This field is required.'], $result->errors()['orders.1.sku']);
        self::assertSame(['This field is required.'], $result->errors()['orders.2.sku']);
    }

    public function test_wildcard_alias_expansion_on_flat_lists(): void
    {
        $validator = self::validator([
            'tags.*' => 'string|length:1,10',
            'ids.*' => 'integer',
        ]);

        $ok = $validator->validate(['tags' => ['a', 'b'], 'ids' => ['1', 2]])->passes();
        self::assertTrue($ok);

        $fail = $validator->validate(['tags' => ['ok', 'way-too-long-tag'], 'ids' => ['x']])->errors();
        self::assertArrayHasKey('tags.1', $fail);
        self::assertArrayHasKey('ids.0', $fail);
    }

    /* ---------- malicious inputs ---------- */

    public function test_control_characters_are_rejected_in_strings(): void
    {
        $validator = self::validator(['bio' => 'string']);

        foreach (["zero\x00", "vert\x0Bical", "del\x7F", "\x1F-unit-sep"] as $evil) {
            self::assertTrue($validator->validate(['bio' => $evil])->fails(), "control char payload [$evil]");
            self::assertStringContainsString(
                'control characters',
                $validator->validate(['bio' => $evil])->errors()['bio'][0]
            );
        }

        // Normal line breaks and tabs stay valid.
        self::assertTrue($validator->validate(['bio' => "line1\nline2\tok\r"])->passes());
    }

    public function test_allowed_control_characters_explicitly_allow(): void
    {
        $validator = self::validator(['payload' => 'string|allowsControlChars']);
        self::assertTrue($validator->validate(['payload' => "bin\x00ary-ish"])->passes());
    }

    public function test_uppercase_casing_attacks_and_html_stay_literally_unchallenged(): void
    {
        // The validator does NOT sanitize HTML; rejection is the app's job.
        // Values pass through byte-for-byte - documented contract.
        $validator = self::validator(['comment' => 'string']);
        $raw = "<script>alert('x')</script>";
        self::assertSame(['comment' => $raw], $validator->validate(['comment' => $raw])->data());
    }

    public function test_deep_recursion_is_not_possible_via_input_shape(): void
    {
        $validator = self::validator(['only.known.path' => 'integer']);
        self::assertTrue($validator->validate(['only' => ['known' => ['path' => 3]]])->passes());
    }

    /* ---------- normalization ---------- */

    public function test_trimming_only_when_explicitly_configured(): void
    {
        $strict = self::validator(['name' => 'string']);
        $strictResult = $strict->validate(['name' => '  ']);
        self::assertTrue($strictResult->passes(), 'without norm the raw value is judged as-is');
        self::assertSame(['name' => '  '], $strictResult->data());

        $trimmed = self::validator(['name' => 'required|string'])->normalize('trim');
        self::assertTrue($trimmed->validate(['name' => '  '])->passes());
        self::assertSame(['name' => ''], $trimmed->validate(['name' => '  '])->data());
    }

    public function test_unknown_normalization_operations_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::validator([])->normalize('yaml_parse_everything');
    }

    /* ---------- custom rules & messages ---------- */

    public function test_custom_rule_objects_and_closures(): void
    {
        $even = new class () implements RuleContract {
            public function validate(string $field, mixed $value): ?string
            {
                return (is_int($value) && $value % 2 === 0) ? null : 'Field must be an even integer.';
            }
        };

        $validator = self::validator([
            'a' => [$even],
            'b' => [static fn (string $f, mixed $v): ?string => $v === 'yes' ? null : ($f.' must be yes')],
        ]);

        $result = $validator->validate(['a' => 5, 'b' => 'no']);

        self::assertFalse($result->passes());
        self::assertSame(['Field must be an even integer.'], $result->errors()['a']);
        self::assertSame(['b must be yes'], $result->errors()['b']);
    }

    public function test_custom_messages_by_field_rule_field_and_global_fallback(): void
    {
        $validator = self::validator(
            [
                'email' => 'required|email',
                'age' => 'integer',
            ],
            [
                'email.required' => 'We need your email address.',
                'age' => 'Age must be a whole number.',
            ]
        );

        $errors = $validator->validate([])->errors();

        self::assertSame(['We need your email address.'], $errors['email']);

        $ageError = $validator->validate(['age' => 'x'])->errors()['age'];
        self::assertSame(['Age must be a whole number.'], $ageError);
    }

    /* ---------- presets & reusable definitions ---------- */

    public function test_presets_are_reusable_and_copy_safe(): void
    {
        Presets::define('user.signup', [
            'email' => 'required|email',
            'password' => 'required|string|length:8,128',
        ]);

        $result = Presets::validator('user.signup')->validate([
            'email' => 'me@here.dev',
            'password' => 'long-enough',
        ]);

        self::assertTrue($result->passes());

        // The registered shape survives mutation attempts.
        $form = Presets::get('user.signup');
        $form['email'] = 'not-a-rule';

        self::assertSame('required|email', Presets::get('user.signup')['email']);
    }

    public function test_undefined_preset_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Presets::validator('never.defined');
    }

    /* ---------- result & exception types ---------- */

    public function test_exception_carries_the_result(): void
    {
        $result = self::validator(['x' => 'required'])->validate([]);

        self::expectException(ValidationException::class);
        self::expectExceptionMessage('x');

        throw $result->toException();
    }

    public function test_error_lines_are_flattened(): void
    {
        $result = self::validator([
            'a' => 'required',
            'b.c' => 'required',
        ])->validate([]);

        self::assertSame(
            ['This field is required.', 'This field is required.'],
            $result->errorLines()
        );
    }

    /* ---------- request payload resolution ---------- */

    public function test_form_input_json_body_wins_over_query(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'REQUEST_URI' => '/submit?q=from-query',
        ], [], null, null, '{"q":"from-body","page":2}');

        $payload = FormInput::fromRequest($request);

        self::assertSame('from-body', $payload['q'], 'body values win on collisions');
        self::assertSame(2, $payload['page']);
    }

    public function test_form_input_reads_json_payload_with_nesting(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'REQUEST_URI' => '/submit',
        ], [], null, null, '{"user":{"name":"Ada"},"items":[1,2]}');

        $payload = FormInput::fromRequest($request);

        self::assertSame('Ada', $payload['user']['name']);
        self::assertSame([1, 2], $payload['items']);
    }

    public function test_form_input_reads_form_bodies(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'REQUEST_URI' => '/submit',
        ], [], null, ['email' => 'form@body.dev', 'strip' => 'x']);

        $payload = FormInput::fromRequest($request);

        self::assertSame('form@body.dev', $payload['email']);
        self::assertSame('x', $payload['strip']);
    }

    public function test_validated_data_only_survives_from_the_full_pipeline(): void
    {
        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'REQUEST_URI' => '/signup',
        ], [], null, null, '{"name":"Ada","role":"superadmin","name2":"ignored"}');

        $payload = FormInput::fromRequest($request);

        $result = self::validator([
            'name' => 'required|string',
            'role' => 'in:admin,editor',
        ])->validate($payload);

        self::assertTrue($result->fails());
        self::assertSame(['This field must be one of: admin, editor.'], $result->errors()['role']);

        self::assertSame(
            ['name' => 'Ada'],
            $result->data(),
            'unvalidated extra fields (name2) never appear in validated data'
        );
        self::assertArrayNotHasKey('name2', $result->data());
    }
}
