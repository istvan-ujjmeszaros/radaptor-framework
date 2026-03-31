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
		$cms_root = rtrim(PackagePathHelper::getCmsRoot() ?? (DEPLOY_ROOT . 'radaptor/radaptor-cms'), '/');
		$blog_root = rtrim(PackagePathHelper::getPackageRoot('plugin', 'blog') ?? (DEPLOY_ROOT . 'plugins/dev/blog'), '/');
		$tracker_root = rtrim(PackagePathHelper::getPackageRoot('plugin', 'tracker') ?? (DEPLOY_ROOT . 'plugins/dev/tracker'), '/');

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
					'record_action',
					'record_meta',
					'resource',
					'response_error',
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
			[
				'group_type' => 'plugin',
				'group_id' => 'blog',
				'seed_dir' => $blog_root . '/modules/Blog/i18n/seeds',
				'domains' => ['blog'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'plugin',
				'group_id' => 'tracker_company',
				'seed_dir' => $tracker_root . '/modules/Company/i18n/seeds',
				'domains' => ['company'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'plugin',
				'group_id' => 'tracker_contact_person',
				'seed_dir' => $tracker_root . '/modules/ContactPerson/i18n/seeds',
				'domains' => ['contact'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'plugin',
				'group_id' => 'tracker_project',
				'seed_dir' => $tracker_root . '/modules/Project/i18n/seeds',
				'domains' => ['project'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'plugin',
				'group_id' => 'tracker_ticket',
				'seed_dir' => $tracker_root . '/modules/Ticket/i18n/seeds',
				'domains' => ['ticket'],
				'key_prefixes' => [],
			],
			[
				'group_type' => 'plugin',
				'group_id' => 'tracker_time_tracker',
				'seed_dir' => $tracker_root . '/modules/TimeTracker/i18n/seeds',
				'domains' => ['timetracker'],
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

		foreach (self::getStaticTargets() as $target) {
			$targets[] = [
				'group_type' => $target['group_type'],
				'group_id' => $target['group_id'],
				'input_dir' => $target['seed_dir'],
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
