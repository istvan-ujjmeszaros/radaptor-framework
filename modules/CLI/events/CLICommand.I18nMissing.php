<?php

/**
 * Report missing translations.
 *
 * Usage: radaptor i18n:missing [--locale hu_HU | locale=hu_HU] [--json]
 *
 * Examples:
 *   radaptor i18n:missing
 *   radaptor i18n:missing --locale hu_HU
 *   radaptor i18n:missing --locale hu_HU --json
 */
class CLICommandI18nMissing extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Report missing translations';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Report missing translations for one or all locales.

			Usage: radaptor i18n:missing [--locale hu_HU] [--json]

			Examples:
			  radaptor i18n:missing
			  radaptor i18n:missing --locale hu_HU
			  radaptor i18n:missing --locale hu_HU --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'locale', 'label' => 'Locale', 'type' => 'option'],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$localeFilter = $this->_getCliOption('locale', '');
		$jsonMode = Request::hasArg('json');

		$pdo = Db::instance();

		if ($localeFilter !== '') {
			$locales = [$localeFilter];
		} else {
			$rows = DbHelper::selectMany('i18n_translations', []);
			$locales = array_unique(array_column($rows, 'locale'));
		}

		$result = [];

		foreach ($locales as $locale) {
			$stmt = $pdo->prepare(
				"SELECT m.domain, m.`key`, m.context
				FROM i18n_messages m
				LEFT JOIN i18n_translations t ON t.domain = m.domain AND t.`key` = m.`key` AND t.context = m.context AND t.locale = :locale
				WHERE t.`key` IS NULL OR TRIM(COALESCE(t.text, '')) = ''
				ORDER BY m.domain, m.`key`"
			);
			$stmt->execute([':locale' => $locale]);
			$missing = $stmt->fetchAll(\PDO::FETCH_ASSOC);

			$result[$locale] = $missing;
		}

		if ($jsonMode) {
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

			return;
		}

		foreach ($result as $locale => $missing) {
			$count = count($missing);
			echo "Locale: {$locale} — {$count} missing\n";

			foreach ($missing as $row) {
				$key = $row['domain'] . '.' . $row['key'];

				if ($row['context'] !== '') {
					$key .= '.' . $row['context'];
				}

				echo "  {$key}\n";
			}
		}
	}

	/**
	 * Read a named CLI option, supporting both --name value and name=value forms.
	 */
	private function _getCliOption(string $name, string $default = ''): string
	{
		global $argv;

		foreach ($argv as $idx => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$idx + 1] ?? null;

				return is_string($value) && !str_starts_with($value, '--') ? trim($value) : $default;
			}
		}

		$keyValue = Request::getArg($name);

		if (!is_null($keyValue) && trim($keyValue) !== '') {
			return trim($keyValue);
		}

		$fallback = $_GET[$name] ?? null;

		if (is_string($fallback) && trim($fallback) !== '') {
			return trim($fallback);
		}

		return $default;
	}
}
