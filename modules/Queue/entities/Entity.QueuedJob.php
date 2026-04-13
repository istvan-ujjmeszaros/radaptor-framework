<?php

/**
 * @phpstan-type ShapeQueuedJob array{
 *   queue_id?: int,
 *   job_id: string,
 *   job_type: string,
 *   payload_json: string,
 *   requested_by_type: string,
 *   requested_by_id?: int|null,
 *   status?: string,
 *   attempts?: int,
 *   run_after_utc?: string,
 *   reserved_at?: string|null,
 *   completed_at?: string|null,
 *   last_error_code?: string|null,
 *   last_error_message?: string|null,
 *   created_at?: string
 * }
 *
 * @extends SQLEntity<ShapeQueuedJob>
 */
class EntityQueuedJob extends SQLEntity
{
	public const string TABLE_NAME = 'queued_jobs';
}
