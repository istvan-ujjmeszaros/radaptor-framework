<?php

declare(strict_types=1);

class CLICommandSiteCutoverRelease extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Release site cutover lock';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Explicitly release the source site cutover read-only lock.

			Usage: radaptor site:cutover-release --confirm "I understand the previous migration export may be inconsistent" [--note <text>] [--keep-workers-paused] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'confirm', 'label' => 'Confirmation text', 'type' => 'option', 'required' => true],
			['name' => 'note', 'label' => 'Release note', 'type' => 'option'],
			['name' => 'keep-workers-paused', 'label' => 'Keep workers paused', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor site:cutover-release --confirm "' . RuntimeSiteCutoverGuard::RELEASE_CONFIRMATION_TEXT . '" [--note <text>] [--keep-workers-paused] [--json]';
		$confirm = CLIOptionHelper::getRequiredOption('confirm', $usage);
		$note = CLIOptionHelper::getOption('note', '');
		$resume_workers = !Request::hasArg('keep-workers-paused');
		$result = RuntimeSiteCutoverGuard::releaseSourceCutover($confirm, $note, $resume_workers);

		if (($result['status'] ?? '') === 'confirmation_required') {
			if (CLIOptionHelper::isJson()) {
				CLIOptionHelper::writeJson($result);

				exit(1);
			}

			echo 'Confirmation required. Re-run with: ' . RuntimeSiteCutoverGuard::RELEASE_CONFIRMATION_TEXT . "\n";

			exit(1);
		}

		if (CLIOptionHelper::isJson()) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo 'Site cutover locks released: ' . (int) ($result['released'] ?? 0) . "\n";

		if (is_array($result['worker_pause_release'] ?? null)) {
			echo 'Worker pause requests released: ' . (int) ($result['worker_pause_release']['released'] ?? 0) . "\n";
		}
	}
}
