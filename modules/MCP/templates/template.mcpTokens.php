<?php assert(isset($this) && $this instanceof Template); ?>

<div class="subheader">
	<h1><?= e($this->strings['mcp.tokens.title'] ?? 'MCP tokens') ?></h1>
	<p><?= e($this->strings['mcp.tokens.description'] ?? '') ?></p>
</div>

<section class="card card-hover mb-4">
	<div class="card-body">
		<h2 class="h5 mb-2"><?= e($this->strings['mcp.tokens.endpoint'] ?? 'MCP endpoint') ?></h2>
		<input class="form-control" type="text" readonly value="<?= e((string) ($this->props['mcp_url'] ?? '')) ?>">
	</div>
</section>

<?php
$panel = new Template('mcpTokenPanel', $this->getRenderer(), $this->getWidgetConnection());
$panel->props = $this->props;
$panel->strings = $this->strings;
$panel->render();
?>
