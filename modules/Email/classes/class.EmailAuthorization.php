<?php

declare(strict_types=1);

class EmailAuthorization
{
	public const string REQUESTED_BY_TYPE_SYSTEM = 'system';
	public const string REQUESTED_BY_TYPE_USER = 'user';

	public static function canCurrentUserEnqueue(): bool
	{
		return Roles::hasRole(RoleList::ROLE_EMAILS_ADMIN);
	}

	public static function canRequestedPrincipalExecute(string $requested_by_type, ?int $requested_by_id): bool
	{
		if ($requested_by_type === self::REQUESTED_BY_TYPE_SYSTEM) {
			return true;
		}

		if ($requested_by_type !== self::REQUESTED_BY_TYPE_USER || is_null($requested_by_id) || $requested_by_id <= 0) {
			return false;
		}

		return Roles::userHasRole($requested_by_id, RoleList::ROLE_EMAILS_ADMIN);
	}
}
