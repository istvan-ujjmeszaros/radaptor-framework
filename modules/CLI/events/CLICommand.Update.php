<?php

/**
 * Update packages from radaptor.json by resolving newer compatible registry versions.
 *
 * Usage: radaptor update [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides] [--apply-layout-renames | --abort-on-layout-renames]
 */
class CLICommandUpdate extends CLICommandInstall
{
	public function getName(): string
	{
		return 'Update packages';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Update packages from radaptor.json by resolving newer compatible registry versions.

			Usage: radaptor update [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json] [--ignore-local-overrides] [--apply-layout-renames | --abort-on-layout-renames]

			Layout renames:
			  After the registry packages are installed, the update inspects every incoming
			  package's .registry-package.json for deprecated_layouts declarations and detects
			  affected webpages and _theme_settings rows. The gate runs BEFORE lockfile-write,
			  asset-build, plugin-bridge, migrations, and seeds, so an abort or failure leaves
			  the rest of the update steps unexecuted and the next run can resume.
			    --apply-layout-renames    apply pending renames non-interactively
			    --abort-on-layout-renames refuse renames non-interactively (exits with error)
			  In CI / non-TTY mode with neither flag set, the command aborts with exit 1.

			Examples:
			  radaptor update
			  radaptor update --include-demo-seeds
			  radaptor update --include-demo-seeds --rerun-demo-seeds
			  radaptor update --skip-seeds
			  radaptor update --dry-run
			  radaptor update --json
			  radaptor update --ignore-local-overrides
			  radaptor update --apply-layout-renames
			  radaptor update --abort-on-layout-renames
			DOC;
	}

	public function run(): void
	{
		$this->runMode(true);
	}
}
