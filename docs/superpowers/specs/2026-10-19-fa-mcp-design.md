# FrontAccounting MCP server (`modules/mcp`)

- **Date:** 2026-10-19
- **Status:** Design approved in conversation; awaiting written-spec review
- **Repos touched:**
  - `saygoweb/frontaccounting-module-mcp` (new, this repo, at `modules/mcp`)
  - `saygoweb/frontaccounting-module-graphql` (`modules/graphql`): a supported
    in-process `Executor` and a token scope claim, minor release
  - `cambell-prince/frontaccounting` (`master-cp`): a dev environment file
    (`docker/ci/dev/mcp.env`); optionally a shared Codecov input on the reusable
    `plugin-test.yml` (§12)
- **Prior art:** the i-MSCP MCP plugin (`/home/cambell/src/sgw/imscp-mcp`,
  branch `feature/mcp-server`, spec `docs/superpowers/specs/2026-10-19-imscp-mcp-design.md`).
  Its protocol layer is ported; its lessons are cited below as *imscp (x)*.

## 1. Purpose and success criteria

A FrontAccounting module that exposes FA, through the GraphQL module, as a remote
MCP server, so that Claude.ai (custom connector) and Claude Code
(`claude mcp add --transport http`) can read and drive FrontAccounting —
customers, sales orders, deliveries, invoices, payments, and whatever extensions
add — as a real FA user.

Success means:

1. A user adds `https://<fa>/modules/mcp/` as a Claude.ai connector, signs in
   with company, FA user name and password, picks read-only or read-and-change,
   and can then list and change FA records from a Claude conversation.
2. The same URL works in Claude Code, either through OAuth or with any GraphQL
   module token (access or machine) as an `Authorization: Bearer` header.
3. Every tool call goes through the GraphQL module's token verification,
   `SA_GRAPHQL` check, FA security areas, depth and complexity limits and error
   formatting. MCP is a client of the API, never a way around it.
4. It installs and activates like any FA extension, refuses to activate without
   the GraphQL module, and needs no web-server change beyond the module's own
   `.htaccess` (plus one optional root rewrite, §5.1).
5. CI runs the module's suites on FA cp/upstream × PHP 7.4/8.3, reports coverage
   to codecov.io, and the README shows build and coverage badges.

## 2. Decisions

| # | Decision | Reason |
|---|---|---|
| D1 | Runs on **PHP 7.4 to 8.3**, Composer platform 7.4.33, like graphql. | FA installs run 7.4. The official `mcp/sdk` and `php-mcp/server` need PHP ≥ 8.1. |
| D2 | **Hand-written MCP protocol** behind a `ProtocolServer` interface, ported from imscp-mcp. | Small surface (stateless Streamable HTTP, JSON-RPC, tools only); the interface lets `mcp/sdk` replace it after a PHP 8 move. |
| D3 | Clients: **Claude Code with a bearer token, and Claude.ai through OAuth 2.1.** | Claude.ai connectors require OAuth; Claude Code accepts either. |
| D4 | Reach GraphQL through a **new supported in-process API in the graphql module** (`FA\GraphQL\Api\Executor`). | Same limits and errors as HTTP, no loopback round trip, a stable seam. graphql's internals stay private. |
| D5 | **Tools generated at runtime from the company's live schema**, one per root field, plus a GraphQL escape hatch. | The schema is small (~23 queries, ~22 mutations) and per company (extensions add fields); generation never drifts. |
| D6 | **Read/write scope as a token claim enforced by graphql.** | A read-only connection stays read-only even if its token leaks. |
| D7 | **OAuth access tokens are graphql tokens** of the stored, revocable machine kind. | One token system: `bin/fa-token list/revoke` covers MCP connections. |
| D8 | **Stateless Streamable HTTP**: one JSON response per POST, no SSE, no sessions. | Simplest model both clients accept; suits Apache/php-fpm. |
| D9 | **No stdio transport, no resources or prompts.** | YAGNI: Claude Code uses HTTP with a bearer. |

## 3. Architecture

