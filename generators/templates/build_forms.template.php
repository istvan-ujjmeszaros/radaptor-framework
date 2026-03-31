<?php
/** @var array<string, string> $form_constants */
?>
class FormList
{
<?php foreach ($form_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>
}
