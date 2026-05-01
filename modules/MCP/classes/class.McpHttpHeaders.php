<?php

declare(strict_types=1);

class McpHttpHeaders
{
	/**
	 * Returns the first scalar value matching $name (case-insensitive), or null
	 * if the header is absent or its value is non-scalar (e.g., a nested array).
	 *
	 * @param array<string, mixed> $headers
	 */
	public static function firstScalar(array $headers, string $name): ?string
	{
		$needle = strtolower($name);

		foreach ($headers as $key => $value) {
			if (strtolower((string) $key) !== $needle) {
				continue;
			}

			if (is_array($value)) {
				if ($value === []) {
					return null;
				}

				$value = reset($value);
			}

			if (!is_scalar($value)) {
				return null;
			}

			return trim((string) $value);
		}

		return null;
	}

	/**
	 * MCP 2025-11-25 streamable-HTTP requires POST clients to advertise both
	 * `application/json` and `text/event-stream` (or `*\/*`).
	 *
	 * Tokenizes on `,`, strips media-type parameters (e.g. `;q=0.9`), trims and
	 * lowercases each token, then checks set membership.
	 */
	public static function acceptsJsonAndEventStream(?string $accept): bool
	{
		if ($accept === null) {
			return false;
		}

		$accept = trim($accept);

		if ($accept === '') {
			return false;
		}

		$tokens = [];

		foreach (explode(',', $accept) as $part) {
			$semi = strpos($part, ';');
			$type = $semi === false ? $part : substr($part, 0, $semi);
			$type = strtolower(trim($type));

			if ($type !== '') {
				$tokens[$type] = true;
			}
		}

		if (isset($tokens['*/*'])) {
			return true;
		}

		return isset($tokens['application/json']) && isset($tokens['text/event-stream']);
	}
}
