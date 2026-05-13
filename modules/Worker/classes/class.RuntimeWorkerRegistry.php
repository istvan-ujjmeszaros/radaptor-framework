<?php

declare(strict_types=1);

class RuntimeWorkerRegistry
{
	public const string TABLE_INSTANCES = 'runtime_worker_instances';
	public const string STATE_STARTING = 'starting';
	public const string STATE_IDLE = 'idle';
	public const string STATE_BUSY = 'busy';
	public const string STATE_PAUSED = 'paused';
	public const string STATE_STOPPING = 'stopping';
	private static array $tableExistsCache = [];

	/**
	 * @param array<string, mixed> $metadata
	 */
	public static function register(string $worker_type, string $queue_name, array $metadata = []): ?string
	{
		if (!self::tableExists(self::TABLE_INSTANCES)) {
			return null;
		}

		$worker_instance_id = self::buildWorkerInstanceId();
		self::upsertHeartbeat(
			$worker_instance_id,
			$worker_type,
			$queue_name,
			self::STATE_STARTING,
			null,
			null,
			null,
			$metadata
		);

		return $worker_instance_id;
	}

	/**
	 * @param array<string, mixed>|null $metadata
	 */
	public static function heartbeat(
		?string $worker_instance_id,
		string $worker_type,
		string $queue_name,
		string $state = self::STATE_IDLE,
		?string $current_job_id = null,
		?string $current_job_type = null,
		?string $confirmed_pause_request_id = null,
		?array $metadata = null
	): void {
		if ($worker_instance_id === null || !self::tableExists(self::TABLE_INSTANCES)) {
			return;
		}

		self::upsertHeartbeat(
			$worker_instance_id,
			$worker_type,
			$queue_name,
			self::normalizeState($state),
			$current_job_id,
			$current_job_type,
			$confirmed_pause_request_id,
			$metadata
		);
	}

	public static function markStopping(?string $worker_instance_id, string $worker_type, string $queue_name): void
	{
		if ($worker_instance_id === null || !self::tableExists(self::TABLE_INSTANCES)) {
			return;
		}

		DbHelper::prexecute(
			"UPDATE `" . self::TABLE_INSTANCES . "`
			SET `state` = ?, `current_job_id` = NULL, `current_job_type` = NULL, `last_seen_at` = NOW(), `stopped_at` = NOW()
			WHERE `worker_instance_id` = ? AND `worker_type` = ? AND `queue_name` = ?",
			[self::STATE_STOPPING, $worker_instance_id, $worker_type, $queue_name]
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function listInstances(string $worker_type, string $queue_name, int $stale_after_seconds = 30): array
	{
		if (!self::tableExists(self::TABLE_INSTANCES)) {
			return [];
		}

		$rows = DbHelper::fetchAll(
			"SELECT *
			FROM `" . self::TABLE_INSTANCES . "`
			WHERE `worker_type` = ? AND `queue_name` = ?
			  AND (`state` <> ? OR `stopped_at` IS NULL)
			ORDER BY `last_seen_at` DESC, `worker_instance_id` ASC",
			[$worker_type, $queue_name, self::STATE_STOPPING]
		);
		$now = time();

		return array_map(
			static function (array $row) use ($now, $stale_after_seconds): array {
				$last_seen_ts = strtotime((string) ($row['last_seen_at'] ?? ''));
				$is_stale = $last_seen_ts === false || ($now - $last_seen_ts) > max(1, $stale_after_seconds);
				$state = (string) ($row['state'] ?? self::STATE_STARTING);

				return $row + [
					'is_stale' => $is_stale,
					'effective_status' => $is_stale && $state !== self::STATE_STOPPING ? 'stale' : $state,
				];
			},
			$rows
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function getActiveInstances(string $worker_type, string $queue_name, int $stale_after_seconds = 30): array
	{
		return array_values(array_filter(
			self::listInstances($worker_type, $queue_name, $stale_after_seconds),
			static fn (array $row): bool => !($row['is_stale'] ?? true) && (string) ($row['state'] ?? '') !== self::STATE_STOPPING
		));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function getStaleBusyInstances(string $worker_type, string $queue_name, int $stale_after_seconds = 30): array
	{
		return array_values(array_filter(
			self::listInstances($worker_type, $queue_name, $stale_after_seconds),
			static fn (array $row): bool => ($row['is_stale'] ?? false) === true && (string) ($row['state'] ?? '') === self::STATE_BUSY
		));
	}

	public static function tableExists(string $table_name): bool
	{
		if (array_key_exists($table_name, self::$tableExistsCache)) {
			return self::$tableExistsCache[$table_name];
		}

		$quoted_table_name = Db::instance()->quote($table_name);

		self::$tableExistsCache[$table_name] = Db::instance()->query("SHOW TABLES LIKE {$quoted_table_name}")?->rowCount() > 0;

		return self::$tableExistsCache[$table_name];
	}

	private static function buildWorkerInstanceId(): string
	{
		return 'worker_' . bin2hex(random_bytes(16));
	}

	/**
	 * @param array<string, mixed>|null $metadata
	 */
	private static function upsertHeartbeat(
		string $worker_instance_id,
		string $worker_type,
		string $queue_name,
		string $state,
		?string $current_job_id,
		?string $current_job_type,
		?string $confirmed_pause_request_id,
		?array $metadata
	): void {
		$metadata_json = self::encodeMetadata($metadata);
		$confirmed_pause_at_sql = $confirmed_pause_request_id === null ? '`confirmed_pause_at`' : 'NOW()';

		DbHelper::prexecute(
			"INSERT INTO `" . self::TABLE_INSTANCES . "` (
				`worker_instance_id`, `worker_type`, `queue_name`, `hostname`, `process_id`, `state`,
				`current_job_id`, `current_job_type`, `confirmed_pause_request_id`, `confirmed_pause_at`, `metadata_json`,
				`started_at`, `last_seen_at`, `stopped_at`
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($confirmed_pause_request_id === null ? 'NULL' : 'NOW()') . ", ?, NOW(), NOW(), NULL)
			ON DUPLICATE KEY UPDATE
				`worker_type` = VALUES(`worker_type`),
				`queue_name` = VALUES(`queue_name`),
				`hostname` = VALUES(`hostname`),
				`process_id` = VALUES(`process_id`),
				`state` = VALUES(`state`),
				`current_job_id` = VALUES(`current_job_id`),
				`current_job_type` = VALUES(`current_job_type`),
				`confirmed_pause_request_id` = COALESCE(VALUES(`confirmed_pause_request_id`), `confirmed_pause_request_id`),
				`confirmed_pause_at` = {$confirmed_pause_at_sql},
				`metadata_json` = COALESCE(VALUES(`metadata_json`), `metadata_json`),
				`last_seen_at` = NOW(),
				`stopped_at` = NULL",
			[
				$worker_instance_id,
				$worker_type,
				$queue_name,
				gethostname() ?: 'unknown',
				getmypid() ?: null,
				$state,
				$current_job_id,
				$current_job_type,
				$confirmed_pause_request_id,
				$metadata_json,
			]
		);
	}

	private static function normalizeState(string $state): string
	{
		return in_array($state, [
			self::STATE_STARTING,
			self::STATE_IDLE,
			self::STATE_BUSY,
			self::STATE_PAUSED,
			self::STATE_STOPPING,
		], true) ? $state : self::STATE_IDLE;
	}

	/**
	 * @param array<string, mixed>|null $metadata
	 */
	private static function encodeMetadata(?array $metadata): ?string
	{
		if ($metadata === null) {
			return null;
		}

		return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
