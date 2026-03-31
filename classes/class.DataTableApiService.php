<?php

declare(strict_types=1);

final class DataTableApiService
{
	/**
	 * Wrap a DataTables-compatible payload inside the API envelope.
	 */
	public static function respond(array $payload): ApiResponse
	{
		return ApiResponse::success($payload);
	}

	/**
	 * Convenience helper for server-side DataTables responses.
	 *
	 * @param array<int, array<int, mixed>> $rows
	 */
	public static function fromRows(array $rows, int $draw, int $recordsTotal, int $recordsFiltered): ApiResponse
	{
		return ApiResponse::success([
			'draw' => $draw,
			'recordsTotal' => $recordsTotal,
			'recordsFiltered' => $recordsFiltered,
			'data' => $rows,
		]);
	}
}
