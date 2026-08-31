# Magic HTML Wireframe Service

A stateless Tier 0 generator that converts Site AST plus a structured brief into a semantic Wireframe AST. It has no application database and can be tested or replaced without affecting stored sites.

The default v1 output describes page sections by stable keys, composition types, and semantic roles. The opt-in v2 output is a nested, content-bearing information architecture whose branches are `Region` nodes and whose leaves are concrete `Text`, `Image`, `Link`, `Button`, `Input`, `Textarea`, `Select`, or `Checkbox` nodes. Neither version contains CSS, selectors, colors, dimensions, scripts, or asset URLs.

## API

`POST /api/v1/wireframes` requires `Authorization: Bearer <MAGIC_HTML_SERVICE_TOKEN>` and accepts:

- `site_ast` — canonical site information architecture
- `brief` — normalized interview fields
- `locale` — output locale
- `wireframe_ast_version` — optional `1` or `2`; defaults to `1` for Site Composer compatibility

Every Site AST page appears exactly once in `wireframe_ast`. v1 pages contain 2–8 sections and use the finite composition and role vocabularies enforced by the validator.

Wireframe AST v2 preserves the Site AST page key, path, and title, then models the page as a finite semantic tree. It carries real locale copy so that information hierarchy, AIDMA order, forms, media placement, and conversion paths can be reviewed without applying a brand skin. It enforces one `heading-1`, 2–12 direct `main` sections, unique node IDs, a depth limit of 8, a 300-node page limit, an 800-node request limit, safe local links, list ancestry, non-nested forms, and form-control ancestry. A request contains at most eight pages, starts with `home` at `/`, and uses unique canonical page paths. Generic `Item` and `Action` leaves are not part of v2.

```json
{
  "contract_version": "1.0",
  "wireframe_ast_version": 2,
  "site_ast": {},
  "brief": {},
  "locale": "ja"
}
```

For production orchestration, use the non-blocking job interface with the same authenticated JSON body and `Idempotency-Key` header:

```text
POST /api/v1/wireframe-jobs
GET  /api/v1/wireframe-jobs/{job}
```

The start request returns HTTP 202 with `queued` or `in_progress`. Poll the returned job URL until `status` is `succeeded` or `failed`; a successful record contains `result.wireframe_ast` and `result.telemetry`. This path starts an OpenAI background response and therefore does not hold a Laravel Cloud request open for the full model runtime. Transient provider polling failures return HTTP 502 without terminally failing the job, so the orchestrator can retry the same GET safely.

Job input and successful output are application-encrypted in the configured cache store and expire after `WIREFRAME_JOB_TTL_SECONDS` (24 hours by default). Public job records omit the encrypted payload and internal provider response identifier. OpenAI background execution uses `store=false`; the provider may retain response data temporarily as required for asynchronous polling, but it is not requested for long-term response storage.

Successful provider-backed responses may include `telemetry` with the runtime provider, response model and ID, input/cached-input/output/reasoning token counts, provider request/semantic-attempt/retry counts, provider duration, and rate-card metadata. `reasoning_tokens` is a subset of `output_tokens`; it is reported for auditing but is not charged a second time. `estimated_cost` is calculated only when complete usage and a matching configured rate card are available, so it is an estimate rather than an invoice. Prompts, API keys, and raw reasoning are never included.

An idempotency replay reports zero tokens, cost, provider requests, attempts, retries, and provider duration for that replay. Its `generation_telemetry_reference` SHA-256 digest identifies the original generation telemetry without presenting the original usage as newly incurred.

The idempotency store persists only the SHA-256 request digest. Content-free v1 responses retain their legacy immutable replay behavior. Content-bearing v2 responses are application-encrypted and expire after `WIREFRAME_IDEMPOTENCY_V2_RESPONSE_TTL_SECONDS` (24 hours by default), so customer copy and form labels are neither plaintext cache records nor indefinite audit data.

### Deterministic preview files

`POST /api/v1/wireframes/materialize` accepts `contract_version` and a validated v1 or v2 `wireframe_ast`. It performs no model or external HTTP call and returns self-contained HTML files. v1 keeps its legacy neutral renderer. v2 receives the server-owned, deterministic `wireframe-neutral-v1` decorate AST:

- white canvas and transparent structural regions
- gray image placeholders
- solid region borders and dashed nested-container borders; atomic leaves remain borderless
- fixed minimum margin, padding, and action height
- compact horizontal spacing for nested regions and grids
- black background with white text for Button and primary action-link leaves
- navy underlined ordinary links
- light-gray form controls and max-width/word-break safeguards for long content
- responsive grid/split collapse at 720 px
- no brand color, gradient, shadow, radius, animation, font asset, or external resource

This `wireframe_decorate_ast` exists only to expose nesting and hierarchy. It is not the Styler Design/Decor AST and is never generated or overridden by the model or caller.

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
  "wireframe_decorate_ast": {
    "version": 1,
    "preset": "wireframe-neutral-v1"
  },
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
