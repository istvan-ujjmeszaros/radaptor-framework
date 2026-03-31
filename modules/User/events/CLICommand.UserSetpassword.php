<?php

/**
 * Set password for a user via CLI.
 *
 * Usage: radaptor user:setpassword <username>
 *
 * Password is prompted interactively (hidden input).
 * Requires confirmation before database write.
 */
class CLICommandUserSetpassword extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Set user password';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Set password for a user via CLI.

			Usage: radaptor user:setpassword <username>

			Password is prompted interactively (hidden input). Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		// Get username as positional argument (not --username=xxx)
		$username = Request::getMainArg();

		if (is_null($username)) {
			Kernel::abort("Usage: radaptor user:setpassword <username>");
		}

		// Find user first
		$user = EntityUser::findFirst(['username' => $username]);

		if (is_null($user)) {
			CLIOutput::error("User '{$username}' not found.");

			return;
		}

		// Ask for password (hidden input)
		$password = CLIOutput::promptPassword("Enter new password: ");

		if (empty($password)) {
			CLIOutput::error("Password cannot be empty.");

			return;
		}

		// Confirm with color-coded database info
		if (!CLIOutput::confirmDatabaseWrite("Update password for user '{$username}'")) {
			CLIOutput::info("Aborted.");

			return;
		}

		// Hash and save
		$hashedPassword = UserBase::encodePassword($password);
		EntityUser::saveFromArray([
			'user_id' => $user->user_id,
			'password' => $hashedPassword,
		]);

		CLIOutput::success("Password updated for user '{$username}'.");
	}
}
