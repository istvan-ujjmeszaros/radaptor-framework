<?php

/**
 * Refactor role identifiers to the new canonical naming scheme
 * and remove deprecated roles from roles_tree.
 */
class Migration_20260226_180000_refactor_roles_hierarchy
{
	public function run(): void
	{
		$pdo = Db::instance();

		$renameMap = [
			'blog_administrator' => 'blog_admin',
			'content_editor' => 'content_admin',
			'domain_administrator' => 'domains_admin',
			'file_uploader' => 'files_admin',
			'resource_acl_administrator' => 'acl_admin',
			'role_administrator' => 'roles_admin',
			'role_spectator' => 'roles_viewer',
			'timetracker_spectator' => 'timetracker_viewer',
			'user_administrator' => 'users_admin',
			'user_role_administrator' => 'users_role_admin',
			'user_usergroup_administrator' => 'users_usergroup_admin',
			'usergroup_administrator' => 'usergroups_admin',
			'usergroup_role_administrator' => 'usergroups_role_admin',
		];

		foreach ($renameMap as $oldRole => $newRole) {
			$stmt = $pdo->prepare("
				UPDATE roles_tree
				SET role = :new_role
				WHERE role = :old_role
			");
			$stmt->execute([
				':new_role' => $newRole,
				':old_role' => $oldRole,
			]);
		}

		$deprecatedRoles = [
			'appearance_administrator',
			'catcher_page_administrator',
			'gallery_administrator',
			'richtext_article_administrator',
			'richtext_info_administrator',
		];

		foreach ($deprecatedRoles as $role) {
			$row = DbHelper::selectOne('roles_tree', ['role' => $role], '', 'node_id');

			if (is_array($row)) {
				Roles::deleteRoleRecursive((int) $row['node_id']);
			}
		}
	}
}
