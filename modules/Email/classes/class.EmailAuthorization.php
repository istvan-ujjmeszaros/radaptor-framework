<?php

declare(strict_types=1);

class EmailAuthorization
{
	public static function canCurrentUserEnqueue(): bool
	{
		return Roles::hasRole(RoleList::ROLE_EMAILS_ADMIN);
	}

	public static function canRequestedPrincipalExecute(string $requested_by_type, ?int $requested_by_id): bool
	{
		if ($requested_by_type !== 'user' || is_null($requested_by_id) || $requested_by_id <= 0) {
			return false;
		}

		return Roles::userHasRole($requested_by_id, RoleList::ROLE_EMAILS_ADMIN);
	}
}
