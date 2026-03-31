<?php

/**
 * Update packages from radaptor.json by resolving newer compatible registry versions.
 *
 * Usage: radaptor update [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json]
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

			Usage: radaptor update [--include-demo-seeds] [--rerun-demo-seeds] [--skip-seeds] [--dry-run] [--json]

			Examples:
			  radaptor update
			  radaptor update --include-demo-seeds
			  radaptor update --include-demo-seeds --rerun-demo-seeds
			  radaptor update --skip-seeds
			  radaptor update --dry-run
			  radaptor update --json
			DOC;
	}

	public function run(): void
	{
		$this->runMode(true);
	}
}
