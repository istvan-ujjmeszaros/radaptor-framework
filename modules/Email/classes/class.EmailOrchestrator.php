<?php

declare(strict_types=1);

/**
 * @phpstan-type ShapeTransactionalRecipient array{
 *     email: string,
 *     name?: string,
 *     context?: array<string, mixed>
 * }
 */
class EmailOrchestrator
{
	/**
	 * @param array<int, ShapeTransactionalRecipient> $recipients
	 * @return array{outbox_id: int, queued_jobs: int}
	 */
	public static function enqueueTransactionalSnapshot(
		string $subject,
		string $htmlBody,
		string $textBody,
		array $recipients,
		?string $scheduledAt = null,
		string $priority = 'instant',
	): array {
		if (!EmailAuthorization::canCurrentUserEnqueue()) {
			throw new RuntimeException('Permission denied for enqueuing email jobs.');
		}

		return self::enqueueTransactionalSnapshotForRequestedPrincipal(
			subject: $subject,
			htmlBody: $htmlBody,
			textBody: $textBody,
			recipients: $recipients,
			scheduledAt: $scheduledAt,
			priority: $priority,
			requestedByType: EmailAuthorization::REQUESTED_BY_TYPE_USER,
			requestedById: User::getCurrentUserId() ?: null,
		);
	}

	/**
	 * @param array<int, ShapeTransactionalRecipient> $recipients
	 * @return array{outbox_id: int, queued_jobs: int}
	 */
	public static function enqueueTransactionalSnapshotAsSystem(
		string $subject,
		string $htmlBody,
		string $textBody,
		array $recipients,
		?string $scheduledAt = null,
		string $priority = 'instant',
	): array {
		return self::enqueueTransactionalSnapshotForRequestedPrincipal(
			subject: $subject,
			htmlBody: $htmlBody,
			textBody: $textBody,
			recipients: $recipients,
			scheduledAt: $scheduledAt,
			priority: $priority,
			requestedByType: EmailAuthorization::REQUESTED_BY_TYPE_SYSTEM,
			requestedById: null,
		);
	}

	/**
	 * @param array<int, ShapeTransactionalRecipient> $recipients
	 * @return array{outbox_id: int, queued_jobs: int}
	 */
	private static function enqueueTransactionalSnapshotForRequestedPrincipal(
		string $subject,
		string $htmlBody,
		string $textBody,
		array $recipients,
		?string $scheduledAt,
		string $priority,
		string $requestedByType,
		?int $requestedById,
	): array {
		$normalized_recipients = self::normalizeRecipients($recipients);

		if ($normalized_recipients === []) {
			throw new InvalidArgumentException('No valid recipient email.');
		}

		$message_uid = 'email_' . bin2hex(random_bytes(16));
		$pdo = Db::instance();
		$owns_transaction = !$pdo->inTransaction();

		if ($owns_transaction) {
			$pdo->beginTransaction();
		}

		try {
			$outbox = EntityEmailOutbox::saveFromArray([
				'message_uid' => $message_uid,
				'send_mode' => 'transactional',
				'subject' => $subject,
				'html_body' => $htmlBody,
				'text_body' => $textBody,
				'status' => 'queued',
				'requested_by_type' => $requestedByType,
				'requested_by_id' => $requestedById,
				'scheduled_at' => $scheduledAt,
			]);
			$outbox_id = (int) ($outbox->dto()['outbox_id'] ?? 0);
			$queued_jobs = 0;

			foreach ($normalized_recipients as $recipient) {
				$recipient_row = EntityEmailOutboxRecipient::saveFromArray([
					'outbox_id' => $outbox_id,
					'recipient_type' => 'to',
					'recipient_email' => $recipient['email'],
					'recipient_name' => $recipient['name'] ?? null,
					'context_json' => json_encode($recipient['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				]);
				$recipient_id = (int) ($recipient_row->dto()['recipient_id'] ?? 0);

				EmailQueueStorage::enqueue(new EmailQueueJob(
					jobId: 'job_' . bin2hex(random_bytes(16)),
					jobType: 'email.transactional.send_snapshot',
					payload: [
						'outbox_id' => $outbox_id,
						'recipient_id' => $recipient_id,
					],
					requestedByType: $requestedByType,
					requestedById: $requestedById,
					priority: 'instant',
					runAfterUtc: $scheduledAt,
				));
				++$queued_jobs;
			}

			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->commit();
			}

			return [
				'outbox_id' => $outbox_id,
				'queued_jobs' => $queued_jobs,
			];
		} catch (Throwable $e) {
			if ($owns_transaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}

			throw $e;
		}
	}

	/**
	 * @param array<int, ShapeTransactionalRecipient> $recipients
	 * @return array<int, ShapeTransactionalRecipient>
	 */
	private static function normalizeRecipients(array $recipients): array
	{
		$normalized = [];
		$seen = [];

		foreach ($recipients as $recipient) {
			$email = trim((string) ($recipient['email'] ?? ''));

			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}

			$dedupe_key = mb_strtolower($email);

			if (isset($seen[$dedupe_key])) {
				continue;
			}

			$seen[$dedupe_key] = true;
			$normalized[] = [
				'email' => $email,
				'name' => isset($recipient['name']) ? trim((string) $recipient['name']) : '',
				'context' => is_array($recipient['context'] ?? null) ? $recipient['context'] : [],
			];
		}

		return $normalized;
	}
}
