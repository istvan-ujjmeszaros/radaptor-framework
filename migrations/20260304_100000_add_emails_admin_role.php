<?php

/**
 * Ensure emails_admin exists under system_administrator.
 */
class Migration_20260304_100000_add_emails_admin_role
{
	public function run(): void
	{
		$systemAdministrator = DbHelper::selectOne('roles_tree', ['role' => 'system_administrator'], '', 'node_id');

		if (!is_array($systemAdministrator)) {
			return;
		}

		$parentId = (int) $systemAdministrator['node_id'];
		$emailsAdmin = DbHelper::selectOne('roles_tree', ['role' => 'emails_admin'], '', 'node_id,parent_id,title,description');

		if (!is_array($emailsAdmin)) {
			Roles::addRole([
				'role' => 'emails_admin',
				'title' => 'E-mail adminisztrátor',
				'description' => 'Email administration and sending',
			], $parentId);

			return;
		}

		$nodeId = (int) $emailsAdmin['node_id'];
		$currentParentId = (int) $emailsAdmin['parent_id'];

		if ($currentParentId !== $parentId) {
			Roles::moveToPosition($nodeId, $parentId, 0);
		}

		$changes = [
			'node_id' => $nodeId,
		];

		if ((string) $emailsAdmin['title'] !== 'E-mail adminisztrátor') {
			$changes['title'] = 'E-mail adminisztrátor';
		}

		if ((string) $emailsAdmin['description'] !== 'Email administration and sending') {
			$changes['description'] = 'Email administration and sending';
		}

		if (count($changes) > 1) {
			Roles::updateRole($changes, $nodeId);
		}
	}
}