```
Claude.ai / Claude Code
        │ HTTPS
        ▼
Apache ── modules/mcp/.htaccess (all paths → index.php)
        │
        ▼
modules/mcp/index.php (Slim 4)
   ├── /                      Http/McpAction → Protocol/ → Tool/ ─┐
   ├── /.well-known/*         OAuth/Http/MetadataAction           │
   └── /oauth/*               OAuth/Http/{Authorize,Token,Register}Action
                                       │                          │
                              Gateway/AccountGateway     Gateway/GraphQlGateway
                                       └──────────┬───────────────┘
                                                  ▼ in-process
                              modules/graphql  FA\GraphQL\Api\Executor
                              (token verify, SA_GRAPHQL, FA session, schema,
                               depth/complexity, execute, ErrorFormatter)
                                                  ▼
                                         FrontAccounting
```

mcp has exactly two seams to graphql, `GraphQlGateway` (run documents, schema)
and `AccountGateway` (companies, authenticate, issue/revoke tokens), so all other
code is unit-testable with in-memory doubles *imscp (i)*.

### 3.1 Module layout (`modules/mcp`)

Follows graphql's house layout. PSR-4 `FA\Mcp\` → `src/`.

```
hooks.php            hooks_mcp: activate_extension (tables, graphql check); no security areas of its own
index.php            front controller: graphql autoloader, own autoloader, output capture, Slim app
app.php, container.php
.htaccess            mod_rewrite: everything not a real file → index.php; deny src/, tests/, vendor/, sql/, tools/
config_mcp.example.php
sql/update_1.0.sql   OAuth tables (§9)
src/Http/            McpAction, Middleware (HeaderNormaliser, Tls, Origin, Cors, Bearer), JsonErrorMiddleware
src/Protocol/        ProtocolServer, McpServer, Versions, RpcError, HttpOutcome (ported)
src/Tool/            Tool, ToolResult, ToolCatalog, SchemaToolFactory, SelectionBuilder,
                     JsonSchemaMapper, InputValidator, EscapeHatch/{Schema,Query,Mutate}
src/OAuth/           Metadata, Pkce, RedirectUriPolicy, ClientResolver, CimdFetcher, CodeService,
                     RefreshService, Store (PDO), InMemoryStore; Http/{Authorize,Token,Register,Metadata}Action
src/OAuth/Page/      login and consent templates (self-contained HTML, no FA theme)
src/Gateway/         GraphQlGateway, AccountGateway (interfaces), ExecutorGraphQlGateway, ExecutorAccountGateway
src/Config.php
tests/{Unit,Integration,Http,Support}
tools/ci.sh, tools/coverage.sh, tools/init.sh
docs/CONNECTING.md   Claude.ai and Claude Code how-to
composer.json        php ^7.4||^8.0; ext-json, ext-pdo; slim/slim ^4.11, slim/psr7, php-di ^6
                     dev: phpunit ^9.6, phpcs, phpstan; webonyx/graphql-php (dev only, see §3.2)
.github/workflows/ci.yml, codecov.yml
README.md (badges), LICENSE (GPL-3.0-or-later, as graphql)
```

### 3.2 Depending on graphql

FA has no way to declare one extension's dependency on another, so it is
enforced in four places:

- **Activation:** `hooks_mcp::activate_extension()` refuses (FA `display_error`
  and `false`) unless graphql is installed and active for the company and
  `FA\GraphQL\Api\Executor::CONTRACT_VERSION` has major `1`.
- **Runtime:** `index.php` requires `../graphql/vendor/autoload.php` before its
  own (the sgw_sales pattern), so one copy of webonyx, Slim and PHP-DI is loaded
  and graphql's wins. If graphql or `Executor` is missing or the contract major
  differs, every route answers `503 text/plain`
  "FrontAccounting MCP needs the GraphQL module (Executor contract 1.x), active".
- **Composer:** no runtime dependency on graphql's package. webonyx is a dev
  dependency only (unit tests parse fixture SDL), kept compatible with graphql's
  lock (`^15.32`). Slim, PSR-7 and PHP-DI use graphql's constraints so either
  autoloader can satisfy them.
- **CI and dev:** `--with graphql=…` and `--with sgw_sales=…`; a new
  `docker/ci/dev/mcp.env` in the FA repo with `FA_DEV_MODULES="sgw_sales graphql mcp"`
  and its own port.

## 4. graphql module additions (Executor contract 1.0)

A new documented entry point, added to graphql's README and changelog. The
logic now in `GraphQLAction` (validation rules, `Warnings`, `ErrorFormatter`)
and in the auth/session middleware is factored so that the HTTP path and
`Executor` share one implementation; HTTP behaviour does not change.

