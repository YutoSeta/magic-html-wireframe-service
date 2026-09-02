# Magic HTML Wireframe Service

A stateless Tier 0 generator that converts Site AST plus a structured brief into a semantic Wireframe AST. It has no application database and can be tested or replaced without affecting stored sites.

The default v1 output describes page sections by stable keys, composition types, and semantic roles. The opt-in v2 output is a nested, content-bearing information architecture whose branches are `Region` nodes and whose leaves are concrete `Text`, `Image`, `Link`, `Button`, `Input`, `Textarea`, `Select`, or `Checkbox` nodes. Neither version contains CSS, selectors, colors, dimensions, scripts, or asset URLs.

## API

`POST /api/v1/wireframes` requires `Authorization: Bearer <MAGIC_HTML_SERVICE_TOKEN>` and accepts:

- `site_ast` — canonical site information architecture
- `brief` — normalized interview fields
- `locale` — output locale
- `wireframe_ast_version` — optional `1` or `2`; defaults to `1` for Site Composer compatibility
- `generation_mode` — `monolithic` (existing single response) or `section_parallel` (v2 asynchronous jobs only)

Every Site AST page appears exactly once in `wireframe_ast`. v1 pages contain 2–8 sections and use the finite composition and role vocabularies enforced by the validator.

Wireframe AST v2 preserves the Site AST page key, path, and title, then models the page as a finite semantic tree. It carries real locale copy so that information hierarchy, AIDMA order, forms, media placement, and conversion paths can be reviewed without applying a brand skin. Provider structured output uses finite level-specific Region definitions rather than a recursive node definition. The validator enforces one `heading-1`, 2–12 direct `main` sections, unique node IDs, a depth limit of 5, a 150-node page limit, a 400-node request limit, semantic child budgets, safe local links, list ancestry, non-nested forms, and form-control ancestry. A request contains at most eight pages, starts with `home` at `/`, and uses unique canonical page paths. Generic `Item` and `Action` leaves are not part of v2.

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

The start request returns HTTP 202 with `queued` or `in_progress`. Poll the returned job URL until `status` is `succeeded` or `failed`; a successful record contains `result.wireframe_ast` and `result.telemetry`. This path starts OpenAI background responses and therefore does not hold a Laravel Cloud request open for the full model runtime. Transient provider polling failures return HTTP 502 without terminally failing the job, so the orchestrator can retry the same GET safely.

For v2, `generation_mode: section_parallel` runs a bounded orchestration: a whole-site planner defines 2–8 sections per page and at most 24 per request; section jobs then receive the complete Site AST, brief, and plan and start in configurable batches; deterministic assembly supplies page chrome; finally a whole-site reviewer returns only bounded copy, link, heading-role, and section-order operations. The reviewer cannot regenerate the AST, change IDs, add styling, or bypass the final `WireframeValidator`; operations that target an incompatible node are skipped and counted instead of discarding valid section work. Public job records expose `stage` (`planning`, `sections`, `review`, `complete`) and progress while encrypted workflow context remains private. `monolithic` remains available for compatibility and A/B comparison.

If a schema-valid provider response fails the deterministic semantic validator, the job starts at most one background repair with the bounded validation message. `semantic_attempt` makes this visible. Usage and estimated cost telemetry aggregate both provider responses, including an invalid first attempt, so repair work is never omitted from the audit. A second invalid result ends the job as `failed` rather than creating an unbounded retry loop.

Job input and successful output are application-encrypted in the configured cache store and expire after `WIREFRAME_JOB_TTL_SECONDS` (24 hours by default). Public job records omit the encrypted payload and internal provider response identifier. OpenAI background execution uses `store=false`; the provider may retain response data temporarily as required for asynchronous polling, but it is not requested for long-term response storage.

Successful provider-backed responses may include `telemetry` with the runtime provider, response model and ID, input/cached-input/output/reasoning token counts, provider request/semantic-attempt/retry counts, provider duration, and rate-card metadata. `reasoning_tokens` is a subset of `output_tokens`; it is reported for auditing but is not charged a second time. `estimated_cost` is calculated only when complete usage and a matching configured rate card are available, so it is an estimate rather than an invoice. Prompts, API keys, and raw reasoning are never included.

An idempotency replay reports zero tokens, cost, provider requests, attempts, retries, and provider duration for that replay. Its `generation_telemetry_reference` SHA-256 digest identifies the original generation telemetry without presenting the original usage as newly incurred.

The idempotency store persists only the SHA-256 request digest. Content-free v1 responses retain their legacy immutable replay behavior. Content-bearing v2 responses are application-encrypted and expire after `WIREFRAME_IDEMPOTENCY_V2_RESPONSE_TTL_SECONDS` (24 hours by default), so customer copy and form labels are neither plaintext cache records nor indefinite audit data.

### Common Layout Snapshot and Wireframe Presenter

The v2 path separates reusable geometry from review-only paint:

