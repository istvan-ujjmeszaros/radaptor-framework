<?php

declare(strict_types=1);

class Migration_20260504_123000_ensure_domain_root
{
	public function getDescription(): string
	{
		return 'Ensure the configured CMS site root exists.';
	}

	public function run(): void
	{
		if (!class_exists(ResourceTreeHandler::class)) {
			throw new RuntimeException('CMS resource tree handler is not available.');
		}

		$root_id = ResourceTreeHandler::ensureConfiguredSiteRoot();

		if (!is_int($root_id) || $root_id <= 0) {
			throw new RuntimeException('Unable to ensure configured CMS site root.');
		}
	}
}
