<?php

declare(strict_types=1);

class Migration_20260504_125000_normalize_site_root_and_repair_active_site_pages
{
	public function getDescription(): string
	{
		return 'Compatibility stub for active-site resource repair.';
	}

	public function run(): void
	{
		// Intentionally empty. This migration used to normalize roots and repair
		// pages; that is now forbidden in package migrations.
	}
}
