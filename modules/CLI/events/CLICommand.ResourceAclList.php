<?php

class CLICommandResourceAclList extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'List resource ACL';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			List local ACL rows for a resource, optionally with inherited ancestors.

			Usage: radaptor resource:acl-list <path> [--resolved] [--json]

			Examples:
			  radaptor resource:acl-list /admin/
			  radaptor resource:acl-list /login.html --resolved --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Path', 'type' => 'main_arg', 'required' => true],
			['name' => 'resolved', 'label' => 'Include inherited', 'type' => 'flag'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:acl-list <path> [--resolved] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$json = CLIOptionHelper::isJson();

		try {
			$result = ['status' => 'success'] + CmsResourceSpecService::listAcl($path, Request::hasArg('resolved'));
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "ACL list failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Path: {$result['path']}\n";
		echo "Inherit: " . ($result['inherit'] ? 'yes' : 'no') . "\n";
		echo "Local rows: " . count($result['local']) . "\n";
		echo "Inherited rows: " . count($result['resolved_ancestors']) . "\n";
	}
}
