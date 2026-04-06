<?php /** @var Template $this */ ?>
<?php
$meta = $this->props['meta'] ?? [];
$params = $meta['request']['params'] ?? [];
$notes = $meta['notes'] ?? [];
$sideEffects = $meta['side_effects'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= e((string) ($meta['name'] ?? 'Browser Event Docs')) ?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0d1321; color: #eef4ff; line-height: 1.55; }
		a { color: #7cc6fe; text-decoration: none; }
		a:hover { text-decoration: underline; }
		code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
		code { color: #f6bd60; }
		.wrapper { max-width: 980px; margin: 0 auto; padding: 2rem 1.5rem 3rem; }
		.back { display: inline-block; margin-bottom: 1rem; }
		h1 { margin: 0 0 0.35rem; font-size: 2rem; }
		.subtitle { margin: 0 0 1.5rem; color: #c5d0e6; }
		.panel { margin-top: 1rem; padding: 1rem 1.1rem; background: #111a2d; border: 1px solid #22304d; border-radius: 12px; }
		.panel h2 { margin: 0 0 0.85rem; font-size: 1.05rem; }
		.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.9rem; }
		.label { margin-bottom: 0.2rem; color: #9fb0cc; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; }
		.value { color: #eef4ff; }
		.param-table { width: 100%; border-collapse: collapse; }
		.param-table th, .param-table td { padding: 0.7rem 0.75rem; text-align: left; vertical-align: top; border-top: 1px solid #22304d; }
		.param-table th { border-top: 0; background: #16213a; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
		.note-list { margin: 0; padding-left: 1.2rem; color: #d6e0f3; }
		.helper-code { white-space: pre-wrap; word-break: break-word; }
	</style>
</head>
<body>
	<div class="wrapper">
		<a class="back" href="?context=events&amp;event=index&amp;format=html">Back to Browser Event API Docs</a>
		<h1><?= e((string) ($meta['name'] ?? 'Unknown event')) ?></h1>
		<p class="subtitle"><?= e((string) ($meta['summary'] ?? '')) ?></p>

		<div class="panel">
			<h2>Description</h2>
			<p><?= e((string) ($meta['description'] ?? '')) ?></p>
		</div>

		<div class="panel">
			<h2>Identity</h2>
			<div class="grid">
				<div>
					<div class="label">Slug</div>
					<div class="value"><code><?= e((string) ($meta['slug'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">Event Name</div>
					<div class="value"><code><?= e((string) ($meta['route']['event_name'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">Browser Route</div>
					<div class="value"><code><?= e((string) ($meta['route']['query'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">Class</div>
					<div class="value"><code><?= e((string) ($meta['class'] ?? '')) ?></code></div>
				</div>
			</div>
		</div>

		<div class="panel">
			<h2>Authorization</h2>
			<div class="grid">
				<div>
					<div class="label">Visibility</div>
					<div class="value"><?= e((string) ($meta['authorization']['visibility'] ?? '')) ?></div>
				</div>
				<div>
					<div class="label">Description</div>
					<div class="value"><?= e((string) ($meta['authorization']['description'] ?? '')) ?></div>
				</div>
			</div>
		</div>

		<div class="panel">
			<h2>Request / Response</h2>
			<div class="grid">
				<div>
					<div class="label">Method</div>
					<div class="value"><?= e((string) ($meta['request']['method'] ?? '')) ?></div>
				</div>
				<div>
					<div class="label">Response Kind</div>
					<div class="value"><?= e((string) ($meta['response']['kind'] ?? '')) ?></div>
				</div>
				<div>
					<div class="label">Content Type</div>
					<div class="value"><?= e((string) ($meta['response']['content_type'] ?? '')) ?></div>
				</div>
				<div>
					<div class="label">Response Description</div>
					<div class="value"><?= e((string) ($meta['response']['description'] ?? '')) ?></div>
				</div>
			</div>
		</div>

		<div class="panel">
			<h2>How to Call This</h2>
			<div class="grid">
				<div>
					<div class="label">PHP URL Helper</div>
					<div class="value helper-code"><code><?= e((string) ($meta['invocation']['url_php'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">Template Helper</div>
					<div class="value helper-code"><code><?= e((string) ($meta['invocation']['template_helper'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">AJAX Helper</div>
					<div class="value helper-code"><code><?= e((string) ($meta['invocation']['ajax_helper'] ?? '')) ?></code></div>
				</div>
				<div>
					<div class="label">AJAX Helper Raw</div>
					<div class="value helper-code"><code><?= e((string) ($meta['invocation']['ajax_helper_raw'] ?? '')) ?></code></div>
				</div>
			</div>
		</div>

		<div class="panel">
			<h2>Parameters</h2>
			<?php if (empty($params)): ?>
				<p>No parameters documented.</p>
			<?php else: ?>
				<table class="param-table">
					<thead>
					<tr>
						<th>Name</th>
						<th>Source</th>
						<th>Type</th>
						<th>Required</th>
						<th>Description</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($params as $param): ?>
						<tr>
							<td><code><?= e((string) ($param['name'] ?? '')) ?></code></td>
							<td><?= e((string) ($param['source'] ?? '')) ?></td>
							<td><?= e((string) ($param['type'] ?? '')) ?></td>
							<td><?= !empty($param['required']) ? 'yes' : 'no' ?></td>
							<td><?= e((string) ($param['description'] ?? '')) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if (!empty($notes)): ?>
			<div class="panel">
				<h2>Notes</h2>
				<ul class="note-list">
					<?php foreach ($notes as $note): ?>
						<li><?= e((string) $note) ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if (!empty($sideEffects)): ?>
			<div class="panel">
				<h2>Side Effects</h2>
				<ul class="note-list">
					<?php foreach ($sideEffects as $effect): ?>
						<li><?= e((string) $effect) ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</body>
</html>
