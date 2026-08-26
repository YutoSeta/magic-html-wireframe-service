# Magic HTML Wireframe Service

A stateless Tier 0 generator that converts Site AST plus a structured brief into a semantic Vocabulary AST. It has no application database and can be tested or replaced without affecting stored sites.

The output describes page sections by stable keys, composition types, and semantic roles. It never generates copy, HTML, CSS, selectors, colors, dimensions, or asset URLs.

## API

`POST /api/v1/wireframes` requires `Authorization: Bearer <MAGIC_HTML_SERVICE_TOKEN>` and accepts:

- `site_ast` — canonical site information architecture
- `brief` — normalized interview fields
- `locale` — output locale

Every Site AST page appears exactly once in `wireframe_ast`. Each page contains 2–8 sections and uses the finite composition and role vocabularies enforced by the validator.

## Verification

```bash
composer install
php artisan test --compact
composer validate --strict
composer audit --no-dev
```