```php
namespace FA\GraphQL\Api;

final class Executor
{
    public const CONTRACT_VERSION = '1.0';

    /** Builds from config_graphql.php (or a given Config) and the FA root. */
    public static function create(?Config $config = null): self;

    /**
     * Run one document as the holder of $bearer, exactly as POST /modules/graphql/:
     * Authenticator (signature, expiry, machine-token row), company open, FA login,
     * SA_GRAPHQL, scope (a `read` token may not run a mutation: FORBIDDEN),
     * depth/complexity, execute, ErrorFormatter, FA warnings in extensions.
     * @return array{data?: array|null, errors?: array, extensions?: array}
     * @throws ExecutorAuthException getReason(): UNAUTHENTICATED (HTTP 401) | FORBIDDEN (HTTP 403, no SA_GRAPHQL)
     */
    public function execute(string $bearer, string $document, array $variables,
                            string $clientIp, ?string $operationName = null): array;

    /** The bearer's effective schema (core + extensions active in its company). Same auth as execute(). */
    public function schema(string $bearer): \GraphQL\Type\Schema;

    /** [companyId => name] from config_db.php, for the OAuth login form. */
    public function companies(): array;

    /** FA's own password check (current_user::login, login_delay applies), then SA_GRAPHQL. */
    public function authenticate(int $company, string $login, string $password): string; // 'OK' | 'BAD_CREDENTIALS' | 'NO_API_ACCESS'

    /**
     * Issue a stored, revocable token (graphql_machine_token row) for a user who
     * has already been authenticated. $scope is 'read' or 'write'.
     * @return array{token: string, jti: string, expiresAt: int}
     */
    public function issueToken(int $company, string $login, string $scope, int $ttlSeconds, string $label): array;

    public function revokeToken(int $company, string $jti): bool;

    /** True when the row is missing or revoked. Expiry is ignored *imscp (b)*. */
    public function tokenIsRevoked(int $company, string $jti): bool;
}
```

- One `Executor` call handles one company. FA's global state allows one
  company per PHP process, which under Apache is one request; an MCP request
  carries one bearer, and an OAuth request one company, so this holds.
- **Scope claim.** graphql JWTs gain an optional `scp` claim, `read` or `write`.
  Absent means `write`, so every existing token keeps working. A `read` token
  running a mutation gets `FORBIDDEN` "This token is read-only" on the HTTP path
  and in `Executor`. `me` gains a `scope: String!` field. `bin/fa-token issue`
  gains `--read-only`, and `list` shows the scope. `graphql_machine_token` gains
  `scope varchar(8) NOT NULL DEFAULT 'write'` (`update_1.2.sql`); its `label`
  holds the connection name.
- **Tests in graphql:** Executor parity with HTTP (same envelope for the same
  document), both auth reasons, `read` refused on a mutation (HTTP and Executor),
  `me { scope }`, `issueToken`/`revokeToken`/`tokenIsRevoked`, `authenticate`
  outcomes.

## 5. Authentication and authorization

### 5.1 URLs

`base_url` (config) is the module's canonical public URL, e.g.
`https://fa.example.com/modules/mcp`. It is never derived from `Host`
*imscp (e)*; it is required when OAuth is enabled.

| Method | Path (under `base_url`) | Purpose |
|---|---|---|
| POST | `/` | MCP JSON-RPC |
| GET, DELETE | `/` | `405` (no SSE stream, no sessions) |
| OPTIONS | `/`, `/oauth/token`, `/oauth/register` | CORS preflight |
| GET | `/.well-known/oauth-protected-resource` | RFC 9728 metadata |
| GET | `/.well-known/oauth-authorization-server`, `/.well-known/openid-configuration` | RFC 8414 metadata |
| GET, POST | `/oauth/authorize` | login and consent |
| POST | `/oauth/token` | code and refresh grants |
| POST | `/oauth/register` | RFC 7591 dynamic client registration |

The issuer is `base_url`. RFC 8414 puts metadata for an issuer with a path at
the origin root (`/.well-known/oauth-authorization-server/modules/mcp`), which a
module cannot serve by itself. The module serves the path-appended forms MCP
clients also try, and the README gives this optional rule for FA's root
`.htaccess`, which the http test suite installs and exercises:

