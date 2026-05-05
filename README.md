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
publish the generated artifact through `radaptor_plugin_registry`.

## License

This package is distributed under the proprietary evaluation license in
[LICENSE](./LICENSE).
Evaluation-only: no production/commercial/distribution/derivative use without
a separate license agreement.

## Contact

istvan@radaptor.com
