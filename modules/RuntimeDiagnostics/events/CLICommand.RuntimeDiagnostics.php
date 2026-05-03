<?php

declare(strict_types=1);

class CLICommandRuntimeDiagnostics extends AbstractCLICommand implements iAuthorizable
{
	public function getName(): string
	{
		return 'Show runtime diagnostics';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show curated, redacted runtime diagnostics.

			Usage: radaptor runtime:diagnostics [--json]

			Requires the current CLI user to have the system_developer role.
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getRiskLevel(): string
	{
		return 'safe';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return RuntimeDiagnosticsAccessPolicy::authorize($policyContext);
	}

	public function run(): void
	{
		$summary = RuntimeDiagnosticsReadModel::getSummary();

		if (Request::hasArg('json')) {
			echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

			return;
		}

		$this->renderPlain($summary);
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private function renderPlain(array $summary): void
	{
		echo "Runtime Diagnostics\n";
		echo "===================\n\n";
		$this->renderSection('Environment', $summary['environment'] ?? []);
		$this->renderSection('Email safety', $summary['email'] ?? []);
		$this->renderSection('Database', $summary['database'] ?? []);
		$this->renderSection('Redis', $summary['redis'] ?? []);
		$this->renderSection('MCP', $summary['mcp'] ?? []);
		$this->renderSection('Packages', $summary['packages'] ?? []);

		$warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];

		if ($warnings !== []) {
			echo "Warnings\n";
			echo "--------\n";

			foreach ($warnings as $warning) {
				echo "  - " . (string) $warning . "\n";
			}

			echo "\n";
		}
	}

	/**
	 * @param mixed $data
	 */
	private function renderSection(string $title, mixed $data): void
	{
		echo $title . "\n";
		echo str_repeat('-', strlen($title)) . "\n";

		foreach ($this->flatten($data) as $key => $value) {
			echo '  ' . str_pad($key . ':', 32) . $value . "\n";
		}

		echo "\n";
	}

	/**
	 * @param mixed $value
	 * @return array<string, string>
	 */
	private function flatten(mixed $value, string $prefix = ''): array
	{
		if (!is_array($value)) {
			return [$prefix !== '' ? $prefix : 'value' => $this->stringify($value)];
		}

		$rows = [];

		foreach ($value as $key => $child) {
			$child_key = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

			if (is_array($child) && !array_is_list($child)) {
				$rows += $this->flatten($child, $child_key);
			} else {
				$rows[$child_key] = $this->stringify($child);
			}
		}

		return $rows;
	}

	private function stringify(mixed $value): string
	{
		if (is_bool($value)) {
			return $value ? 'yes' : 'no';
		}

		if ($value === null) {
			return '-';
		}

		if (is_array($value)) {
			return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
		}

		return (string) $value;
	}
}