```
RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)/modules/mcp/?$ modules/mcp/.well-known/$1 [L]
```

### 5.2 Claude Code with a token made by hand

```
claude mcp add --transport http fa https://fa.example.com/modules/mcp/ \
  --header "Authorization: Bearer <graphql access or machine token>"
```

A machine token from `bin/fa-token issue --company 0 --user <u> --days 90 [--read-only]`
is the intended credential. No OAuth, no refresh.

### 5.3 OAuth 2.1 (Claude.ai, or Claude Code without a header)

1. `POST /` without a token → `401`,
   `WWW-Authenticate: Bearer resource_metadata="<base_url>/.well-known/oauth-protected-resource"`.
2. **Protected-resource metadata:** `resource` = `base_url` + `/`,
   `authorization_servers` = `[base_url]`, `scopes_supported` = `["read","write"]`.
3. **Authorization-server metadata:** `issuer`, `authorization_endpoint`,
   `token_endpoint`, `registration_endpoint` (when `allow_dcr`),
   `response_types_supported: ["code"]`,
   `grant_types_supported: ["authorization_code","refresh_token"]`,
   `code_challenge_methods_supported: ["S256"]`,
   `token_endpoint_auth_methods_supported: ["none"]`,
   `client_id_metadata_document_supported` (when `allow_cimd`),
   `authorization_response_iss_parameter_supported: true`,
   `scopes_supported: ["read","write"]`.
4. **Client registration:** DCR (`POST /oauth/register`, public clients only,
   rate-limited per source IP) or CIMD (`client_id` is an HTTPS URL whose
   metadata document is fetched on `/authorize` only, never on the token
   endpoint *imscp (d)*, with the SSRF guard of §7).
5. **`/oauth/authorize`** with `response_type=code`, `client_id`,
   `redirect_uri`, `code_challenge`, `code_challenge_method=S256`, `scope`,
   `state`, `resource`. The client and exact redirect URI are checked first;
   failures there are HTML error pages, never redirects.
6. **Login form** (self-contained HTML): **company** (select from
   `companies()`; hidden when there is one), user name, password. Checked with
   `AccountGateway::authenticate`, so FA's `login_delay` brute-force throttling
   applies. `NO_API_ACCESS` shows a 403 page "Your FrontAccounting role does not
   have GraphQL API access". The sign-in is kept in the module's own PHP session
   (cookie `fa_mcp_oauth`, path = `base_url`'s path + `/oauth/`, `HttpOnly`,
   `Secure`, `SameSite=Lax`) for at most 10 minutes; it never creates an FA
   login session.
7. **Consent page:** "*<client name>* wants to use FrontAccounting as *<user>*
   in *<company name>*". Choice: **Read-only** (`read`) or **Read and change**
   (`write`), preselected from the requested `scope`. CSRF token,
   `Content-Security-Policy: frame-ancestors 'none'`, `X-Frame-Options: DENY`.
   - Allow: one-time code `<company>.<random>` (60 s, stored as SHA-256, bound
     to client, redirect URI, PKCE challenge, login, scope, resource); redirect
     with `code`, `state`, `iss` (RFC 9207).
   - Deny: redirect with `error=access_denied`.
8. **`POST /oauth/token`, `authorization_code`:** the code's company prefix
   selects the company's tables; code unused and unexpired, client and redirect
   URI match, PKCE S256 verifier, `resource` (if given) equals the MCP resource
   ignoring a trailing slash (else `invalid_target`). The code is used up
   whether or not the exchange succeeds. Response:
   - `access_token` = `issueToken(company, login, scope, access_token_ttl, "Claude (MCP) – <client name>")`
   - `token_type: "Bearer"`, `expires_in`, `scope`
   - `refresh_token` = `<company>.<256-bit random, base64url>`, stored as SHA-256,
     lifetime `refresh_token_ttl`. The company prefix routes the refresh to the
     right company's tables, as graphql's own refresh tokens do.

### 5.4 Refresh and revocation

