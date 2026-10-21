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
        . " and `sort`. A default limit applies when none is given: page with `skip` for more.\n"
        . "- Most tools take `fields`, a GraphQL selection set, to ask for fields beyond the default ones. The"
        . " default leaves out lists of nested records (an invoice's or order's `lines`, a customer's"
        . " `branches`); a tool's description names those it leaves out.\n"
        . "- Deletes follow FrontAccounting's rules: a posted transaction is voided or cancelled, not removed,"
        . " and FrontAccounting refuses what it would refuse in its own screens; its messages come back in"
        . " the tool result.\n"
        . "- When listed, graphql_schema, graphql_query and graphql_mutate work with GraphQL directly, for"
        . " what no other tool covers.";
}
