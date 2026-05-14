<?php

class SeedCliPromptHelper
{
	public static function isInteractive(): bool
	{
		if (!defined('STDIN')) {
			return false;
		}

		if (function_exists('stream_isatty')) {
			return stream_isatty(STDIN);
		}

		if (function_exists('posix_isatty')) {
			return posix_isatty(STDIN);
		}

		return false;
	}

	/**
	 * @param list<array<string, mixed>> $demo_seeds
	 */
	public static function confirmDemoSeedRerun(array $demo_seeds): bool
	{
		if (!self::isInteractive()) {
			return false;
		}

		echo "Demo seeds already ran before and may contain destructive cleanup logic.\n";
		echo "Selected demo seeds:\n";

		foreach ($demo_seeds as $seed) {
			$description = trim((string) ($seed['description'] ?? ''));
			$status = trim((string) ($seed['status'] ?? 'applied'));
			echo ' - ' . $seed['module'] . ' / ' . $seed['class'] . " ({$status})";

			if ($description !== '') {
				echo " - {$description}";
			}

			echo "\n";
		}

		echo "Rerun demo seeds? [y/N]: ";
		$answer = function_exists('readline')
			? readline()
			: fgets(STDIN);
		$answer = strtolower(trim((string) $answer));

		return in_array($answer, ['y', 'yes'], true);
	}

	/**
	 * @param array{
	 *     renames: array<string, string>,
	 *     webpages: list<array{resource_id: int, path: string, resource_name: string, old_layout: string, new_layout: string}>,
	 *     theme_settings: list<array{old_layout: string, new_layout: string, theme: string, conflict: bool, conflict_theme: ?string}>,
	 *     has_changes: bool
	 * } $pending
	 * @param array<string, array{new_layout: string, package: string, version: string}> $rename_metadata
	 */
	public static function confirmLayoutRenames(array $pending, array $rename_metadata = []): bool
	{
		if (!self::isInteractive()) {
			return false;
		}

		self::renderLayoutRenameSummary($pending, $rename_metadata);

		echo "Apply these layout renames? [y/N]: ";
		$answer = function_exists('readline')
			? readline()
			: fgets(STDIN);
		$answer = strtolower(trim((string) $answer));

		return in_array($answer, ['y', 'yes'], true);
	}

	/**
	 * @param array{
	 *     renames: array<string, string>,
	 *     webpages: list<array{resource_id: int, path: string, resource_name: string, old_layout: string, new_layout: string}>,
	 *     theme_settings: list<array{old_layout: string, new_layout: string, theme: string, conflict: bool, conflict_theme: ?string}>,
	 *     has_changes: bool
	 * } $pending
	 * @param array<string, array{new_layout: string, package: string, version: string}> $rename_metadata
	 */
	public static function renderLayoutRenameSummary(array $pending, array $rename_metadata = []): void
	{
		echo "Deprecated layouts declared by upcoming packages:\n\n";

		foreach ($pending['renames'] as $old_layout => $new_layout) {
			$declared_by = $rename_metadata[$old_layout] ?? null;
			$origin = $declared_by === null
				? ''
				: ' (declared by ' . $declared_by['package'] . ' ' . $declared_by['version'] . ')';
			echo "  {$old_layout} -> {$new_layout}{$origin}\n";

			$affected_webpages = array_values(array_filter(
				$pending['webpages'],
				static fn (array $row): bool => $row['old_layout'] === $old_layout
			));

			if ($affected_webpages !== []) {
				echo '    Affected webpages (' . count($affected_webpages) . "):\n";

				foreach ($affected_webpages as $row) {
					echo '      ' . $row['path'] . $row['resource_name'] . "\n";
				}
			} else {
				echo "    No webpages currently use this layout.\n";
			}

			$affected_theme_settings = array_values(array_filter(
				$pending['theme_settings'],
				static fn (array $row): bool => $row['old_layout'] === $old_layout
			));

			if ($affected_theme_settings !== []) {
				echo "    _theme_settings entries:\n";

				foreach ($affected_theme_settings as $row) {
					if ($row['conflict']) {
						echo '      WARNING: ' . $old_layout . ' -> ' . $row['theme']
							. ' (conflict: ' . $new_layout . ' already maps to ' . $row['conflict_theme'] . ")\n";
						echo "        On apply: keep " . $new_layout . ' -> ' . $row['conflict_theme']
							. ", drop " . $old_layout . " -> " . $row['theme'] . "\n";

						continue;
					}

					echo '      ' . $old_layout . ' -> ' . $row['theme']
						. ' (will be renamed to ' . $new_layout . ")\n";
				}
			}

			echo "\n";
		}
	}
}
