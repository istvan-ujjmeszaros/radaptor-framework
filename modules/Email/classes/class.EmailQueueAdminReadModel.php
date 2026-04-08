<?php

declare(strict_types=1);

class EmailQueueAdminReadModel
{
	private const array REQUIRED_TABLES = [
		'config_app',
		'email_queue_transactional',
		'email_queue_archive',
		'email_queue_dead_letter',
		'email_outbox',
		'email_outbox_recipients',
	];

	/**
	 * @return array{
	 *     pending_count: int,
	 *     retry_wait_count: int,
	 *     dead_letter_count: int,
	 *     sent_last_24h_count: int,
	 *     worker: array{
	 *         last_seen_at: ?string,
	 *         last_processed_at: ?string,
	 *         status: string,
	 *         is_stale: bool
	 *     }
	 * }
	 */
	public static function getSummary(): array
	{
		if (!self::hasRequiredTables()) {
			return [
				'pending_count' => 0,
				'retry_wait_count' => 0,
				'dead_letter_count' => 0,
				'sent_last_24h_count' => 0,
				'worker' => [
					'last_seen_at' => null,
					'last_processed_at' => null,
					'status' => 'unavailable',
					'is_stale' => true,
				],
			];
		}

		return [
			'pending_count' => self::fetchInt(
				"SELECT COUNT(*) FROM email_queue_transactional WHERE status = 'pending'"
			),
			'retry_wait_count' => self::fetchInt(
				"SELECT COUNT(*) FROM email_queue_transactional WHERE status = 'retry_wait'"
			),
			'dead_letter_count' => self::fetchInt(
				"SELECT COUNT(*) FROM email_queue_dead_letter"
			),
			'sent_last_24h_count' => self::fetchInt(
				"SELECT COUNT(*) FROM email_outbox WHERE status = 'sent' AND sent_at IS NOT NULL AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
			),
			'worker' => EmailQueueHeartbeat::getState(),
		];
	}

	/**
	 * @return array{
	 *     summary: array<string, mixed>,
	 *     outbox_rows: list<array<string, mixed>>,
	 *     recent_failures: list<array<string, mixed>>,
	 *     page: int,
	 *     per_page: int,
	 *     total: int,
	 *     total_pages: int,
	 *     status_filter: string,
	 *     search: string
	 * }
	 */
	public static function getOutboxViewData(string $status_filter = '', string $search = '', int $page = 1, int $per_page = 25): array
	{
		if (!self::hasRequiredTables()) {
			return [
				'summary' => self::getSummary(),
				'outbox_rows' => [],
				'recent_failures' => [],
				'page' => 1,
				'per_page' => $per_page,
				'total' => 0,
				'total_pages' => 1,
				'status_filter' => '',
				'search' => '',
			];
		}

		$page = max(1, $page);
		$per_page = max(1, min(100, $per_page));
		$offset = ($page - 1) * $per_page;
		$where_sql = self::buildOutboxWhereSql($status_filter, $search);

		$total = self::fetchInt(
			"SELECT COUNT(*) FROM email_outbox o {$where_sql['sql']}",
			$where_sql['params']
		);

		$list_sql = "
			SELECT
				o.outbox_id,
				o.message_uid,
				o.subject,
				o.status,
				o.created_at,
				o.sent_at,
				o.last_error_code,
				o.last_error_message,
				COUNT(r.recipient_id) AS recipient_total,
				SUM(CASE WHEN r.status = 'sent' THEN 1 ELSE 0 END) AS recipient_sent,
				SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) AS recipient_failed
			FROM email_outbox o
			LEFT JOIN email_outbox_recipients r ON r.outbox_id = o.outbox_id
			{$where_sql['sql']}
			GROUP BY o.outbox_id
			ORDER BY o.outbox_id DESC
			LIMIT {$per_page} OFFSET {$offset}
		";

		$outbox_rows = DbHelper::prexecute($list_sql, $where_sql['params'])?->fetchAll(PDO::FETCH_ASSOC) ?? [];
		$recent_failures = DbHelper::prexecute(
			"SELECT dead_letter_id, source_table, job_type, error_code, error_message, dead_lettered_at
			FROM email_queue_dead_letter
			ORDER BY dead_letter_id DESC
			LIMIT 10"
		)?->fetchAll(PDO::FETCH_ASSOC) ?? [];

		return [
			'summary' => self::getSummary(),
			'outbox_rows' => $outbox_rows,
			'recent_failures' => $recent_failures,
			'page' => $page,
			'per_page' => $per_page,
			'total' => $total,
			'total_pages' => max(1, (int) ceil($total / $per_page)),
			'status_filter' => $status_filter,
			'search' => $search,
		];
	}

	/**
	 * @return array{sql: string, params: list<mixed>}
	 */
	private static function buildOutboxWhereSql(string $status_filter, string $search): array
	{
		$clauses = [];
		$params = [];
		$allowed_statuses = ['queued', 'processing', 'sent', 'partial_failed', 'failed'];

		if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
			$clauses[] = 'o.status = ?';
			$params[] = $status_filter;
		}

		$search = trim($search);

		if ($search !== '') {
			$like = '%' . $search . '%';
			$clauses[] = '(o.subject LIKE ? OR o.message_uid LIKE ? OR EXISTS (
				SELECT 1
				FROM email_outbox_recipients r2
				WHERE r2.outbox_id = o.outbox_id
				  AND r2.recipient_email LIKE ?
			))';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return [
			'sql' => $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses),
			'params' => $params,
		];
	}

	/**
	 * @param list<mixed> $params
	 */
	private static function fetchInt(string $query, array $params = []): int
	{
		$value = DbHelper::prexecute($query, $params)?->fetchColumn();

		return is_numeric($value) ? (int) $value : 0;
	}

	private static function hasRequiredTables(): bool
	{
		foreach (self::REQUIRED_TABLES as $table_name) {
			if (!self::tableExists($table_name)) {
				return false;
			}
		}

		return true;
	}

	private static function tableExists(string $table_name): bool
	{
		$quoted_table_name = Db::instance()->quote($table_name);

		return Db::instance()->query("SHOW TABLES LIKE {$quoted_table_name}")?->rowCount() > 0;
	}
}
