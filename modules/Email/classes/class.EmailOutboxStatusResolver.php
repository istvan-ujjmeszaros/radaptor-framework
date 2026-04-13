<?php

declare(strict_types=1);

class EmailOutboxStatusResolver
{
	public static function recompute(int $outbox_id): void
	{
		if ($outbox_id <= 0) {
			return;
		}

		$rows = DbHelper::selectMany(
			'email_outbox_recipients',
			['outbox_id' => $outbox_id],
			false,
			'recipient_id ASC',
			'status,sent_at,last_error_code,last_error_message'
		);

		if (empty($rows)) {
			return;
		}

		$total = count($rows);
		$sent = 0;
		$failed = 0;
		$queued = 0;
		$latest_sent_at = null;
		$last_error_code = null;
		$last_error_message = null;

		foreach ($rows as $row) {
			$status = (string) ($row['status'] ?? 'queued');

			if ($status === 'sent') {
				++$sent;
				$sent_at = (string) ($row['sent_at'] ?? '');

				if ($sent_at !== '' && (is_null($latest_sent_at) || $sent_at > $latest_sent_at)) {
					$latest_sent_at = $sent_at;
				}

				continue;
			}

			if ($status === 'failed') {
				++$failed;
				$error_code = trim((string) ($row['last_error_code'] ?? ''));
				$error_message = trim((string) ($row['last_error_message'] ?? ''));

				if ($error_code !== '') {
					$last_error_code = $error_code;
				}

				if ($error_message !== '') {
					$last_error_message = $error_message;
				}

				continue;
			}

			++$queued;
		}

		$status = 'queued';
		$sent_at = null;

		if ($sent === $total) {
			$status = 'sent';
			$sent_at = $latest_sent_at ?? date('Y-m-d H:i:s');
			$last_error_code = null;
			$last_error_message = null;
		} elseif ($failed === $total) {
			$status = 'failed';
		} elseif ($sent > 0 && $failed > 0) {
			$status = 'partial_failed';
		} elseif (($sent > 0 || $failed > 0) && $queued > 0) {
			$status = 'processing';
		}

		EntityEmailOutbox::updateById($outbox_id, [
			'status' => $status,
			'sent_at' => $sent_at,
			'last_error_code' => $last_error_code,
			'last_error_message' => $last_error_message,
		]);
	}
}
