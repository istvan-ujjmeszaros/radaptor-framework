<?php

declare(strict_types=1);

/**
 * @phpstan-type ShapeEmailQueueTransactional array{
 *   job_id: string,
 *   job_type: string,
 *   payload_json: string,
 *   requested_by_type: string,
 *   requested_by_id?: int|null,
 *   status?: string,
 *   attempts?: int,
 *   run_after_utc?: string,
 *   reserved_at?: string|null,
 *   created_at?: string
 * }
 *
 * @extends SQLEntity<ShapeEmailQueueTransactional>
 */
class EntityEmailQueueTransactional extends SQLEntity
{
	public const string TABLE_NAME = 'email_queue_transactional';
}
