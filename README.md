# Magic HTML Wireframe Service

A stateless Tier 0 generator that converts Site AST plus a structured brief into a semantic Vocabulary AST. It has no application database and can be tested or replaced without affecting stored sites.

The output describes page sections by stable keys, composition types, and semantic roles. It never generates copy, HTML, CSS, selectors, colors, dimensions, or asset URLs.

## API

`POST /api/v1/wireframes` requires `Authorization: Bearer <MAGIC_HTML_SERVICE_TOKEN>` and accepts:

- `site_ast` — canonical site information architecture
- `brief` — normalized interview fields
- `locale` — output locale

Every Site AST page appears exactly once in `wireframe_ast`. Each page contains 2–8 sections and uses the finite composition and role vocabularies enforced by the validator.

Successful provider-backed responses may include `telemetry` with the runtime provider, response model and ID, input/cached-input/output/reasoning token counts, provider request/semantic-attempt/retry counts, provider duration, and rate-card metadata. `reasoning_tokens` is a subset of `output_tokens`; it is reported for auditing but is not charged a second time. `estimated_cost` is calculated only when complete usage and a matching configured rate card are available, so it is an estimate rather than an invoice. Prompts, API keys, and raw reasoning are never included.

An idempotency replay reports zero tokens, cost, provider requests, attempts, retries, and provider duration for that replay. Its `generation_telemetry_reference` SHA-256 digest identifies the original generation telemetry without presenting the original usage as newly incurred.

### Deterministic preview files

`POST /api/v1/wireframes/materialize` accepts `contract_version` and a validated `wireframe_ast`. It performs no model or external HTTP call and returns neutral, self-contained HTML files:

```json
{
  "contract_version": "1.0",
  "source_digest": "<sha256-of-canonical-wireframe-ast>",
  "entry_path": "page-home.html",
  "files": [
    {
      "path": "page-home.html",
      "mime": "text/html; charset=UTF-8",
      "content_base64": "PCFkb2N0eXBlIGh0bWw+..."
    }
  ],
  "file_manifest": [
    {
      "page_key": "home",
      "path": "page-home.html",
      "size": 1234,
      "sha256": "<sha256-of-decoded-content>"
    }
  ],
  "telemetry": {
    "operation": "wireframes.materialize",
    "provider": "deterministic",
    "render_duration_ms": 1
  }
}
```

For Preview create, forward `entry_path` and `files` unchanged from this response. The separate `file_manifest` maps each page key to its path and describes the bytes obtained by base64-decoding the matching file's `content_base64`.

## Verification

```bash
composer install
php artisan test --compact
composer validate --strict
composer audit --no-dev
```
