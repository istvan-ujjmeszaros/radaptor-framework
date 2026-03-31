<?php

/**
 * Class Usergroups
 * Handles operations related to user groups and their hierarchies.
 */
class Usergroups
{
	/** @var int Must match the corresponding node_id in the usergroups_tree table! */
	public const SYSTEMUSERGROUP_EVERYBODY = 1;

	/** @var int Must match the corresponding node_id in the usergroups_tree table! */
	public const SYSTEMUSERGROUP_LOGGEDIN = 2;

	/**
	 * Get the resource tree for user groups.
	 *
	 * @param int $parent_id The parent node ID.
	 * @return array<int, array{
	 *     node_id: int,
	 *     lft: int,
	 *     rgt: int,
	 *     title: string,
	 *     is_system_group: bool
	 * }>
	 */
	public static function getResourceTree(int $parent_id): array
	{
		return NestedSet::getChildren('usergroups_tree', $parent_id, [
			'title',
			'is_system_group',
		]);
	}

	/**
	 * Get resource tree entry data by ID.
	 *
	 * @param int $resource_id The ID of the resource to retrieve
	 * @return array{
	 *     node_id: int,
	 *     lft: int,
	 *     rgt: int,
	 *     parent_id: int,
	 *     is_system_group: bool,
	 *     title: string,
	 *     description: string,
	 * }|null The resource tree entry data or null if not found
	 */
	public static function getResourceTreeEntryDataById(int $resource_id): ?array
	{
		$cached = Cache::get(self::class, $resource_id);

		if (!is_null($cached)) {
			return $cached;
		}

		$return = NestedSet::getNodeInfo('usergroups_tree', $resource_id);

		return Cache::set(self::class, $resource_id, $return);
	}

	/**
	 * Get user group values.
	 *
	 * @param int $usergroup_id The user group ID.
	 * @return array<string, mixed>|null
	 */
	public static function getUsergroupValues(int $usergroup_id): ?array
	{
		return DbHelper::selectOne('usergroups_tree', ['node_id' => $usergroup_id]) ?: null;
	}

	/**
	 * Add a new user group.
	 *
	 * @param array<string, mixed> $savedata The data to save.
	 * @param int $parent_id The parent node ID.
	 * @return int|null The new node ID or null if failed.
	 */
	public static function addUsergroup(array $savedata, $parent_id = 0): ?int
	{
		return NestedSet::addNode('usergroups_tree', $parent_id, $savedata);
	}

	/**
	 * Update a user group.
	 *
	 * @param array<string, mixed> $savedata The data to update.
	 * @param int $id The user group ID.
	 * @return int The number of affected rows.
	 */
	public static function updateUsergroup(array $savedata, int $id): int
	{
		return DbHelper::updateHelper('usergroups_tree', $savedata, $id);
	}

	/**
	 * Move a node to a new position.
	 *
	 * @param int $id The node ID to move.
	 * @param int $parent_id The new parent node ID.
	 * @param int $position The new position.
	 * @return bool Whether the operation was successful.
	 */
	public static function moveToPosition(int $id, int $parent_id, int $position): bool
	{
		return NestedSet::moveToPosition('usergroups_tree', $id, $parent_id, $position);
	}

	/**
	 * Delete a user group recursively.
	 *
	 * @param int $node_id The node ID to delete.
	 * @return array{success: bool, erroneous: int, usergroup: int}
	 */
	public static function deleteUsergroupRecursive(int $node_id): array
	{
		$usergroup_count = 0;
		$erroneous_count = 0;

		// 0. lekérjük az adott id-jű node adatait
		$node_data = NestedSet::getNodeInfo('usergroups_tree', $node_id);

		$lft = $node_data['lft'];
		$rgt = $node_data['rgt'];

		if ($rgt - $lft == 1) {
			if (Usergroups::deleteUsergroup($node_id)) {
				++$usergroup_count;
			} else {
				++$erroneous_count;
			}
		} else {
			// 1. Lekérjük az alatta lévő node-okat, (rgt-lft) szerint rendezve
			$stmt = Db::instance()
					  ->prepare("SELECT node_id, (rgt-lft) AS rgtlft FROM usergroups_tree WHERE lft >= ? AND rgt <= ? ORDER BY rgtlft ASC, node_id DESC");
			$stmt->execute([
				$lft,
				$rgt,
			]);

			$rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rs as $node) {
				++$usergroup_count;
				Usergroups::deleteUsergroup($node['node_id']);
			}
		}

