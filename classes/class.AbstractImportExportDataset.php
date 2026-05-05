<?php

declare(strict_types=1);

abstract class AbstractImportExportDataset implements iImportExportDataset
{
	public const string ID = '';

	public function getKey(): string
	{
		return static::ID;
	}

	public function getDescription(): string
	{
		return '';
	}

	public function getListVisibility(): bool
	{
		return true;
	}

	public function supportsExport(): bool
	{
		return true;
	}

	public function supportsImport(): bool
	{
		return true;
	}

	public function getExportFieldDefinitions(): array
	{
		return [];
	}

	public function getImportFieldDefinitions(): array
	{
		return [];
	}

	public function buildExportFilename(array $options): string
	{
		return $this->getKey() . '.csv';
	}

	public function getExportContentType(): string
	{
		return 'text/csv; charset=UTF-8';
	}

	public function getExportTitle(): string
	{
		return t('import_export.export.title');
	}

	public function getImportTitle(): string
	{
		return t('import_export.import.title');
	}

	public function getExportActionLabel(): string
	{
		return t('import_export.action.export_csv');
	}
}
