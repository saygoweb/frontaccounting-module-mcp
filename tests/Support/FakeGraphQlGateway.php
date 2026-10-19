<?php

namespace FA\Mcp\Tests\Support;

use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\GraphQlGateway;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;

/**
 * A GraphQlGateway that records each execute() and answers with a canned
 * envelope: the first on() whose needle the document contains, else the
 * default. schema() returns the given schema (the fixture by default).
 */
class FakeGraphQlGateway implements GraphQlGateway
{
    /** @var array[] each ['document' => string, 'variables' => array, 'operationName' => ?string] */
    public array $calls = [];
    public int $schemaCalls = 0;

    private array $envelope;
    /** @var array<string, array> needle => envelope */
    private array $answers = [];
    private ?Schema $schema;
    private ?AuthFailure $failure = null;

    public function __construct(array $envelope = ['data' => []], ?Schema $schema = null)
    {
        $this->envelope = $envelope;
        $this->schema = $schema;
    }

    /** A gateway whose every call throws $failure. */
    public static function failing(AuthFailure $failure): self
    {
        return (new self())->failWith($failure);
    }

    public static function fixture(): Schema
    {
        return BuildSchema::build((string)file_get_contents(dirname(__DIR__) . '/data/schema.graphql'));
    }

    /** Answer documents containing $needle with $envelope. */
    public function on(string $needle, array $envelope): self
    {
        $this->answers[$needle] = $envelope;
        return $this;
    }

    /** Make every later call throw $failure (null: stop failing). */
    public function failWith(?AuthFailure $failure): self
    {
        $this->failure = $failure;
        return $this;
    }

    public function execute(string $document, array $variables = [], ?string $operationName = null): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->calls[] = ['document' => $document, 'variables' => $variables, 'operationName' => $operationName];
        foreach ($this->answers as $needle => $envelope) {
            if (strpos($document, $needle) !== false) {
                return $envelope;
            }
        }
        return $this->envelope;
    }

    public function schema(): Schema
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->schemaCalls++;
        if ($this->schema === null) {
            $this->schema = self::fixture();
        }
        return $this->schema;
    }

    /** Documents executed so far that contain $needle. */
    public function callsContaining(string $needle): array
    {
        return array_values(array_filter($this->calls, function (array $call) use ($needle): bool {
            return strpos($call['document'], $needle) !== false;
        }));
    }
}
