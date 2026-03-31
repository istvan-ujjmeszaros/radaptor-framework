<?php

class Migration_20260319_110000_add_slug_and_i18n_keys_to_tags
{
	public function run(): void
	{
		$pdo = Db::instance();

		if ($pdo->query("SHOW COLUMNS FROM tags LIKE 'slug'")->fetch() === false) {
			$pdo->exec("ALTER TABLE tags ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT '' AFTER context");
		}

		$rows = $pdo->query("SELECT id, context, slug, name FROM tags ORDER BY context, id")->fetchAll(PDO::FETCH_ASSOC);
		$usedSlugs = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$context = trim((string) ($row['context'] ?? ''));
			$name = trim((string) ($row['name'] ?? ''));
			$currentSlug = trim((string) ($row['slug'] ?? ''));

			$resolvedSlug = $this->resolveUniqueSlug(
				context: $context,
				name: $name,
				currentSlug: $currentSlug,
				tagId: $id,
				usedSlugs: $usedSlugs
			);

			if ($currentSlug !== $resolvedSlug) {
				$stmt = $pdo->prepare("UPDATE tags SET slug = ? WHERE id = ?");
				$stmt->execute([$resolvedSlug, $id]);
			}

			$this->upsertTagMessage($pdo, $context, $resolvedSlug, $name);
		}

		if ($pdo->query("SHOW INDEX FROM tags WHERE Key_name = 'uq_tags_context_slug'")->fetch() === false) {
			$pdo->exec("ALTER TABLE tags ADD UNIQUE KEY uq_tags_context_slug (context, slug)");
		}
	}

	/**
	 * @param array<string, array<string, true>> $usedSlugs
	 */
	private function resolveUniqueSlug(
		string $context,
		string $name,
		string $currentSlug,
		int $tagId,
		array &$usedSlugs
	): string {
		$baseSlug = $this->normalizeSlug($currentSlug !== '' ? $currentSlug : $name);

		if ($baseSlug === '') {
			$baseSlug = 'tag-' . $tagId;
		}

		$usedSlugs[$context] ??= [];

		$candidate = $baseSlug;
		$suffix = 2;

		while (isset($usedSlugs[$context][$candidate])) {
			$candidate = $baseSlug . '-' . $suffix;
			$suffix++;
		}

		$usedSlugs[$context][$candidate] = true;

		return $candidate;
	}

	private function upsertTagMessage(PDO $pdo, string $context, string $slug, string $name): void
	{
		if ($context === '' || $slug === '' || $name === '') {
			return;
		}

		$key = $context . '_' . $slug . '.label';
		$sourceText = HtmlProcessor::cleanText($name);
		$sourceHash = md5($sourceText);

		$stmt = $pdo->prepare(
			"INSERT INTO i18n_messages (`domain`, `key`, `context`, `source_text`, `source_hash`)
			VALUES ('tag', ?, '', ?, ?)
			ON DUPLICATE KEY UPDATE
				`source_text` = VALUES(`source_text`),
				`source_hash` = VALUES(`source_hash`)"
		);
		$stmt->execute([$key, $sourceText, $sourceHash]);
	}

	private function normalizeSlug(string $value): string
	{
		$value = trim(mb_strtolower($value));

		$transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

		if (is_string($transliterated) && $transliterated !== '') {
			$value = $transliterated;
		}

		$value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
		$value = preg_replace('/-+/', '-', $value) ?? '';

		return trim($value, '-');
	}
}
