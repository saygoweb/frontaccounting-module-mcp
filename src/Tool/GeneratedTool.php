<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\GraphQlGateway;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One root field of the GraphQL schema as a tool (spec §6.3). The arguments
 * map one to one onto the field's, plus an optional `fields` selection set
 * unless the field has an argument of that name; a list field's missing
 * `query.limit` is filled in so a model cannot pull a whole ledger by accident,
 * and a list that comes back with `limit` rows carries a note that more may
 * exist and the `query.skip` of the next page.
 */
final class GeneratedTool implements Tool
{
    public const QUERY = 'query';
    public const MUTATION = 'mutation';

    public const FIELDS_DESCRIPTION
        = 'A GraphQL selection set to return instead of the default, e.g. `id name contacts { id email }`. The'
        . ' default is every scalar field and the scalars of each nested object; lists of nested objects (an'
        . ' invoice\'s `lines`, a customer\'s `contacts`) are left out unless selected here.';

    /** Starts the description's list of the fields a list's `query` can filter and sort on. */
    private const QUERYABLE = 'Fields for `query.selector` and `query.sort`: ';

    /** Starts the description's list of what the default selection leaves out. */
    private const ON_REQUEST = 'Not returned unless asked for in `fields`: ';

    /** Mutations that send e-mail. */
    private const OPEN_WORLD = ['invoiceEmail', 'recurringGenerate'];

    private FieldDefinition $field;
    private string $operation;
    private string $defaultSelection;
    private ?int $listLimit;
    /** @var string[] */
    private array $onRequest;
    /** @var string[] the fields `query` can filter and sort on, named in the description */
    private array $queryable = [];
    /** Whether `fields` is ours (a selection set) rather than one of the field's arguments. */
    private bool $ownFields;
    private ?array $inputSchema = null;

    /**
     * @param string $operation self::QUERY or self::MUTATION
     * @param string $defaultSelection SelectionBuilder's, '' for a scalar or enum return
     * @param int|null $listLimit the query.limit to inject, or null when the field takes no Mango `query`
     * @param string[] $onRequest SelectionBuilder::onRequestFor()'s, named in the description
     */
    public function __construct(
        FieldDefinition $field,
        string $operation,
        string $defaultSelection,
        ?int $listLimit,
        array $onRequest = []
    ) {
        $this->field = $field;
        $this->operation = $operation;
        $this->defaultSelection = $defaultSelection;
        $this->listLimit = $listLimit;
        $this->onRequest = $onRequest;
        $this->ownFields = $defaultSelection !== '' && $field->getArg('fields') === null;
    }

    /** The root field's name when it is a list that takes a Mango `query`, else null. */
    public function listField(): ?string
    {
        return $this->listLimit === null ? null : $this->field->name;
    }

    /**
     * This tool with $names, the fields its `query` can filter and sort on
     * (QueryableFields'), named in its description: in the row type's
     * declared order, any the type does not declare after them.
     *
     * @param string[] $names
     */
    public function withQueryable(array $names): self
    {
        $type = Type::getNamedType($this->field->getType());
        $declared = $type instanceof ObjectType || $type instanceof InterfaceType
            ? array_keys($type->getFields())
            : [];
        $tool = clone $this;
        $tool->queryable = array_values(array_unique(array_merge(
            array_intersect($declared, $names),
            array_diff($names, $declared)
        )));
        return $tool;
    }

