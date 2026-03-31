<?php

class SeedContext
{
	public function __construct(
		public readonly string $module,
		public readonly string $kind,
		public readonly string $basePath,
		public readonly bool $dryRun,
	) {
	}
}
