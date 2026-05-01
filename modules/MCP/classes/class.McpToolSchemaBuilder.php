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
		$title = trim((string) ($meta['name'] ?? ''));

		$tool = [
			'name' => self::getToolName($meta),
			'description' => $description,
			'inputSchema' => self::buildInputSchema($meta),
			'annotations' => self::buildAnnotations($mcp, $title),
		];

		if ($title !== '') {
			$tool['title'] = $title;
		}

		$output_schema = $mcp['output_schema'] ?? null;

		if (is_array($output_schema)) {
			$tool['outputSchema'] = $output_schema;
		}

		return $tool;
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
	 * Annotation defaults follow MCP 2025-11-25 with a cautious AI-safety bias:
	 * non-readonly tools default to destructive=true and openWorld=true unless
	 * the event meta explicitly relaxes them. Read-only always wins over the
	 * destructive override — read-only + destructive is not representable.
	 *
	 * @param array<string, mixed> $mcp
	 * @return array<string, mixed>
	 */
	private static function buildAnnotations(array $mcp, string $title): array
	{
		$risk = (string) ($mcp['risk'] ?? 'write');
		$read_only = $risk === 'read';

		if ($read_only) {
			$destructive = false;
		} elseif (array_key_exists('destructive', $mcp)) {
			$destructive = (bool) $mcp['destructive'];
		} else {
			$destructive = true;
		}

		$idempotent = ($mcp['idempotent'] ?? false) === true;
		$open_world = array_key_exists('open_world', $mcp) ? (bool) $mcp['open_world'] : true;

		$annotations = [
			'readOnlyHint' => $read_only,
			'destructiveHint' => $destructive,
			'idempotentHint' => $idempotent,
			'openWorldHint' => $open_world,
		];

		if ($title !== '') {
			$annotations['title'] = $title;
		}

		return $annotations;
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
