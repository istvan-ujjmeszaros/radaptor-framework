<?php

declare(strict_types=1);

class EmailQueueWorker
{
	public const string WORKER_TYPE = 'email_queue';
	public const string QUEUE_NAME = 'transactional_email';
	private static ?string $workerInstanceId = null;

	public static function runForever(): void
	{
		$purge_interval_seconds = max(1, (int) Config::EMAIL_QUEUE_PURGE_INTERVAL_SECONDS->value());
		$next_purge_at = time() + $purge_interval_seconds;

		self::ensureWorkerRegistered();

		try {
			for (;;) {
				if (RuntimeWorkerPauseControl::pauseIfRequested(self::$workerInstanceId, self::WORKER_TYPE, self::QUEUE_NAME)) {
					usleep((int) Config::EMAIL_QUEUE_WORKER_SLEEP_MS->value() * 1000);

					continue;
				}

				$processed = self::runOnce(self::$workerInstanceId);

				if (RuntimeWorkerPauseControl::getActivePauseRequest(self::WORKER_TYPE, self::QUEUE_NAME) !== null) {
					usleep((int) Config::EMAIL_QUEUE_WORKER_SLEEP_MS->value() * 1000);

					continue;
				}

				if (time() >= $next_purge_at) {
					EmailQueueStorage::purgeArchives();
					$next_purge_at = time() + $purge_interval_seconds;
				}

				if (!$processed) {
					usleep((int) Config::EMAIL_QUEUE_WORKER_SLEEP_MS->value() * 1000);
				}
			}
		} finally {
			RuntimeWorkerRegistry::markStopping(self::$workerInstanceId, self::WORKER_TYPE, self::QUEUE_NAME);
		}
	}

	public static function runOnce(?string $worker_instance_id = null): bool
	{
		$registered_here = false;
		$paused = false;

		if ($worker_instance_id === null) {
			self::ensureWorkerRegistered();
			$worker_instance_id = self::$workerInstanceId;
			$registered_here = $worker_instance_id !== null;
		}

		EmailQueueHeartbeat::markSeen();
		Cache::flush();

		try {
			if (RuntimeWorkerPauseControl::pauseIfRequested($worker_instance_id, self::WORKER_TYPE, self::QUEUE_NAME)) {
				$paused = true;

				return false;
			}

			RuntimeWorkerRegistry::heartbeat(
				$worker_instance_id,
				self::WORKER_TYPE,
				self::QUEUE_NAME,
				RuntimeWorkerRegistry::STATE_IDLE
			);

			$row = EmailQueueStorage::reserveNext();

			if (!is_array($row)) {
				return false;
			}

			$job_id = (string) ($row['job_id'] ?? '');
			$job_type = (string) $row['job_type'];
			$requested_by_type = (string) ($row['requested_by_type'] ?? '');
			$requested_by_id = isset($row['requested_by_id']) ? (int) $row['requested_by_id'] : null;

			if ($job_id === '') {
				return false;
			}

			RuntimeWorkerRegistry::heartbeat(
				$worker_instance_id,
				self::WORKER_TYPE,
				self::QUEUE_NAME,
				RuntimeWorkerRegistry::STATE_BUSY,
				$job_id,
				$job_type
			);

			if (!EmailAuthorization::canRequestedPrincipalExecute($requested_by_type, $requested_by_id)) {
				EmailQueueStorage::fail($job_id, 'AUTH_DENIED', 'Requested principal is not authorized anymore.', false);

				return true;
			}

			$payload = json_decode((string) $row['payload_json'], true);

			if (!is_array($payload)) {
				EmailQueueStorage::fail($job_id, 'INVALID_PAYLOAD', 'Invalid payload JSON.', false);

				return true;
			}

			try {
				switch ($job_type) {
					case 'email.transactional.send_snapshot':
						self::processTransactionalSendSnapshot($payload);

						break;

					default:
						EmailQueueStorage::fail($job_id, 'UNKNOWN_JOB_TYPE', 'Unsupported job type: ' . $job_type, false);

						return true;
				}
			} catch (EmailJobProcessingException $e) {
				$outcome = EmailQueueStorage::fail(
					$job_id,
					$e->getErrorCodeString(),
					$e->getMessage(),
					$e->isRetryable()
				);

				if (!$e->isRetryable() || $outcome === EmailQueueStorage::FAIL_OUTCOME_TERMINAL_DEAD_LETTERED) {
					self::markEmailPayloadFailedIfPossible($job_type, $payload, $e->getErrorCodeString(), $e->getMessage());
				}

				return true;
			} catch (Throwable $e) {
				$outcome = EmailQueueStorage::fail($job_id, 'EXECUTION_ERROR', $e->getMessage(), true);

				if ($outcome === EmailQueueStorage::FAIL_OUTCOME_TERMINAL_DEAD_LETTERED) {
					self::markEmailPayloadFailedIfPossible($job_type, $payload, 'EXECUTION_ERROR', $e->getMessage());
				}

				return true;
			}

			EmailQueueStorage::complete($job_id);
			EmailQueueHeartbeat::markProcessed();

			return true;
		} finally {
			if ($registered_here) {
				RuntimeWorkerRegistry::markStopping($worker_instance_id, self::WORKER_TYPE, self::QUEUE_NAME);
				self::$workerInstanceId = null;
			} elseif (!$paused) {
				RuntimeWorkerRegistry::heartbeat(
					$worker_instance_id,
					self::WORKER_TYPE,
					self::QUEUE_NAME,
					RuntimeWorkerRegistry::STATE_IDLE
				);
			}
		}
	}

