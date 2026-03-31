<?php

declare(strict_types=1);

/**
 * Runtime helper around the generated import/export dataset registry.
 */
class ImportExportDataset extends ImportExportDatasetList
{
	public static function factory(string $dataset_name): AbstractImportExportDataset
	{
		$class_name = 'ImportExportDataset' . ucwords($dataset_name);

		if (!AutoloaderFromGeneratedMap::autoloaderClassExists($class_name)) {
			Kernel::abort("Requested import/export dataset class '{$class_name}' does not exist.");
		}

		$instance = new $class_name();

		if (!$instance instanceof AbstractImportExportDataset) {
			Kernel::abort("Import/export dataset <i>{$dataset_name}</i> must implement <b>AbstractImportExportDataset</b>.");
		}

		return $instance;
	}

	public static function checkDatasetExists(string $dataset_name): bool
	{
		return in_array($dataset_name, self::$_datasetNames, true);
	}

	public static function getVisibleDatasetList(): array
	{
		$return = [];

		foreach (self::$_datasetNames as $dataset_name) {
			$dataset = self::factory($dataset_name);

			if ($dataset->getListVisibility()) {
				$return[] = $dataset;
			}
		}

		return $return;
	}

	public static function getByKey(string $key): ?AbstractImportExportDataset
	{
		foreach (self::getVisibleDatasetList() as $dataset) {
			if ($dataset->getKey() === $key) {
				return $dataset;
			}
		}

		return null;
	}
}
