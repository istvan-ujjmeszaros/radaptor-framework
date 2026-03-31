<?php
/**
 * @var array<string, string> $dataset_constants
 * @var array<string> $dataset_names
 */
?>
class ImportExportDatasetList
{
<?php foreach ($dataset_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>

	protected static array $_datasetNames = [
<?php foreach ($dataset_names as $value) { ?>
		<?= var_export($value, true) ?>,
<?php } ?>
	];
}
