<?php

declare(strict_types=1);

/**
 * Generic CSV export/import helper.
 *
 * Works with any iCsvMap implementation. The helper handles:
 *   - header validation (required columns present, no unknown columns)
 *   - CSV parsing and default-value injection
 *   - driving the import loop with mode-aware delete-absent logic
 *   - export to a UTF-8 CSV string with BOM for Excel compatibility
 *
 * The map is responsible for all database interaction.
 *
 * ## Import modes
 *
 * Pass a CsvImportMode to import(). The map declares which modes it supports
 * via getSupportedImportModes(). If you pass an unsupported mode the import
 * will throw before touching any data.
 *
 * See the CsvImportMode enum doc for guidance on when to use each mode.
 * In particular: nested-set tables (resource_tree, adminmenu_tree, etc.)
 * MUST use Sync — partial imports corrupt lft/rgt values.
 */
class CsvHelper
{
	/** UTF-8 BOM — makes Excel open the file correctly without manual encoding steps */
	private const string _BOM = "\xEF\xBB\xBF";

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Export rows from the map to a UTF-8 CSV string (with BOM).
	 *
	 * @param array<string, mixed> $filters  Passed through to iCsvMap::exportRows()
	 */
	public static function export(iCsvMap $map, array $filters = []): string
	{
		$columns = array_keys($map->getColumnDefinitions());

		$buf = fopen('php://temp', 'r+');

		fwrite($buf, self::_BOM);
		fputcsv($buf, $columns, ',', '"', '\\');

		foreach ($map->exportRows($filters) as $row) {
			$line = [];

			foreach ($columns as $col) {
				$line[] = (string) ($row[$col] ?? '');
			}

			fputcsv($buf, $line, ',', '"', '\\');
		}

		rewind($buf);
		$csv = stream_get_contents($buf);
		fclose($buf);

		return $csv !== false ? $csv : '';
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate CSV headers against the map definition.
	 *
	 * @param  list<string>         $headers   First row of the CSV (column names)
	 * @param  iCsvMap              $map
	 * @return list<string>                    Error messages; empty = valid
	 */
	public static function validateHeaders(array $headers, iCsvMap $map): array
	{
		$headers = self::normalizeHeaderRow($headers);
		$definitions = $map->getColumnDefinitions();
		$errors      = [];

		// Required columns must be present
		foreach ($definitions as $col => $def) {
			if ($def['required'] && !in_array($col, $headers, true)) {
				$errors[] = "Required column missing: {$col}";
			}
		}

		// No columns outside the definition are allowed
		foreach ($headers as $col) {
			if (!array_key_exists($col, $definitions)) {
				$errors[] = "Unknown column: {$col}";
			}
		}

		return $errors;
	}

	/**
	 * Normalize a parsed header row.
	 *
	 * Trims values and removes trailing empty columns that spreadsheet tools
	 * often preserve when saving edited CSV files.
	 *
	 * @param list<string> $headers
	 * @return list<string>
	 */
	public static function normalizeHeaderRow(array $headers): array
	{
		$headers = array_map(
			fn ($header): string => trim((string) $header, " \t\n\r\0\x0B"),
			$headers
		);

		while (!empty($headers) && end($headers) === '') {
			array_pop($headers);
		}

		return $headers;
	}

	/**
	 * Treat CSV rows as blank when every value is null, empty, or only contains
	 * whitespace / null-byte artifacts.
	 *
	 * This also filters out the trailing "\0" artifact that can appear in some
	 * CLI-exported files because of the framework's output-buffer workaround.
	 *
	 * @param list<mixed> $rawRow
	 */
	public static function isIgnorableRawRow(array $rawRow): bool
	{
		$meaningful = array_filter(
			$rawRow,
			fn ($v): bool => $v !== null && trim((string) $v, " \t\n\r\0\x0B") !== ''
		);

		return empty($meaningful);
	}

	// -------------------------------------------------------------------------
	// Import
	// -------------------------------------------------------------------------

	/**
	 * Parse and import CSV content using the map.
	 *
	 * Steps:
	 *   1. Parse headers; validate against the map (fails fast on errors)
	 *   2. For each data row: fill defaults, call importRow()
	 *   3. In Sync mode: call deleteAbsentRows() with the collected natural keys
	 *
	 * In dry-run mode the method validates the entire file and returns a preview
	 * without writing anything to the database.
	 *
	 * @param  string        $csvContent  Raw CSV string (UTF-8, BOM optional)
	 * @param  iCsvMap       $map
	 * @param  CsvImportMode|null $mode   Defaults to $map->getDefaultImportMode()
	 * @param  bool          $dryRun      When true: validate only, no DB writes
	 * @return array{
	 *   processed: int,
	 *   imported: int,
	 *   inserted: int,
	 *   updated: int,
	 *   skipped: int,
	 *   deleted: int,
	 *   errors: list<string>,
	 *   row_results: list<array{
	 *     line?: int,
	 *     action: string,
	 *     natural_key?: string,
	 *     reason?: string
	 *   }>
	 * }
	 */
	public static function import(
		string $csvContent,
		iCsvMap $map,
		?CsvImportMode $mode = null,
		bool $dryRun = false
	): array {
		$mode ??= $map->getDefaultImportMode();

		// Validate the requested mode is supported by this map
		if (!in_array($mode, $map->getSupportedImportModes(), true)) {
			$supported = implode(', ', array_map(fn ($m) => $m->value, $map->getSupportedImportModes()));

			throw new \InvalidArgumentException(
				"Import mode '{$mode->value}' is not supported by this map. Supported: {$supported}"
			);
		}

		// Strip BOM if present
		$csvContent = ltrim($csvContent, self::_BOM);

		$handle = fopen('php://temp', 'r+');
		fwrite($handle, $csvContent);
		rewind($handle);

		// Parse header row
		$headers = fgetcsv($handle, 0, ',', '"', '\\');

		if ($headers === false) {
			fclose($handle);

			return [
				'processed' => 0,
				'imported' => 0,
				'inserted' => 0,
				'updated' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => ['CSV is empty or unreadable'],
				'row_results' => [],
			];
		}

		$headers = self::normalizeHeaderRow($headers);

		$headerErrors = self::validateHeaders($headers, $map);

		if (!empty($headerErrors)) {
			fclose($handle);

			return [
				'processed' => 0,
				'imported' => 0,
				'inserted' => 0,
				'updated' => 0,
				'skipped' => 0,
				'deleted' => 0,
				'errors' => $headerErrors,
				'row_results' => [],
			];
		}

		$definitions      = $map->getColumnDefinitions();
		$naturalKeyColumns = $map->getNaturalKeyColumns();
		$processed        = 0;
		$imported         = 0;
		$inserted         = 0;
		$updated          = 0;
		$skipped          = 0;
		$errors           = [];
		$importedNatKeys  = [];
		$row_results      = [];
		$lineNumber       = 1;

		while (($rawRow = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			$lineNumber++;

			if (self::isIgnorableRawRow($rawRow)) {
				continue; // blank line
			}

			$processed++;

			// Map CSV columns to named keys
			$row = [];

			foreach ($headers as $i => $col) {
				$row[$col] = $rawRow[$i] ?? '';
			}

			// Fill in defaults for optional columns not in the CSV
			foreach ($definitions as $col => $def) {
				if (!isset($row[$col]) && array_key_exists('default', $def)) {
					$row[$col] = (string) $def['default'];
				}
			}

			// Track natural key for Sync delete pass
			$naturalKey = self::_serializeNaturalKey($row, $naturalKeyColumns);
			$importedNatKeys[] = $naturalKey;

			try {
				$rowResult = [
					'line' => $lineNumber,
					'natural_key' => $naturalKey,
				] + $map->importRow($row, $mode, $dryRun);
				$row_results[] = $rowResult;

				switch ($rowResult['action']) {
					case 'inserted':
						$inserted++;
						$imported++;

						break;

					case 'updated':
						$updated++;
						$imported++;

						break;

					default:
						$skipped++;

						break;
				}
			} catch (\Throwable $e) {
				$errors[] = "Line {$lineNumber}: " . $e->getMessage();
				$skipped++;
				$row_results[] = [
					'line' => $lineNumber,
					'action' => 'error',
					'natural_key' => $naturalKey,
					'reason' => $e->getMessage(),
				];
			}
		}

		fclose($handle);

		$deleted = 0;

		if ($mode === CsvImportMode::Sync && empty($errors)) {
			$deletedRows = $map->deleteAbsentRows($importedNatKeys, $dryRun);
			$deleted = count($deletedRows);
			$row_results = array_merge($row_results, $deletedRows);
		}

		return compact('processed', 'imported', 'inserted', 'updated', 'skipped', 'deleted', 'errors', 'row_results');
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Serialize all required columns into a single string for natural-key tracking.
	 *
	 * @param array<string, string> $row
	 * @param list<string> $naturalKeyColumns
	 */
	private static function _serializeNaturalKey(array $row, array $naturalKeyColumns): string
	{
		$keyParts = [];

		foreach ($naturalKeyColumns as $col) {
			$keyParts[] = $col . '=' . ($row[$col] ?? '');
		}

		return implode('|', $keyParts);
	}
}
