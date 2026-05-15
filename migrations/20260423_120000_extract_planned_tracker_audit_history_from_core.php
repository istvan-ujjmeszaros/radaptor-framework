<?php

declare(strict_types=1);

class Migration_20260423_120000_extract_planned_tracker_audit_history_from_core
{
	public function getDescription(): string
	{
		return 'Compatibility stub for tracker extraction cleanup.';
	}

	public function run(): void
	{
		// Intentionally empty.
		//
		// Package migrations must not delete app-authored CMS content. Tracker
		// cleanup now belongs to explicit app/plugin maintenance tooling, not to
		// framework migrate:run.
	}
}