- **Refresh:** the row must be unexpired and not rotated, and
  `tokenIsRevoked(company, jti)` false for its current token *imscp (b)*. Then
  *imscp (c)*:
  1. issue a new graphql token (a failure changes nothing; the client may retry);
  2. insert the new refresh row in the same `family` (on failure revoke the new
     token, answer `server_error`);
  3. atomically mark the old row rotated (losing a race: delete the new row,
     revoke the new token, `invalid_grant`);
  4. revoke the old graphql token;
  5. return both.
- **Reuse detection:** presenting a rotated refresh token revokes the whole
  family and its current graphql token; `invalid_grant`.
- **User revocation:** each connection is one `graphql_machine_token` row
  labelled "Claude (MCP) – <client>". `bin/fa-token revoke` ends the access
  token at once and makes the next refresh fail.
- Expired codes and refresh rows past `expires_at` are pruned on each token
  request (`DELETE … LIMIT 100`).

### 5.5 One `POST /`

1. **Header normaliser:** headers are read through PSR-7 names, falling back to
   `HTTP_MCP_*` server params *imscp (a)*.
2. **TLS:** required unless `require_tls` is false; `X-Forwarded-Proto` believed
   only with graphql's `trust_proxy`.
3. **Origin:** if present, must be `base_url`'s origin or in `allowed_origins`
   (default `['https://claude.ai']`), else `403` (DNS-rebinding guard).
4. **CORS** for allowed origins.
5. **Bearer:** missing → the `401` of §5.3. Not decoded here; `Executor` is the
   one source of truth.
6. Parse and dispatch (§6). The client IP passed to `Executor` is the real
   end-client address, resolved with `trust_proxy` *imscp (h)*.
7. `ExecutorAuthException` `UNAUTHENTICATED` → `401`,
   `WWW-Authenticate: Bearer error="invalid_token", resource_metadata=…`;
   `FORBIDDEN` → `403` JSON `{"error":"NO_API_ACCESS"}`.

## 6. MCP protocol surface

### 6.1 Protocol (ported from imscp-mcp `Protocol/`)

Dual-era, stateless:

- **Supported versions:** `['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26']`.
- **Era:** `params._meta["io.modelcontextprotocol/protocolVersion"]` present →
  modern; otherwise legacy, version from `MCP-Protocol-Version` (absent →
  `2025-03-26`).
- **Transport:** a request gets `200 application/json` with one response; a
  notification gets `202` and no body; a JSON-RPC response or a batch gets
  `-32600`; no `Mcp-Session-Id` is minted, any sent is ignored.
- **Modern validation:** `MCP-Protocol-Version` equals the `_meta` version,
  `Mcp-Method` equals `method`, and for `tools/call` `Mcp-Name` equals
  `params.name` (with the `=?base64?…?=` form decoded); else `400` `-32020`.
  Unsupported version → `400` `-32022` with `data.supported`/`data.requested`.
  Unknown method → `404` `-32601`.
- **Legacy validation:** unsupported header version → `400` `-32600`; unknown
  method → `200` `-32601`.
- **Methods:** modern `server/discover`, `tools/list`, `tools/call`; legacy
  `initialize`, `notifications/initialized`, `ping`, `tools/list`,
  `tools/call`. Either era's handshake method is answered on the other.
- **`initialize`:** client's version if supported, else `2025-11-25`;
  `serverInfo {name: "frontaccounting-mcp", version}`,
  `capabilities: {tools: {listChanged: false}}`, `instructions`.
- **`server/discover`:** `resultType: "complete"`, `supportedVersions`,
  `capabilities: {tools: {}}`, `instructions`, `_meta` serverInfo,
  `ttlMs: 3600000`, `cacheScope: "public"`. Every modern result carries
  `resultType` and the serverInfo `_meta`.
- **`instructions`:** the caller acts as one FA user in one company; IDs come
  from the list tools; list tools take a Mango `query` (`selector`, `limit`,
  `skip`, `sort`); deletes follow FA's rules (void or cancel).
- **`tools/list`:** deterministic order (queries, then mutations, then escape
  hatch, each alphabetical), no pagination. Modern results add
  `ttlMs: 300000`, `cacheScope: "private"`.
- **`tools/call`:** unknown or hidden tool → `-32602`; arguments validated
  against `inputSchema` before GraphQL → `-32602` with the failing path.
- Not implemented: resources, prompts, logging, subscriptions, tasks, MRTR.

### 6.2 Tool contract

