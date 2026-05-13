<?php

declare(strict_types=1);

class RuntimeSiteCutoverGuard
{
	public const string TABLE_LOCKS = 'runtime_site_locks';
	public const string LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY = 'site_migration_source_readonly';
	public const string STATUS_ACTIVE = 'active';
	public const string STATUS_RELEASED = 'released';
	public const string RELEASE_CONFIRMATION_TEXT = 'I understand the previous migration export may be inconsistent';
	public const string READONLY_MESSAGE_KEY = 'runtime.site_cutover.readonly_message';
	public const string READONLY_TITLE_KEY = 'runtime.site_cutover.readonly_title';
	private const array CLI_ALLOWLIST = [
		'site:cutover-release',
		'site:cutover-status',
		'site:diff',
		'site:export',
		'site:uploads-check',
		'emailqueue:status',
		'worker:status',
	];

	private const array WEB_EVENT_CLASS_ALLOWLIST = [
		'EventUserLogin',
		'EventUserLogout',
	];

	private static ?bool $tableExists = null;
	private static ?bool $defaultDatabaseReachable = null;

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public static function activateSourceCutover(string $reason, string $context = '', array $metadata = []): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'active' => false,
				'status' => 'unavailable',
			];
		}

		$existing = self::getActiveSourceCutoverLock();

		if (is_array($existing)) {
			return [
				'available' => true,
				'active' => true,
				'status' => 'already_active',
				'lock' => $existing,
			];
		}

		$lock_id = 'site_lock_' . bin2hex(random_bytes(16));
		$metadata_json = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		DbHelper::prexecute(
			"INSERT INTO `" . self::TABLE_LOCKS . "` (
				`lock_id`, `lock_type`, `status`, `reason`, `context`, `message`, `created_by_user_id`, `metadata_json`
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
			[
				$lock_id,
				self::LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY,
				self::STATUS_ACTIVE,
				$reason,
				$context,
				self::READONLY_MESSAGE_KEY,
				self::getCurrentUserIdOrNull(),
				$metadata_json,
			]
		);

		return [
			'available' => true,
			'active' => true,
			'status' => self::STATUS_ACTIVE,
			'lock' => self::getLockById($lock_id),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function releaseSourceCutover(string $confirmation_text, string $release_note = '', bool $resume_workers = true): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'released' => 0,
				'worker_pause_release' => null,
			];
		}

		if (trim($confirmation_text) !== self::RELEASE_CONFIRMATION_TEXT) {
			return [
				'available' => true,
				'released' => 0,
				'status' => 'confirmation_required',
				'required_confirmation' => self::RELEASE_CONFIRMATION_TEXT,
			];
		}

		$active_locks = self::getActiveSourceCutoverLocks();
		$stmt = DbHelper::prexecute(
			"UPDATE `" . self::TABLE_LOCKS . "`
				SET `status` = ?, `released_at` = NOW(), `released_by_user_id` = ?, `release_note` = ?
				WHERE `lock_type` = ? AND `status` = ?",
			[
				self::STATUS_RELEASED,
				self::getCurrentUserIdOrNull(),
				$release_note,
				self::LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY,
				self::STATUS_ACTIVE,
			]
		);
		$released = $stmt?->rowCount() ?? 0;
		$worker_release = null;

		if ($released > 0 && $resume_workers && class_exists(RuntimeWorkerPauseControl::class)) {
			$worker_release = self::releaseWorkerPausesForLocks($active_locks);
		}

		return [
			'available' => true,
			'released' => $released,
			'status' => self::STATUS_RELEASED,
			'worker_pause_release' => $worker_release,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function attachWorkerPauseRequest(string $lock_id, string $pause_request_id, ?bool $created_by_cutover = null): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'attached' => false,
			];
		}

		$lock = self::getLockById($lock_id);

		if (!is_array($lock) || (string) ($lock['status'] ?? '') !== self::STATUS_ACTIVE) {
			return [
				'available' => true,
				'attached' => false,
				'status' => 'lock_not_active',
				'lock_id' => $lock_id,
			];
		}

		$metadata = self::decodeMetadata($lock['metadata_json'] ?? null);
		$metadata['worker_pause_request_id'] = $pause_request_id;
		$metadata['worker_pause_created_by_cutover'] = $created_by_cutover ?? self::isPauseRequestCreatedForLock($pause_request_id, $lock_id);

		DbHelper::prexecute(
			"UPDATE `" . self::TABLE_LOCKS . "`
			SET `metadata_json` = ?
			WHERE `lock_id` = ? AND `status` = ?",
			[json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $lock_id, self::STATUS_ACTIVE]
		);

		return [
			'available' => true,
			'attached' => true,
			'lock_id' => $lock_id,
			'pause_request_id' => $pause_request_id,
			'created_by_cutover' => $metadata['worker_pause_created_by_cutover'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getStatus(): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'active' => false,
				'lock' => null,
			];
		}

		$lock = self::getActiveSourceCutoverLock();

		return [
			'available' => true,
			'active' => is_array($lock),
			'lock' => $lock,
			'message' => is_array($lock) ? self::readonlyMessage() : null,
		];
	}

	public static function isActive(): bool
	{
		return is_array(self::getActiveSourceCutoverLock());
	}

	public static function shouldBlockCliCommand(AbstractCLICommand $command, string $command_slug): bool
	{
		if (in_array($command_slug, self::CLI_ALLOWLIST, true)) {
			return false;
		}

		if ($command->getRiskLevel() === 'safe' && self::commandDeclaresRiskLevel($command)) {
			return false;
		}

		return self::isActiveForGate('cli', ['command' => $command_slug]);
	}

	private static function commandDeclaresRiskLevel(AbstractCLICommand $command): bool
	{
		$method = new ReflectionMethod($command, 'getRiskLevel');

		return $method->getDeclaringClass()->getName() !== AbstractCLICommand::class;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function cliBlockedPayload(string $command_slug, AbstractCLICommand $command): array
	{
		return [
			'status' => 'error',
			'code' => 'SITE_CUTOVER_READONLY',
			'message' => self::readonlyMessage(),
			'command' => $command_slug,
			'risk_level' => $command->getRiskLevel(),
			'lock' => self::getActiveSourceCutoverLock(),
			'release_command' => 'site:cutover-release',
			'required_confirmation' => self::RELEASE_CONFIRMATION_TEXT,
		];
	}

	public static function shouldBlockWebEvent(iEvent $event): bool
	{
		if (!self::isActiveForGate('web', ['event_class' => get_class($event)])) {
			return false;
		}

		if (in_array(Request::getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
			return false;
		}

		$class_name = get_class($event);

		if (in_array($class_name, self::WEB_EVENT_CLASS_ALLOWLIST, true)) {
			return false;
		}

		if ($class_name === 'EventCliRunnerExecute') {
			$command_slug = trim((string) Request::_POST('command', ''));

			return !in_array($command_slug, self::CLI_ALLOWLIST, true);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function shouldBlockMcpTool(array $meta): bool
	{
		if (!self::isActiveForGate('mcp', ['tool' => $meta['name'] ?? $meta['tool'] ?? null])) {
			return false;
		}

		$mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];

		return (string) ($mcp['risk'] ?? 'write') !== 'read';
	}

	public static function readonlyTitle(): string
	{
		return t(self::READONLY_TITLE_KEY);
	}

	public static function readonlyMessage(): string
	{
		return t(self::READONLY_MESSAGE_KEY);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function isActiveForGate(string $gate, array $context = []): bool
	{
		if (!self::canProbeRuntimeLockTable()) {
			return false;
		}

		try {
			return self::isActive();
		} catch (Throwable $exception) {
			Kernel::logException($exception, 'Cutover guard failed open', ['gate' => $gate] + $context);

			return false;
		}
	}

	private static function canProbeRuntimeLockTable(): bool
	{
		if (self::$defaultDatabaseReachable === true) {
			return true;
		}

		try {
			if (!Db::checkDsnConnection((string) Config::DB_DEFAULT_DSN->value())) {
				return false;
			}

			self::$defaultDatabaseReachable = true;

			return true;
		} catch (Throwable $exception) {
			Kernel::logException($exception, 'Cutover guard database probe failed open');

			return false;
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function getActiveSourceCutoverLock(): ?array
	{
		if (!self::isAvailable()) {
			return null;
		}

		$row = DbHelper::fetch(
			"SELECT *
			FROM `" . self::TABLE_LOCKS . "`
			WHERE `lock_type` = ? AND `status` = ?
			ORDER BY `created_at` DESC
			LIMIT 1",
			[self::LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY, self::STATUS_ACTIVE]
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function getActiveSourceCutoverLocks(): array
	{
		if (!self::isAvailable()) {
			return [];
		}

		$rows = DbHelper::fetchAll(
			"SELECT *
			FROM `" . self::TABLE_LOCKS . "`
			WHERE `lock_type` = ? AND `status` = ?
			ORDER BY `created_at` DESC",
			[self::LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY, self::STATUS_ACTIVE]
		);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function getLockById(string $lock_id): ?array
	{
		if (!self::isAvailable()) {
			return null;
		}

		$row = DbHelper::fetch("SELECT * FROM `" . self::TABLE_LOCKS . "` WHERE `lock_id` = ? LIMIT 1", [$lock_id]);

		return is_array($row) ? $row : null;
	}

	private static function isAvailable(): bool
	{
		if (self::$tableExists === true) {
			return true;
		}

		$quoted_table_name = Db::instance()->quote(self::TABLE_LOCKS);
		self::$tableExists = Db::instance()->query("SHOW TABLES LIKE {$quoted_table_name}")?->rowCount() > 0;

		return self::$tableExists;
	}

	private static function getCurrentUserIdOrNull(): ?int
	{
		try {
			$user_id = User::getCurrentUserId();

			return is_numeric($user_id) && (int) $user_id > 0 ? (int) $user_id : null;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @param list<array<string, mixed>> $locks
	 * @return array<string, mixed>
	 */
	private static function releaseWorkerPausesForLocks(array $locks): array
	{
		$results = [];
		$total_released = 0;

		foreach ($locks as $lock) {
			$metadata = self::decodeMetadata($lock['metadata_json'] ?? null);
			$pause_request_id = (string) ($metadata['worker_pause_request_id'] ?? '');
			$created_by_cutover = ($metadata['worker_pause_created_by_cutover'] ?? false) === true;

			if ($pause_request_id === '') {
				continue;
			}

			if (!$created_by_cutover) {
				$results[] = [
					'lock_id' => (string) ($lock['lock_id'] ?? ''),
					'pause_request_id' => $pause_request_id,
					'skipped' => true,
					'skip_reason' => 'pause_not_created_by_cutover',
				];

				continue;
			}

			$result = RuntimeWorkerPauseControl::resumeById($pause_request_id);
			$results[] = [
				'lock_id' => (string) ($lock['lock_id'] ?? ''),
				'pause_request_id' => $pause_request_id,
				'result' => $result,
			];
			$total_released += (int) ($result['released'] ?? 0);
		}

		return [
			'available' => true,
			'released' => $total_released,
			'requests' => $results,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodeMetadata(mixed $metadata_json): array
	{
		if (!is_string($metadata_json) || trim($metadata_json) === '') {
			return [];
		}

		try {
			$metadata = json_decode($metadata_json, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return [];
		}

		return is_array($metadata) ? $metadata : [];
	}

	private static function isPauseRequestCreatedForLock(string $pause_request_id, string $lock_id): bool
	{
		if (!class_exists(RuntimeWorkerPauseControl::class)) {
			return false;
		}

		$request = RuntimeWorkerPauseControl::getPauseRequestById($pause_request_id);

		if (!is_array($request)) {
			return false;
		}

		$metadata = self::decodeMetadata($request['metadata_json'] ?? null);

		return (string) ($request['reason'] ?? '') === 'site_migration_export'
			&& (string) ($metadata['cutover_lock_id'] ?? '') === $lock_id;
	}
}
