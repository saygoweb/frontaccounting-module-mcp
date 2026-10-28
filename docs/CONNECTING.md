# Connecting to the MCP server

The endpoint is the module's URL, for example
`https://fa.example.com/modules/mcp/`. Replace it in the commands below.

## Claude Code with a machine token

Issue a token with the GraphQL module's CLI, from `modules/graphql`:

```
php bin/fa-token issue --company 0 --user alice --days 90 [--read-only]
```

Then add the server with the token as a header:

```
claude mcp add --transport http fa https://fa.example.com/modules/mcp/ \
  --header "Authorization: Bearer <token>"
```

No OAuth and no refresh are involved; any GraphQL access or machine token
works. Use `--read-only` for a token that can list and read but not change.

## Claude Code with OAuth

This needs `base_url` set in `config_mcp.php`.

```
claude mcp add --transport http fa https://fa.example.com/modules/mcp/
```

Then run `/mcp` in Claude Code, pick `fa` and authenticate. A browser opens on
the module's page: choose the company, enter your FrontAccounting user name and
password, then choose Read-only or Read and change and allow.

## Claude.ai custom connector

1. Settings, Connectors, Add custom connector.
2. Enter the URL, `https://fa.example.com/modules/mcp/`.
3. Connect, sign in with company, user name and password, and choose
   Read-only or Read and change.

The server must be reachable over HTTPS from the internet.

## Revoking a connection

Each connection is one machine token labelled "Claude (MCP) - <client>":

```
php bin/fa-token list --company 0
php bin/fa-token revoke --company 0 <jti>
```

The access token stops working at once and the next refresh fails.

## Reconciling a bank statement

These tools need a GraphQL module with bank reconciliation (`bankTransactionList`,
`bankReconcile`); with an older one they are not listed.

With a role that has *Bank transactions view* (`SA_BANKTRANSVIEW`) and *Bank
reconciliation* (`SA_RECONCILE`), Claude can reconcile an account against a
statement (a PDF or CSV you give it), as FrontAccounting's Reconcile Bank Account
screen does. Finding the account with `bank_account_list` also needs *Customer
payments* (`SA_SALESPAYMNT`); without it, give Claude the bank account's id.

1. `bank_account_list` to find the account; `bank_reconciliation` with the
   statement's date, to check the statement's opening balance against
   `beginningBalance`.
2. `bank_transaction_list` with `{"bankAccountId": "<id>", "reconciled": null,
   "voided": false, "date": {"$lte": "<statement date>"}}` for the entries to tick.
   It returns a page at a time: keep paging with `query.skip`, as the result's
   note says (or check `bank_transaction_count` first), until every entry up to
   the statement date is in hand.
3. Match statement lines to entries (amount, date, counterparty, memo).
4. `bank_reconcile` with the statement date, its closing balance as
   `endingBalance`, and the matched entry ids in `reconcile`. All or nothing;
   repeating it changes nothing. It is marked destructive (it can untick entries
   and replaces the account's last reconciliation), so your client may ask you to
   confirm it.
5. `difference` in the result is 0 when the statement is reconciled. Report the
   statement lines with no entry, and the entries left unticked; nothing is created.

## Checking the server

```
npx @modelcontextprotocol/inspector --cli https://fa.example.com/modules/mcp/ \
  --transport http --header "Authorization: Bearer <token>" --method tools/list
```

## Troubleshooting

- **401**: the token is missing, expired or revoked. Issue a new one, or
  reconnect through OAuth. If a proxy strips the `Authorization` header, the
  server cannot see it; pass it through (Apache: `SetEnvIf Authorization`
  or `CGIPassAuth On`).
- **403 `NO_API_ACCESS`**: the user's FrontAccounting role lacks the *GraphQL
  API access* area. Add it in Setup, Access Setup.
- **403 `TLS_REQUIRED`**: use HTTPS, or behind a proxy set graphql's
  `trust_proxy`. `require_tls` can be turned off for local trials only.
- **403 Origin not allowed**: add the browser origin to `allowed_origins`.
- **503**: the GraphQL module is missing, or its Executor contract is not 1.x.
- **A tool is missing**: read-only connections do not list tools that change
  data, and extension tools (for example sgw_sales) appear only where the
  extension is active.
