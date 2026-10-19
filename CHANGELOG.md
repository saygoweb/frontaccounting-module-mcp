# Changelog

## Unreleased - 1.0.0

- MCP server over HTTP at `modules/mcp/` (JSON-RPC `POST /`), a client of the
  GraphQL module's in-process Executor.
- Tools generated from the caller's GraphQL schema, one per root field, with
  `query`/`selector`/`limit`, an optional `fields` selection, annotations, and
  the `graphql_schema`, `graphql_query` and `graphql_mutate` escape hatch.
- Read-only and read-and-change connections; read-only hides and refuses
  mutations.
- Bearer authentication with any GraphQL access or machine token.
- OAuth 2.1 with PKCE: protected-resource and authorization-server metadata,
  dynamic client registration, Client ID Metadata Documents, sign-in and
  consent pages, authorization code and rotating refresh tokens with reuse
  detection, and an optional root rewrite for RFC 8414 metadata.
- Origin guard, CORS, TLS requirement, stray-output capture.
- Activation checks that the GraphQL module is active and creates the OAuth
  tables.
- CI on the FrontAccounting image (cp and upstream, PHP 7.4 and 8.3), Codecov
  coverage and README badges.
- Temporary: CI's coverage job uses FrontAccounting's `ci/plugin-coverage`
  branch; switch it to `master-cp` once the shared coverage input is merged.
  The GraphQL module is taken from `main`, which has Executor 1.0.
