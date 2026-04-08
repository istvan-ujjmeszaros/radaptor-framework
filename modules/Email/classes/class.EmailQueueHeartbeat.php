<?php

declare(strict_types=1);

class EmailQueueHeartbeat
{
	public const string KEY_LAST_SEEN_AT = 'email_queue.worker.last_seen_at';
	public const string KEY_LAST_PROCESSED_AT = 'email_queue.worker.last_processed_at';

	public static function markSeen(): void
	{
		self::writeValue(self::KEY_LAST_SEEN_AT, date('Y-m-d H:i:s'));
	}

	public static function markProcessed(): void
	{
		$now = date('Y-m-d H:i:s');

		self::writeValue(self::KEY_LAST_SEEN_AT, $now);
		self::writeValue(self::KEY_LAST_PROCESSED_AT, $now);
	}

	/**
	 * @return array{
	 *     last_seen_at: ?string,
	 *     last_processed_at: ?string,
	 *     status: string,
	 *     is_stale: bool
	 * }
	 */
	public static function getState(): array
	{
		$last_seen_at = self::readValue(self::KEY_LAST_SEEN_AT);
		$last_processed_at = self::readValue(self::KEY_LAST_PROCESSED_AT);
		$stale_after_seconds = max(15, (int) ceil(max(1, (int) Config::EMAIL_QUEUE_WORKER_SLEEP_MS->value()) / 1000) * 20);

		if ($last_seen_at === null) {
			return [
				'last_seen_at' => null,
				'last_processed_at' => $last_processed_at,
				'status' => 'never_seen',
				'is_stale' => true,
			];
		}

		$last_seen_ts = strtotime($last_seen_at);

		if ($last_seen_ts === false) {
			return [
				'last_seen_at' => $last_seen_at,
				'last_processed_at' => $last_processed_at,
				'status' => 'stale',
				'is_stale' => true,
			];
		}

		$is_stale = (time() - $last_seen_ts) > $stale_after_seconds;

		return [
			'last_seen_at' => $last_seen_at,
			'last_processed_at' => $last_processed_at,
			'status' => $is_stale ? 'stale' : 'running',
			'is_stale' => $is_stale,
		];
	}

	private static function writeValue(string $config_key, string $value): void
	{
		$result = DbHelper::insertOrUpdateHelper('config_app', [
			'config_key' => $config_key,
			'value' => $value,
			'updated_by_user_id' => null,
		]);

		if ($result === null) {
			throw new RuntimeException('Unable to update queue heartbeat for key: ' . $config_key);
		}
	}

	private static function readValue(string $config_key): ?string
	{
		$value = DbHelper::selectOneColumn('config_app', ['config_key' => $config_key], '', 'value');

		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		return trim($value);
	}
}