    /** `customerList` => `customer_list`. */
    public static function snakeCase(string $name): string
    {
        return strtolower((string)preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name));
    }

    public function name(): string
    {
        return self::snakeCase($this->field->name);
    }

    /** The name with underscores as spaces and the first letter capitalised: "customer_list" => "Customer list". */
    public function title(): string
    {
        return ucfirst(str_replace('_', ' ', $this->name()));
    }

    /**
     * The field's description (or a generic one), then the fields a list's
     * `query` can filter and sort on, then what `fields` can add to the
     * default selection.
     */
    public function description(): string
    {
        $description = $this->field->description !== null && $this->field->description !== ''
            ? $this->field->description
            : ucfirst($this->operation) . ' `' . $this->field->name . '` of the FrontAccounting GraphQL API.';
        if ($this->queryable !== []) {
            $description .= "\n\n" . self::QUERYABLE . implode(', ', $this->queryable) . '.';
        }
        if ($this->ownFields && $this->onRequest !== []) {
            $description .= "\n\n" . self::ON_REQUEST . '`' . implode('`, `', $this->onRequest) . '`.';
        }
        return $description;
    }

    public function inputSchema(): array
    {
        if ($this->inputSchema === null) {
            $schema = JsonSchemaMapper::forArguments($this->field);
            if ($this->ownFields) {
                $properties = $schema['properties'] instanceof \stdClass ? [] : $schema['properties'];
                $properties['fields'] = ['type' => 'string', 'description' => self::FIELDS_DESCRIPTION];
                $schema['properties'] = $properties;
            }
            $this->inputSchema = $schema;
        }
        return $this->inputSchema;
    }

    public function annotations(): array
    {
        if ($this->operation === self::QUERY) {
            return self::READ_ONLY;
        }
        $name = $this->field->name;
        return [
            'readOnlyHint' => false,
            'destructiveHint' => substr($name, -6) === 'Delete',
            'idempotentHint' => substr($name, -6) === 'Update',
            'openWorldHint' => in_array($name, self::OPEN_WORLD, true),
        ];
    }

    public function requiresWrite(): bool
    {
        return $this->operation === self::MUTATION;
    }

    /**
     * The GraphQL document call() runs for $args: a variable for each of the
     * field's arguments present in $args (and `query` when the limit is
     * injected), the `fields` selection set or else the default one.
     */
    public function document(array $args): string
    {
        $variables = [];
        $arguments = [];
        foreach ($this->field->args as $arg) {
            if (array_key_exists($arg->name, $args) || ($arg->name === 'query' && $this->listLimit !== null)) {
                $variables[] = '$' . $arg->name . ': ' . $arg->getType()->toString();
                $arguments[] = $arg->name . ': $' . $arg->name;
            }
        }

        $selection = $this->defaultSelection;
        if ($this->ownFields && isset($args['fields']) && is_string($args['fields']) && trim($args['fields']) !== '') {
            $selection = trim($args['fields']);
        }

        return $this->operation
            . ($variables === [] ? '' : ' (' . implode(', ', $variables) . ')')
            . ' { ' . $this->field->name
            . ($arguments === [] ? '' : '(' . implode(', ', $arguments) . ')')
            . ($selection === '' ? '' : ' { ' . $selection . ' }')
            . ' }';
    }

    public function call(array $args, GraphQlGateway $gql): ToolResult
    {
        $variables = $args;
        if ($this->ownFields) {
            unset($variables['fields']);
        }

        $limit = null;
        $skip = 0;
        if ($this->listLimit !== null) {
            $query = $variables['query'] ?? [];
            if (is_array($query)) {
                if (!isset($query['limit'])) {
                    $query['limit'] = $this->listLimit;
                }
                $limit = is_int($query['limit']) ? $query['limit'] : null;
                $skip = isset($query['skip']) && is_int($query['skip']) ? $query['skip'] : 0;
                if (isset($query['selector']) && is_array($query['selector'])) {
                    $query['selector'] = self::encodeSelector($query['selector']);
                }
                $variables['query'] = $query;
            }
        }

        $document = $this->document($args);
        if ($this->ownFields && isset($args['fields'])) {
            $refusal = $this->checkSpliced($document);
            if ($refusal !== null) {
                return ToolResult::error('fields: ' . $refusal);
            }
        }

        $envelope = $gql->execute($document, $variables);

        $note = null;
        $rows = $envelope['data'][$this->field->name] ?? null;
        if ($limit !== null && $limit > 0 && is_array($rows) && count($rows) === $limit) {
            $note = self::limitReached($limit, $skip);
        }
        return ToolResult::fromGraphQl($envelope, $this->field->name, $note);
    }

    /**
     * Why a document with `fields` spliced in is refused, or null: a selection
     * set must not close the field and add another root field (a mutation
     * riding in on a read-only tool, or a second mutation), so the document
     * must still be one operation whose root selection is this field alone,
     * unaliased, with no fragment spread or inline fragment beside it.
     */
    private function checkSpliced(string $document): ?string
    {
        try {
            $definitions = Parser::parse($document)->definitions;
        } catch (SyntaxError $e) {
            return 'GraphQL syntax error: ' . $e->getMessage();
        }
        $refusal = 'must be a selection set of `' . $this->field->name . '` only';
        if (count($definitions) !== 1) {
            return $refusal;
        }
        $operation = $definitions[0];
        if (!$operation instanceof OperationDefinitionNode || $operation->operation !== $this->operation) {
            return $refusal;
        }
        $selections = $operation->selectionSet->selections;
        if (count($selections) !== 1) {
            return $refusal;
        }
        $root = $selections[0];
        if (!$root instanceof FieldNode || $root->alias !== null || $root->name->value !== $this->field->name) {
            return $refusal;
        }
        return null;
    }

    /**
     * The note on a list that came back full: there may be more rows than
     * the page holds, and the next page starts at $skip + $limit. (No total:
     * the GraphQL lists don't give one yet.)
     */
    private static function limitReached(int $limit, int $skip): string
    {
        return $limit . ($limit === 1 ? ' row' : ' rows') . ' returned, the limit; more may exist: repeat with'
            . ' query.skip: ' . ($skip + $limit) . ', or narrow the selector.';
    }

    /** graphql's MangoInput takes the selector JSON-encoded; {} stays an object. */
    private static function encodeSelector(array $selector): string
    {
        $json = json_encode(
            $selector === [] ? new \stdClass() : $selector,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }
}
