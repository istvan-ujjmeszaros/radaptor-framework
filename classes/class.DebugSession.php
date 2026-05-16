<?php

declare(strict_types=1);

final class DebugSession
{
	private const string HEADER_NAME = 'Radaptor-Debug';

	private const array FEATURES = [
		'tree',
		'dommap',
		'timings',
	];

	public static function beginIfRequested(): void
	{
		$ctx = RequestContextHolder::current();

		if ($ctx->debug->enabled) {
			return;
		}

		if (Request::header(self::HEADER_NAME) !== '1') {
			$ctx->debug = DebugSessionState::disabled();

			return;
		}

		if (!self::isDebugAllowed()) {
			$ctx->debug = DebugSessionState::disabled();

			return;
		}

		$ctx->debug = DebugSessionState::enabled(
			sessionId: self::generateId('dbg'),
			requestId: self::generateId('req'),
			features: self::FEATURES
		);
	}

	public static function isEnabled(): bool
	{
		return self::state()->enabled;
	}

	public static function sessionId(): string
	{
		return self::state()->sessionId;
	}

	public static function requestId(): string
	{
		return self::state()->requestId;
	}

	/**
	 * @return list<string>
	 */
	public static function features(): array
	{
		return self::state()->features;
	}

	/**
	 * Whether this request should skip persistent page-cache reads for debug.
	 *
	 * This runs from persistent_cache_reader.php, before Kernel initialization,
	 * so the session/user/roles are not available yet. It therefore gates only
	 * on the debug header plus the DEV_APP_DEBUG_INFO config flag — the
	 * developer-role check still happens later in isDebugAllowed(). Keeping the
	 * config flag here means the header cannot bypass the cache in production.
	 */
	public static function isCacheBypassRequested(): bool
	{
		return (bool)Config::DEV_APP_DEBUG_INFO->value()
			&& Request::header(self::HEADER_NAME) === '1';
	}

	private static function state(): DebugSessionState
	{
		return RequestContextHolder::current()->debug;
	}

	private static function isDebugAllowed(): bool
	{
		if (!Config::DEV_APP_DEBUG_INFO->value()) {
			return false;
		}

		$raw_environment = getenv('ENVIRONMENT') === false ? 'production' : getenv('ENVIRONMENT');

		if ($raw_environment === 'development') {
			return true;
		}

		try {
			return Roles::hasRole(RoleList::ROLE_SYSTEM_DEVELOPER);
		} catch (Throwable) {
			return false;
		}
	}

	private static function generateId(string $prefix): string
	{
		return $prefix . '_' . bin2hex(random_bytes(8));
	}
}
