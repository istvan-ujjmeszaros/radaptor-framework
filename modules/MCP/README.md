# MCP module

Implements the Radaptor [Model Context Protocol](https://modelcontextprotocol.io/) server,
exposing curated browser Events as MCP tools through a JSON-RPC 2.0 router.

## Protocol revision

This module implements **MCP `2025-11-25`**. The server negotiates the body-level
`protocolVersion` on `initialize`: when a client requests an unsupported version, the
`InitializeResult` echoes `2025-11-25` and the client decides whether to disconnect.

## Transport

Streamable HTTP, **POST only**:

- `Accept: application/json, text/event-stream` (or `*/*`) is **required** on every POST.
  Missing or partial `Accept` → HTTP 400 + JSON-RPC `-32600`.
- `MCP-Protocol-Version: 2025-11-25` is required on every request **after** `initialize`.
  On the `initialize` request itself, body-level `protocolVersion` is the source of truth and a
  mismatching/missing header is ignored.
- `Origin` is validated against `APP_MCP_ALLOWED_ORIGINS` (or the loopback default set).
  Disallowed origin → 403.
- Authentication is `Authorization: Bearer mcp_<prefix>_<secret>`. Missing or invalid token →
  401 with `WWW-Authenticate: Bearer realm="radaptor-mcp"`.

GET / DELETE and other HTTP methods are out of scope for the framework router; the consumer
app's MCP entrypoint (e.g. `radaptor-app/bin/mcp_server.php`) handles HTTP method dispatch and
should answer non-POST with `405 Method Not Allowed; Allow: POST`.

## Tool result contract

- `tools/call` argument validation failures (missing required argument, etc.) are returned as
  *result-level* `isError: true` payloads, **not** JSON-RPC `-32602`. The accompanying
  `structuredContent.error_code` distinguishes the failure (`validation_failed`,
  `authorization_denied`, `execution_failed`, `missing_structured_response`).
- Unknown tool names remain a JSON-RPC protocol-level error with code `-32602`.
- Every successful tool result mirrors `structuredContent` as a JSON-encoded `text` content
  block in addition to the human-readable summary block, per back-compat guidance in the spec.

## Tool annotations

Defaults are AI-safety-cautious; event meta can override:

| Hint              | Default        | Override key (`meta.mcp.*`)               |
|-------------------|----------------|-------------------------------------------|
| `readOnlyHint`    | `risk == 'read'` | `risk` (`'read'` / `'write'` / …)         |
| `destructiveHint` | non-readonly → `true`; read-only → `false` | `destructive` (only honored on non-readonly tools) |
| `idempotentHint`  | `false`        | `idempotent: true`                        |
| `openWorldHint`   | `true`         | `open_world: false`                       |
| `title`           | `meta.name`    | (top-level `tool.title` and `annotations.title`) |

`outputSchema` is emitted only when the event meta declares `mcp.output_schema`.

## Stateless v1 — deferred features

This v1 is intentionally stateless and bearer-token-only. The following features are spec-
compatible but **not** implemented yet; do not relitigate without a concrete client demand:

- `MCP-Session-Id` / session lifecycle / `DELETE /mcp` — every request is self-identifying via
  the bearer token.
- SSE streaming, resumable streams, `Last-Event-ID` — no server-initiated streams; tool calls
  are request/response.
- OAuth 2.1 / RFC 9728 / RFC 8707 / DCR — bearer-token MCP only; suitable for trusted local /
  developer deployments.
- The unique-prefix retry path in `McpTokenService::createToken` is exercised in production but
  not deterministically in tests (would require a seam in the random generator).

## Environment variables

| Variable                   | Purpose                                                    |
|----------------------------|------------------------------------------------------------|
| `APP_MCP_PORT`             | Public-facing port used by the loopback origin defaults.   |
| `APP_MCP_PUBLIC_URL`       | Override the URL shown to users in the token-management panel. |
| `APP_MCP_ALLOWED_ORIGINS`  | Comma-separated allow-list. When empty, loopback defaults are used. |

## CSRF

The GUI POST endpoints (`mcp.token-create`, `mcp.token-revoke`, `mcp.token-list`) follow
project-wide convention: session auth + `SameSite=Lax` cookies. The framework does not have a
per-endpoint CSRF token mechanism, and MCP is not special-cased.

## Audit logging

`McpRequestLogger` writes one row to `mcp_audit` per request. Sensitive argument keys
(`*token*`, `*password*`, `*secret*`, `*api_key*`) are redacted in `args_redacted_json`; the
SHA-256 of the un-redacted payload is recorded in `args_hash` for correlation.

## Reference

- Spec: <https://modelcontextprotocol.io/specification/2025-11-25>
- Transport: <https://modelcontextprotocol.io/specification/2025-11-25/basic/transports>
- Lifecycle / negotiation: <https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle>
- Tools: <https://modelcontextprotocol.io/specification/2025-11-25/server/tools>
