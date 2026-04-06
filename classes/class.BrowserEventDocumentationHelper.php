<?php

final class BrowserEventDocumentationHelper
{
	/**
	 * @return array{
	 *   name: string,
	 *   source: string,
	 *   type: string,
	 *   required: bool,
	 *   description: string
	 * }
	 */
	public static function param(
		string $name,
		string $source,
		string $type,
		bool $required,
		string $description
	): array {
		return [
			'name' => $name,
			'source' => $source,
			'type' => $type,
			'required' => $required,
			'description' => $description,
		];
	}

	/**
	 * @param string ...$items
	 * @return list<string>
	 */
	public static function lines(string ...$items): array
	{
		return array_values(
			array_filter(
				array_map(
					static fn (string $item): string => trim($item),
					$items
				),
				static fn (string $item): bool => $item !== ''
			)
		);
	}
}
