<?php

class SessionHandlerMysql implements SessionHandlerInterface
{
	//private $_dsn = 'mysql:host=127.0.0.1;dbname=persistent_cache';
	private string $_dsn = 'mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=persistent_cache';
	private string $_username = 'persistent_cache';
	private string $_password = 'persistent_cache';

	public function __construct(private ?PDO $_connection = null)
	{
	}

	public function getConnection(): ?PDO
	{
		return $this->_connection;
	}

	public function setConfig(array $config = []): void
	{
		foreach ($config as $key => $value) {
			switch ($key) {
				case 'dsn':
					$this->_dsn = $value;

					break;

				case 'username':
					$this->_username = $value;

					break;

				case 'password':
					$this->_password = $value;

					break;

				default:
					Kernel::abort("Unknown config key: {$key}");
			}
		}
	}

	public function open(string $path, string $name): bool
	{
		if ($this->_connection) {
			return true;
		}

		try {
			$this->_connection = new PDO($this->_dsn, $this->_username, $this->_password, [PDO::ATTR_PERSISTENT => true]);
		} catch (Exception) {
			return false;
		}

		return true;
	}

	public function close(): bool
	{
		return true;
	}

	public function read(string $id): string
	{
		$query = "SELECT data FROM sessions WHERE id=? LIMIT 1;";
		$stmt = $this->_connection->prepare($query);
		$stmt->execute([hex2bin($id)]);

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($rs === false) {
			return '';
		}

		return $rs['data'];
	}

	public function write(string $id, string $data): bool
	{
		$savedata = [
			'id' => hex2bin($id),
			'data' => $data,
		];

		$query = "INSERT INTO sessions SET " . DbHelper::generateEnumeration($savedata);
		$query .= ' ON DUPLICATE KEY UPDATE ' . DbHelper::generateEnumeration($savedata);

		$stmt = $this->_connection->prepare($query);
		$stmt->execute(array_merge(array_values($savedata), array_values($savedata)));

		return true;
	}

	public function destroy(string $id): bool
	{
		$query = "DELETE FROM sessions WHERE id = ?;";

		$stmt = $this->_connection->prepare($query);
		$stmt->execute([hex2bin($id)]);

		return true;
	}

	public function gc(int $max_lifetime): int|false
	{
		return $max_lifetime;
	}
}
