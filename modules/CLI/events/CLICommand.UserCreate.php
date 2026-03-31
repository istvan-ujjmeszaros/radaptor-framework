<?php

/**
 * Create a new user interactively.
 *
 * Usage: radaptor user:create
 *
 * Prompts for:
 * - Username
 * - Password (hidden input)
 */
class CLICommandUserCreate extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Create user';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Create a new user interactively.

			Usage: radaptor user:create

			Prompts for username and password (hidden input). Not available via web runner.
			DOC;
	}

	public function run(): void
	{
		echo "\n=== Create New User ===\n\n";

		// Get username
		$username = CLIOutput::prompt("Username");

		if (empty($username)) {
			CLIOutput::error("Username is required");

			return;
		}

		// Validate username format (alphanumeric + underscore, starting with letter)
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $username)) {
			CLIOutput::error("Username must start with a letter and contain only letters, numbers, and underscores");

			return;
		}

		// Check if username already exists
		$existing = User::getUserByName($username);

		if ($existing) {
			CLIOutput::error("Username '{$username}' already exists");

			return;
		}

		// Get password
		$password = CLIOutput::promptPassword("Password");

		if (empty($password)) {
			CLIOutput::error("Password is required");

			return;
		}

		if (strlen($password) < 6) {
			CLIOutput::error("Password must be at least 6 characters");

			return;
		}

		// Confirm password
		$passwordConfirm = CLIOutput::promptPassword("Confirm password");

		if ($password !== $passwordConfirm) {
			CLIOutput::error("Passwords do not match");

			return;
		}

		// Confirm
		echo "\n";

		if (!CLIOutput::confirmDatabaseWrite("Create user \"{$username}\"")) {
			CLIOutput::info("Cancelled");

			return;
		}

		// Create user
		$hashedPassword = User::encodePassword($password);

		$userId = User::addUser([
			'username' => $username,
			'password' => $hashedPassword,
		]);

		if ($userId) {
			CLIOutput::success("Created user \"{$username}\" with ID {$userId}");
		} else {
			CLIOutput::error("Failed to create user");
		}
	}
}
