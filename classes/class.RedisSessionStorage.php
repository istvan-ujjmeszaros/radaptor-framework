<?php

class RedisSessionStorage implements iSessionStorage
{
	private const string DEFAULT_HOST = '127.0.0.1';
	private const int DEFAULT_PORT = 6379;
	private const int DEFAULT_TIMEOUT = 2;
	private const int DEFAULT_TTL = 3600;
	private const string DEFAULT_PREFIX = 'radaptor:session:';

	private ?Redis $_connection = null;
	private string $_host = self::DEFAULT_HOST;
	private int $_port = self::DEFAULT_PORT;
	private float $_timeout = self::DEFAULT_TIMEOUT;

	public function assertAvailable(): void
	{
		try {
			$this->executeWithReconnect(static fn (Redis $redis) => $redis->ping(), true);
		} catch (RedisException $e) {
			throw new RuntimeException("Redis session backend unavailable at {$this->_host}:{$this->_port}", 0, $e);
		}
	}

	private function getConnection(): Redis
	{
		if ($this->_connection instanceof Redis) {
			return $this->_connection;
		}

		$this->_host = getenv('SESSION_REDIS_HOST') ?: self::DEFAULT_HOST;
		$this->_port = (int) (getenv('SESSION_REDIS_PORT') ?: self::DEFAULT_PORT);
		$this->_timeout = (float) (getenv('SESSION_REDIS_TIMEOUT') ?: self::DEFAULT_TIMEOUT);

		try {
			$redis = new Redis();

			if ($this->shouldUsePersistentConnection()) {
				$redis->pconnect($this->_host, $this->_port, $this->_timeout);
			} else {
				$redis->connect($this->_host, $this->_port, $this->_timeout);
			}
			$this->_connection = $redis;
		} catch (RedisException $e) {
			throw new RuntimeException("Redis session backend unavailable at {$this->_host}:{$this->_port}", 0, $e);
		}

		return $this->_connection;
	}

	private function shouldUsePersistentConnection(): bool
	{
		if (getenv('RADAPTOR_RUNTIME') !== 'swoole') {
			return false;
		}

		$env = getenv('SWOOLE_PERSISTENT_REDIS_CONNECTION');

		if ($env === false) {
			return true;
		}

		return filter_var($env, FILTER_VALIDATE_BOOLEAN);
	}

	private function executeWithReconnect(callable $operation, bool $throwOnFailure = false): mixed
	{
		try {
			return $operation($this->getConnection());
		} catch (RedisException $firstException) {
			$this->_connection = null;

			try {
				return $operation($this->getConnection());
			} catch (RedisException $secondException) {
				if ($throwOnFailure) {
					throw $secondException;
				}

				return null;
			}
		}
	}

	private function getKeyPrefix(): string
	{
		return getenv('SESSION_REDIS_PREFIX') ?: self::DEFAULT_PREFIX;
	}

	private function getSessionKey(string $sessionId): string
	{
		return $this->getKeyPrefix() . $sessionId;
	}

	private function getSessionTtl(): int
	{
		return (int) (getenv('SESSION_REDIS_TTL') ?: self::DEFAULT_TTL);
	}

	private function isSessionIdValid(string $sessionId): bool
	{
		return preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) === 1;
	}

	private function ensureSessionStarted(): RequestContext
	{
		$this->start();

		return RequestContextHolder::current();
	}

	public function start(): void
	{
		$ctx = RequestContextHolder::current();

		if ($ctx->sessionStarted) {
			return;
		}

		$this->getConnection();

		$sessionName = session_name();
		$cookieSource = !empty($ctx->COOKIE) ? $ctx->COOKIE : $_COOKIE;
		$incomingSessionId = $cookieSource[$sessionName] ?? null;

		if (is_string($incomingSessionId) && $incomingSessionId !== '' && $this->isSessionIdValid($incomingSessionId)) {
			$ctx->sessionId = $incomingSessionId;
		} else {
			$ctx->sessionId = bin2hex(random_bytes(16));
		}

		$rawSession = $this->executeWithReconnect(
			fn (Redis $redis) => $redis->get($this->getSessionKey($ctx->sessionId))
		);
		$decoded = is_string($rawSession) ? json_decode($rawSession, true) : [];

		$ctx->sessionData = is_array($decoded) ? $decoded : [];
		$ctx->sessionStarted = true;

		if (!isset($cookieSource[$sessionName]) || $cookieSource[$sessionName] !== $ctx->sessionId) {
			setcookie($sessionName, $ctx->sessionId, [
				'path' => '/',
				'httponly' => true,
				'samesite' => 'Lax',
			]);
		}
	}

	public function get(array|string $key): mixed
	{
		$ctx = $this->ensureSessionStarted();
		$session = $ctx->sessionData;

		if (is_string($key)) {
			return $session[$key] ?? null;
		}

		foreach ($key as $k) {
			if (!isset($session[$k])) {
				return null;
			}
			$session = $session[$k];
		}

		return $session;
	}

	public function set(array|string $key, mixed $value): void
	{
		$ctx = $this->ensureSessionStarted();

		if (is_string($key)) {
			$ctx->sessionData[$key] = $value;
		} else {
			$session = &$ctx->sessionData;
			$last = array_pop($key);

			if ($last === null) {
				return;
			}

			foreach ($key as $k) {
				if (!isset($session[$k]) || !is_array($session[$k])) {
					$session[$k] = [];
				}

				$session = &$session[$k];
			}

			$session[$last] = $value;
		}

		$this->commit();
	}

	public function unset(array|string $key): void
	{
		$ctx = $this->ensureSessionStarted();

		if (is_string($key)) {
			unset($ctx->sessionData[$key]);
		} else {
			if (empty($key)) {
				return;
			}

			$last = array_pop($key);
			$session = &$ctx->sessionData;

			foreach ($key as $k) {
				if (!isset($session[$k]) || !is_array($session[$k])) {
					return;
				}

				$session = &$session[$k];
			}

			if ($last !== null) {
				unset($session[$last]);
			}
		}

		$this->commit();
	}

	public function isset(array|string $key): bool
	{
		$ctx = $this->ensureSessionStarted();
		$session = $ctx->sessionData;

		if (is_string($key)) {
			return isset($session[$key]);
		}

		foreach ($key as $k) {
			if (!isset($session[$k])) {
				return false;
			}
			$session = $session[$k];
		}

		return true;
	}

	public function commit(): void
	{
		$ctx = $this->ensureSessionStarted();

		if ($ctx->sessionId === null) {
			return;
		}

		$payload = json_encode($ctx->sessionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($payload === false) {
			throw new RuntimeException('Failed to encode session data for Redis storage');
		}

		$result = $this->executeWithReconnect(
			fn (Redis $redis) => $redis->setEx(
				$this->getSessionKey($ctx->sessionId),
				$this->getSessionTtl(),
				$payload
			),
			true
		);

		if ($result === null) {
			throw new RuntimeException("Redis session backend unavailable at {$this->_host}:{$this->_port}");
		}
	}

	public function destroy(): void
	{
		$ctx = $this->ensureSessionStarted();

		if ($ctx->sessionId !== null) {
			$this->executeWithReconnect(
				fn (Redis $redis) => $redis->del($this->getSessionKey($ctx->sessionId))
			);
		}

		$ctx->sessionData = [];
		$ctx->sessionStarted = false;
		$ctx->sessionId = null;
	}
}
