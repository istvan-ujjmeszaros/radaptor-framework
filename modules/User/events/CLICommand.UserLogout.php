<?php

class CLICommandUserLogout extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Logout current user';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Log out the currently logged-in CLI user.

			Usage: radaptor user:logout

			Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		$current_user = User::getCurrentUser();

		if (is_null($current_user)) {
			echo "No user is currently logged in.";
		} else {
			User::logout();
			echo "The user '{$current_user['username']}' has been logged out.";
		}
	}
}
