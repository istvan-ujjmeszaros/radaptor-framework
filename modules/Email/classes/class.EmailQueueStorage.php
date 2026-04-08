<?php

declare(strict_types=1);

class EmailQueueStorage
{
	public const string TABLE_TRANSACTIONAL = 'email_queue_transactional';

	public const string STATUS_PENDING = 'pending';
	public const string STATUS_RETRY_WAIT = 'retry_wait';
	public const string STATUS_RESERVED = 'reserved';

	public const string FAIL_OUTCOME_RETRY_SCHEDULED = 'retry_scheduled';
	public const string FAIL_OUTCOME_TERMINAL_DEAD_LETTERED = 'terminal_dead_lettered';

	public static function enqueue(EmailQueueJob $job): void
	{
		$insert_id = DbHelper::insertHelper(self::TABLE_TRANSACTIONAL, [
			'job_id' => $job->jobId,
			'job_type' => $job->jobType,
			'payload_json' => json_encode($job->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'requested_by_type' => $job->requestedByType,
			'requested_by_id' => $job->requestedById,
			'priority' => $job->priority,
			'status' => self::STATUS_PENDING,
			'run_after_utc' => $job->runAfterUtc ?? date('Y-m-d H:i:s'),
		]);

		if (!is_int($insert_id) || $insert_id <= 0) {
			throw new RuntimeException('Unable to enqueue transactional email job.');
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function reserveNext(): ?array
	{
		$pdo = Db::instance();
		$reservation_timeout_seconds = max(1, (int) Config::EMAIL_QUEUE_RESERVATION_TIMEOUT_SECONDS->value());

		for ($attempt = 0; $attempt < 4; ++$attempt) {
			$query = "SELECT queue_id
				FROM `" . self::TABLE_TRANSACTIONAL . "`
				WHERE (
					status IN ('" . self::STATUS_PENDING . "', '" . self::STATUS_RETRY_WAIT . "')
					AND run_after_utc <= NOW()
				)
				OR (
					status = '" . self::STATUS_RESERVED . "'
					AND reserved_at IS NOT NULL
					AND reserved_at < (NOW() - INTERVAL {$reservation_timeout_seconds} SECOND)
				)
				ORDER BY CASE priority WHEN 'instant' THEN 0 WHEN 'bulk' THEN 1 ELSE 2 END, queue_id ASC
				LIMIT 1";

			$select_stmt = $pdo->prepare($query);
			$select_stmt->execute();
			$row = $select_stmt->fetch(PDO::FETCH_ASSOC);

			if (!is_array($row)) {
				return null;
			}

			$queue_id = (int) $row['queue_id'];
			$update_stmt = $pdo->prepare(
				"UPDATE `" . self::TABLE_TRANSACTIONAL . "`
				SET status = :status, reserved_at = NOW(), attempts = attempts + 1
				WHERE queue_id = :queue_id
				AND (
					status IN ('" . self::STATUS_PENDING . "', '" . self::STATUS_RETRY_WAIT . "')
					OR (
						status = '" . self::STATUS_RESERVED . "'
						AND reserved_at IS NOT NULL
						AND reserved_at < (NOW() - INTERVAL {$reservation_timeout_seconds} SECOND)
					)
				)"
			);
			$update_stmt->execute([
				':status' => self::STATUS_RESERVED,
				':queue_id' => $queue_id,
			]);

			if ($update_stmt->rowCount() !== 1) {
				continue;
			}

			$fetch_stmt = $pdo->prepare("SELECT * FROM `" . self::TABLE_TRANSACTIONAL . "` WHERE queue_id = :queue_id LIMIT 1");
			$fetch_stmt->execute([':queue_id' => $queue_id]);
			$reserved = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

			return is_array($reserved) ? $reserved : null;
		}

		return null;
	}

	public static function complete(int $queue_id): void
	{
		$row = DbHelper::selectOne(self::TABLE_TRANSACTIONAL, ['queue_id' => $queue_id]);

		if (!is_array($row)) {
			return;
		}

		$result = DbHelper::insertHelper('email_queue_archive', [
			'source_table' => self::TABLE_TRANSACTIONAL,
			'job_id' => $row['job_id'],
			'job_type' => $row['job_type'],
			'payload_json' => $row['payload_json'],
			'requested_by_type' => $row['requested_by_type'],
			'requested_by_id' => $row['requested_by_id'],
			'priority' => $row['priority'] ?? null,
			'attempts' => $row['attempts'],
			'completed_at' => date('Y-m-d H:i:s'),
			'created_at' => $row['created_at'],
		]);

		if (!is_int($result) || $result <= 0) {
			throw new RuntimeException('Unable to archive completed transactional email job.');
		}

		DbHelper::deleteHelper(self::TABLE_TRANSACTIONAL, ['queue_id' => $queue_id]);
	}

	public static function fail(int $queue_id, string $error_code, string $error_message, bool $retryable): string
	{
		$row = DbHelper::selectOne(self::TABLE_TRANSACTIONAL, ['queue_id' => $queue_id]);

		if (!is_array($row)) {
			return self::FAIL_OUTCOME_TERMINAL_DEAD_LETTERED;
		}

		$max_attempts = max(1, (int) Config::EMAIL_QUEUE_MAX_ATTEMPTS->value());
		$attempts = (int) ($row['attempts'] ?? 0);

		if ($retryable && $attempts < $max_attempts) {
			$delay_seconds = self::getRetryDelaySeconds($attempts);

			DbHelper::updateHelper(self::TABLE_TRANSACTIONAL, [
				'status' => self::STATUS_RETRY_WAIT,
				'run_after_utc' => date('Y-m-d H:i:s', time() + $delay_seconds),
				'last_error_code' => $error_code,
				'last_error_message' => $error_message,
			], ['queue_id' => $queue_id]);

			return self::FAIL_OUTCOME_RETRY_SCHEDULED;
		}

		$result = DbHelper::insertHelper('email_queue_dead_letter', [
			'source_table' => self::TABLE_TRANSACTIONAL,
			'job_id' => $row['job_id'],
			'job_type' => $row['job_type'],
			'payload_json' => $row['payload_json'],
			'requested_by_type' => $row['requested_by_type'],
			'requested_by_id' => $row['requested_by_id'],
			'priority' => $row['priority'] ?? null,
			'attempts' => $row['attempts'],
			'error_code' => $error_code,
			'error_message' => $error_message,
			'created_at' => $row['created_at'],
		]);

		if (!is_int($result) || $result <= 0) {
			throw new RuntimeException('Unable to archive dead-letter transactional email job.');
		}

		DbHelper::deleteHelper(self::TABLE_TRANSACTIONAL, ['queue_id' => $queue_id]);

		return self::FAIL_OUTCOME_TERMINAL_DEAD_LETTERED;
	}

	public static function purgeArchives(): void
	{
		$archive_ttl_days = max(1, (int) Config::EMAIL_QUEUE_ARCHIVE_TTL_DAYS->value());
		$dead_ttl_days = max(1, (int) Config::EMAIL_QUEUE_DEAD_LETTER_TTL_DAYS->value());

		Db::instance()->exec("DELETE FROM email_queue_archive WHERE archived_at < DATE_SUB(NOW(), INTERVAL {$archive_ttl_days} DAY)");
		Db::instance()->exec("DELETE FROM email_queue_dead_letter WHERE dead_lettered_at < DATE_SUB(NOW(), INTERVAL {$dead_ttl_days} DAY)");
	}

	private static function getRetryDelaySeconds(int $attempts): int
	{
		return min(3600, (int) pow(2, max(1, $attempts)));
	}
}
