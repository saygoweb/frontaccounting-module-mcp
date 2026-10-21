<?php

namespace FA\Mcp\Protocol;

/** The `instructions` sent with initialize and server/discover (spec §6.1). */
final class Instructions
{
    public const TEXT = "FrontAccounting accounting and sales, through its GraphQL API.\n\n"
        . "- You act as one FrontAccounting user in one company: the one the token was issued for. What you"
        . " can see and change is what that user's role allows; a read-only connection lists no tools that"
        . " change data.\n"
        . "- IDs (customers, branches, items, orders, invoices, ...) come from the *_list tools. Look a record"
        . " up before you use its ID; never guess one.\n"
        . "- The list tools take a Mango `query`: `selector` (field conditions to filter by), `limit`, `skip`"
        . " and `sort` (`sort: [\"-date\"]` is newest first; `-` before a field sorts descending). Each list"
        . " tool's description names the fields it can filter and sort on; any other is a BAD_INPUT error"
        . " whose message lists them. A default limit applies when none is given. A result with as many rows"
        . " as the limit has a `note` with the total (\"Rows 1–50 of 312\") where it can be counted, and the"
        . " `skip` for the next page: a full page is not the whole list. To ask only how many, a *_count tool"
        . " beside a list takes the same `query`.\n"
        . "- Most tools take `fields`, a GraphQL selection set, to ask for fields beyond the default ones. The"
        . " default leaves out lists of nested records (an invoice's or order's `lines`, a customer's"
        . " `branches`); a tool's description names those it leaves out.\n"
        . "- Deletes follow FrontAccounting's rules: a posted transaction is voided or cancelled, not removed,"
        . " and FrontAccounting refuses what it would refuse in its own screens; its messages come back in"
        . " the tool result.\n"
        . "- When listed, graphql_schema, graphql_query and graphql_mutate work with GraphQL directly, for"
        . " what no other tool covers.";
}
