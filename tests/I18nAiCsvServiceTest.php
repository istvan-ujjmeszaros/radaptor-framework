<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/class.I18nAiCsvService.php';

final class I18nAiCsvServiceTest extends TestCase
{
	public function testNormalizeAiImportCsvClearsReviewFlags(): void
	{
		$csv = "\xEF\xBB\xBFdomain,key,context,locale,source_text,expected_text,human_reviewed,text\n"
			. "admin,menu.runtime_diagnostics,,de_DE,Runtime diagnostics,Laufzeitdiagnose,1,Neue Laufzeitdiagnose\n"
			. "admin,menu.mcp_tokens,,de_DE,MCP tokens,MCP Tokens,,MCP Token\n";

		$normalize = Closure::bind(
			static fn (string $csv): string => I18nAiCsvService::normalizeAiImportCsv($csv),
			null,
			I18nAiCsvService::class
		);

		$this->assertInstanceOf(Closure::class, $normalize);

		$normalized = $normalize($csv);
		$rows = $this->_readCsvRows($normalized);

		$this->assertSame('0', $rows[1][6]);
		$this->assertSame('0', $rows[2][6]);
	}

	/**
	 * @return list<list<string|null>>
	 */
	private function _readCsvRows(string $csv): array
	{
		$handle = fopen('php://temp', 'r+');

		$this->assertIsResource($handle);

		fwrite($handle, ltrim($csv, "\xEF\xBB\xBF"));
		rewind($handle);

		$rows = [];

		while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
			$rows[] = $row;
		}

		fclose($handle);

		return $rows;
	}
}
