<?php

class CLIStorage
{
	private static ?string $storage_file_path = null;

	/**
	 * Initialize the file path and create the JSON file if it doesn't exist.
	 */
	private static function initialize(): void
	{
		if (!defined('RADAPTOR_CLI')) {
			Kernel::abort('CLIStorage can only be used when radaptor.php was run via the command line.');
		}

		if (!is_null(self::$storage_file_path)) {
			return;
		}

		$directory = (getenv('HOME') ?: getenv('USERPROFILE')) . DIRECTORY_SEPARATOR . '.radaptor';

		// Create the .radaptor directory if it doesn't exist
		if (!is_dir($directory)) {
			mkdir($directory, 0o700, true);
		}

		self::$storage_file_path = $directory . DIRECTORY_SEPARATOR . 'radaptor_userstore.json';

		// Initialize the storage file if it doesn't exist
		if (!file_exists(self::$storage_file_path)) {
			file_put_contents(self::$storage_file_path, json_encode([]));
			chmod(self::$storage_file_path, 0o600);
		}
	}

	/**
	 * Store a key/value pair in the JSON file.
	 *
	 * @param string $key The key to store.
	 * @param mixed $value The value to store.
	 */
	public static function save(string $key, mixed $value): void
	{
		self::initialize();

		$data = self::readData();

		$data[$key] = $value;

		self::writeData($data);
	}

	/**
	 * Retrieve the value associated with a key from the JSON file.
	 *
	 * @param string $key The key to retrieve.
	 * @return mixed|null The value associated with the key, or null if the key does not exist.
	 */
	public static function read(string $key): mixed
	{
		self::initialize();

		$data = self::readData();

		return $data[$key] ?? null;
	}

	/**
	 * Delete a key/value pair from the JSON file.
	 *
	 * @param string $key The key to delete.
	 */
	public static function delete(string $key): void
	{
		self::initialize();

		$data = self::readData();

		if (isset($data[$key])) {
			unset($data[$key]);
			self::writeData($data);
		}
	}

	/**
	 * Read and decode the JSON file into an associative array.
	 *
	 * @return array The data from the JSON file.
	 */
	private static function readData(): array
	{
		$json = file_get_contents(self::$storage_file_path);

		return json_decode($json, true);
	}

	/**
	 * Encode and write the associative array back to the JSON file.
	 *
	 * @param array $data The data to write to the JSON file.
	 */
	private static function writeData(array $data): void
	{
		file_put_contents(self::$storage_file_path, json_encode($data, JSON_PRETTY_PRINT));
	}
}
