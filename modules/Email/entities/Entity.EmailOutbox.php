<?php

declare(strict_types=1);

/**
 * @phpstan-type ShapeEmailOutbox array{
 *   outbox_id?: int,
 *   message_uid?: string,
 *   send_mode?: string,
 *   template_version_id?: int|null,
 *   subject?: string|null,
 *   html_body?: string|null,
 *   text_body?: string|null,
 *   status?: string,
 *   requested_by_type?: string,
 *   requested_by_id?: int|null,
 *   metadata_json?: string|null,
 *   scheduled_at?: string|null,
 *   sent_at?: string|null,
 *   last_error_code?: string|null,
 *   last_error_message?: string|null,
 *   created_at?: string,
 *   updated_at?: string
 * }
 *
 * @extends SQLEntity<ShapeEmailOutbox>
 */
class EntityEmailOutbox extends SQLEntity
{
	public const string TABLE_NAME = 'email_outbox';
}
