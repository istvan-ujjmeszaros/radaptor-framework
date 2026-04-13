<?php

declare(strict_types=1);

/**
 * @phpstan-type ShapeEmailQueuePayload array<string, mixed>
 */
class EmailQueueJob
{
	/**
	 * @param ShapeEmailQueuePayload $payload
	 */
	public function __construct(
		public string $jobId,
		public string $jobType,
		public array $payload,
		public string $requestedByType,
		public ?int $requestedById,
		public string $priority = 'instant',
		public ?string $runAfterUtc = null,
	) {
	}
}
