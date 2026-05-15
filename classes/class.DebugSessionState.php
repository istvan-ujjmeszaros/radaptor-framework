<?php

declare(strict_types=1);

final class DebugSessionState
{
	/**
	 * @param list<string> $features
	 */
	public function __construct(
		public readonly bool $enabled = false,
		public readonly string $sessionId = '',
		public readonly string $requestId = '',
		public readonly array $features = [],
	) {
	}

	public static function disabled(): self
	{
		return new self(
			enabled: false,
			sessionId: '',
			requestId: '',
			features: []
		);
	}

	/**
	 * @param list<string> $features
	 */
	public static function enabled(string $sessionId, string $requestId, array $features): self
	{
		return new self(
			enabled: true,
			sessionId: $sessionId,
			requestId: $requestId,
			features: array_values($features)
		);
	}
}
