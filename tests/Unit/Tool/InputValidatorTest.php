<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tool\InputValidator;
use PHPUnit\Framework\TestCase;

class InputValidatorTest extends TestCase
{
    private function objectSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'kind' => ['type' => 'string', 'enum' => ['MAILBOX', 'FORWARD']],
                'note' => ['type' => ['string', 'null']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'input' => [
                    'type' => 'object',
                    'properties' => [
                        'quota' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100]
                    ],
                    'additionalProperties' => false
                ]
            ],
            'required' => ['name'],
            'additionalProperties' => false
        ];
    }

    public function testValidObjectPasses(): void
    {
        $this->assertNull(InputValidator::validate($this->objectSchema(), [
            'name' => 'a', 'kind' => 'FORWARD', 'note' => 'x', 'tags' => ['t'], 'input' => ['quota' => 5]
        ]));
    }

    public function testRequiredMissingNamesThePath(): void
    {
        $message = InputValidator::validate($this->objectSchema(), []);
        $this->assertNotNull($message);
        $this->assertStringContainsString('/name', $message);
    }

    public function testAdditionalPropertiesFalseRejectsExtra(): void
    {
        $message = InputValidator::validate($this->objectSchema(), ['name' => 'a', 'extra' => 1]);
        $this->assertNotNull($message);
        $this->assertStringContainsString('/extra', $message);
    }

    public function testEnumOutsideListIsRejected(): void
    {
        $message = InputValidator::validate($this->objectSchema(), ['name' => 'a', 'kind' => 'CATCHALL']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('/kind', $message);
    }

    public function testNullableTypeAcceptsNull(): void
    {
        $this->assertNull(InputValidator::validate($this->objectSchema(), ['name' => 'a', 'note' => null]));
        $this->assertNotNull(InputValidator::validate($this->objectSchema(), ['name' => 'a', 'note' => 3]));
    }

    public function testNestedObjectPath(): void
    {
        $message = InputValidator::validate($this->objectSchema(), ['name' => 'a', 'input' => ['quota' => 'x']]);
        $this->assertSame('/input/quota: expected integer', $message);
    }

    public function testMinimumAndMaximum(): void
    {
        $this->assertNotNull(
            InputValidator::validate($this->objectSchema(), ['name' => 'a', 'input' => ['quota' => -1]])
        );
        $this->assertNotNull(
            InputValidator::validate($this->objectSchema(), ['name' => 'a', 'input' => ['quota' => 101]])
        );
    }

    public function testMinLength(): void
    {
        $message = InputValidator::validate($this->objectSchema(), ['name' => '']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('/name', $message);
    }

    public function testArrayItems(): void
    {
        $this->assertNull(InputValidator::validate($this->objectSchema(), ['name' => 'a', 'tags' => ['x', 'y']]));
        $message = InputValidator::validate($this->objectSchema(), ['name' => 'a', 'tags' => ['x', 2]]);
        $this->assertNotNull($message);
        $this->assertStringContainsString('/tags/1', $message);
    }

    public function testStringIsNotAnInteger(): void
    {
        $this->assertNotNull(InputValidator::validate(['type' => 'integer'], '5'));
        $this->assertNull(InputValidator::validate(['type' => 'integer'], 5));
    }

    public function testIntegerValuedFloatIsNotAnInteger(): void
    {
        $this->assertNotNull(InputValidator::validate(['type' => 'integer'], 5.0));
        $this->assertNull(InputValidator::validate(['type' => 'number'], 5.0));
        $this->assertNull(InputValidator::validate(['type' => 'number'], 5));
    }

    public function testListIsNotAnObjectAndObjectIsNotAList(): void
    {
        $this->assertNotNull(InputValidator::validate(['type' => 'object'], [1, 2]));
        $this->assertNotNull(InputValidator::validate(['type' => 'array'], ['a' => 1]));
        // JSON's {} and [] both decode to an empty PHP array: either type accepts it.
        $this->assertNull(InputValidator::validate(['type' => 'object'], []));
        $this->assertNull(InputValidator::validate(['type' => 'array'], []));
    }

    public function testBoolean(): void
    {
        $this->assertNull(InputValidator::validate(['type' => 'boolean'], false));
        $this->assertNotNull(InputValidator::validate(['type' => 'boolean'], 0));
    }

    public function testEmptyPropertiesAsAnObject(): void
    {
        $schema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $this->assertNull(InputValidator::validate($schema, []));
        $this->assertSame('/x: unexpected property', InputValidator::validate($schema, ['x' => 1]));
    }

    public function testAListOfTypesAcceptsAnyOfThem(): void
    {
        $schema = ['type' => ['string', 'object']];
        $this->assertNull(InputValidator::validate($schema, '{"a":1}'));
        $this->assertNull(InputValidator::validate($schema, ['a' => 1]));
        $this->assertSame('/: expected string or object', InputValidator::validate($schema, 5));
    }
}
