# Changelog

## Unreleased

- `bank_transaction_list` hint for reconciliation; `bank_reconcile` is idempotent and destructive;
  CONNECTING.md: reconciling a bank statement.

## 1.1.1 - 2026-10-22

- `invoice_list`, `customer_payment_list` and `delivery_list` descriptions say how
  to ask for unpaid invoices (`{"outstanding": {"$gt": 0}, "voided": false}`,
  newest first with `sort: ["-date"]`, overdue with `dueDate`), unallocated
  payments and non-voided deliveries. A hint appears only when the list's
  queryable fields include the ones it names, so an older graphql is described
  as it is.
- The OAuth HTTP test tolerates clock ticks in `expires_in` (#15).

## 1.1.0 - 2026-10-22

- The fields each list's selector and sort take are read from the `query`
  argument's description in the GraphQL schema ("Fields for selector and sort:
  ..."), so computed fields are included and tools/list no longer sends a probe
  request per list. A list whose description lacks the sentence (an older
  graphql) is still probed, for that list only.

## 1.0.0 - 2026-10-21

- Versioned releases: pushing a `v<semver>` tag publishes the packaged module as a
  GitHub release; activating the module refreshes its stored version.
- MCP server over HTTP at `modules/mcp/` (JSON-RPC `POST /`), a client of the
  GraphQL module's in-process Executor.
- Tools generated from the caller's GraphQL schema, one per root field, with
  `query`/`selector`/`limit`, an optional `fields` selection, annotations, and
  the `graphql_schema`, `graphql_query` and `graphql_mutate` escape hatch.
- The default selection leaves out lists of nested objects (an invoice's
  `lines`, a customer's `branches` and `contacts`), and each tool's description
  names what it leaves out, ready to paste into `fields`.
- A list that returns exactly `limit` rows, the default limit or the caller's,
  carries a note with the total ("Rows 1–50 of 312"), from graphql's
  `<entity>Count` for the same selector, and the `query.skip` of the next page;
  without a count it says more may exist.
- A `*_count` tool for each `<entity>Count`, described by the list it counts
  (the same selector fields); `limit`, `skip` and `sort` are accepted and
  ignored. A Mango field that returns no list gets no default limit.
- The `query` schema describes `limit`, `skip` and `sort` (`["-date"]` for
  newest first), each list tool's description names the fields its selector
  and sort take (as graphql reports them), and the instructions say how to
  sort and that a `BAD_INPUT` message lists the valid fields.
- Read-only and read-and-change connections; read-only hides and refuses
  mutations.
- Bearer authentication with any GraphQL access or machine token.
- OAuth 2.1 with PKCE: protected-resource and authorization-server metadata,
  dynamic client registration, Client ID Metadata Documents, sign-in and
  consent pages, authorization code and rotating refresh tokens with reuse
  detection, and an optional root rewrite for RFC 8414 metadata.
- Origin guard, CORS, TLS requirement, stray-output capture.
- Activation checks that the GraphQL module is active and creates the OAuth
  tables, and warns when OAuth is served but FrontAccounting's root `.htaccess`
  lacks the root rewrite rule.
- CI on the FrontAccounting image (cp and upstream, PHP 7.4 and 8.3), Codecov
  coverage and README badges.
