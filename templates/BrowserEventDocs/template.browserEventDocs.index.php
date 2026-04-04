<?php /** @var Template $this */ ?>
<?php
$grouped = $this->props['grouped'] ?? [];
$total = (int) ($this->props['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Browser Event API Docs</title>
	<style>
		* { box-sizing: border-box; }
		body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0d1321; color: #eef4ff; line-height: 1.5; }
		a { color: #7cc6fe; text-decoration: none; }
		a:hover { text-decoration: underline; }
		code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #f6bd60; }
		.wrapper { max-width: 1080px; margin: 0 auto; padding: 2rem 1.5rem 3rem; }
		h1 { margin: 0 0 0.5rem; font-size: 2rem; }
		.lead { margin: 0 0 2rem; color: #c5d0e6; }
		.group { margin-top: 2rem; }
		.group h2 { margin: 0 0 0.75rem; font-size: 1.25rem; }
		.count { color: #9fb0cc; font-weight: normal; }
		table { width: 100%; border-collapse: collapse; background: #111a2d; border: 1px solid #22304d; border-radius: 12px; overflow: hidden; }
		th, td { padding: 0.8rem 0.9rem; text-align: left; vertical-align: top; }
		th { background: #16213a; color: #d9e6ff; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.04em; }
		td { border-top: 1px solid #22304d; }
		.summary { color: #c5d0e6; max-width: 32rem; }
		.empty { padding: 1rem 1.25rem; background: #111a2d; border: 1px solid #22304d; border-radius: 12px; color: #c5d0e6; }
	</style>
</head>
<body>
	<div class="wrapper">
		<h1>Browser Event API Docs</h1>
		<p class="lead">Curated manual for important browser events. Total documented events: <?= $total ?></p>

		<?php if (empty($grouped)): ?>
			<div class="empty">No browser event docs were generated yet. Run <code>php radaptor.php build:event-docs</code>.</div>
		<?php endif; ?>

		<?php foreach ($grouped as $group => $events): ?>
			<section class="group">
				<h2><?= e((string) $group) ?> <span class="count">(<?= count($events) ?>)</span></h2>
				<table>
					<thead>
					<tr>
						<th>Name</th>
						<th>Route</th>
						<th>Slug</th>
						<th>Summary</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($events as $event): ?>
						<tr>
							<td>
								<a href="?context=events&amp;event=show&amp;slug=<?= urlencode((string) $event['slug']) ?>&amp;format=html">
									<?= e((string) $event['name']) ?>
								</a>
							</td>
							<td><code><?= e((string) ($event['route']['query'] ?? '')) ?></code></td>
							<td><code><?= e((string) $event['slug']) ?></code></td>
							<td class="summary"><?= e((string) ($event['summary'] ?? '')) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endforeach; ?>
	</div>
</body>
</html>
