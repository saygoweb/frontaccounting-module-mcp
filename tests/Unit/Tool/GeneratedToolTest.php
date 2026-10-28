<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\GeneratedTool;
use FA\Mcp\Tool\SchemaToolFactory;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class GeneratedToolTest extends TestCase
{
    private function tool(string $name): GeneratedTool
    {
        foreach ((new SchemaToolFactory(50))->fromSchema(FakeGraphQlGateway::fixture()) as $tool) {
            if ($tool->name() === $name) {
                $this->assertInstanceOf(GeneratedTool::class, $tool);
                return $tool;
            }
        }
        $this->fail("no tool $name");
    }

    public function testDocumentAndVariables(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $tool = $this->tool('customer_list');
        $tool->call(['query' => ['limit' => 5]], $gql);
        $this->assertSame(
            'query ($query: MangoInput) { customerList(query: $query) '
            . '{ id name currency creditStatus balance } }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['query' => ['limit' => 5]], $gql->calls[0]['variables']);
    }

    public function testAnExplicitNullForANullableArgumentIsPassedThrough(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['7'], 'fields' => null], $gql);
        $this->assertSame(
            'mutation ($id: [ID!]!, $fields: String) { invoiceEmail(id: $id, fields: $fields) }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['id' => ['7'], 'fields' => null], $gql->calls[0]['variables']);
    }

    public function testNullsInsideTheMangoQueryAreKeptAndTheLimitStillApplies(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['selector' => null, 'limit' => null, 'skip' => null]], $gql);
        $this->assertSame(
            ['query' => ['selector' => null, 'limit' => 50, 'skip' => null]],
            $gql->calls[0]['variables']
        );

        // A null query is no query: the default limit is still injected.
        $this->tool('customer_list')->call(['query' => null], $gql);
        $this->assertSame(['query' => ['limit' => 50]], $gql->calls[1]['variables']);
    }

    public function testDataIsTheRootFieldsValue(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 5]], $gql)->toMcp();
        $this->assertSame(['data' => [['id' => '1', 'name' => 'A']]], $mcp['structuredContent']);
        $this->assertFalse($mcp['isError']);
    }

    public function testObjectSelectorIsEncoded(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(
            ['query' => ['selector' => ['name' => ['$regex' => 'Acme']], 'limit' => 5]],
            $gql
        );
        $this->assertSame('{"name":{"$regex":"Acme"}}', $gql->calls[0]['variables']['query']['selector']);
    }

    public function testStringSelectorIsPassedAsIs(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['selector' => '{"id":"1"}', 'limit' => 5]], $gql);
        $this->assertSame('{"id":"1"}', $gql->calls[0]['variables']['query']['selector']);
    }

    public function testDefaultLimitInjectedAndNoted(): void
    {
        $rows = array_fill(0, 50, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call([], $gql)->toMcp();
        $this->assertSame(50, $gql->calls[0]['variables']['query']['limit']);
        $this->assertStringStartsWith(
            'query ($query: MangoInput) { customerList(query: $query) {',
            $gql->calls[0]['document']
        );
        $note = '50 rows returned, the limit; more may exist: repeat with query.skip: 50, or narrow the selector.';
        $this->assertSame($note, $mcp['structuredContent']['note']);
        // Clients that read only `content` see the note too.
        $this->assertStringContainsString(json_encode($note), $mcp['content'][0]['text']);
    }

    public function testAnExplicitLimitThatIsReachedIsNotedWithTheNextSkip(): void
    {
        $rows = array_fill(0, 10, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 10, 'skip' => 20]], $gql)->toMcp();
        $this->assertSame(
            '10 rows returned, the limit; more may exist: repeat with query.skip: 30, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testTheDefaultLimitReachedOnALaterPageCountsFromItsSkip(): void
    {
        $rows = array_fill(0, 50, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['skip' => 50]], $gql)->toMcp();
        $this->assertSame(
            '50 rows returned, the limit; more may exist: repeat with query.skip: 100, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testLimitInjectedBesideOtherQueryKeys(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['skip' => 50]], $gql);
        $this->assertSame(['skip' => 50, 'limit' => 50], $gql->calls[0]['variables']['query']);
    }

    public function testNoNoteBelowTheLimit(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $this->assertArrayNotHasKey('note', $this->tool('customer_list')->call([], $gql)->toMcp()['structuredContent']);

        $rows = array_fill(0, 9, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 10]], $gql)->toMcp();
        $this->assertArrayNotHasKey('note', $mcp['structuredContent']);
    }

    public function testALimitOfOneIsOneRow(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 1]], $gql)->toMcp();
        $this->assertSame(
            '1 row returned, the limit; more may exist: repeat with query.skip: 1, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testNoNoteForALimitOfZero(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 0]], $gql)->toMcp();
        $this->assertArrayNotHasKey('note', $mcp['structuredContent']);
    }

    /** A gateway answering customerList with $rows and customerCount with $count. */
    private static function counting(int $rows, $count): FakeGraphQlGateway
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => array_fill(0, $rows, ['id' => '1'])]]);
        return $gql->on('customerCount', ['data' => ['customerCount' => $count]]);
    }

    public function testAFullPageCountsTheSelectorAndSaysTheTotal(): void
    {
        $gql = self::counting(50, 312);
        $mcp = $this->tool('customer_list')->call(
            ['query' => ['selector' => ['name' => ['$regex' => 'A']], 'sort' => ['name']]],
            $gql
        )->toMcp();
        $this->assertCount(2, $gql->calls);
        // The selector only: the count ignores limit, skip and sort.
        $this->assertSame(
            'query ($query: MangoInput) { customerCount(query: $query) }',
            $gql->calls[1]['document']
        );
        $this->assertSame(['query' => ['selector' => '{"name":{"$regex":"A"}}']], $gql->calls[1]['variables']);
        $this->assertSame(
            'Rows 1–50 of 312 returned; for more, repeat with query.skip: 50, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testTheTotalOnALaterPageCountsFromItsSkip(): void
    {
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 10, 'skip' => 20]], self::counting(10, 45))
            ->toMcp();
        $this->assertSame(
            'Rows 21–30 of 45 returned; for more, repeat with query.skip: 30, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testAFullPageOfOneRowSaysWhichRow(): void
    {
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 1, 'skip' => 4]], self::counting(1, 9))
            ->toMcp();
        $this->assertSame(
            'Row 5 of 9 returned; for more, repeat with query.skip: 5, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testAFullPageWithoutASelectorCountsEverything(): void
    {
        $gql = self::counting(50, 51);
        $this->tool('customer_list')->call([], $gql);
        $this->assertSame('query { customerCount }', $gql->calls[1]['document']);
        $this->assertSame([], $gql->calls[1]['variables']);
    }

    public function testAFullLastPageSaysThereAreNoMore(): void
    {
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 10, 'skip' => 10]], self::counting(10, 20))
            ->toMcp();
        $this->assertSame('Rows 11–20 of 20 returned; there are no more.', $mcp['structuredContent']['note']);

        // A count below the page (rows added between the two requests' reads) is no more either.
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 10]], self::counting(10, 7))->toMcp();
        $this->assertSame('Rows 1–10 of 10 returned; there are no more.', $mcp['structuredContent']['note']);
    }

    public function testACountThatFailsLeavesTheNoteWithoutATotal(): void
    {
        $plain = '50 rows returned, the limit; more may exist: repeat with query.skip: 50, or narrow the selector.';
        $answers = [
            'an error' => ['errors' => [['message' => 'No.', 'extensions' => ['code' => 'FORBIDDEN']]]],
            'no data' => ['data' => null],
            'not a number' => ['data' => ['customerCount' => 'many']],
        ];
        foreach ($answers as $case => $answer) {
            $gql = (new FakeGraphQlGateway(['data' => ['customerList' => array_fill(0, 50, ['id' => '1'])]]))
                ->on('customerCount', $answer);
            $mcp = $this->tool('customer_list')->call([], $gql)->toMcp();
            $this->assertFalse($mcp['isError'], $case);
            $this->assertSame($plain, $mcp['structuredContent']['note'], $case);
        }

        // Nor does an AuthFailure on the count alone: the list was answered.
        $page = ['data' => ['customerList' => array_fill(0, 50, ['id' => '1'])]];
        $failing = new class ($page) extends FakeGraphQlGateway {
            public function execute(string $document, array $variables = [], ?string $operationName = null): array
            {
                if (strpos($document, 'customerCount') !== false) {
                    throw new AuthFailure(AuthFailure::FORBIDDEN, 'no');
                }
                return parent::execute($document, $variables, $operationName);
            }
        };
        $mcp = $this->tool('customer_list')->call([], $failing)->toMcp();
        $this->assertSame($plain, $mcp['structuredContent']['note']);
    }

    public function testAPageThatIsNotFullIsNotCounted(): void
    {
        $gql = self::counting(49, 49);
        $this->tool('customer_list')->call([], $gql);
        $this->assertSame([], $gql->callsContaining('customerCount'));
    }

    public function testAListWithoutACountFieldIsNotCounted(): void
    {
        $schema = BuildSchema::build('
            input MangoInput { selector: String limit: Int skip: Int sort: [String] }
            type T { id: ID }
            type Query { thingList(query: MangoInput): [T!]! otherCount(query: MangoInput): Int! }
        ');
        $list = null;
        foreach ((new SchemaToolFactory(2))->fromSchema($schema) as $tool) {
            if ($tool->name() === 'thing_list') {
                $list = $tool;
            }
        }
        $this->assertInstanceOf(GeneratedTool::class, $list);
        $gql = new FakeGraphQlGateway(['data' => ['thingList' => [['id' => '1'], ['id' => '2']]]]);
        $mcp = $list->call([], $gql)->toMcp();
        $this->assertCount(1, $gql->calls);
        $this->assertSame(
            '2 rows returned, the limit; more may exist: repeat with query.skip: 2, or narrow the selector.',
            $mcp['structuredContent']['note']
        );
    }

    public function testACountToolTakesTheQueryWithoutALimitAndEncodesTheSelector(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerCount' => 7]]);
        $tool = $this->tool('customer_count');
        $mcp = $tool->call(['query' => ['selector' => ['name' => 'A'], 'limit' => 5]], $gql)->toMcp();
        $this->assertSame('query ($query: MangoInput) { customerCount(query: $query) }', $gql->calls[0]['document']);
        $this->assertSame(['query' => ['selector' => '{"name":"A"}', 'limit' => 5]], $gql->calls[0]['variables']);
        $this->assertSame(['data' => 7], $mcp['structuredContent']);

        $tool->call([], $gql);
        $this->assertSame('query { customerCount }', $gql->calls[1]['document']);
        $this->assertSame([], $gql->calls[1]['variables']);
        $this->assertArrayNotHasKey('fields', $tool->inputSchema()['properties']);
        // Accepted, so a page's query passes as it is, but undescribed: they do nothing here.
        $query = $tool->inputSchema()['properties']['query']['properties'];
        foreach (['limit', 'skip', 'sort'] as $ignored) {
            $this->assertArrayNotHasKey('description', $query[$ignored], $ignored);
        }
        $this->assertArrayHasKey('description', $query['selector']);
        $this->assertArrayHasKey(
            'description',
            $this->tool('customer_list')->inputSchema()['properties']['query']['properties']['limit']
        );
    }

    public function testACountToolIsNotAListButIsDescribedByItsList(): void
    {
        $count = $this->tool('customer_count');
        $this->assertNull($count->listField());
        $this->assertSame('customerList', $count->queryableList());
        $this->assertSame('customerList', $this->tool('customer_list')->queryableList());
        $this->assertNull($this->tool('customer_create')->queryableList());
        $this->assertSame(
            'How many rows customer_list returns for this `query`: the same selector, scope and access. limit,'
            . ' skip and sort are ignored, so a page\'s query can be passed as it is.'
            . "\n\nFields for `query.selector`: id, name, balance.",
            $count->withQueryable(['balance', 'id', 'name'])->description()
        );
    }

    public function testFieldsOverrideSelection(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['fields' => 'id contacts { email }'], $gql);
        $this->assertStringContainsString(
            'customerList(query: $query) { id contacts { email } }',
            $gql->calls[0]['document']
        );
        $this->assertArrayNotHasKey('fields', $gql->calls[0]['variables']);
    }

    public function testFieldsCannotAddAnOperation(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(
            ['fields' => 'id } } mutation { customerDelete(id: ["1"]) { id'],
            $gql
        )->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAddARootMutation(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerUpdate' => []]]);
        $mcp = $this->tool('customer_update')->call(
            ['input' => [['id' => '1', 'name' => 'N']], 'fields' => 'id } customerDelete(id: ["1"]) { id'],
            $gql
        )->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAddARootQuery(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } me { login'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAliasTheRootField(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } other: customerList { id'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotSpreadAFragmentAtTheRoot(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } ... on Query { me { login }'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsSyntaxErrorIsAToolError(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id {'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: GraphQL syntax error: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testMutationDocument(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerCreate' => [['id' => '9', 'name' => 'N']]]]);
        $this->tool('customer_create')->call(['input' => [['name' => 'N']]], $gql);
        $this->assertStringStartsWith(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { id name',
            $gql->calls[0]['document']
        );
    }

    public function testScalarReturnHasNoSelection(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['3']], $gql);
        $this->assertSame('mutation ($id: [ID!]!) { invoiceEmail(id: $id) }', $gql->calls[0]['document']);
        $this->assertSame(['id' => ['3']], $gql->calls[0]['variables']);
    }

    public function testARealFieldsArgumentIsSentAsAVariable(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['3'], 'fields' => 'x'], $gql);
        $this->assertSame(
            'mutation ($id: [ID!]!, $fields: String) { invoiceEmail(id: $id, fields: $fields) }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['id' => ['3'], 'fields' => 'x'], $gql->calls[0]['variables']);
    }

    public function testNoArguments(): void
    {
        $this->assertSame('query { me { login areas scope } }', $this->tool('me')->document([]));
    }

    public function testDocumentIsPublicAndMatchesTheCall(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['fields' => 'id'], $gql);
        $this->assertSame($this->tool('customer_list')->document(['fields' => 'id']), $gql->calls[0]['document']);
    }

    public function testGraphQlErrorsBecomeAToolError(): void
    {
        $gql = new FakeGraphQlGateway(['errors' => [['message' => 'No.', 'extensions' => ['code' => 'FORBIDDEN']]]]);
        $mcp = $this->tool('customer_list')->call([], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame('FORBIDDEN: No.', $mcp['content'][0]['text']);
    }

    public function testOnlyAMangoListHasAListField(): void
    {
        $this->assertSame('customerList', $this->tool('customer_list')->listField());
        $this->assertNull($this->tool('recurring_due_list')->listField());
        $this->assertNull($this->tool('customer_create')->listField());
    }

    public function testTheQueryableFieldsAreNamedInDeclaredOrderBeforeTheFieldsHint(): void
    {
        $tool = $this->tool('customer_list');
        $named = $tool->withQueryable(['balance', 'id', 'notDeclared', 'name']);
        $this->assertSame(
            'Query `customerList` of the FrontAccounting GraphQL API.'
            . "\n\nFields for `query.selector` and `query.sort`: id, name, balance, notDeclared."
            . "\n\nNot returned unless asked for in `fields`: `contacts { id name email }`.",
            $named->description()
        );
        $this->assertStringNotContainsString('query.sort', $tool->description(), 'withQueryable() leaves $tool be');
        $this->assertSame($tool->description(), $tool->withQueryable([])->description());
    }

    private function listToolOf(string $listField, string $row): GeneratedTool
    {
        $schema = BuildSchema::build('
            input MangoInput { selector: String limit: Int skip: Int sort: [String] }
            type Row { id: ID ' . $row . ' }
            type Query { ' . $listField . '(query: MangoInput): [Row!]! }
        ');
        foreach ((new SchemaToolFactory(50))->fromSchema($schema) as $tool) {
            if ($tool instanceof GeneratedTool && $tool->listField() === $listField) {
                return $tool;
            }
        }
        $this->fail("no $listField");
    }

    public function testInvoiceListSaysHowToAskForUnpaidInvoices(): void
    {
        $tool = $this->listToolOf('invoiceList', 'outstanding: Float voided: Boolean dueDate: String');
        $text = $tool->withQueryable(['id', 'outstanding', 'voided', 'dueDate'])->description();
        $this->assertStringContainsString(
            'Unpaid invoices: selector {"outstanding": {"$gt": 0}, "voided": false}; add customerId to narrow to one'
            . ' customer; sort ["-date"] for newest first. Overdue: also {"dueDate": {"$lt": "<today>"}}.',
            $text
        );
    }

    public function testAHintIsOmittedWhenTheListDoesNotQueryItsFields(): void
    {
        $tool = $this->listToolOf('invoiceList', 'total: Float');
        $this->assertStringNotContainsString('Unpaid invoices', $tool->description());
        $this->assertStringNotContainsString('Unpaid invoices', $tool->withQueryable(['id', 'total'])->description());
        $partial = $tool->withQueryable(['id', 'voided'])->description();
        $this->assertStringNotContainsString('Unpaid invoices', $partial);
        $overdueless = $tool->withQueryable(['id', 'outstanding', 'voided'])->description();
        $this->assertStringContainsString('Unpaid invoices', $overdueless);
        $this->assertStringNotContainsString('Overdue', $overdueless);
    }

    public function testPaymentAndDeliveryListsHaveHints(): void
    {
        $pay = $this->listToolOf('customerPaymentList', 'unallocated: Float voided: Boolean')
            ->withQueryable(['id', 'unallocated', 'voided'])->description();
        $this->assertStringContainsString('{"unallocated": {"$gt": 0}, "voided": false}', $pay);
        $delivery = $this->listToolOf('deliveryList', 'voided: Boolean');
        $named = $delivery->withQueryable(['id', 'voided']);
        $this->assertStringContainsString('{"voided": false}', $named->description());
        $this->assertStringNotContainsString('{"voided": false}', $delivery->description());
    }

    public function testBankTransactionListSaysHowToFindWhatToReconcile(): void
    {
        $tool = $this->listToolOf(
            'bankTransactionList',
            'bankAccountId: ID reconciled: String voided: Boolean date: String'
        );
        $text = $tool->withQueryable(['id', 'bankAccountId', 'reconciled', 'voided', 'date'])->description();

        $this->assertStringContainsString(
            'Unreconciled up to a statement: selector {"bankAccountId": "<account>", "reconciled": null, "voided":'
            . ' false, "date": {"$lte": "<statement date>"}}. A statement\'s entries: {"reconciled": "<statement'
            . ' date>"}.',
            $text
        );
        $this->assertStringNotContainsString('Unreconciled', $tool->withQueryable(['id', 'date'])->description());
    }

    public function testACountToolCarriesNoHint(): void
    {
        $count = $this->tool('customer_count')->withQueryable(['id']);
        $this->assertStringNotContainsString('Unpaid', $count->description());
    }

    public function testAuthFailurePropagates(): void
    {
        $this->expectException(AuthFailure::class);
        $this->tool('me')->call(
            [],
            FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'expired'))
        );
    }
}