```text
Wireframe AST v2
  -> geometry-only Layout AST v2 + deterministic Layout CSS
  -> immutable candidate Layout Snapshot
  -> Wireframe Presenter (paint-only Skin/Decor)
  -> browser validation at 390 / 768 / 1440 px
  -> immutable frozen Layout Snapshot
  -> Styler Skin / Decor / Motion
```

Create a candidate with `POST /api/v1/layout-snapshots`:

```json
{
  "contract_version": "1.0",
  "wireframe_ast": {},
  "validation_viewports": [390, 768, 1440]
}
```

The response contains `layout_snapshot`, one `layout_snapshot_reference` per page, and encrypted write-once `storage` metadata. The snapshot is content-addressed (`snapshot_id = "ls_" + snapshot_digest`), has `layout_ast_version: 2`, and records container width, content width, responsive breakpoints, mobile source-order behavior, and minimum action height in `constraints`. Every page keeps its canonical route separately from its HTML artifact path. `source_html.sha256` hashes the exact decoded HTML bytes; Layout AST and CSS have independent digests. Geometry-only CSS is rejected if it contains paint, ornament, or motion declarations.

A newly compiled snapshot is `candidate` with per-page validation `pending`; the service does not invent browser measurements. Render any candidate page for validation with `POST /api/v1/wireframe-presentations`, passing only its complete `layout_snapshot_ref`. The Presenter reads the stored snapshot and preserves the semantic body and exact Layout CSS, then adds a fixed paint-only Wireframe Skin/Decor stylesheet. It never invokes the Layout compiler or legacy materializer. The returned HTML declares the source HTML, Layout AST, Layout CSS, and snapshot digests so the preview validator can bind measurements to the rendered bytes.

If Layout-only visual feedback reports a bounded geometry issue, apply its finite plan to a pending candidate without regenerating the page:

```text
POST /api/v1/layout-snapshots/{candidate_snapshot_id}/patches
```

The exact request has only `contract_version`, `page_key`, and a non-empty Layout `patch_plan`. The route's content-addressed Snapshot id and `patch_plan.base_ast_digest` independently bind the intended candidate and page AST. Supported operations are limited to cluster wrap, flow, columns/ratio, gap, section spacing, alignment, image aspect/object-fit, and minimum action height. A viewport of `{ "min_width": 320 }` means the all-device/base scope. Mobile order is not part of the public v1 operation vocabulary while `preserve_source_order=true`; ambiguous viewport ranges that cross breakpoints and semantic no-ops are also rejected.

Applying a plan creates another immutable `candidate` and resets all page validation to `pending`. Source HTML, page identity, and semantic structure remain byte-identical. Its lineage stores the complete canonical patch plan and one `apply_finite_layout_patch` record per issue, including problem, viewport, value, and before/after digest. A minimum-action-height correction updates the site root constraint and deterministically recompiles every page; its lineage binds the root constraints digests. The resulting candidate must complete browser validation again before it can be frozen.

After the preview service has checked every page at all three required widths, freeze the candidate without mutating it:

```text
POST /api/v1/layout-snapshots/{candidate_snapshot_id}/freeze
```

The request supplies `candidate_snapshot_digest` plus a `magic-html-preview-service` validation attestation. Its `subject` must identify that exact candidate, and `validation.pages` must cover every candidate page exactly once with passed 390 px, 768 px, and 1440 px geometry results. Freezing creates a second, immutable content-addressed snapshot whose source HTML, Layout AST, Layout CSS, constraints, and their digests are byte-identical to the candidate. Its lineage records the finite `attach_validation` operation. Only this `frozen` / `passed` reference is suitable as the Styler Layout input; the candidate remains renderable solely so browser validation can occur.

After freezing, a direct consumer may pass the reference to Styler pipeline
contract 1.3 for uninterrupted Skin → Decor → Motion generation. A consumer
that needs visual feedback after every stage instead passes it to Styler Layout
contract 1.3 for Stage Bundle hydration, then runs separate Skin, Decor, and
optional Motion generate/review/finite-patch loops. Both routes consume the same
frozen Layout bytes; neither receives Wireframe presentation paint.

Snapshots are application-encrypted on the configured Laravel Storage disk, use write-once content-addressed paths, and expire after `WIREFRAME_LAYOUT_SNAPSHOT_TTL_SECONDS` (seven days by default). There are no list, update, or delete APIs.

### Legacy deterministic preview files

`POST /api/v1/wireframes/materialize` remains available byte-compatibly for existing contract 1.0/1.1 consumers. New v2 orchestration should use Layout Snapshots and the Wireframe Presenter above. The legacy endpoint accepts `contract_version` and a validated v1 or v2 `wireframe_ast`, performs no model or external HTTP call, and returns self-contained HTML files. v1 keeps its legacy neutral renderer. v2 receives the server-owned, deterministic `wireframe-neutral-v1` decorate AST:

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