	private static function ensureWorkerRegistered(): void
	{
		if (self::$workerInstanceId !== null) {
			return;
		}

		self::$workerInstanceId = RuntimeWorkerRegistry::register(self::WORKER_TYPE, self::QUEUE_NAME, [
			'command' => 'emailqueue:run',
		]);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function processTransactionalSendSnapshot(array $payload): void
	{
		$outbox_id = (int) ($payload['outbox_id'] ?? 0);
		$recipient_id = (int) ($payload['recipient_id'] ?? 0);

		$outbox = EntityEmailOutbox::findById($outbox_id);
		$recipient = EntityEmailOutboxRecipient::findById($recipient_id);

		if (is_null($outbox) || is_null($recipient)) {
			throw new EmailJobProcessingException('EMAIL_DEPENDENCY_MISSING', 'Outbox or recipient not found.', false);
		}

		$outbox_data = $outbox->dto();
		$recipient_data = $recipient->dto();
		$recipient_email = trim((string) ($recipient_data['recipient_email'] ?? ''));

		if ($recipient_email === '') {
			throw new EmailJobProcessingException('RECIPIENT_EMPTY', 'Recipient email is empty.', false);
		}

		EmailSmtpTransport::send(
			subject: (string) ($outbox_data['subject'] ?? ''),
			htmlBody: (string) ($outbox_data['html_body'] ?? ''),
			textBody: (string) ($outbox_data['text_body'] ?? ''),
			to: [[
				'email' => $recipient_email,
				'name' => trim((string) ($recipient_data['recipient_name'] ?? '')),
			]]
		);

		self::markRecipientSent($outbox_id, $recipient_id);
	}

	private static function markRecipientSent(int $outbox_id, int $recipient_id): void
	{
		$pdo = Db::instance();
		$owns_transaction = !$pdo->inTransaction();

		if ($owns_transaction) {
			$pdo->beginTransaction();
		}

		try {
			EntityEmailOutboxRecipient::updateById($recipient_id, [
				'status' => 'sent',
				'sent_at' => date('Y-m-d H:i:s'),
				'last_error_code' => null,
				'last_error_message' => null,
			]);
			EmailOutboxStatusResolver::recompute($outbox_id);

			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			throw $e;
		}
	}

	private static function markRecipientFailed(int $outbox_id, int $recipient_id, string $error_code, string $error_message): void
	{
		$pdo = Db::instance();
		$owns_transaction = !$pdo->inTransaction();

		if ($owns_transaction) {
			$pdo->beginTransaction();
		}

		try {
			EntityEmailOutboxRecipient::updateById($recipient_id, [
				'status' => 'failed',
				'last_error_code' => $error_code,
				'last_error_message' => $error_message,
			]);
			EmailOutboxStatusResolver::recompute($outbox_id);

			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			throw $e;
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function markEmailPayloadFailedIfPossible(string $job_type, array $payload, string $error_code, string $error_message): void
	{
		if ($job_type !== 'email.transactional.send_snapshot') {
			return;
		}

		$outbox_id = (int) ($payload['outbox_id'] ?? 0);
		$recipient_id = (int) ($payload['recipient_id'] ?? 0);

		if ($outbox_id <= 0 || $recipient_id <= 0) {
			return;
		}

		$recipient = EntityEmailOutboxRecipient::findById($recipient_id);

		if (is_null($recipient)) {
			return;
		}

		if ((string) ($recipient->dto()['status'] ?? '') === 'sent') {
			return;
		}

		self::markRecipientFailed($outbox_id, $recipient_id, $error_code, $error_message);
	}
}
