<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.I18nHardcodedUiScanner.php';

final class I18nHardcodedUiScannerTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/radaptor-i18n-hardcoded-ui-' . bin2hex(random_bytes(6));
		self::mkdir($this->root);
	}

	protected function tearDown(): void
	{
		self::deleteTree($this->root);
	}

	public function testScansPhpInlineHtmlTextAndAttributes(): void
	{
		$this->writeFile('templates/template.mailpit.php', <<<'PHP'
			<?php $label = 'Ignored'; ?>
			<table>
				<tr>
					<th>Subject</th>
					<td><?= e($label) ?></td>
					<td><?= t('mailpit.subject') ?></td>
				</tr>
			</table>
			<a href="<?= e($url) ?>" class="btn">Open</a>
			<button title="Delete message">Delete</button>
			<input placeholder="Search">
			PHP);

		$result = $this->scan();
		$literals = self::literals($result);

		self::assertContains('Subject', $literals);
		self::assertContains('Open', $literals);
		self::assertContains('Delete message', $literals);
		self::assertContains('Delete', $literals);
		self::assertContains('Search', $literals);
		self::assertNotContains('Ignored', $literals);
		self::assertNotContains('mailpit.subject', $literals);
		self::assertFalse(self::containsLiteralFragment($result, '<a href'));
		self::assertSame('php', self::findByLiteral($result, 'Subject')['format']);
	}

	public function testScansBladeTemplatesAndIgnoresBladeSyntax(): void
	{
		$this->writeFile('templates/template.panel.blade.php', <<<'BLADE'
			@if($enabled)
				<span>Save</span>
				<span>{{ $title }}</span>
				<span>{!! $html !!}</span>
				<button aria-label="Close panel">@escape($label)</button>
			@endif
			BLADE);

		$result = $this->scan();
		$literals = self::literals($result);

		self::assertContains('Save', $literals);
		self::assertContains('Close panel', $literals);
		self::assertNotContains('$title', $literals);
		self::assertNotContains('@if', $literals);
		self::assertSame('blade', self::findByLiteral($result, 'Save')['format']);
	}

	public function testScansTwigTemplatesAndIgnoresTwigSyntax(): void
	{
		$this->writeFile('templates/template.panel.twig', <<<'TWIG'
			{% if enabled %}
				<span>{{ title }}</span>
				<button title="Refresh">Refresh</button>
			{% endif %}
			TWIG);

		$result = $this->scan();
		$refresh_results = array_values(array_filter(
			$result['results'],
			static fn (array $row): bool => $row['literal'] === 'Refresh'
		));

		self::assertCount(2, $refresh_results);
		self::assertContains('attribute', array_column($refresh_results, 'type'));
		self::assertContains('text_node', array_column($refresh_results, 'type'));
		self::assertSame('twig', $refresh_results[0]['format']);
		self::assertNotContains('title', self::literals($result));
	}

	public function testIgnoresNonUiAndTechnicalContent(): void
	{
		$this->writeFile('templates/template.ignore.blade.php', <<<'BLADE'
			<script>Alert message</script>
			<style>.warning:before { content: "Warning"; }</style>
			<pre>Preformatted message</pre>
			<code>Code message</code>
			<svg><title>Vector title</title><text>Vector text</text></svg>
			<span>HTTP</span>
			<span>API</span>
			<span>100%</span>
			<span>support@example.com</span>
			<a href="https://example.com">https://example.com</a>
			<span>text/html</span>
			<!--[if IE]>Legacy browser text<![endif]-->
			BLADE);

		$result = $this->scan();

		self::assertSame(0, $result['issues'], json_encode($result['results'], JSON_PRETTY_PRINT));
	}

	public function testReportsLineNumbersForAllFormats(): void
	{
		$this->writeFile('templates/template.lines.php', <<<'PHP'
			<div>

				<span>Php Line</span>
			</div>
			PHP);
		$this->writeFile('templates/template.lines.blade.php', <<<'BLADE'
			@if($enabled)
			<div>
				<span>Blade Line</span>
			</div>
			@endif
			BLADE);
		$this->writeFile('templates/template.lines.twig', <<<'TWIG'
			{% if enabled %}
			<div>
				<span>Twig Line</span>
			</div>
			{% endif %}
			TWIG);

		$result = $this->scan();

		self::assertSame(3, self::findByLiteral($result, 'Php Line')['line']);
		self::assertSame(3, self::findByLiteral($result, 'Blade Line')['line']);
		self::assertSame(3, self::findByLiteral($result, 'Twig Line')['line']);
	}

	/**
	 * @return array{status: string, files_scanned: int, issues: int, results: list<array<string, mixed>>}
	 */
	private function scan(): array
	{
		return I18nHardcodedUiScanner::scan([
			'roots' => [$this->root],
		]);
	}

	private function writeFile(string $relative_path, string $content): void
	{
		$path = $this->root . '/' . $relative_path;
		self::mkdir(dirname($path));
		file_put_contents($path, $content);
	}

	/**
	 * @param array{results: list<array<string, mixed>>} $result
	 * @return list<string>
	 */
	private static function literals(array $result): array
	{
		return array_values(array_map(
			static fn (array $row): string => (string) $row['literal'],
			$result['results']
		));
	}

	/**
	 * @param array{results: list<array<string, mixed>>} $result
	 * @return array<string, mixed>
	 */
	private static function findByLiteral(array $result, string $literal): array
	{
		foreach ($result['results'] as $row) {
			if ($row['literal'] === $literal) {
				return $row;
			}
		}

		self::fail("Missing scanner result for literal: {$literal}");
	}

	/**
	 * @param array{results: list<array<string, mixed>>} $result
	 */
	private static function containsLiteralFragment(array $result, string $fragment): bool
	{
		foreach ($result['results'] as $row) {
			if (str_contains((string) $row['literal'], $fragment)) {
				return true;
			}
		}

		return false;
	}

	private static function mkdir(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0o777, true) && !is_dir($path)) {
			throw new RuntimeException("Unable to create fixture directory: {$path}");
		}
	}

	private static function deleteTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($path);
	}
}
