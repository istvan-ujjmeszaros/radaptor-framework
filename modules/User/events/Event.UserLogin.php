<?php

class EventUserLogin extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		// Prompt for username
		$username = readline("Enter username: ");

		// Prompt for password (hiding input)
		echo "Enter password: ";
		system('stty -echo');
		$password = trim(fgets(STDIN));
		system('stty echo');
		echo "\n";

		User::loginUser($username, $password);

		if (User::getCurrentUser()) {
			echo "\nLogged in as '$username'.";
		} else {
			echo "\nLogin failed.";
		}
	}
}
