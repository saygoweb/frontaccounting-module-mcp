<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\GraphQlGateway;
use GraphQL\Type\Schema;

/**
 * The fields each list's Mango `query` can filter (`selector`) and sort on,
 * for the list tools' descriptions, as graphql itself reports them.
 *
 * graphql (anorm-graphql) accepts the properties of a row's model, its stored
 * columns, and the schema does not tell those apart from computed fields (an
 * invoice's `total`, `outstanding` and `voided`, a payment's `memo`): graphql
 * gives every field a resolver of its own. What graphql does say is in the
 * BAD_INPUT error for an unknown sort field, whose message ends "The sortable
 * fields are: a, b, c"; its selector check takes the same fields. So each
 * list is asked for a sort on a field no list has, and the fields are read
 * back from the error. One document per list: the lists are non-null, so the
 * first error ends a document. A list whose answer says anything else (not
 * allowed to the user, a changed message) gets no fields, and its tool's
 * description names none.
 *
 * Newer graphql says it outright: each list's `query` argument is described
 * "Fields for selector and sort: a, b, c. Only these fields ...", computed
 * fields included. fromSchema() reads that, and probes only a list whose
 * description lacks the sentence (an older graphql).
 */
final class QueryableFields
{
    /** No list has a field of this name, and none can: graphql refuses it with the list. */
    private const NO_FIELD = '?';
    private const LISTED = '/Fields for selector and sort: (\w+(?:, \w+)*)/';
    private const SORTABLE = '/The sortable fields are: ([^\r\n]+)$/';

    /**
     * @param string[] $listFields root query fields that take a Mango `query`
     * @return array<string, string[]> list field => its queryable fields, as graphql orders them
     */
    public static function probe(GraphQlGateway $gql, array $listFields): array
    {
        $fields = [];
        foreach ($listFields as $listField) {
            $names = self::fromEnvelope($gql->execute(self::document($listField)));
            if ($names !== []) {
                $fields[$listField] = $names;
            }
        }
        return $fields;
    }

    /**
     * The fields from each list's `query` argument description in $schema; the
     * probe, for a list whose description does not list them.
     *
     * @param string[] $listFields root query fields that take a Mango `query`
     * @return array<string, string[]> list field => its queryable fields
     */
    public static function fromSchema(GraphQlGateway $gql, Schema $schema, array $listFields): array
    {
        $fields = [];
        $unlisted = [];
        $query = $schema->getQueryType();
        foreach ($listFields as $listField) {
            $arg = $query !== null && $query->hasField($listField)
                ? $query->getField($listField)->getArg('query')
                : null;
            $names = $arg === null ? [] : self::fromDescription((string)$arg->description);
            if ($names === []) {
                $unlisted[] = $listField;
            } else {
                $fields[$listField] = $names;
            }
        }
        $probed = self::probe($gql, $unlisted);
        $ordered = [];
        foreach ($listFields as $listField) {
            if (isset($fields[$listField]) || isset($probed[$listField])) {
                $ordered[$listField] = $fields[$listField] ?? $probed[$listField];
            }
        }
        return $ordered;
    }

    /** @return string[] the names after "Fields for selector and sort: ", or [] */
    public static function fromDescription(string $description): array
    {
        return preg_match(self::LISTED, $description, $m) ? explode(', ', $m[1]) : [];
    }

    /** $listField sorted on NO_FIELD. */
    public static function document(string $listField): string
    {
        return '{ ' . $listField . '(query: {sort: ["' . self::NO_FIELD . '"], limit: 1}) { __typename } }';
    }

    /** @return string[] the fields a BAD_INPUT error lists, or [] */
    public static function fromEnvelope(array $envelope): array
    {
        foreach ((array)($envelope['errors'] ?? []) as $error) {
            if (
                ($error['extensions']['code'] ?? null) === 'BAD_INPUT'
                && preg_match(self::SORTABLE, (string)($error['message'] ?? ''), $sortable)
            ) {
                return explode(', ', $sortable[1]);
            }
        }
        return [];
    }
}
