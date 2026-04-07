<?php

class CLIOptionHelper
{
	public static function isJson(): bool
	{
		return Request::hasArg('json');
	}

	public static function getMainArgOrAbort(string $usage): string
	{
		$main_arg = Request::getMainArg();

		if (!is_string($main_arg) || trim($main_arg) === '') {
			Kernel::abort($usage);
		}

		return trim($main_arg);
	}

	public static function getOption(string $name, string $default = ''): string
	{
		global $argv;

		foreach ($argv ?? [] as $index => $arg) {
			if ($arg === "--{$name}") {
				$value = $argv[$index + 1] ?? null;

				if (is_string($value) && !str_starts_with($value, '--')) {
					return trim($value);
				}

				return $default;
			}
		}

		$value = Request::getArg($name);

		if (!is_string($value)) {
			return $default;
		}

		$value = trim($value);

		return $value !== '' ? $value : $default;
	}

	public static function getRequiredOption(string $name, string $usage): string
	{
		$value = self::getOption($name);

		if ($value === '') {
			Kernel::abort($usage);
		}

		return $value;
	}

	public static function getNullableIntOption(string $name): ?int
	{
		$value = self::getOption($name);

		if ($value === '') {
			return null;
		}

		if (!is_numeric($value)) {
			Kernel::abort("Option --{$name} must be numeric.");
		}

		return (int) $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getJsonOptionAsArray(string $name, bool $required = false, ?string $usage = null): array
	{
		$value = self::getOption($name);

		if ($value === '') {
			if ($required) {
				Kernel::abort($usage ?? "Missing required --{$name} option.");
			}

			return [];
		}

		try {
			$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			Kernel::abort("Invalid JSON for --{$name}: {$exception->getMessage()}");
		}

		if (!is_array($decoded)) {
			Kernel::abort("Option --{$name} must decode to a JSON object or array.");
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function writeJson(array $payload): void
	{
		echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}
}
