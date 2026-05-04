<?php

declare(strict_types=1);

class CLICommandResourceSpecDiff extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Diff resource tree spec';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Compare a repo-managed resource spec with the current database state.

			Usage: radaptor resource-spec:diff <spec-file> [--json]

			Examples:
			  radaptor resource-spec:diff app/resource-specs/site.php
			  radaptor resource-spec:diff app/resource-specs/site.php --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource-spec:diff <spec-file> [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$diff = CmsResourceTreeSpecService::diffSpec(CmsResourceTreeSpecService::loadSpecFile($path));
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource spec diff failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($diff);

			return;
		}

		self::renderPlainDiff($diff);
	}

	/**
	 * @param array<string, mixed> $diff
	 */
	private static function renderPlainDiff(array $diff): void
	{
		echo "Resource spec diff: {$diff['status']}\n";
		echo 'Summary: ' . json_encode($diff['summary'], JSON_UNESCAPED_SLASHES) . "\n";

		foreach ($diff['resources'] as $resource) {
			echo sprintf(
				"- %-9s %-8s %s %s\n",
				(string) $resource['action'],
				(string) $resource['type'],
				(string) $resource['path'],
				(string) $resource['message']
			);
		}
	}
}
