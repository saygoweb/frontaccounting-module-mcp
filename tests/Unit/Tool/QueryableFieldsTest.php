<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\QueryableFields;
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
