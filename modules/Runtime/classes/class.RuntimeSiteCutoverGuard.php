<?php

declare(strict_types=1);

class RuntimeSiteCutoverGuard
{
	public const string TABLE_LOCKS = 'runtime_site_locks';
	public const string LOCK_TYPE_SITE_MIGRATION_SOURCE_READONLY = 'site_migration_source_readonly';
	public const string STATUS_ACTIVE = 'active';
	public const string STATUS_RELEASED = 'released';
	public const string RELEASE_CONFIRMATION_TEXT = 'I understand the previous migration export may be inconsistent';
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
		$message = self::readonlyMessage();

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
				$message,
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

		if ($resume_workers && class_exists(RuntimeWorkerPauseControl::class) && class_exists(EmailQueueWorker::class)) {
			$worker_release = RuntimeWorkerPauseControl::resume(EmailQueueWorker::WORKER_TYPE, EmailQueueWorker::QUEUE_NAME);
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
		if (!self::isActive()) {
			return false;
		}

		if (in_array($command_slug, self::CLI_ALLOWLIST, true)) {
			return false;
		}

		return $command->getRiskLevel() !== 'safe';
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
		if (!self::isActive()) {
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
		if (!self::isActive()) {
			return false;
		}

		$mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];

		return (string) ($mcp['risk'] ?? 'write') !== 'read';
	}

	public static function readonlyMessage(): string
	{
		return 'This site instance is locked read-only after a site migration export. Use the new site instance for further work, or explicitly release the cutover lock if you understand that the previous migration export may become inconsistent.';
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
		if (self::$tableExists !== null) {
			return self::$tableExists;
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
}
