<?php

declare(strict_types=1);

class CLICommandResourceSpecExport extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Export resource tree spec';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Export a resource subtree into the repo-managed PHP array spec shape.

			Usage: radaptor resource-spec:export <path> [--output <file>] [--json]

			Examples:
			  radaptor resource-spec:export / --output app/resource-specs/site.php
			  radaptor resource-spec:export /admin/ --json
			DOC;
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource-spec:export <path> [--output <file>] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$output = CLIOptionHelper::getOption('output');
		$json = CLIOptionHelper::isJson();

		try {
			$spec = CmsResourceTreeSpecService::exportTreeSpec($path);

			if ($output !== '') {
				$target = str_starts_with($output, '/') ? $output : DEPLOY_ROOT . $output;
				$dir = dirname($target);

				if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
					throw new RuntimeException("Unable to create directory: {$dir}");
				}

				$bytes_written = file_put_contents($target, self::renderPhpSpec($spec), LOCK_EX);

				if ($bytes_written === false) {
					throw new RuntimeException("Unable to write resource spec: {$target}");
				}
			}

			if ($json) {
				CLIOptionHelper::writeJson([
					'status' => 'success',
					'output' => $output !== '' ? $output : null,
					'spec' => $spec,
				]);

				return;
			}

			if ($output !== '') {
				echo "Resource spec exported: {$output}\n";

				return;
			}

			echo self::renderPhpSpec($spec);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "Resource spec export failed: {$exception->getMessage()}\n";
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function renderPhpSpec(array $spec): string
	{
		return "<?php\n\nreturn " . var_export($spec, true) . ";\n";
	}
}
