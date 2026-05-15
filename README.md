# Radaptor Framework

`radaptor/core/framework` is the runtime and CLI foundation of Radaptor consumer apps.
It provides package bootstrapping, migrations, role/ACL primitives, i18n CLI workflows,
and shared infrastructure used by higher-level packages.

## Installation

End users do not install this package manually.
Use a registry-first consumer app:

- [`radaptor-app`](https://github.com/istvan-ujjmeszaros/radaptor-app)
- [`radaptor-portal`](https://github.com/istvan-ujjmeszaros/radaptor-portal)

The package is resolved via `radaptor.json` / `radaptor.lock.json` during
`radaptor install` or `radaptor update`.

## Dependencies

From `.registry-package.json`:

- No package-level runtime dependency declared.

## Development and Release

Maintain this package in `/apps/_RADAPTOR/packages-dev/core/framework`, not inside a consumer
app's `packages/registry/...` runtime copy. Validate through a consumer app `php` container; do not
run host PHP, Composer, PHPUnit, PHPStan, or PHP-CS-Fixer.

Release key:

- `core:framework`

Normal flow: package PR, `@codex review`, clean repo checks, squash merge, fast-forward local
`main`, `package:release core:framework`, commit the `.registry-package.json` version bump, then
publish the generated artifact through `radaptor_package_registry`.

## I18n Diagnostics

The framework ships CLI diagnostics for both keyed fallback literals and unkeyed hardcoded UI text.

Useful commands:

```bash
radaptor i18n:doctor [--all-packages] [--json] [--strict-hardcoded]
radaptor i18n:scan-literals [--all-packages] [--json]
radaptor i18n:scan-hardcoded [--all-packages] [--json] [--strict]
```

`i18n:scan-hardcoded` scans supported template formats (`.php`, `.blade.php`, `.twig`) for visible
text nodes and common UI attributes such as `title`, `placeholder`, `aria-label`, `alt`, and button
or option `value` attributes. PHP templates are tokenized so PHP string literals are not treated as
template UI text.

Hardcoded UI findings are advisory by default. `i18n:doctor` reports them in the `hardcoded_ui`
section and only fails because of them when `--strict-hardcoded` is passed.

Shipped i18n database audits compare database rows against the source text carried by the shipped
seed file. If a package changes `source_text`, the audit uses the shipped source hash even when the
`i18n_messages` row already exists in the database. This prevents stale source-hash snapshots from
hiding required translation sync work. Enabled locales that are not shipped by a package are skipped
for that package instead of being reported as missing seed files.

## Migration Safety

Package and app migrations are upgrade steps for an already initialized Radaptor database. They are
not a replacement for the bootstrap schema or a site snapshot restore.

`migrate:run` performs a preflight check before applying pending migrations. If the target database
contains only migration metadata and no application tables, the command fails before running any
migration and before marking anything as applied. This protects against broken container init flows
where `radaptor_app` exists but the bootstrap schema was never loaded.

Useful commands:

```bash
radaptor migrate:run --dry-run --json
radaptor migrate:run --dry-run --sandbox --json
radaptor migrate:run --json
```

`--dry-run` lists pending migrations and runs the same preflight without mutating the database.
It computes pending migrations from the existing metadata table in read-only mode, so it does not
create or upgrade the `migrations` table on empty or legacy schemas.
`--dry-run --sandbox` clones the current database into a temporary database, applies pending
migrations there, reports the result, and drops the temporary database. Use the sandbox proof before
running migrations on important development, staging, migration, or production-like snapshots.

## Site Migration Cutover

Site migration exports can coordinate with runtime workers before the snapshot is written. A
`site_migration` export requires the operator to choose exactly one source-worker behavior:

```bash
radaptor site:export --output tmp/site-migration.json --uploads-backed-up --profile site_migration --pause-source-workers --json
radaptor site:export --output tmp/site-migration.json --uploads-backed-up --profile site_migration --skip-source-worker-pause --json
```

When source workers are paused, workers confirm the pause through the runtime worker registry before
export continues. The source instance is then locked into cutover read-only mode. Web mutations, MCP
write tools, and mutating CLI commands are blocked until `site:cutover-release` is run with the
required confirmation text. The read-only title/message are i18n keys; the lock table stores the
message key, not a rendered translation snapshot. Releasing a cutover lock only auto-resumes worker
pause requests that were created for that cutover; pre-existing/manual pauses stay paused and must be
released explicitly by the operator.

Site snapshots must preserve `migrations` and `seeds` metadata. Without those rows, a restored site
would treat historical migrations or bootstrap seeds as pending and could re-run non-idempotent
schema/data changes.

Runtime table existence checks intentionally cache only positive results. In long-running runtimes,
missing tables are re-probed after migrations instead of being treated as absent until process
restart. Cutover gate checks also fail open if the default database cannot be probed, so bootstrap,
diagnostic, and repair CLI commands are not blocked by the guard before the database is reachable.
The positive database probe result is cached for the process lifetime to avoid an extra connectivity
probe on every mutating gate check.

## Runtime Worker Pause Control

Runtime workers register heartbeat/state rows in `runtime_worker_instances`. Pause requests live in
`runtime_worker_pause_requests` and are confirmed when every active worker in the scope has observed
the request. Stale workers are evaluated with a worker-specific stale timeout; the transactional
email worker derives it from `EMAIL_QUEUE_WORKER_SLEEP_MS` so long sleep intervals do not create
false pause timeouts.

Useful commands:

```bash
radaptor emailqueue:pause --wait --json
radaptor emailqueue:status --json
radaptor emailqueue:resume --json
radaptor worker:pause --type email_queue --queue transactional_email --wait --json
radaptor worker:status --type email_queue --queue transactional_email --json
radaptor worker:resume --type email_queue --queue transactional_email --json
```

## License

This package is distributed under the proprietary evaluation license in
[LICENSE](./LICENSE).
Evaluation-only: no production/commercial/distribution/derivative use without
a separate license agreement.

## Contact

istvan@radaptor.com
