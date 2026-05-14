# Radaptor Framework Package Notes

The canonical repo workflow rules live in [`AGENTS.md`](./AGENTS.md). Treat `AGENTS.md` as source
of truth.

## Package Scope

- This is the standalone `radaptor/core/framework` package repository.
- Framework changes belong here, not in consumer app `packages/registry/...` copies.
- CMS-owned behavior, services, resource specs, site snapshots, and CMS-specific CLI commands
  belong in `radaptor/core/cms`. Put only generic infrastructure here.

## Supported Runtime

- Run checks from a Radaptor consumer app container, not with host PHP or host Composer.
- PHP-CS-Fixer from the `_RADAPTOR` workspace:
  ```
  ../../../bin/docker-compose-packages-dev.sh radaptor-app-skeleton exec -T php bash -lc \
    'cd /workspace/packages-dev/core/framework && /app/php-cs-fixer.sh --config=.php-cs-fixer.php'
  ```
- PHPStan: use the documented workspace command from the root `AGENTS.md` (runs the
  `NonHtmlResponseHeaderDetectionRule` autoload alongside this repo's `phpstan.neon`).

## Runtime Response Detection Rule

- When adding or touching PHP files that can inspect response-family headers (`HTTP_ACCEPT`,
  `HTTP_X_REQUESTED_WITH`, `HTTP_HX_REQUEST`), add them to `phpstan.neon`'s `paths` entry so the
  detection rule actually checks them.
- New code must use `Request::wantsNonHtmlResponse()`; do not hand-read those headers and do not
  add `ajax=1`-style query fallbacks.

## Commit & PR

- Do not commit without explicit maintainer approval.
- After opening or updating a GitHub PR, add a PR comment containing exactly `@codex review`.
- Thread-aware review reads; resolve threads that pushed commits address; never resolve to clear
  the list. Re-check unresolved count before requesting another review, merging, or publishing.
- After publishing this package, update every dependent consumer lockfile/runtime in separate commits.
