# FrontAccounting MCP module

[![CI](https://github.com/saygoweb/frontaccounting-module-mcp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/saygoweb/frontaccounting-module-mcp/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/saygoweb/frontaccounting-module-mcp/branch/main/graph/badge.svg)](https://codecov.io/gh/saygoweb/frontaccounting-module-mcp)

A FrontAccounting module that serves FrontAccounting, through the
[GraphQL module](https://github.com/saygoweb/frontaccounting-module-graphql),
as a remote [MCP](https://modelcontextprotocol.io) server. Claude.ai (a custom
connector) and Claude Code (`claude mcp add --transport http`) can then read
and change customers, sales orders, deliveries, invoices, payments and
whatever extensions add, as a real FrontAccounting user.

Every tool call goes through the GraphQL module: token verification, the
`SA_GRAPHQL` check, FrontAccounting's security areas, depth and complexity
limits and error formatting. MCP is a client of the API, never a way around it.

The tool list is generated from the GraphQL schema of the caller's company, so
it looks like this (names are examples):

```
customer_list, customer_get, customer_create, customer_update, ...
sales_order_list, sales_order_create, ...
recurring_due_list, recurring_generate     (only where sgw_sales is active)
graphql_schema, graphql_query, graphql_mutate
```

## Requirements

- FrontAccounting 2.4 (the `cp` fork or upstream), PHP 7.4 to 8.3.
- The GraphQL module, with the in-process Executor (contract 1.x) active for
  the company.
- A FrontAccounting role with the *GraphQL API access* area (`SA_GRAPHQL`) for
  every user who connects.

## Install

1. Clone this repository to `modules/mcp` in your FrontAccounting directory.
2. Run `composer install --no-dev` in `modules/mcp`.
3. In FrontAccounting, open Setup > Install/Activate Extensions. Activate
   GraphQL first, then MCP. (Activating both in one form submission fails for
   MCP; the error says to activate GraphQL first.)
4. Optional for bearer-only use, required for OAuth: copy
   `config_mcp.example.php` to `config_mcp.php` and set `base_url`, for example
   `https://fa.example.com/modules/mcp`.

The endpoint is `https://fa.example.com/modules/mcp/`. If the GraphQL module
is missing or has the wrong contract version, the endpoint answers `503`.

## Connecting

Claude Code with a machine token, Claude Code with OAuth and the Claude.ai
custom connector are described in [docs/CONNECTING.md](docs/CONNECTING.md).

```
claude mcp add --transport http fa https://fa.example.com/modules/mcp/ \
  --header "Authorization: Bearer <graphql access or machine token>"
```

## Tools

- **One tool per GraphQL root field**, named in snake_case: `customerList`
  becomes `customer_list`, `salesOrderCreate` becomes `sales_order_create`.
  `login`, `tokenRefresh`, `tokenRevoke` and `apiVersion` are not exposed.
- **Arguments** come from the field's GraphQL arguments. List tools take
  `query` and, inside it, `selector` (a JSON object, or its JSON-encoded
  string), `limit` and `skip`. When `query.limit` is missing, `default_list_limit`
  (50) is applied. When a list returns exactly `limit` rows (the default or the
  caller's), the result's `note` says more may exist and gives the next page's
  `query.skip` (`skip` + `limit`). It gives no total: the GraphQL lists don't
  count their rows yet.
- **`query.sort`** is graphql's: each item is a field, ascending, or `-field`
  (or `field desc`) for descending, so `sort: ["-date"]` is newest first. Each
  list tool's description names the fields its `selector` and `sort` take,
  e.g. "Fields for `query.selector` and `query.sort`: id, transType, ...".
  The schema doesn't say which fields those are (computed ones such as
  an invoice's `outstanding` are refused), so on `tools/list` each list is
  asked for a sort on a field none has, and the fields are read from the
  `BAD_INPUT` error graphql answers with: one in-process request per list.
  The same error, verbatim, is what a model gets for a field it guessed wrong.
- **`fields`** (optional string) replaces the default selection set with a
  GraphQL selection body. By default every scalar and enum field is returned
  (lists of them too, such as an invoice's `deliveryIds`), plus the scalars of
  each single nested object one level deep (a customer's `balance`). Lists of
  nested objects (an invoice's `lines`, a customer's `branches` and
  `contacts`) are left out, as they are what makes a page of rows large; the
  tool's description names each one with its scalars, e.g. "Not returned
  unless asked for in `fields`: `lines { id invoiceId ... }`". The result must
  stay one operation on the tool's own field.
- **Annotations** tell the client what a tool does: `*List`, `me` and
  `recurringDueList` are `readOnlyHint`; `*Delete` is `destructiveHint`;
  `*Update` is `idempotentHint`; `invoiceEmail` and `recurringGenerate` are
  `openWorldHint` (they send e-mail).
- **Escape hatch** (`escape_hatch`, on by default): `graphql_schema` prints
  the SDL of the caller's schema, `graphql_query` runs a read-only document and
  `graphql_mutate` runs a mutation.
- **Read-only connections** (scope `read`) do not see tools that change data
  (`tools/list` hides them and `tools/call` refuses them). The scope is read
  from the token and fails closed: anything but an explicit `write` is `read`.

FrontAccounting's security areas are not filtered from the list; a refusal at
call time comes back as an error result "FORBIDDEN: ...".

## Routes

All paths are under `base_url`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/` (or `/index.php`) | MCP JSON-RPC |
| GET, DELETE | `/` | `405` (no event stream, no sessions) |
| OPTIONS | `/`, `/oauth/token`, `/oauth/register` | CORS preflight |
| GET | `/.well-known/oauth-protected-resource` | RFC 9728 metadata |
| GET | `/.well-known/oauth-authorization-server`, `/.well-known/openid-configuration` | RFC 8414 metadata |
| GET, POST | `/oauth/authorize` | Sign in and consent |
| POST | `/oauth/token` | Authorization code and refresh grants |
| POST | `/oauth/register` | RFC 7591 dynamic client registration (`allow_dcr`) |

The OAuth routes exist only when `oauth` is on and `base_url` is set. The
module's `.htaccess` sends only `index.php` to PHP: the module's `vendor/`,
`src/`, `sql/`, `hooks.php` and `config_mcp.php` answer `403`, and any other
file answers `404`.

A plain `POST /` without a token answers `401` with
`WWW-Authenticate: Bearer resource_metadata="<base_url>/.well-known/oauth-protected-resource"`.
A role without the GraphQL area answers `403 {"error":"NO_API_ACCESS"}`, and
plain HTTP answers `403 {"error":"TLS_REQUIRED"}` (the OAuth endpoints answer
`400 invalid_request`).

## OAuth and the root rewrite rule

OAuth 2.1 with PKCE (S256) is built in: dynamic client registration and Client
ID Metadata Documents, a sign-in page (company, FrontAccounting user, password)
and a consent page where the user picks *Read-only* or *Read and change*. Each
connection is one GraphQL machine token labelled "Claude (MCP) - <client>"
that `bin/fa-token revoke` ends at once. Sign-in uses a signed cookie of the
module's own and never creates a FrontAccounting login session.

The issuer is `base_url`. RFC 8414 puts the metadata of an issuer with a path
at the origin root (`/.well-known/oauth-authorization-server/modules/mcp`),
which a module cannot serve by itself. The module serves the path-appended
forms too, but some clients (Claude Code among them) ask only at the root: when
they find nothing there they fall back to `POST /register` at the origin, which
the web server answers `404`. So add this rule to FrontAccounting's root
`.htaccess` (with `RewriteEngine On` above it if the file has none):

```
RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)/modules/mcp/?$ modules/mcp/.well-known/$1 [L]
```

Activating the module warns when OAuth is served (and FrontAccounting is the
origin root) but the root `.htaccess` has no such rule. On a web server other
than Apache, route those three root paths to the module the same way.

## Configuration

`config_mcp.php`, copied from `config_mcp.example.php`. Every key is optional.
With no `config_mcp.php` the defaults apply and OAuth is off (no `base_url`):
bearer-only use works out of the box.

| Key | Default | Meaning |
|---|---|---|
| `base_url` | `''` | Canonical public URL of the module; required for OAuth |
| `oauth` | `true` | Serve the OAuth endpoints and metadata (only when `base_url` is set) |
| `require_tls` | `true` | Refuse plain HTTP (graphql's `trust_proxy` decides forwarded HTTPS) |
| `allowed_origins` | `['https://claude.ai']` | Origins accepted besides `base_url`'s own |
| `access_token_ttl` | `3600` | Seconds |
| `refresh_token_ttl` | `2592000` | Seconds (30 days) |
| `default_list_limit` | `50` | Injected `query.limit` |
| `escape_hatch` | `true` | List the `graphql_*` tools |
| `allow_dcr` | `true` | Offer RFC 7591 registration |
| `allow_cimd` | `true` | Accept Client ID Metadata Documents |
| `cimd_cache_ttl` | `86400` | Seconds |
| `dcr_rate_limit` | `10` | Registrations (and new CIMD lookups) per source IP per hour |
| `debug` | `false` | Exception messages in error responses |

## Development

A development environment (FrontAccounting, MariaDB, Apache, the GraphQL
module) comes from FrontAccounting's CI package:

```
docker/ci/plugin-dev.sh --env mcp up
```

(in `cambell-prince/frontaccounting`; `docker/ci/dev/mcp.env` describes it).
The full gate runs inside the CI image, from the FrontAccounting checkout:

```
docker/ci/plugin-test.sh --dataset demo \
  --with sgw_sales=<path to sgw_sales> --with graphql=<path to graphql> \
  --setup "composer install --no-interaction --no-progress" \
  <path to this module> -- 'sh tools/ci.sh'
```

`tools/ci.sh` runs lint, phpcs, phpstan, then the unit, integration and http
suites. `sh tools/coverage.sh` in the same way writes `build/coverage.xml`
(unit and integration suites only; the http suite runs in Apache).

CI (`.github/workflows/ci.yml`) runs that on FrontAccounting cp and upstream
with PHP 7.4 and 8.3, with the GraphQL module (Executor 1.0) from its `main`,
and uploads coverage to Codecov from one cell through the reusable workflow's
`coverage` input.

## License

GPL-3.0, see [LICENSE](LICENSE).
