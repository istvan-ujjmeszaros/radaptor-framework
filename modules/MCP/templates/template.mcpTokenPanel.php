<?php assert(isset($this) && $this instanceof Template); ?>
<?php
$tokens = is_array($this->props['tokens'] ?? null) ? $this->props['tokens'] : [];
$newToken = is_array($this->props['new_token'] ?? null) ? $this->props['new_token'] : null;
$error = trim((string) ($this->props['error'] ?? ''));
$createUrl = (string) ($this->props['create_url'] ?? Url::getAjaxUrl('mcp.token-create'));
$revokeUrl = (string) ($this->props['revoke_url'] ?? Url::getAjaxUrl('mcp.token-revoke'));
$defaultDays = (int) ($this->props['default_days'] ?? McpTokenService::DEFAULT_EXPIRY_DAYS);
$expiryOptions = [
	30 => $this->strings['mcp.tokens.expiry_30'] ?? '30 days',
	90 => $this->strings['mcp.tokens.expiry_90'] ?? '90 days',
	365 => $this->strings['mcp.tokens.expiry_365'] ?? '1 year',
	0 => $this->strings['mcp.tokens.expiry_never'] ?? 'No expiry',
];
?>

<div id="mcp-token-panel">
	<?php if ($error !== '') { ?>
		<div class="alert alert-danger" role="alert"><?= e($error) ?></div>
	<?php } ?>

	<?php if ($newToken !== null) { ?>
		<section class="card card-hover mb-4 border-success">
			<div class="card-body">
				<h2 class="h5 mb-2"><?= e($this->strings['mcp.tokens.created_title'] ?? 'Token created') ?></h2>
				<p class="mb-3"><?= e($this->strings['mcp.tokens.created_help'] ?? 'Copy this token now. It will not be shown again.') ?></p>
				<input class="form-control font-monospace" type="text" readonly value="<?= e((string) ($newToken['token'] ?? '')) ?>">
			</div>
		</section>
	<?php } ?>

	<section class="card card-hover mb-4">
		<div class="card-body">
			<h2 class="h5 mb-3"><?= e($this->strings['mcp.tokens.create_title'] ?? 'Create token') ?></h2>
			<form method="post"
				  action="<?= e($createUrl) ?>"
				  hx-post="<?= e($createUrl) ?>"
				  hx-target="#mcp-token-panel"
				  hx-swap="outerHTML">
				<input type="hidden" name="format" value="html">
				<div class="row g-3 align-items-end">
					<div class="col-12 col-lg-6">
						<label class="form-label" for="mcp-token-name"><?= e($this->strings['mcp.tokens.name'] ?? 'Name') ?></label>
						<input id="mcp-token-name"
							   class="form-control"
							   type="text"
							   name="name"
							   maxlength="190"
							   placeholder="<?= e($this->strings['mcp.tokens.name_placeholder'] ?? '') ?>">
					</div>
					<div class="col-12 col-lg-3">
						<label class="form-label" for="mcp-token-days"><?= e($this->strings['mcp.tokens.expiry'] ?? 'Expiry') ?></label>
						<select id="mcp-token-days" class="form-select" name="days">
							<?php foreach ($expiryOptions as $days => $label) { ?>
								<option value="<?= e((string) $days) ?>" <?= $days === $defaultDays ? 'selected' : '' ?>>
									<?= e((string) $label) ?>
								</option>
							<?php } ?>
						</select>
					</div>
					<div class="col-12 col-lg-3">
						<button class="btn btn-primary w-100" type="submit">
							<?= e($this->strings['mcp.tokens.create'] ?? 'Create token') ?>
						</button>
					</div>
				</div>
			</form>
		</div>
	</section>

	<section class="card card-hover">
		<div class="card-header">
			<h2 class="h5 mb-0"><?= e($this->strings['mcp.tokens.existing_title'] ?? 'Existing tokens') ?></h2>
		</div>

		<?php if ($tokens === []) { ?>
			<div class="card-body">
				<p class="mb-0"><?= e($this->strings['mcp.tokens.empty'] ?? 'No MCP tokens yet.') ?></p>
			</div>
		<?php } else { ?>
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0">
					<thead>
					<tr>
						<th><?= e($this->strings['mcp.tokens.col.name'] ?? 'Name') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.prefix'] ?? 'Prefix') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.status'] ?? 'Status') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.created'] ?? 'Created') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.expires'] ?? 'Expires') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.last_used'] ?? 'Last used') ?></th>
						<th><?= e($this->strings['mcp.tokens.col.actions'] ?? 'Actions') ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($tokens as $token) { ?>
						<?php
						$status = (string) ($token['status'] ?? 'active');
						$statusLabel = $this->strings['mcp.tokens.status.' . $status] ?? ucfirst($status);
						$isRevoked = !empty($token['is_revoked']);
						?>
						<tr>
							<td><?= e((string) ($token['name'] ?? '')) ?></td>
							<td><code><?= e((string) ($token['display_token'] ?? '')) ?></code></td>
							<td><?= e((string) $statusLabel) ?></td>
							<td><?= e((string) ($token['created_at'] ?? '')) ?></td>
							<td><?= e((string) (($token['expires_at'] ?? '') ?: ($this->strings['mcp.tokens.never'] ?? 'Never'))) ?></td>
							<td><?= e((string) (($token['last_used_at'] ?? '') ?: ($this->strings['mcp.tokens.never'] ?? 'Never'))) ?></td>
							<td>
								<?php if (!$isRevoked) { ?>
									<form method="post"
										  action="<?= e($revokeUrl) ?>"
										  hx-post="<?= e($revokeUrl) ?>"
										  hx-target="#mcp-token-panel"
										  hx-swap="outerHTML"
										  hx-confirm="<?= e($this->strings['mcp.tokens.revoke_confirm'] ?? 'Revoke this MCP token?') ?>">
										<input type="hidden" name="format" value="html">
										<input type="hidden" name="token_id" value="<?= e((string) ($token['mcp_token_id'] ?? '')) ?>">
										<button class="btn btn-outline-danger btn-sm" type="submit">
											<?= e($this->strings['mcp.tokens.revoke'] ?? 'Revoke') ?>
										</button>
									</form>
								<?php } ?>
							</td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
		<?php } ?>
	</section>
</div>
