<?php
/** @var array<string> $layout_type_names */
?>
class LayoutTypes
{
	protected static array $_layoutTypeNames = [
<?php foreach ($layout_type_names as $value) { ?>
		<?= var_export($value, true) ?>,
<?php } ?>
	];
}
