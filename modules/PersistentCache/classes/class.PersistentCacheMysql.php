<?php

class PersistentCacheMysql implements iPersistentCache
{
	private ?PDO $_connection = null;
	//private $_dsn = 'mysql:host=127.0.0.1;dbname=persistent_cache';
	private string $_dsn = 'mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=persistent_cache';
	private string $_username = 'persistent_cache';
	private string $_password = 'persistent_cache';

	//private $_socket = '/var/run/redis/redis.sock';

	public function __construct($connection = false)
	{
		if ($connection === false) {
			$this->initConnection();
		} else {
			$this->_connection = $connection;
		}
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

	public function initConnection(): void
	{
		if ($this->_connection) {
			return;
		}

		$this->_connection = new PDO($this->_dsn, $this->_username, $this->_password, [PDO::ATTR_PERSISTENT => true]);
	}

	public function set(string $key, mixed $value): mixed
	{
		$savedata = [
			'id' => md5($key, true),
			'data' => $value,
		];

		$query = "INSERT INTO resource_cache SET " . DbHelper::generateEnumeration($savedata);
		$query .= ' ON DUPLICATE KEY UPDATE ' . DbHelper::generateEnumeration($savedata);

		$stmt = $this->_connection->prepare($query);
		$stmt->execute(array_merge(array_values($savedata), array_values($savedata)));

		return $value;
	}

	public function setEx(string $key, int $ttl, mixed $value): mixed
	{
		$savedata = [
			'id' => md5($key, true),
			'expires' => time() + $ttl,
			'data' => $value,
		];

		$query = "INSERT INTO resource_cache SET " . DbHelper::generateEnumeration($savedata);
		$query .= ' ON DUPLICATE KEY UPDATE ' . DbHelper::generateEnumeration($savedata);

		$stmt = $this->_connection->prepare($query);
		$stmt->execute(array_merge(array_values($savedata), array_values($savedata)));

		return $value;
	}

	public function get(string $key): mixed
	{
		$key = md5($key, true);
		$query = "SELECT data FROM resource_cache WHERE id=? LIMIT 1";
		$stmt = $this->_connection->prepare($query);
		$stmt->execute([$key]);

		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		if ($rs === false) {
			return null;
		}

		return $rs['data'];
	}

	public function exists(string $key): bool
	{
		$key = md5($key, true);
		$query = "SELECT COUNT(1) AS counter FROM resource_cache WHERE id=?";
		$stmt = $this->_connection->prepare($query);
		$stmt->execute([$key]);
		$rs = $stmt->fetch(PDO::FETCH_ASSOC);

		return $rs['counter'];
	}
}
