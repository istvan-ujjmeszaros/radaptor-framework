<?php

declare(strict_types=1);

class CLITrustPolicy
{
	public static function isTrustedOperatorCli(?bool $is_cli_runtime = null, ?bool $has_web_runner_bridge = null): bool
	{
		$is_cli_runtime ??= self::isCliRuntime();
		$has_web_runner_bridge ??= self::hasWebRunnerBridge();

		return $is_cli_runtime && !$has_web_runner_bridge;
	}

	public static function isCliRuntime(): bool
	{
		return defined('RADAPTOR_CLI') && !defined('RADAPTOR_MCP');
	}

	public static function hasWebRunnerBridge(?bool $is_web_runner_process = null): bool
	{
		$is_web_runner_process ??= CLIWebRunnerUserBridge::isWebRunnerProcess();

		return $is_web_runner_process;
	}
}
