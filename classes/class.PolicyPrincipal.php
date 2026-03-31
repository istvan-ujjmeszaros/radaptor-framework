<?php

final class PolicyPrincipal
{
	/** @var array<int>|null */
	private ?array $_groups = null;

	public function __construct(
		public readonly int $id,
	) {
	}

	public static function fromCurrentUser(): self
	{
		return new self(User::getCurrentUserId());
	}

	/**
	 * Check if this principal has the given role.
	 * Wraps Roles::hasRole() which checks the current session user.
	 */
	public function hasRole(string $role): bool
	{
		return Roles::hasRole($role);
	}

	/**
	 * Returns all usergroup IDs for this principal.
	 * Lazy-loaded, one DB call max per request.
	 *
	 * @return array<int>
	 */
	public function getGroups(): array
	{
		if ($this->_groups === null) {
			$this->_groups = Usergroups::getAllUsergroupsForUser($this->id);
		}

		return $this->_groups;
	}

	/**
	 * Check if this principal belongs to a given usergroup.
	 *
	 * System group shortcuts avoid any DB hit:
	 *   SYSTEMUSERGROUP_EVERYBODY (1) → always true
	 *   SYSTEMUSERGROUP_LOGGEDIN  (2) → user is logged in ($this->id > 0)
	 * All others: lazy-load via getGroups()
	 */
	public function inGroup(int $usergroupId): bool
	{
		if ($usergroupId === Usergroups::SYSTEMUSERGROUP_EVERYBODY) {
			return true;
		}

		if ($usergroupId === Usergroups::SYSTEMUSERGROUP_LOGGEDIN) {
			return $this->id > 0;
		}

		return in_array($usergroupId, $this->getGroups(), true);
	}
}
