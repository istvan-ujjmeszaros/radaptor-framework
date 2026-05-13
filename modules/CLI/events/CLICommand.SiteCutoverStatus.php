<?php

declare(strict_types=1);

class CLICommandSiteCutoverStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show site cutover lock status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show whether this site instance is locked read-only after a site migration export.

			Usage: radaptor site:cutover-status [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$result = RuntimeSiteCutoverGuard::getStatus();

		if (CLIOptionHelper::isJson()) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo 'Site cutover lock: ' . (($result['active'] ?? false) ? 'active' : 'inactive') . "\n";

		if (($result['active'] ?? false) === true) {
			echo RuntimeSiteCutoverGuard::readonlyMessage() . "\n";
		}
	}
}
