<?php

interface iBrowserEventDocumentable
{
	/**
	 * @return array{
	 *   event_name: string,
	 *   group: string,
	 *   name: string,
	 *   summary: string,
	 *   description: string,
	 *   request: array{
	 *     method: string,
	 *     params: list<array{
	 *       name: string,
	 *       source: string,
	 *       type: string,
	 *       required: bool,
	 *       description: string
	 *     }>
	 *   },
	 *   response: array{
	 *     kind: string,
	 *     content_type: string,
	 *     description: string
	 *   },
	 *   authorization: array{
	 *     visibility: string,
	 *     description: string
	 *   },
	 *   notes?: list<string>,
	 *   side_effects?: list<string>
	 * }
	 */
	public static function describeBrowserEvent(): array;
}
