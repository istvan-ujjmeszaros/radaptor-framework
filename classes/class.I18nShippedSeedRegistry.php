<?php

class I18nShippedSeedRegistry
{
	/**
	 * @return list<array{
	 *     group_type:string,
	 *     group_id:string,
	 *     seed_dir:string,
	 *     domains:list<string>,
	 *     key_prefixes:list<string>
	 * }>
	 */
	public static function getStaticTargets(): array
	{
		$cms_root = PackagePathHelper::getCmsRoot();

		if (!is_string($cms_root) || !is_dir($cms_root)) {
			throw new RuntimeException('CMS package root is unavailable.');
		}

		$cms_root = rtrim($cms_root, '/');

		return [
			[
				'group_type' => 'core',
				'group_id' => 'cms_root',
				'seed_dir' => $cms_root . '/i18n/seeds',
				'domains' => [
					'admin',
					'cms',
					'common',
					'datatable',
					'layout',
					'mcp',
					'record_action',
					'record_meta',
					'resource',
					'response_error',
					'runtime_diagnostics',
					'selection',
					'social',
					'system_message',
					'theme',
					'widget',
					'workbench',
				],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'core',
				'group_id' => 'cms_form',
				'seed_dir' => $cms_root . '/modules-common/Form/i18n/seeds',
				'domains' => ['form'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'core',
				'group_id' => 'cms_import_export',
				'seed_dir' => $cms_root . '/modules-common/ImportExport/i18n/seeds',
				'domains' => ['import_export'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'core',
				'group_id' => 'cms_cli_runner',
				'seed_dir' => $cms_root . '/modules-common/CLIRunner/i18n/seeds',
				'domains' => ['cli_runner'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'core',
				'group_id' => 'cms_tags',
				'seed_dir' => $cms_root . '/modules-common/Tags/i18n/seeds',
				'domains' => ['tags'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'core',
				'group_id' => 'cms_user',
				'seed_dir' => $cms_root . '/modules-common/User/i18n/seeds',
				'domains' => ['user'],
				'key_prefixes' => [],
			],
		];
	}

	/**
	 * @return list<array{group_type:string,group_id:string,input_dir:string}>
	 */
	public static function getSyncTargets(): array
	{
		$targets = [];

		foreach (I18nSeedTargetDiscovery::discoverTargets() as $target) {
			$targets[] = [
				'group_type' => $target['group_type'],
				'group_id' => $target['group_id'],
				'input_dir' => $target['input_dir'],
				'source' => (string) ($target['source'] ?? 'static'),
				'relative_path' => (string) ($target['relative_path'] ?? ''),
			];
		}

		return $targets;
	}

	/**
	 * @return list<array{
	 *     group_type:string,
	 *     group_id:string,
	 *     output_dir:string,
	 *     domains:list<string>,
	 *     key_prefixes:list<string>
	 * }>
	 */
	public static function getExportTargets(): array
	{
		$targets = [];

		foreach (self::getStaticTargets() as $target) {
			$targets[] = [
				'group_type' => $target['group_type'],
				'group_id' => $target['group_id'],
				'output_dir' => $target['seed_dir'],
				'domains' => $target['domains'],
				'key_prefixes' => $target['key_prefixes'],
			];
		}

		return $targets;
	}
}
