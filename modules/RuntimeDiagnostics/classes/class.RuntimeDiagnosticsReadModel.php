<?php

declare(strict_types=1);

final class RuntimeDiagnosticsReadModel
{
	/**
	 * @return array{
	 *     environment: array<string, mixed>,
	 *     email: array<string, mixed>,
	 *     database: array<string, mixed>,
	 *     redis: array<string, mixed>,
	 *     mcp: array<string, mixed>,
	 *     packages: array<string, mixed>,
	 *     warnings: list<string>
	 * }
	 */
	public static function getSummary(): array
	{
		$warnings = [];
		$environment = self::buildEnvironment();
		$email = self::buildEmail((string) $environment['environment'], $warnings);
		$database = self::buildDatabase();
		$redis = self::buildRedis();
		$mcp = self::buildMcp();
		$packages = self::buildPackages($warnings);

		return [
			'environment' => $environment,
			'email' => $email,
			'database' => $database,
			'redis' => $redis,
			'mcp' => $mcp,
			'packages' => $packages,
			'warnings' => array_values(array_unique($warnings)),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildEnvironment(): array
	{
		return [
			'environment' => Kernel::getEnvironment(),
			'application_identifier' => Config::APP_APPLICATION_IDENTIFIER->value(),
			'domain_context' => Config::APP_DOMAIN_CONTEXT->value(),
			'site_context' => class_exists('CmsSiteContext') && method_exists('CmsSiteContext', 'resolve') ? CmsSiteContext::resolve() : Config::APP_DOMAIN_CONTEXT->value(),
			'runtime' => getenv('RADAPTOR_RUNTIME') ?: PHP_SAPI,
		];
	}

	/**
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function buildEmail(string $environment, array &$warnings): array
	{
		$error = null;

		try {
			$transport = EmailSmtpTransport::resolveEffectiveSettings($environment);
		} catch (EmailJobProcessingException $exception) {
			$error = [
				'code' => $exception->getErrorCodeString(),
				'message' => $exception->getMessage(),
			];
			$transport = [
				'host' => trim((string) Config::EMAIL_SMTP_HOST->value()),
				'port' => max(1, (int) Config::EMAIL_SMTP_PORT->value()),
				'username' => trim((string) Config::EMAIL_SMTP_USERNAME->value()),
				'password' => (string) Config::EMAIL_SMTP_PASSWORD->value(),
				'use_starttls' => (bool) Config::EMAIL_SMTP_USE_STARTTLS->value(),
				'ehlo_host' => trim((string) Config::EMAIL_SMTP_EHLO_HOST->value()),
				'from_address' => trim((string) Config::EMAIL_FROM_ADDRESS->value()),
				'from_name' => trim((string) Config::EMAIL_FROM_NAME->value()),
				'using_catcher' => false,
			];
			$warnings[] = $exception->getMessage();
		}

		$transport = RuntimeDiagnosticsRedactor::redactArray($transport);
		$using_catcher = (bool) ($transport['using_catcher'] ?? false);
		$host = trim((string) ($transport['host'] ?? ''));
		$mailpit_ui_url = self::inferMailpitUiUrl();

		if ($environment !== 'production' && !$using_catcher) {
			$warnings[] = 'Non-production email is not using the catcher; test sends may leave this environment.';
		}

		if ($environment === 'production' && ($using_catcher || self::looksLikeCatcherHost($host))) {
			$warnings[] = 'Production email appears to use a catcher host.';
		}

		if (!$using_catcher && $host === '') {
			$warnings[] = 'SMTP host is not configured.';
		}

		if ($using_catcher && $mailpit_ui_url === null) {
			$warnings[] = 'Mailpit UI URL could not be inferred from APP_MAILPIT_HTTP_PORT.';
		}

		return [
			'transport' => $transport,
			'using_catcher' => $using_catcher,
			'safe_to_test' => $environment !== 'production' && $using_catcher,
			'catcher' => [
				'host' => Config::EMAIL_CATCHER_HOST->value(),
				'smtp_port' => Config::EMAIL_CATCHER_SMTP_PORT->value(),
				'mailpit_ui_url' => $mailpit_ui_url,
			],
			'error' => $error,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildDatabase(): array
	{
		return RuntimeDiagnosticsRedactor::parseDsn((string) Config::DB_DEFAULT_DSN->value());
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildRedis(): array
	{
		return [
			'session' => self::redisEndpoint('SESSION_REDIS'),
			'cache' => self::redisEndpoint('CACHE_REDIS'),
			'test' => self::redisEndpoint('TEST_REDIS', include_if_empty: false)
				?? self::redisEndpoint('REDIS_TEST', include_if_empty: false),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildMcp(): array
	{
		$port = self::envString('APP_MCP_PORT') ?? '9512';
		$configured_origins = self::envString('APP_MCP_ALLOWED_ORIGINS');

		return [
			'public_url' => self::inferMcpEndpointUrl($port),
			'port' => is_numeric($port) ? (int) $port : $port,
			'allowed_origins' => self::allowedMcpOrigins($port),
			'allowed_origins_configured' => $configured_origins !== null,
			'enabled_hint' => true,
		];
	}

	/**
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function buildPackages(array &$warnings): array
	{
		$local_manifest_path = rtrim(DEPLOY_ROOT, '/') . '/radaptor.local.json';
		$local_lock_path = rtrim(DEPLOY_ROOT, '/') . '/radaptor.local.lock.json';

		$summary = [
			'mode' => 'unknown',
			'local_manifest_present' => is_file($local_manifest_path),
			'local_lock_present' => is_file($local_lock_path),
			'workspace_dev_mode_enabled' => PackageLocalOverrideHelper::isWorkspaceDevModeEnabled(),
			'local_overrides_disabled' => PackageLocalOverrideHelper::areLocalOverridesDisabled(),
			'package_roots' => [],
			'issues' => [],
		];

		try {
			$status = PackageStateInspector::getStatus();
			$summary['mode'] = $status['mode'];
			$summary['issues'] = $status['issues'];
			$summary['package_roots'] = array_map(
				static fn (array $package): array => [
					'package_key' => $package['package_key'] ?? null,
					'package' => $package['package'] ?? null,
					'source_type' => $package['source_type'] ?? null,
					'version' => $package['version'] ?? null,
					'active_path' => $package['active_path'] ?? null,
					'registry_path' => $package['registry_path'] ?? null,
				],
				$status['packages']
			);

			foreach ($status['issues'] as $issue) {
				$warnings[] = 'Package status: ' . $issue;
			}
		} catch (Throwable $exception) {
			$summary['issues'] = [$exception->getMessage()];
			$warnings[] = 'Package status unavailable: ' . $exception->getMessage();
		}

		return $summary;
	}

	private static function inferMailpitUiUrl(): ?string
	{
		$port = self::envString('APP_MAILPIT_HTTP_PORT');

		if ($port === null) {
			return null;
		}

		return 'http://localhost:' . $port;
	}

	private static function looksLikeCatcherHost(string $host): bool
	{
		$normalized = strtolower($host);

		return $normalized === 'mailpit'
			|| str_contains($normalized, 'mailpit')
			|| str_contains($normalized, 'mailhog')
			|| str_contains($normalized, 'catcher');
	}

	/**
	 * @return array{host: string|null, port: int|string|null, prefix: string|null}|null
	 */
	private static function redisEndpoint(string $prefix, bool $include_if_empty = true): ?array
	{
		$host = self::envString($prefix . '_HOST');
		$port = self::envString($prefix . '_PORT');
		$key_prefix = self::envString($prefix . '_PREFIX');

		if (!$include_if_empty && $host === null && $port === null && $key_prefix === null) {
			return null;
		}

		return [
			'host' => $host,
			'port' => is_string($port) && is_numeric($port) ? (int) $port : $port,
			'prefix' => $key_prefix,
		];
	}

	private static function envString(string $name): ?string
	{
		$value = getenv($name);

		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		return trim($value);
	}

	private static function inferMcpEndpointUrl(string $port): string
	{
		$configured = self::envString('APP_MCP_PUBLIC_URL');

		if ($configured !== null) {
			return rtrim($configured, '/') . '/mcp';
		}

		$server = RequestContextHolder::current()->SERVER;
		$host = (string) ($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? 'localhost');
		$host = preg_replace('/:\d+$/', '', $host) ?? $host;
		$scheme = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');

		if ($scheme === '') {
			$scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
		}

		return "{$scheme}://{$host}:{$port}/mcp";
	}

	/**
	 * @return list<string>
	 */
	private static function allowedMcpOrigins(string $port): array
	{
		$configured = self::envString('APP_MCP_ALLOWED_ORIGINS');

		if ($configured !== null) {
			return array_values(array_filter(array_map(
				static fn (string $origin): string => rtrim(trim($origin), '/'),
				explode(',', $configured)
			)));
		}

		return [
			'http://127.0.0.1',
			'https://127.0.0.1',
			'http://[::1]',
			'https://[::1]',
			'http://localhost',
			'https://localhost',
			"http://127.0.0.1:{$port}",
			"https://127.0.0.1:{$port}",
			"http://[::1]:{$port}",
			"https://[::1]:{$port}",
			"http://localhost:{$port}",
			"https://localhost:{$port}",
		];
	}
}