		if ($erroneous_count + $usergroup_count > 0) {
			$success = true;
		} else {
			$success = false;
		}

		return ([
			'success' => $success,
			'erroneous' => $erroneous_count,
			'usergroup' => $usergroup_count,
		]);
	}

	/**
	 * Delete a user group.
	 *
	 * @param int $node_id The node ID to delete.
	 * @return bool Whether the operation was successful.
	 */
	public static function deleteUsergroup(int $node_id): bool
	{
		return NestedSet::deleteNode('usergroups_tree', $node_id);
	}

	/**
	 * Assign a user group to a user.
	 *
	 * @param int $usergroup_id The user group ID.
	 * @param int $user_id The user ID.
	 * @return bool True if the assignment was successful, false otherwise.
	 */
	public static function assignToUser(int $usergroup_id, int $user_id): bool
	{
		try {
			EntityUsersUsergroupsMapping::saveFromArray([
				'usergroup_id' => $usergroup_id,
				'user_id' => $user_id,
			]);

			return true;
		} catch (EntitySaveException) {
			return false;
		}
	}

	/**
	 * Remove a user group from a user.
	 *
	 * @param int $usergroup_id The user group ID.
	 * @param int $user_id The user ID.
	 * @return bool Whether the operation was successful.
	 */
	public static function removeFromUser(int $usergroup_id, int $user_id): bool
	{
		return EntityUsersUsergroupsMapping::deleteByKey($user_id, $usergroup_id);
	}

	/**
	 * Check if a user is assigned to a user group.
	 *
	 * @param int $usergroup_id The user group ID.
	 * @param int $user_id The user ID.
	 * @return bool Whether the user is assigned to the group.
	 */
	public static function checkUserIsAssigned(int $usergroup_id, int $user_id): bool
	{
		return EntityUsersUsergroupsMapping::exists($user_id, $usergroup_id);
	}

	/**
	 * Get system user groups for the current user.
	 *
	 * @return array<int> The system user group IDs.
	 */
	public static function getSystemUsergroupsForCurrentUser(): array
	{
		$return = [];

		$current_user_id = User::getCurrentUserId();

		$return[] = self::SYSTEMUSERGROUP_EVERYBODY;

		if ($current_user_id > 0) {
			$return[] = self::SYSTEMUSERGROUP_LOGGEDIN;
		}

		return $return;
	}

	/**
	 * Get all user groups for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return array<int> The user group IDs.
	 */
	public static function getAllUsergroupsForUser(int $user_id): array
	{
		$users_system_usergroups = self::getSystemUsergroupsForCurrentUser();
		$users_usergroups = [];

		$query = "
			SELECT
			ugm.usergroup_id,
			ugt.lft,
			ugt.rgt
			FROM
			users_usergroups_mapping ugm
			LEFT JOIN usergroups_tree ugt
				ON ugt.node_id = ugm.usergroup_id
			WHERE user_id = ?;
		";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$user_id]);

		$rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rs as $usergroup) {
			$query = "SELECT node_id FROM usergroups_tree WHERE lft>=? AND rgt<=?";
			$stmt = Db::instance()->prepare($query);
			$stmt->execute([
				$usergroup['lft'],
				$usergroup['rgt'],
			]);

			$rs = $stmt->fetchAll(PDO::FETCH_COLUMN);

			foreach ($rs as $value) {
				$users_usergroups[] = (int)$value;
			}
		}

		return array_unique(array_merge($users_system_usergroups, $users_usergroups));
	}

	/**
	 * Get user group by name.
	 *
	 * @param string $title The user group title.
	 * @return array<string, mixed>|null The user group data or null if not found.
	 */
	public static function getUsergroupByName(string $title): ?array
	{
		return DbHelper::selectOne('usergroups_tree', ['title' => $title]);
	}
}
