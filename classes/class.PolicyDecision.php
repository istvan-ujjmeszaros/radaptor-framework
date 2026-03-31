<?php

final class PolicyDecision
{
	private function __construct(
		public readonly bool   $allow,
		public readonly string $reason = '',
	) {
	}

	public static function allow(string $reason = 'allow'): self
	{
		return new self(true, $reason);
	}

	public static function deny(string $reason = 'access denied'): self
	{
		return new self(false, $reason);
	}
}
