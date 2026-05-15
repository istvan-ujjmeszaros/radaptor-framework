<?php

declare(strict_types=1);

final class DebugMessage
{
	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $level,
		public readonly string $kind,
		public readonly array $context,
		public readonly string $time,
		public readonly ?string $nodeId,
		public readonly string $requestId,
	) {
	}

	/**
	 * @return array{
	 *     code: string,
	 *     level: string,
	 *     kind: string,
	 *     context: array<string, mixed>,
	 *     time: string,
	 *     nodeId: string|null,
	 *     requestId: string
	 * }
	 */
	public function toArray(): array
	{
		return [
			'code' => $this->code,
			'level' => $this->level,
			'kind' => $this->kind,
			'context' => $this->context,
			'time' => $this->time,
			'nodeId' => $this->nodeId,
			'requestId' => $this->requestId,
		];
	}
}
