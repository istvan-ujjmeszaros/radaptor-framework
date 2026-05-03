<?php

declare(strict_types=1);

final class RuntimeDiagnosticsAccessPolicy
{
	public static function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return $policyContext->principal->hasRole(RoleList::ROLE_SYSTEM_DEVELOPER)
			? PolicyDecision::allow('role: system_developer')
			: PolicyDecision::deny('role required: system_developer');
	}
}
