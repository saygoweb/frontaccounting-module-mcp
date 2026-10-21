<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\QueryableFields;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class QueryableFieldsTest extends TestCase
{
    private static function badSort(string $fields): array
    {
        return ['data' => null, 'errors' => [[
            'message' => "Argument 'query.sort' item '?' names an unknown field '?'. The sortable fields are: "
                . $fields,
            'path' => ['invoiceList'],
            'extensions' => ['code' => 'BAD_INPUT'],
        ]]];
    }

    private static function schema(string $customerDescription, string $invoiceDescription = ''): \GraphQL\Type\Schema
    {
        $arg = function (string $d): string {
            return $d === '' ? '' : '"""' . $d . '"""';
        };
        return BuildSchema::build('input MangoInput { limit: Int } type Row { id: ID }
            type Query {
              customerList(' . $arg($customerDescription) . ' query: MangoInput): [Row!]!
              invoiceList(' . $arg($invoiceDescription) . ' query: MangoInput): [Row!]!
            }');
    }

    public function testFieldsAreReadFromTheQueryDescriptionWithoutAProbe(): void
    {
        $gql = new FakeGraphQlGateway();
        $schema = self::schema(
            'Fields for selector and sort: id, name, outstanding. Only these fields are accepted.',
            'Fields for selector and sort: id, total. Only these fields are accepted.'
        );
        $this->assertSame(
            ['customerList' => ['id', 'name', 'outstanding'], 'invoiceList' => ['id', 'total']],
            QueryableFields::fromSchema($gql, $schema, ['customerList', 'invoiceList'])
        );
        $this->assertSame([], $gql->calls);
    }

    public function testAListWithoutTheSentenceFallsBackToTheProbeAlone(): void
    {
        $gql = (new FakeGraphQlGateway())->on('{ invoiceList(', self::badSort('amount, id'));
        $schema = self::schema('Fields for selector and sort: id, name. Only these.', 'Some other text.');
        $this->assertSame(
            ['customerList' => ['id', 'name'], 'invoiceList' => ['amount', 'id']],
            QueryableFields::fromSchema($gql, $schema, ['customerList', 'invoiceList'])
        );
        $this->assertCount(1, $gql->calls);
        $this->assertStringContainsString('{ invoiceList(', $gql->calls[0]['document']);
    }

    public function testTrailingTextAddsNoNames(): void
    {
        $this->assertSame(
            ['a', 'b_2', 'c'],
            QueryableFields::fromDescription(
                "Fields for selector and sort: a, b_2, c. Only these fields are accepted; any other, "
                . "a computed field included, is BAD_INPUT."
            )
        );
        $this->assertSame(['a'], QueryableFields::fromDescription('Fields for selector and sort: a'));
        $this->assertSame([], QueryableFields::fromDescription('Nothing here'));
    }

    public function testAListIsSortedOnAFieldNoListHas(): void
    {
        $this->assertSame(
            '{ invoiceList(query: {sort: ["?"], limit: 1}) { __typename } }',
            QueryableFields::document('invoiceList')
        );
    }

    public function testEachListsFieldsAreReadFromItsBadInputMessage(): void
    {
        $gql = (new FakeGraphQlGateway(['data' => ['salesAreaList' => []]]))
            ->on('{ invoiceList(', self::badSort('amount, date, id'))
            ->on('{ customerList(', self::badSort('id, name'));
        $this->assertSame(
            ['invoiceList' => ['amount', 'date', 'id'], 'customerList' => ['id', 'name']],
            QueryableFields::probe($gql, ['invoiceList', 'salesAreaList', 'customerList'])
        );
        $this->assertCount(3, $gql->calls);
    }

    /** @dataProvider noFields */
    public function testAnAnswerSayingAnythingElseGivesNoFields(array $envelope): void
    {
        $this->assertSame([], QueryableFields::fromEnvelope($envelope));
    }

    public static function noFields(): array
    {
        $listing = 'The sortable fields are: x';
        return [
            'rows' => [['data' => ['invoiceList' => []]]],
            'forbidden' => [['errors' => [['message' => $listing, 'extensions' => ['code' => 'FORBIDDEN']]]]],
            'no code' => [['errors' => [['message' => $listing]]]],
            'another message' => [['errors' => [['message' => 'Bad.', 'extensions' => ['code' => 'BAD_INPUT']]]]],
        ];
    }

    public function testNoListsNoCall(): void
    {
        $gql = new FakeGraphQlGateway();
        $this->assertSame([], QueryableFields::probe($gql, []));
        $this->assertSame([], $gql->calls);
    }
}
