<?php

/**
 * Base class for all CLI command handlers.
 *
 * This is a separate hierarchy from AbstractEvent — CLI commands are not HTTP
 * events. SSH access is the trust boundary; authorization is opt-in via
 * iAuthorizable for high-risk commands.
 */
abstract class AbstractCLICommand
{
	abstract public function run(): void;

	/** Human-readable command name, e.g. "Show table schema". */
	abstract public function getName(): string;

	/** Usage documentation: what the command does, parameters, examples. */
	abstract public function getDocs(): string;

	/**
	 * Parameter definitions for the web GUI form.
	 *
	 * @return list<array{name: string, label: string, type: 'main_arg'|'flag'|'option', required?: bool, default?: string, choices?: array<string, string>}>
	 */
	public function getWebParams(): array
	{
		return [];
	}

	/** Whether this command can be run from the web GUI. */
	public function isWebRunnable(): bool
	{
		return false;
	}

	/**
	 * Risk level for web execution.
	 *
	 * - 'safe': read-only, no confirmation needed
	 * - 'build': file generation, light confirmation
	 * - 'mutation': data/state change, confirmation required
	 */
	public function getRiskLevel(): string
	{
		return 'safe';
	}

	/** Timeout in seconds for web execution. */
	public function getWebTimeout(): int
	{
		return 30;
	}
}
