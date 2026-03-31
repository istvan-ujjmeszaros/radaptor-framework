<?php

declare(strict_types=1);

interface iImportExportDataset
{
	public function getKey(): string;

	public function getName(): string;

	public function getDescription(): string;

	public function getListVisibility(): bool;

	public function supportsExport(): bool;

	public function supportsImport(): bool;

	/**
	 * @return array<string, array{
	 *   type: string,
	 *   label: string,
	 *   required?: bool,
	 *   default?: string,
	 *   help?: string,
	 *   options?: array<string, string>,
	 *   accept?: string
	 * }>
	 */
	public function getExportFieldDefinitions(): array;

	/**
	 * @return array<string, array{
	 *   type: string,
	 *   label: string,
	 *   required?: bool,
	 *   default?: string,
	 *   help?: string,
	 *   options?: array<string, string>,
	 *   accept?: string
	 * }>
	 */
	public function getImportFieldDefinitions(): array;

	/**
	 * @param array<string, mixed> $options
	 */
	public function export(array $options): string;

	/**
	 * @param array<string, string> $options
	 */
	public function buildExportFilename(array $options): string;

	/**
	 * @param array<string, string> $options
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
	 *   }>,
	 *   detected_locale?: string,
	 *   detected_locales?: list<string>,
	 *   mode?: string,
	 *   format?: string
	 * }
	 */
	public function import(string $csvContent, array $options): array;
}
