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

## License

This package is distributed under the proprietary evaluation license in
[LICENSE](./LICENSE).
Evaluation-only: no production/commercial/distribution/derivative use without
a separate license agreement.

## Contact

istvan@radaptor.com
