<?php

/**
 * Scan source files for t() calls and register unknown keys into i18n_messages.
 *
 * Usage: radaptor i18n:scan [--dry-run]
 *
 * Examples:
 *   radaptor i18n:scan           # scan and register
 *   radaptor i18n:scan --dry-run # report only, no DB writes
 */
class CLICommandI18nScan extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Scan for i18n keys';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Scan source files for t() calls and register unknown keys into i18n_messages.

			Usage: radaptor i18n:scan [--dry-run] [--json]

			Examples:
			  radaptor i18n:scan
			  radaptor i18n:scan --dry-run
			  radaptor i18n:scan --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebTimeout(): int
	{
		return 60;
	}

	public function getRiskLevel(): string
	{
		return 'mutation';
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	private const array _SCAN_JS_DIRS = [
		'public/www/assets/',
	];

	/** @var array<string, true> */
	private array $_found = [];

	/** @var array<string, true>|null */
	private ?array $_existingMessageKeys = null;

	public function run(): void
	{
		$dryRun = Request::hasArg('dry-run');

		$this->_scanPhpDirs();
		$this->_scanJsDirs();

		$keys = array_keys($this->_found);
		sort($keys);

		if (empty($keys)) {
			echo "No i18n keys found.\n";

			return;
		}

		echo "Found " . count($keys) . " unique keys.\n";

		if ($dryRun) {
			foreach ($keys as $key) {
				echo "  {$key}\n";
			}
			echo "(dry-run — no changes written)\n";

			return;
		}

		$new = 0;

		foreach ($keys as $key) {
			if ($this->_messageKeyExists($key)) {
				continue;
			}

			[$domain, $keyPart] = $this->_splitKey($key);

			DbHelper::insertIfMissingHelper('i18n_messages', [
				'domain'  => $domain,
				'key'     => $keyPart,
				'context' => '',
			]);

			$this->_existingMessageKeys[$key] = true;
			$new++;
			echo "  + {$key}\n";
		}

		echo "Registered {$new} new keys.\n";
	}

	private function _messageKeyExists(string $fullKey): bool
	{
		if ($this->_existingMessageKeys === null) {
			$this->_existingMessageKeys = [];

			$stmt = Db::instance()->prepare("SELECT `domain`, `key` FROM `i18n_messages` WHERE `context` = ''");
			$stmt->execute();

			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$existingKey = $row['domain'] . '.' . $row['key'];
				$this->_existingMessageKeys[$existingKey] = true;
			}
		}

		return isset($this->_existingMessageKeys[$fullKey]);
	}

	private function _scanPhpDirs(): void
	{
		foreach ($this->_getPhpScanDirs() as $path) {
			if (!is_dir($path)) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file->isFile()) {
					continue;
				}

				$ext = $file->getExtension();

				if ($ext === 'php') {
					$this->_extractPhpKeys($file->getPathname());
				} elseif ($ext === 'twig' || str_ends_with($file->getFilename(), '.blade.php')) {
					$this->_extractRegexKeys(file_get_contents($file->getPathname()));
				}
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function _getPhpScanDirs(): array
	{
		$dirs = [
			DEPLOY_ROOT . 'app/',
		];

		foreach (PackagePathHelper::getActivePackageRoots(['core', 'theme', 'plugin']) as $package_root) {
			$dirs[] = rtrim($package_root, '/') . '/';
		}

		$framework_root = PackagePathHelper::getFrameworkRoot();
		$cms_root = PackagePathHelper::getCmsRoot();

		if (is_string($cms_root)) {
			$dirs[] = rtrim($cms_root, '/') . '/';
		}

		if (is_string($framework_root)) {
			$dirs[] = rtrim($framework_root, '/') . '/';
		}

		$dirs = array_values(array_unique(array_map(
			static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/') . '/',
			$dirs
		)));

		sort($dirs);

		return $dirs;
	}

	private function _scanJsDirs(): void
	{
		$root = DEPLOY_ROOT;

		foreach (self::_SCAN_JS_DIRS as $dir) {
			$path = $root . $dir;

			if (!is_dir($path)) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file->isFile() || $file->getExtension() !== 'js') {
					continue;
				}

				$content = file_get_contents($file->getPathname());
				$this->_extractJsKeys($content);
			}
		}
	}

	private function _extractPhpKeys(string $filePath): void
	{
		$content = file_get_contents($filePath);
		$tokens = token_get_all($content);
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token) || $token[0] !== T_STRING) {
				continue;
			}

			$name = $token[1];

			if ($name !== 't' && $name !== 'registerI18n') {
				continue;
			}

			// Skip past whitespace to '('
			$j = $i + 1;

			while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}

			if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
				continue;
			}

			$j++;

			// Skip whitespace
			while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				$j++;
			}

			if (!isset($tokens[$j])) {
				continue;
			}

			// Single string arg: t('key') or registerI18n('key')
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
				$key = trim($tokens[$j][1], "'\"");
				$this->_registerKey($key);

				continue;
			}

			// Array arg: registerI18n(['key1', 'key2'])
			if ($tokens[$j] === '[' || (is_array($tokens[$j]) && $tokens[$j][1] === 'array')) {
				$depth = 1;
				$j++;

				while ($j < $count && $depth > 0) {
					$t = $tokens[$j];

					if ($t === '[' || (is_array($t) && $t[1] === 'array')) {
						$depth++;
					} elseif ($t === ']' || $t === ')') {
						$depth--;
					} elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
						$key = trim($t[1], "'\"");
						$this->_registerKey($key);
					}

					$j++;
				}
			}
		}
	}

	private function _extractRegexKeys(string $content): void
	{
		// t('key') or t("key")
		if (preg_match_all("/\bt\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
			foreach ($matches[1] as $key) {
				$this->_registerKey($key);
			}
		}

		// registerI18n('key')
		if (preg_match_all("/registerI18n\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
			foreach ($matches[1] as $key) {
				$this->_registerKey($key);
			}
		}

		// registerI18n(['key1', 'key2', ...])
		if (preg_match_all("/registerI18n\(\s*\[([^\]]+)\]/", $content, $matches)) {
			foreach ($matches[1] as $inner) {
				if (preg_match_all("/['\"]([^'\"]+)['\"]/", $inner, $keyMatches)) {
					foreach ($keyMatches[1] as $key) {
						$this->_registerKey($key);
					}
				}
			}
		}
	}

	private function _extractJsKeys(string $content): void
	{
		// t('key') or t("key")
		if (preg_match_all("/\bt\(\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
			foreach ($matches[1] as $key) {
				$this->_registerKey($key);
			}
		}

		// window.__i18n['key'] or window.__i18n["key"]
		if (preg_match_all("/window\.__i18n\[['\"]([^'\"]+)['\"]\]/", $content, $matches)) {
			foreach ($matches[1] as $key) {
				$this->_registerKey($key);
			}
		}
	}

	private function _registerKey(string $key): void
	{
		if (!preg_match('/^\w+(\.\w+)+$/', $key)) {
			return;
		}

		$this->_found[$key] = true;
	}

	/**
	 * Split 'domain.rest.of.key' into ['domain', 'rest.of.key'].
	 * The first segment is always the domain; everything after is the key.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function _splitKey(string $fullKey): array
	{
		$pos = strpos($fullKey, '.');

		if ($pos === false) {
			return [$fullKey, $fullKey];
		}

		return [substr($fullKey, 0, $pos), substr($fullKey, $pos + 1)];
	}
}
