<?php

namespace FA\Mcp\Tests\Integration;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Fa\Bootstrap;
use FA\Mcp\Gateway\ExecutorGraphQlGateway;
use FA\Mcp\Tool\GeneratedTool;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Tool\ToolCatalog;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use PHPUnit\Framework\TestCase;

/**
 * The catalog generated from the schema the CI image really serves (graphql
 * and sgw_sales active in company 0), through ExecutorGraphQlGateway, as the
 * CI user `test` (role FA CI, every area).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CatalogAgainstRealSchemaTest extends TestCase
{
    private const LOGIN = 'test';
    private const LABEL = 'mcp-catalog-test';

    private ?Executor $executor = null;
    /** @var string[] jtis to revoke in tearDown */
    private array $jtis = [];

    protected function setUp(): void
    {
        $graphql = dirname(__DIR__, 3) . '/graphql';
        if (
            !class_exists(Executor::class)
            || !is_file($graphql . '/config_graphql.php')
            || !is_file(Bootstrap::defaultRoot() . '/config_db.php')
        ) {
            $this->markTestSkipped('Needs FrontAccounting and the GraphQL module: run in the FA CI image.');
        }
        $this->executor = Executor::create();
    }

    protected function tearDown(): void
    {
        if ($this->executor === null) {
            return;
        }
        foreach ($this->jtis as $jti) {
            $this->executor->revokeToken(0, $jti);
        }
    }

    private function token(string $scope): string
    {
        $issued = $this->executor->issueToken(0, self::LOGIN, $scope, 600, self::LABEL);
        $this->jtis[] = $issued['jti'];
        return $issued['token'];
    }

    private function catalog(string $scope): ToolCatalog
    {
        return new ToolCatalog(
            new ExecutorGraphQlGateway($this->executor, $this->token($scope), '127.0.0.1'),
            new SchemaToolFactory(50),
            true
        );
    }

    public function testListHasTheGeneratedToolsAndNotTheExcludedOnes(): void
    {
        $names = array_column($this->catalog('write')->listTools(), 'name');
        foreach (['customer_list', 'sales_order_create', 'invoice_email', 'recurring_due_list'] as $name) {
            $this->assertContains($name, $names);
        }
        foreach (['login', 'token_refresh', 'token_revoke', 'api_version'] as $name) {
            $this->assertNotContains($name, $names);
        }
        $this->assertSame(['graphql_schema', 'graphql_query', 'graphql_mutate'], array_slice($names, -3));
    }

    public function testEveryGeneratedDocumentValidatesAgainstTheSchema(): void
    {
        $schema = (new ExecutorGraphQlGateway($this->executor, $this->token('write'), '127.0.0.1'))->schema();
        $tools = (new SchemaToolFactory(50))->fromSchema($schema);
        $this->assertGreaterThan(20, count($tools));
        foreach ($tools as $tool) {
            $this->assertInstanceOf(GeneratedTool::class, $tool);
            $document = $tool->document(self::minimumArguments($tool->inputSchema()));
            $errors = DocumentValidator::validate($schema, Parser::parse($document));
            $this->assertSame([], array_map(static function ($e): string {
                return $e->getMessage();
            }, $errors), $tool->name() . ': ' . $document);
        }
    }

    public function testCustomerListRunsWithTheLimitGiven(): void
    {
        $result = $this->catalog('read')->callTool('customer_list', ['query' => ['limit' => 2]]);
        $this->assertFalse($result['isError'], $result['content'][0]['text']);
        $this->assertIsArray($result['structuredContent']['data']);
        $this->assertNotSame([], $result['structuredContent']['data'], 'the demo dataset has customers');
        $this->assertLessThanOrEqual(2, count($result['structuredContent']['data']));
    }

    /**
     * The fields a list tool's description names for query.selector and
     * query.sort are the ones graphql accepts: those its BAD_INPUT message for
     * an unknown sort field lists.
     */
    public function testEachListNamesTheFieldsGraphqlFiltersAndSortsOn(): void
    {
        $catalog = $this->catalog('read');
        $checked = 0;
        foreach ($catalog->listTools() as $tool) {
            $properties = $tool['inputSchema']['properties'];
            if (
                !is_array($properties) || !isset($properties['query']['properties']['sort'])
                || substr($tool['name'], -6) === '_count'
            ) {
                continue;
            }
            $line = '/Fields for `query.selector` and `query.sort`: ([^.]*)\./';
            $this->assertSame(1, preg_match($line, $tool['description'], $named), $tool['description']);
            $result = $catalog->callTool($tool['name'], ['query' => ['sort' => ['noSuchField'], 'limit' => 1]]);
            $text = $result['content'][0]['text'];
            $this->assertTrue($result['isError'], $tool['name'] . ': ' . $text);
            $this->assertSame(1, preg_match('/^BAD_INPUT: .*The sortable fields are: (.*)$/m', $text, $sortable));
            $this->assertSame(
                self::sorted(explode(', ', $sortable[1])),
                self::sorted(explode(', ', $named[1])),
                $tool['name']
            );
            if ($tool['name'] === 'invoice_list') {
                // Computed, so not in the schema-only guess this replaces: the scalars of the row type.
                $this->assertNotContains('outstanding', explode(', ', $named[1]));
                $this->assertContains('date', explode(', ', $named[1]));
            }
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }

    /**
     * Each list graphql counts has a count tool, described by the list: the
     * same fields, for the selector only.
     */
    public function testEachCountToolIsDescribedByItsList(): void
    {
        $descriptions = array_column($this->catalog('read')->listTools(), 'description', 'name');
        $counted = 0;
        foreach ($descriptions as $name => $description) {
            if (substr($name, -6) !== '_count') {
                continue;
            }
            $list = substr($name, 0, -6) . '_list';
            $this->assertArrayHasKey($list, $descriptions, $name);
            $this->assertStringStartsWith('How many rows ' . $list . ' returns for this `query`', $description);
            $line = '/Fields for `query.selector` and `query.sort`: ([^.]*)\./';
            $this->assertSame(1, preg_match($line, $descriptions[$list], $listed), $list);
            $this->assertStringEndsWith("\n\nFields for `query.selector`: " . $listed[1] . '.', $description, $name);
            $counted++;
        }
        $this->assertGreaterThan(15, $counted);
    }

    public function testAFullPageSaysTheTotalTheCountToolGives(): void
    {
        $catalog = $this->catalog('read');
        $count = $catalog->callTool('customer_count', []);
        $this->assertFalse($count['isError'], $count['content'][0]['text']);
        $total = $count['structuredContent']['data'];
        $this->assertIsInt($total);
        $this->assertGreaterThan(1, $total, 'the demo dataset has customers');

        $result = $catalog->callTool('customer_list', ['query' => ['limit' => 1, 'skip' => 1], 'fields' => 'id']);
        $this->assertFalse($result['isError'], $result['content'][0]['text']);
        $this->assertStringStartsWith('Row 2 of ' . $total . ' returned; ', $result['structuredContent']['note']);
    }

    public function testInvoiceListSortsNewestFirstAsTheSchemaSays(): void
    {
        $result = $this->catalog('read')->callTool('invoice_list', [
            'query' => ['sort' => ['-date', '-id'], 'limit' => 10],
            'fields' => 'id date',
        ]);
        $this->assertFalse($result['isError'], $result['content'][0]['text']);
        $dates = array_column($result['structuredContent']['data'], 'date');
        $this->assertGreaterThan(1, count($dates), 'the demo dataset has invoices');
        $newestFirst = $dates;
        rsort($newestFirst, SORT_STRING);
        $this->assertSame($newestFirst, $dates);
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private static function sorted(array $names): array
    {
        sort($names, SORT_STRING);
        return $names;
    }

    public function testReadTokenListsNoMutationTools(): void
    {
        $write = array_column($this->catalog('write')->listTools(), 'annotations', 'name');
        $read = array_column($this->catalog('read')->listTools(), 'name');
        $this->assertContains('customer_list', $read);
        $this->assertNotContains('customer_create', $read);
        $this->assertNotContains('graphql_mutate', $read);
        // Exactly the read-only tools of the write list.
        $readOnly = array_keys(array_filter($write, static function (array $annotations): bool {
            return $annotations['readOnlyHint'] === true;
        }));
        $this->assertSame($readOnly, $read);
    }

    /**
     * The arguments a call must carry, by name. document() declares a variable
     * for each argument present, whatever its value, so the values are
     * placeholders: what is validated is the document.
     *
     * @return array<string, string>
     */
    private static function minimumArguments(array $inputSchema): array
    {
        return array_fill_keys($inputSchema['required'] ?? [], 'x');
    }
}
