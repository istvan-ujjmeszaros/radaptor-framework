<?php

declare(strict_types=1);

class Migration_20260504_122000_repair_admin_login_webpage
{
	public function getDescription(): string
	{
		return 'Compatibility stub for admin login webpage repair.';
	}

	public function run(): void
	{
		// Intentionally empty. Login page layout/widget assignment is app
		// content and must be reconciled by app seed or explicit resource spec.
	}
}
