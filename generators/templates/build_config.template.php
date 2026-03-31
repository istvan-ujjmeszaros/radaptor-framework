<?php
/** @var array<string> $constant_names */
?>
/**
 * Enum Config.
 *
 * This enum serves as the central point for accessing configuration values
 * throughout the application. It ensures that all configuration access is
 * consistent, type-safe, and respects any overrides specified through
 * environment variables.
 *
 * The Config enum abstracts the access to configuration settings, allowing
 * for a flexible and robust configuration management system. It fetches
 * values from environment variables if they exist; otherwise, it falls back
 * to the default constants defined in the ApplicationConfig class.
 *
 * Usage of this enum is recommended over direct access to the ApplicationConfig
 * constants to ensure that any environment-specific overrides are applied
 * and that the types are correctly handled.
 *
 * Example Usage:
 * ---------------
 * To access the database host, use:
 *   - `$host = Config::DB_HOST->value();`
 *
 * This method will first check for an 'DB_HOST' environment variable. If it
 * is not found, it will return the default value from ApplicationConfig::DB_HOST.
 * This approach automatically handles type conversion based on the type of
 * the default value defined in ApplicationConfig.
 *
 * @see ApplicationConfig Refer to this class for default constant values and types.
 */
enum Config: string
{
<?php foreach ($constant_names as $name) { ?>
	case <?= $name ?> = '<?= $name ?>';
<?php } ?>

	/**
	 * Retrieves the configuration value for a given setting, with a fallback to
	 * the ApplicationConfig constant if no environment variable is set.
	 *
	 * @return mixed The configuration value, with type conversion based on the ApplicationConfig constant type.
	 */
	public function value(): mixed
	{
		$envValue = getenv($this->value);
		$constantValue = constant("ApplicationConfig::" . $this->value);

		if ($envValue !== false) {
			return $this->convertToType($envValue, gettype($constantValue));
		}

		return $constantValue;
	}

	/**
	 * Converts the environment variable string to the appropriate type based on
	 * the ApplicationConfig constant's type.
	 *
	 * @param string $value The environment variable value.
	 * @param string $type  The type to convert the value to.
	 * @return mixed The converted value.
	 */
	private function convertToType(string $value, string $type): mixed
	{
		return match ($type) {
			'integer' => (int) $value,
			'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'double' => (float) $value,
			default => $value,
		};
	}
}
