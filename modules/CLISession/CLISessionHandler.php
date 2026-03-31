<?php

class CLISessionHandler implements SessionHandlerInterface
{
	private const string SESSION_KEY = 'SESSION';

	public function open(string $path, string $name): bool
	{
		// No need to do anything here as CLIStorage handles initialization
		return true;
	}

	public function close(): bool
	{
		// No need to do anything here as CLIStorage handles file operations
		return true;
	}

	public function read(string $id): string|false
	{
		$data = CLIStorage::read(self::SESSION_KEY);

		return $data !== null ? (string)$data : '';
	}

	public function write(string $id, string $data): bool
	{
		CLIStorage::save(self::SESSION_KEY, $data);

		return true;
	}

	public function destroy(string $id): bool
	{
		CLIStorage::delete(self::SESSION_KEY);

		return true;
	}

	public function gc(int $max_lifetime): int|false
	{
		// CLI Session doesn't need to expire
		return 0;
	}
}
