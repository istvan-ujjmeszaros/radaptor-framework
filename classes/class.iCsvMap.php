<?php

declare(strict_types=1);

/**
 * Contract for a CSV map — binds column definitions to specific import/export logic.
 *
 * Each concrete map is responsible for:
 *   - declaring which columns the CSV must (and may) contain
 *   - exporting rows (any JOIN/query is the map's business)
 *   - importing one validated row (upsert, ID remapping, etc.)
 *   - declaring which CsvImportMode values are valid for this data type
 *   - deleting absent rows in Sync mode
 *
 * The CsvHelper uses this interface to validate the CSV file header and drive
 * the import loop; it never touches the database directly.
 *
 * ## Choosing import modes
 *
 * A map MUST restrict getSupportedImportModes() to modes that cannot corrupt
 * the underlying data:
 *
 *   - Flat tables with natural keys: all three modes are safe.
 *   - Nested-set tables (resource_tree, adminmenu_tree, mainmenu_tree, etc.):
 *     declare [CsvImportMode::Sync] ONLY. Partial imports leave lft/rgt values
 *     inconsistent. deleteAbsentRows() must also rebuild the nested-set indices
 *     after deletion.
 */
interface iCsvMap
{
	/**
	 * Column definitions.
	 *
	 * Keys are the CSV column names (must match exactly, case-sensitive).
	 * Each value is an array with:
	 *   - required (bool): whether the column must be present in the CSV header
	 *   - default (mixed, optional): value to use when the column is absent from
	 *     an individual row; only meaningful for non-required columns
	 *
	 * @return array<string, array{required: bool, default?: mixed}>
	 */
	public function getColumnDefinitions(): array;

	/**
	 * Export rows. The map may perform any JOINs or queries needed.
	 * Yielding rows is preferred over returning a large array.
	 *
	 * @param array<string, mixed> $filters  Optional filters (e.g. ['locale' => 'hu_HU'])
	 * @return iterable<array<string, string>>
	 */
	public function exportRows(array $filters = []): iterable;

	/**
	 * Natural key columns used to identify a row for Sync-mode deletion and
	 * structured import reporting.
	 *
	 * This MUST contain only stable identity columns and MUST NOT include
	 * mutable payload columns such as translated text or status values.
	 *
	 * @return list<string>
	 */
	public function getNaturalKeyColumns(): array;

	/**
	 * Import one row that has already been validated against getColumnDefinitions().
	 * The map handles insert/update logic, ID remapping, or anything else needed,
	 * and returns a structured row result describing what happened (or would
	 * happen in dry-run mode).
	 *
	 * @param array<string, string> $row  CSV row with default values filled in
	 * @param CsvImportMode $mode         The active import mode
	 * @param bool $dryRun               Validate/plan only; do not mutate state
	 * @return array{
	 *   action: 'inserted'|'updated'|'skipped',
	 *   natural_key?: string,
	 *   reason?: string
	 * }
	 */
	public function importRow(array $row, CsvImportMode $mode, bool $dryRun = false): array;

	/**
	 * Delete rows whose natural key was NOT present in the imported CSV.
	 * Called only at the end of a Sync import, after all rows have been processed.
	 *
	 * For nested-set tables this method MUST rebuild lft/rgt indices after
	 * deletion to keep the tree consistent.
	 *
	 * @param list<string> $importedNaturalKeys  Serialized natural keys seen during import
	 * @param bool $dryRun                      Validate/plan only; do not mutate state
	 * @return list<array{
	 *   action: 'deleted',
	 *   natural_key: string,
	 *   reason?: string
	 * }>
	 */
	public function deleteAbsentRows(array $importedNaturalKeys, bool $dryRun = false): array;

	/**
	 * Which import modes this map supports.
	 * CsvHelper will refuse a mode that is not in this list.
	 *
	 * Nested-set maps MUST return [CsvImportMode::Sync] only.
	 *
	 * @return list<CsvImportMode>
	 */
	public function getSupportedImportModes(): array;

	/**
	 * The mode to use when the caller does not specify one explicitly.
	 */
	public function getDefaultImportMode(): CsvImportMode;
}
