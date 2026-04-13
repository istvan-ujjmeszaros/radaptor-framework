<?php

class Helpers
{
	// Can be used as a workaround to get rid of "Closing tag matches nothing" warning
	public static function closingDiv(): string
	{
		return "</div>";
	}

	public static function getClassConstants(string $sClassName): array
	{
		try {
			$oClass = new ReflectionClass($sClassName);

			return $oClass->getConstants();
		} catch (ReflectionException) {
		}

		return [];
	}

	/**
	 * Cleans whitespaces (enables only one space between words).
	 */
	public static function clearText(string $string): string
	{
		return preg_replace('/\s+/', ' ', trim($string));
	}

	public static function sanitize(string $string = '', bool $force_lowercase = true, bool $alphanumeric = false): string
	{
		// Remove HTML tags and trim whitespace
		$clean = trim(strip_tags($string));

		// Replace multiple spaces with hyphens
		$clean = preg_replace('/\s+/', '-', $clean);

		$clean = $alphanumeric ? preg_replace('/[^a-zA-Z0-9]/', '', $clean) : $clean;

		// Convert to lowercase if needed
		return $force_lowercase ? mb_strtolower((string) $clean, 'UTF-8') : $clean;
	}

	public static function getHtmlDataAttribute(array $data): string
	{
		$return = "";

		foreach ($data as $key => $value) {
			$escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
			$return .= " data-$key=\"$escapedValue\"";
		}

		return $return;
	}

	/**
	 * Truncates a string to a specified length at the last occurrence of a specified break character and appends an optional pad string.
	 *
	 * @param string $string The input string to truncate.
	 * @param int $limit The maximum allowed length of the truncated string.
	 * @param string $break The character or substring where the string should be cut. Default is a space (" ").
	 * @param string $pad The string to append to the end of truncated text. Default is "...".
	 * @return string The truncated string with the pad appended.
	 */
	public static function truncateLongText(string $string, int $limit, string $break = " ", string $pad = "..."): string
	{
		if (strlen($string) <= $limit) {
			return $string;
		}

		$string = substr($string, 0, $limit);

		if (($breakpoint = strrpos($string, $break)) !== false) {
			$string = substr($string, 0, $breakpoint);
		}

		return $string . $pad;
	}
}
