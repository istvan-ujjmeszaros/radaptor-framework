<?php
/** @var array<string, string> $role_constants */
?>
class RoleList
{
<?php foreach ($role_constants as $name => $value) { ?>
	public const string <?= $name ?> = <?= var_export($value, true) ?>;
<?php } ?>
}
