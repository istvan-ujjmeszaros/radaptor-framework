<?php

declare(strict_types=1);

class Migration_20260504_123000_ensure_domain_root
{
	public function getDescription(): string
	{
		return 'Compatibility stub for configured CMS site root repair.';
	}

	public function run(): void
	{
		// Intentionally empty. Site-root normalization is an explicit repair
		// operation, not a package migration side effect.
	}
}
