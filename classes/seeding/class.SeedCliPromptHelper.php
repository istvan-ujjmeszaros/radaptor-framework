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
}
