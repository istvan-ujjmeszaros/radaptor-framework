<?php

class CLICommandResourceAclSync extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Sync resource ACL';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Reconcile one resource ACL against a JSON spec.

			Usage: radaptor resource:acl-sync <path> --spec-json <json> [--dry-run] [--json]

			Examples:
			  radaptor resource:acl-sync /admin/ --spec-json '{"inherit":false,"usergroups":{"Administrators":{"view":true,"list":true,"edit":true,"create":true}}}'
			  radaptor resource:acl-sync /comparison/ --spec-json '{"inherit":true,"usergroups":[]}' --json
			DOC;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function run(): void
	{
		$usage = 'Usage: radaptor resource:acl-sync <path> --spec-json <json> [--dry-run] [--json]';
		$path = CLIOptionHelper::getMainArgOrAbort($usage);
		$spec = CLIOptionHelper::getJsonOptionAsArray('spec-json', true, $usage);
		$dry_run = Request::hasArg('dry-run');
		$json = CLIOptionHelper::isJson();

		try {
			$result = $dry_run
				? ['status' => 'success', 'dry_run' => true, 'spec' => $spec]
				: ['status' => 'success', 'dry_run' => false] + CmsResourceSpecService::syncAclForPath($path, $spec);
		} catch (Throwable $exception) {
			if ($json) {
				CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

				return;
			}

			echo "ACL sync failed: {$exception->getMessage()}\n";

			return;
		}

		if ($json) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo ($dry_run ? '[dry-run] ' : '') . "ACL sync prepared for {$path}.\n";
	}
}
