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

## License

This package is distributed under the proprietary evaluation license in
[LICENSE](./LICENSE).
Evaluation-only: no production/commercial/distribution/derivative use without
a separate license agreement.

## Contact

istvan@radaptor.com
