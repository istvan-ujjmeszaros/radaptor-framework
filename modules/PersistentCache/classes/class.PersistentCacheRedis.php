<?php

class PersistentCacheRedis implements iPersistentCache
{
	private ?Redis $_connection = null;
	private string $_host = '127.0.0.1';
	private int $_port = 6379;
	private float $_timeout = 2.0;
	private bool $_connected = false;

	public function __construct()
	{
		$this->_host = getenv('CACHE_REDIS_HOST') ?: $this->_host;
		$this->_port = (int) (getenv('CACHE_REDIS_PORT') ?: $this->_port);
		$this->_timeout = (float) (getenv('CACHE_REDIS_TIMEOUT') ?: $this->_timeout);
	}

	public function setConfig(array $config = []): void
	{
		foreach ($config as $key => $value) {
			switch ($key) {
				case 'host':
					$this->_host = (string) $value;

					break;

				case 'port':
					$this->_port = (int) $value;

					break;

				case 'timeout':
					$this->_timeout = (float) $value;

					break;

				default:
					Kernel::abort("Unknown config key: {$key}");
			}
		}
	}

	public function initConnection(): void
	{
		if ($this->_connected) {
			return;
		}

		try {
			$this->_connection = new Redis();
			$this->_connection->pconnect($this->_host, $this->_port, $this->_timeout);
			$this->_connected = true;
		} catch (Exception $e) {
			throw new RuntimeException(
				"Redis cache connection error ({$this->_host}:{$this->_port})",
				0,
				$e
			);
		}
	}

	public function assertAvailable(): void
	{
		try {
			$this->executeWithReconnect(static fn (Redis $redis) => $redis->ping(), true);
		} catch (RedisException $e) {
			throw new RuntimeException(
				"Redis cache connection error ({$this->_host}:{$this->_port})",
				0,
				$e
			);
		}
	}

	private function executeWithReconnect(callable $operation, bool $throwOnFailure = false): mixed
	{
		$this->initConnection();
		$connection = $this->_connection;

		if (!$connection instanceof Redis) {
			return null;
		}

		try {
			return $operation($connection);
		} catch (RedisException) {
			$this->_connected = false;
			$this->_connection = null;

			try {
				$this->initConnection();
				$connection = $this->_connection;

				if (!$connection instanceof Redis) {
					return null;
				}

				return $operation($connection);
			} catch (RedisException $retryException) {
				if ($throwOnFailure) {
					throw $retryException;
				}

				return null;
			}
		}
	}

	public function set(string $key, mixed $value): mixed
	{
		$this->executeWithReconnect(fn (Redis $redis) => $redis->set($key, $value));

		return $value;
	}

	public function setEx(string $key, int $ttl, mixed $value): mixed
	{
		$result = $this->executeWithReconnect(fn (Redis $redis) => $redis->setEx($key, $ttl, $value));

		if ($result === null) {
			return null;
		}

		return $value;
	}

	public function get(string $key): mixed
	{
		// A single GET avoids two round-trips; Redis::get() returns false on miss, null on error.
		$result = $this->executeWithReconnect(fn (Redis $redis) => $redis->get($key));

		return $result === false ? null : $result;
	}

	public function exists(string $key): bool
	{
		return (bool)$this->executeWithReconnect(fn (Redis $redis) => $redis->exists($key));
	}
}
