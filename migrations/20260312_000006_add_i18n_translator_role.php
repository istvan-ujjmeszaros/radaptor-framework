<?php

/**
 * Ensure i18n_translator exists under system_administrator.
 */
class Migration_20260312_000006_add_i18n_translator_role
{
	public function run(): void
	{
		$systemAdministrator = DbHelper::selectOne('roles_tree', ['role' => 'system_administrator'], '', 'node_id');

		if (!is_array($systemAdministrator)) {
			return;
		}

		$parentId = (int) $systemAdministrator['node_id'];
		$translatorRole = DbHelper::selectOne('roles_tree', ['role' => 'i18n_translator'], '', 'node_id,parent_id,title,description');

		if (!is_array($translatorRole)) {
			Roles::addRole([
				'role' => 'i18n_translator',
				'title' => 'Fordító',
				'description' => 'Manage i18n translations in the workbench and import/export flows',
			], $parentId);

			return;
		}

		$nodeId = (int) $translatorRole['node_id'];
		$currentParentId = (int) $translatorRole['parent_id'];

		if ($currentParentId !== $parentId) {
			Roles::moveToPosition($nodeId, $parentId, 0);
		}

		$changes = [
			'node_id' => $nodeId,
		];

		if ((string) $translatorRole['title'] !== 'Fordító') {
			$changes['title'] = 'Fordító';
		}

		if ((string) $translatorRole['description'] !== 'Manage i18n translations in the workbench and import/export flows') {
			$changes['description'] = 'Manage i18n translations in the workbench and import/export flows';
		}

		if (count($changes) > 1) {
			Roles::updateRole($changes, $nodeId);
		}
	}
}