```php
interface Tool {
    public function name(): string;
    public function title(): string;
    public function description(): string;
    public function inputSchema(): array;   // JSON Schema; objects use additionalProperties:false; empty properties encode as {}
    public function annotations(): array;   // readOnlyHint, destructiveHint, idempotentHint, openWorldHint
    public function requiresWrite(): bool;
    public function call(array $args, GraphQlGateway $gql): ToolResult;
}
```

`ToolResult` becomes `content: [{type: "text", text: <pretty JSON>}]` plus
`structuredContent` (the same data). Errors set `isError: true`.

### 6.3 Generated tools (`SchemaToolFactory`)

Built per request from `GraphQlGateway::schema()` — the token's company schema,
so extension fields (sgw_sales' `recurringDueList`, `recurringGenerate`) appear
only where the extension is active.

- **One tool per root field**, name in snake_case (`customerList` →
  `customer_list`, `salesOrderCreate` → `sales_order_create`). Excluded:
  `login`, `tokenRefresh`, `tokenRevoke`, `apiVersion`.
- **Input schema** from the field's arguments (`JsonSchemaMapper`): `String`/`ID`
  → string, `Int` → integer, `Float` → number, `Boolean` → boolean, custom
  scalars → string (with the scalar's description), enums → `enum`, input
  objects → nested objects (recursion capped at depth 6), lists → arrays,
  non-null → `required`. Argument and type descriptions are carried over.
- **Selection set** (`SelectionBuilder`): every scalar and enum field of the
  return type, plus each object or list-of-object field one level deep with its
  scalars only. An optional `fields` argument (string, a GraphQL selection set
  body) replaces the default; it is spliced into the document and validated by
  graphql like any other query.
- **Document:** `query|mutation ($a: T!, …) { field(a: $a, …) { <selection> } }`,
  variable types copied from the argument types.
- **List limit:** for a root field with a `query: MangoInput` argument, a
  missing `query.limit` is set to `default_list_limit` (50); the result notes
  `"limit applied: 50; pass query.limit or query.skip for more"` when exactly
  that many rows come back.
- **Annotations:** fields named `*List`, `me`, `recurringDueList` →
  `readOnlyHint: true`; `*Delete` → `destructiveHint: true`; `*Update` →
  `idempotentHint: true`; `invoiceEmail`, `recurringGenerate` →
  `openWorldHint: true` (they send e-mail); other mutations
  `destructiveHint: false`. Every mutation tool `requiresWrite`.
- **Description:** the GraphQL field description; fallback
  "<Query|Mutation> `<field>` of the FrontAccounting GraphQL API."

### 6.4 Escape hatch (config `escape_hatch`, default `true`)

| Tool | Behaviour |
|---|---|
| `graphql_schema` | The SDL of the caller's schema (`SchemaPrinter`). `readOnlyHint`. |
| `graphql_query` | `query`, `variables`, `operationName`. Rejected (`isError`) if any operation is a mutation or subscription. `readOnlyHint`. |
| `graphql_mutate` | `mutation`, `variables`, `operationName`. `requiresWrite`, `destructiveHint`. |

Both executing tools go through `Executor::execute`.

### 6.5 Filtering

- A `read` connection's `tools/list` hides every tool with `requiresWrite`.
  The token's scope is learned with one `me { scope }` query, cached for the
  request. Calling a hidden tool is `-32602`; graphql refuses the mutation
  anyway.
- No role filtering: FA's `SA_*` areas are checked by graphql at call time and a
  refusal comes back as `isError` "FORBIDDEN: …".

## 7. Security

- **Redirect URIs:** exact match to a registered URI; HTTPS, except loopback
  `http://localhost:*` / `http://127.0.0.1:*` on any port; no fragments.
- **CIMD:** HTTPS only; host must resolve only to public addresses (private,
  loopback, link-local, CGNAT and IPv6 equivalents refused after DNS
  resolution, the address pinned for the fetch); 5 s timeout, 64 KB cap, no
  redirects; the document's `client_id` must equal the URL; cached in
  `mcp_oauth_client` for `cimd_cache_ttl`; lookups of new URLs rate-limited per
  source IP; failures cached too.
- **DCR:** open (Claude.ai needs it), `dcr_rate_limit` per source IP per hour;
  clients unused for 30 days without a live refresh token are pruned.
- **Secrets at rest:** codes and refresh tokens stored only as SHA-256, compared
  with `hash_equals`. Tokens, codes and passwords never reach logs.
- **Codes:** single use, 60 s, PKCE-bound; a second exchange revokes what the
  first issued.
- **Pages:** CSRF token tied to the module session, `frame-ancestors 'none'`,
  `X-Frame-Options: DENY`; login throttled by FA's `login_delay`.
- **Output:** `index.php` captures stray output (as graphql's `OutputCapture`)
  so FA notices never corrupt a JSON response.
- **Failure:** every action catches `Throwable`, logs it to FA's error log and
  returns a generic `-32603` or OAuth `server_error` (message only with `debug`).

## 8. Configuration (`config_mcp.php`, copied from `config_mcp.example.php`)

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

With no `config_mcp.php`, the defaults apply and OAuth is off (no `base_url`):
bearer-only use works out of the box.

## 9. Data model (`sql/update_1.0.sql`, per company, utf8mb4 *imscp (f)*)

- `0_mcp_oauth_client`: `client_id` (PK, varchar 255), `kind` (`dcr`|`cimd`),
  `client_name`, `redirect_uris` (JSON text), `created_at`, `last_used_at`,
  `fetched_at`, `source_ip`.
- `0_mcp_oauth_code`: `code_hash` (PK, char 64), `client_id`, `login`,
  `redirect_uri`, `code_challenge`, `scope`, `resource`, `expires_at`, `used_at`.
- `0_mcp_oauth_refresh`: `id` (PK), `token_hash` (unique, char 64), `family`
  (char 32), `client_id`, `login`, `scope`, `graphql_jti`, `expires_at`,
  `rotated_at`, `revoked_at`, `created_at`.
- `0_mcp_oauth_rate`: `bucket` (PK: kind + source IP + hour), `count`.

Clients are registered before a company is known (DCR, CIMD), so client and
rate rows live in **company 0**'s tables; codes and refresh rows live in the
chosen company's tables. `activate_extension` creates all four tables in the
company being activated and, if missing, in company 0 too.

## 10. Errors

| Situation | Response |
|---|---|
| Malformed JSON | `-32700` |
| Not a JSON-RPC request, a response, or a batch | `-32600` |
| Unknown method | `-32601` |
| Unknown or hidden tool; arguments fail the schema | `-32602` |
| GraphQL error in a tool | `200`, `isError: true`, text `"<CODE>: <message>"`; `FA_REJECTED` adds FA's messages, warnings are appended |
| No, invalid, expired or revoked token | `401` + `WWW-Authenticate: Bearer error="invalid_token", resource_metadata=…` |
| User lacks `SA_GRAPHQL` | `403` JSON `{"error":"NO_API_ACCESS"}` |
| graphql missing, inactive or wrong contract | `503 text/plain` |
| Unexpected exception | `-32603` (message only with `debug`) |
| OAuth errors | RFC 6749 JSON (`invalid_request`, `invalid_client`, `invalid_grant`, `invalid_scope`, `invalid_target`, `unsupported_grant_type`, `server_error`); errors before redirect-URI validation are HTML pages |

## 11. Testing

PHPUnit 9 with graphql's suite layout, run in the FA CI image (`tools/ci.sh`),
and in development through `docker/ci/plugin-dev.sh --env mcp`.

- **unit** (no FA):
  - protocol: every error code of §10, both eras, header normalisation,
    notification `202`, batch rejection, version negotiation;
  - `SchemaToolFactory`, `JsonSchemaMapper`, `SelectionBuilder` against a
    fixture SDL (graphql's printed schema plus sgw_sales): names, exclusions,
    JSON Schema shapes (`{}` vs `[]`), documents, annotations, list limit, read
    filtering;
  - tools against a fake `GraphQlGateway`: variable mapping, result and error
    mapping;
  - OAuth against `InMemoryStore` and a fake `AccountGateway`: PKCE, code single
    use and expiry, redirect rules, refresh rotation and reuse detection, the
    revoked-token refresh path, `invalid_target`, CIMD SSRF guard (private,
    loopback, IPv6; redirects refused), DCR rate limit, company routing by the
    code and refresh prefixes.
- **integration** (FA in-process, demo company, graphql + sgw_sales active):
  the catalog generated from the real schema; every generated document
  validates against it; `ExecutorGraphQlGateway` runs `customer_list`.
- **http** (Apache in the CI image):
  - the full OAuth flow over HTTP: protected-resource and AS metadata (module
    paths and the root rewrite), DCR, authorize → login → consent, token,
    refresh, reuse detection;
  - `initialize` and `server/discover`, `tools/list` for a `write` and a `read`
    token (exact differences), `tools/call` of `customer_list` and
    `customer_create`, a `read` token refused on `customer_create`;
  - `bin/fa-token revoke` of an OAuth connection: the next call is `401`, the
    refresh `invalid_grant`;
  - activation refused when graphql is not active.
- **Conformance and manual** (in `docs/CONNECTING.md`): MCP Inspector CLI
  (`npx @modelcontextprotocol/inspector --cli <url> --transport http --header "Authorization: Bearer …" --method tools/list`),
  `claude mcp add` with a static token and with OAuth, and a Claude.ai custom
  connector against a reachable development environment.

## 12. CI, coverage and badges

- `.github/workflows/ci.yml` calls `cambell-prince/frontaccounting/.github/workflows/plugin-test.yml@master-cp`
  with the matrix fa `[cp, upstream]` × php `['7.4', '8.3']`, `dataset: demo`,
  `setup: composer install --no-interaction --no-progress`,
  `with:` graphql (`saygoweb/frontaccounting-module-graphql` at the release
  with Executor 1.0) and sgw_sales, `test: sh tools/ci.sh`.
- **Coverage:** `tools/coverage.sh`, as graphql's: `tools/init.sh`, then the
  in-process suites (`composer run test:coverage -- --testsuite unit,integration`)
  with xdebug (installed but disabled in the CI image) loaded through an extra
  `PHP_INI_SCAN_DIR` directory, which also reaches process-isolated tests;
  `XDEBUG_MODE=coverage`, `COMPOSER_ALLOW_XDEBUG=1`, `COMPOSER_PROCESS_TIMEOUT=0`.
  It writes `build/coverage.xml` (Clover); `phpunit.xml` includes `src/`. The
  http suite runs in Apache and is not measured.
- **Upload, graphql's mechanism:** `ci.yml` has a separate `coverage` job (one
  cell: fa `cp`, php `8.3`, dataset demo, graphql and sgw_sales alongside) that
  repeats the reusable workflow's steps (checkout the plugin and FA's
  `docker/ci` sparsely, composer cache, pull the image, run
  `fa-ci/docker/ci/plugin-test.sh … plugin -- "sh tools/coverage.sh"`); the
  plugin checkout is bind-mounted, so `plugin/build/coverage.xml` lands on the
  runner and `codecov/codecov-action@v5` uploads it with
  `token: ${{ secrets.CODECOV_TOKEN }}`.
- **Shared alternative:** with graphql and mcp both needing it, a `coverage`
  input and `codecov-token` secret on FA's reusable `plugin-test.yml` would
  replace both copies. The graphql coverage work is raising this with the user;
  mcp uses it if it exists when mcp's CI is built, and graphql's separate-job
  pattern otherwise.
- `codecov.yml`: `fixes: ["/var/www/html/modules/mcp/::"]` (Clover paths are the
  container's), project and patch statuses informational at first.
- **README badges**, under the H1:

  ```markdown
  [![CI](https://github.com/saygoweb/frontaccounting-module-mcp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/saygoweb/frontaccounting-module-mcp/actions/workflows/ci.yml)
  [![codecov](https://codecov.io/gh/saygoweb/frontaccounting-module-mcp/branch/main/graph/badge.svg)](https://codecov.io/gh/saygoweb/frontaccounting-module-mcp)
  ```

## 13. Delivery order

1. graphql: `Executor` 1.0, the `scp` claim and `me { scope }`,
   `fa-token --read-only`, tests; released as a minor version.
2. FA: `docker/ci/dev/mcp.env` (and the shared Codecov input, if the user chooses it).
3. mcp: protocol port, gateways, generated tools and escape hatch (bearer-only
   end to end), then OAuth, then CI, coverage, badges and docs.

## 14. Out of scope

stdio transport; resources and prompts; SSE and server-initiated messages; an
admin page for OAuth clients in FA's UI (`bin/fa-token list` shows connections);
per-tool role filtering; multiple companies in one connection.
