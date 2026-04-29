<?php

declare(strict_types=1);

class McpToolSchemaBuilder
{
	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public static function buildTool(array $meta): array
	{
		$description = trim(implode("\n\n", array_filter([
			(string) ($meta['summary'] ?? ''),
			(string) ($meta['description'] ?? ''),
		])));
		$mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
		$risk = (string) ($mcp['risk'] ?? 'write');

		return [
			'name' => self::getToolName($meta),
			'description' => $description,
			'inputSchema' => self::buildInputSchema($meta),
			'annotations' => [
				'readOnlyHint' => $risk === 'read',
				'destructiveHint' => in_array($risk, ['dangerous', 'destructive'], true),
			],
		];
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function getToolName(array $meta): string
	{
		$mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
		$tool_name = trim((string) ($mcp['tool_name'] ?? ''));

		if ($tool_name !== '') {
			return $tool_name;
		}

		return 'radaptor.' . str_replace('_', '.', (string) ($meta['event_name'] ?? $meta['slug'] ?? 'unknown'));
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private static function buildInputSchema(array $meta): array
	{
		$params = $meta['request']['params'] ?? [];
		$properties = [];
		$required = [];

		if (!is_array($params)) {
			$params = [];
		}

		foreach ($params as $param) {
			if (!is_array($param)) {
				continue;
			}

			$name = (string) ($param['name'] ?? '');

			if ($name === '') {
				continue;
			}

			$properties[$name] = self::schemaForType(
				(string) ($param['type'] ?? 'string'),
				(string) ($param['description'] ?? '')
			);

			if (($param['required'] ?? false) === true) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
		];

		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function schemaForType(string $type, string $description): array
	{
		$schema = match ($type) {
			'int', 'integer' => ['type' => 'integer'],
			'bool', 'boolean' => ['type' => 'boolean'],
			'float', 'number' => ['type' => 'number'],
			'json-array', 'array' => ['type' => 'array', 'items' => new stdClass()],
			'json-object', 'object' => ['type' => 'object'],
			default => ['type' => 'string'],
		};

		if ($description !== '') {
			$schema['description'] = $description;
		}

		return $schema;
	}
}
