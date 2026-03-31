<?php
/**
 * @var array<string, string> $widget_constants
 * @var array<string> $widget_names
 */
?>
class WidgetList
{
<?php foreach ($widget_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>

	protected static array $_widgetNames = [
<?php foreach ($widget_names as $value) { ?>
		<?= var_export($value, true) ?>,
<?php } ?>
	];
}
