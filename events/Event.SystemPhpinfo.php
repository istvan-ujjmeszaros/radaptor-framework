<?php

class EventSystemPhpinfo extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return $policyContext->principal->hasRole(RoleList::ROLE_SYSTEM_DEVELOPER)
			? PolicyDecision::allow('role: system_developer')
			: PolicyDecision::deny();
	}

	public function run(): void
	{
		if (Roles::hasRole(RoleList::ROLE_SYSTEM_DEVELOPER)) {
			phpinfo();
		} else {
			SystemMessages::_warning(t('response_error.access_restricted'));
			Kernel::redirectToReferer();
		}
	}
}
