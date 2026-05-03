<?php

class CLICommandResourceFileUsage extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Find uploaded file usage';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Find virtual filesystem resources that reference an uploaded file.

			Usage: radaptor resource:file-usage [path] [--file-id <id>] [--json]

			Examples:
			  radaptor resource:file-usage /uploads/logo.png
			  radaptor resource:file-usage --file-id 42 --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'VFS path', 'type' => 'main_arg', 'required' => false],
			['name' => 'file-id', 'label' => 'File id', 'type' => 'option', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$path = Request::getMainArg();
		$path = is_string($path) && !str_starts_with($path, '--') ? $path : null;
		$file_id_option = CLIOptionHelper::getOption('file-id');
		$file_id = $file_id_option !== '' && is_numeric($file_id_option) ? (int) $file_id_option : null;

		try {
			$result = CmsUsageInspector::inspectFileUsage($file_id, is_string($path) ? $path : null);
		} catch (Throwable $exception) {
			if (Request::hasArg('json')) {
				echo json_encode([
					'status' => 'error',
					'message' => $exception->getMessage(),
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

				return;
			}

			echo "File usage check failed: {$exception->getMessage()}\n";

			return;
		}

		if (Request::hasArg('json')) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		echo "Uploaded file #{$result['file_id']} is referenced by {$result['reference_count']} VFS resource(s).\n";

		if (!$result['file_exists']) {
			echo "  Warning: media container row is missing.\n";
		} elseif (!$result['physical_exists']) {
			echo "  Warning: stored physical file is missing.\n";
		}

		foreach ($result['resources'] as $resource) {
			echo "  - {$resource['path']} (node_id: {$resource['node_id']})\n";
		}
	}
}
