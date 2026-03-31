<?php
/**
 * @var array<string, string> $theme_constants
 * @var array<string> $theme_data_names
 */
?>
class ThemeList extends ThemeBase
{
<?php foreach ($theme_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>

	protected static array $_themeDataNames = [
<?php foreach ($theme_data_names as $value) { ?>
		<?= var_export($value, true) ?>,
<?php } ?>
	];
}
