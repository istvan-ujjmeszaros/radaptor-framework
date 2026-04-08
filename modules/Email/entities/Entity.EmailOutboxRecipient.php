<?php

declare(strict_types=1);

/**
 * @phpstan-type ShapeEmailOutboxRecipient array{
 *   recipient_id?: int,
 *   outbox_id?: int,
 *   recipient_type?: string,
 *   recipient_email?: string,
 *   recipient_name?: string|null,
 *   context_json?: string|null,
 *   status?: string,
 *   sent_at?: string|null,
 *   last_error_code?: string|null,
 *   last_error_message?: string|null,
 *   created_at?: string
 * }
 *
 * @extends SQLEntity<ShapeEmailOutboxRecipient>
 */
class EntityEmailOutboxRecipient extends SQLEntity
{
	public const string TABLE_NAME = 'email_outbox_recipients';
}
