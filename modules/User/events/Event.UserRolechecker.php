<?php

class EventUserRolechecker extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		$args = $_SERVER['argv'];

		if (count($args) < 3) {
			echo "Please provide a role name as a command line argument.\n";

			return;
		}

		$roleName = $args[2];

		if (!Roles::roleNameExists($roleName)) {
			echo "The role '{$roleName}' does not exist.\n";

			return;
		}

		$currentUser = User::getCurrentUser();

		if (!$currentUser) {
			echo "Run 'radaptor user:login' to login with a user to check against.\n";

			return;
		}

		if (Roles::hasRole($roleName)) {
			echo "The logged in user '{$currentUser['username']}' has the role '{$roleName}'\n";
		} else {
			echo "The logged in user '{$currentUser['username']}' do not have the role '{$roleName}'\n";
		}
	}
}
