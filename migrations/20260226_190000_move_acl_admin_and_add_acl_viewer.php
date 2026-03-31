<?php

/**
 * Move acl_admin under system_developer and add acl_viewer under system_administrator.
 */
class Migration_20260226_190000_move_acl_admin_and_add_acl_viewer
{
	public function run(): void
	{
		$systemDeveloper = DbHelper::selectOne('roles_tree', ['role' => 'system_developer'], '', 'node_id');
		$systemAdministrator = DbHelper::selectOne('roles_tree', ['role' => 'system_administrator'], '', 'node_id');
		$aclAdmin = DbHelper::selectOne('roles_tree', ['role' => 'acl_admin'], '', 'node_id,parent_id');
		$aclViewer = DbHelper::selectOne('roles_tree', ['role' => 'acl_viewer'], '', 'node_id');

		if (is_array($aclAdmin) && is_array($systemDeveloper) && (int) $aclAdmin['parent_id'] !== (int) $systemDeveloper['node_id']) {
			Roles::moveToPosition((int) $aclAdmin['node_id'], (int) $systemDeveloper['node_id'], 0);
		}

		if (is_null($aclViewer) && is_array($systemAdministrator)) {
			Roles::addRole([
				'role' => 'acl_viewer',
				'title' => 'Weboldal hozzáférés megtekintő',
				'description' => 'Resource ACL viewer',
			], (int) $systemAdministrator['node_id']);
		}
	}
}
