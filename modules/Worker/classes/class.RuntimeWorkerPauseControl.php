<?php

declare(strict_types=1);

class RuntimeWorkerPauseControl
{
	public const string TABLE_REQUESTS = 'runtime_worker_pause_requests';
	public const string STATUS_REQUESTED = 'requested';
	public const string STATUS_CONFIRMED = 'confirmed';
	public const string STATUS_RELEASED = 'released';
	public const string STATUS_EXPIRED = 'expired';

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public static function requestPause(
		string $worker_type,
		string $queue_name,
		string $reason,
		string $context = '',
		array $metadata = []
	): array {
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'pause_request_id' => null,
				'status' => 'unavailable',
			];
		}

		$lock_name = self::buildScopeLockName($worker_type, $queue_name);
		self::acquireScopeLock($lock_name);

		try {
			$existing = self::getActivePauseRequest($worker_type, $queue_name);

			if (is_array($existing)) {
				return [
					'available' => true,
					'pause_request_id' => (string) $existing['pause_request_id'],
					'status' => 'already_requested',
					'request' => $existing,
				];
			}

			$pause_request_id = 'pause_' . bin2hex(random_bytes(16));
			$user_id = self::getCurrentUserIdOrNull();
			$metadata_json = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

			DbHelper::prexecute(
				"INSERT INTO `" . self::TABLE_REQUESTS . "` (
					`pause_request_id`, `worker_type`, `queue_name`, `status`, `reason`, `context`, `requested_by_user_id`, `metadata_json`
				) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
				[
					$pause_request_id,
					$worker_type,
					$queue_name,
					self::STATUS_REQUESTED,
					$reason,
					$context,
					$user_id,
					$metadata_json,
				]
			);

			return [
				'available' => true,
				'pause_request_id' => $pause_request_id,
				'status' => self::STATUS_REQUESTED,
				'request' => self::getPauseRequestById($pause_request_id),
			];
		} finally {
			self::releaseScopeLock($lock_name);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function getActivePauseRequest(string $worker_type, string $queue_name): ?array
	{
		if (!self::isAvailable()) {
			return null;
		}

		$row = DbHelper::fetch(
			"SELECT *
			FROM `" . self::TABLE_REQUESTS . "`
			WHERE `worker_type` = ? AND `queue_name` = ? AND `status` IN (?, ?)
			ORDER BY `requested_at` DESC
			LIMIT 1",
			[$worker_type, $queue_name, self::STATUS_REQUESTED, self::STATUS_CONFIRMED]
		);

		return is_array($row) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function getPauseRequestById(string $pause_request_id): ?array
	{
		if (!self::isAvailable()) {
			return null;
		}

		$row = DbHelper::fetch(
			"SELECT * FROM `" . self::TABLE_REQUESTS . "` WHERE `pause_request_id` = ? LIMIT 1",
			[$pause_request_id]
		);

		return is_array($row) ? $row : null;
	}

	public static function pauseIfRequested(?string $worker_instance_id, string $worker_type, string $queue_name): bool
	{
		$request = self::getActivePauseRequest($worker_type, $queue_name);

		if (!is_array($request)) {
			return false;
		}

		RuntimeWorkerRegistry::heartbeat(
			$worker_instance_id,
			$worker_type,
			$queue_name,
			RuntimeWorkerRegistry::STATE_PAUSED,
			null,
			null,
			(string) $request['pause_request_id']
		);

		self::markRequestConfirmedIfComplete($worker_type, $queue_name, (string) $request['pause_request_id']);

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function waitForPauseConfirmation(
		string $worker_type,
		string $queue_name,
		string $pause_request_id,
		int $timeout_seconds = 30,
		bool $allow_stale_workers = false,
		int $stale_after_seconds = 30
	): array {
		$deadline = microtime(true) + max(0, $timeout_seconds);
		$state = self::getPauseConfirmationState($worker_type, $queue_name, $pause_request_id, $allow_stale_workers, $stale_after_seconds);

		while (!($state['confirmed'] ?? false) && microtime(true) < $deadline) {
			usleep(200000);
			$state = self::getPauseConfirmationState($worker_type, $queue_name, $pause_request_id, $allow_stale_workers, $stale_after_seconds);
		}

		if (($state['confirmed'] ?? false) === true) {
			self::markRequestConfirmedIfComplete($worker_type, $queue_name, $pause_request_id, $allow_stale_workers, $stale_after_seconds);
		}

		return $state + [
			'timed_out' => !($state['confirmed'] ?? false),
			'timeout_seconds' => max(0, $timeout_seconds),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getPauseConfirmationState(
		string $worker_type,
		string $queue_name,
		string $pause_request_id,
		bool $allow_stale_workers = false,
		int $stale_after_seconds = 30
	): array {
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'confirmed' => false,
				'pending_workers' => [],
				'confirmed_workers' => [],
				'stale_busy_workers' => [],
			];
		}

		$active_workers = RuntimeWorkerRegistry::getActiveInstances($worker_type, $queue_name, $stale_after_seconds);
		$stale_busy_workers = $allow_stale_workers
			? []
			: RuntimeWorkerRegistry::getStaleBusyInstances($worker_type, $queue_name, $stale_after_seconds);
		$pending_workers = [];
		$confirmed_workers = [];

		foreach ($active_workers as $worker) {
			if ((string) ($worker['confirmed_pause_request_id'] ?? '') === $pause_request_id) {
				$confirmed_workers[] = $worker;

				continue;
			}

			$pending_workers[] = $worker;
		}

		return [
			'available' => true,
			'confirmed' => $pending_workers === [] && $stale_busy_workers === [],
			'pause_request_id' => $pause_request_id,
			'pending_workers' => $pending_workers,
			'confirmed_workers' => $confirmed_workers,
			'stale_busy_workers' => $stale_busy_workers,
			'active_worker_count' => count($active_workers),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function resume(string $worker_type, string $queue_name): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'released' => 0,
			];
		}

		$stmt = DbHelper::prexecute(
			"UPDATE `" . self::TABLE_REQUESTS . "`
			SET `status` = ?, `released_at` = NOW()
			WHERE `worker_type` = ? AND `queue_name` = ? AND `status` IN (?, ?)",
			[self::STATUS_RELEASED, $worker_type, $queue_name, self::STATUS_REQUESTED, self::STATUS_CONFIRMED]
		);
		$released = $stmt?->rowCount() ?? 0;

		DbHelper::prexecute(
			"UPDATE `" . RuntimeWorkerRegistry::TABLE_INSTANCES . "`
			SET `state` = CASE WHEN `state` = ? THEN ? ELSE `state` END,
				`confirmed_pause_request_id` = NULL,
				`confirmed_pause_at` = NULL,
				`last_seen_at` = NOW()
			WHERE `worker_type` = ? AND `queue_name` = ?",
			[RuntimeWorkerRegistry::STATE_PAUSED, RuntimeWorkerRegistry::STATE_IDLE, $worker_type, $queue_name]
		);

		return [
			'available' => true,
			'released' => $released,
			'status' => self::STATUS_RELEASED,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getScopeState(string $worker_type, string $queue_name, int $stale_after_seconds = 30): array
	{
		if (!self::isAvailable()) {
			return [
				'available' => false,
				'status' => 'unavailable',
				'is_stale' => true,
				'instances' => [],
				'active_pause_request' => null,
			];
		}

		$instances = RuntimeWorkerRegistry::listInstances($worker_type, $queue_name, $stale_after_seconds);
		$active_instances = RuntimeWorkerRegistry::getActiveInstances($worker_type, $queue_name, $stale_after_seconds);
		$active_request = self::getActivePauseRequest($worker_type, $queue_name);
		$last_seen_at = self::findLatestValue($instances, 'last_seen_at');
		$status = 'never_seen';
		$is_stale = true;

		if (is_array($active_request)) {
			$confirmation = self::getPauseConfirmationState(
				$worker_type,
				$queue_name,
				(string) $active_request['pause_request_id'],
				false,
				$stale_after_seconds
			);
			$status = ($confirmation['confirmed'] ?? false) === true ? 'paused' : 'pausing';
			$is_stale = false;
		} elseif ($active_instances !== []) {
			$status = 'running';
			$is_stale = false;
		} elseif ($instances !== []) {
			$status = 'stale';
		}

		return [
			'available' => true,
			'status' => $status,
			'is_stale' => $is_stale,
			'last_seen_at' => $last_seen_at,
			'instances' => $instances,
			'active_pause_request' => $active_request,
		];
	}

	private static function markRequestConfirmedIfComplete(
		string $worker_type,
		string $queue_name,
		string $pause_request_id,
		bool $allow_stale_workers = false,
		int $stale_after_seconds = 30
	): void {
		$state = self::getPauseConfirmationState($worker_type, $queue_name, $pause_request_id, $allow_stale_workers, $stale_after_seconds);

		if (($state['confirmed'] ?? false) !== true) {
			return;
		}

		DbHelper::prexecute(
			"UPDATE `" . self::TABLE_REQUESTS . "`
			SET `status` = ?, `confirmed_at` = COALESCE(`confirmed_at`, NOW())
			WHERE `pause_request_id` = ? AND `status` IN (?, ?)",
			[self::STATUS_CONFIRMED, $pause_request_id, self::STATUS_REQUESTED, self::STATUS_CONFIRMED]
		);
	}

	private static function isAvailable(): bool
	{
		return RuntimeWorkerRegistry::tableExists(RuntimeWorkerRegistry::TABLE_INSTANCES)
			&& RuntimeWorkerRegistry::tableExists(self::TABLE_REQUESTS);
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

	private static function buildScopeLockName(string $worker_type, string $queue_name): string
	{
		return 'worker_pause_' . substr(hash('sha256', $worker_type . '|' . $queue_name), 0, 48);
	}

	private static function acquireScopeLock(string $lock_name): void
	{
		$result = DbHelper::fetch('SELECT GET_LOCK(?, 10) AS lock_acquired', [$lock_name]);

		if ((string) ($result['lock_acquired'] ?? '0') !== '1') {
			throw new RuntimeException('Unable to acquire worker pause scope lock.');
		}
	}

	private static function releaseScopeLock(string $lock_name): void
	{
		DbHelper::fetch('SELECT RELEASE_LOCK(?) AS lock_released', [$lock_name]);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private static function findLatestValue(array $rows, string $field): ?string
	{
		$latest_ts = null;
		$latest_value = null;

		foreach ($rows as $row) {
			$value = (string) ($row[$field] ?? '');
			$ts = strtotime($value);

			if ($value === '' || $ts === false) {
				continue;
			}

			if ($latest_ts === null || $ts > $latest_ts) {
				$latest_ts = $ts;
				$latest_value = $value;
			}
		}

		return $latest_value;
	}
}
