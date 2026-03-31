<?php

class NativeSessionStorage implements iSessionStorage
{
	public function start(): void
	{
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
	}

	public function get(array|string $key): mixed
	{
		$this->start();
		$session = $_SESSION ?? [];

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
		$this->start();

		if (is_string($key)) {
			$_SESSION[$key] = $value;
		} else {
			$session = &$_SESSION;

			$last = array_pop($key);

			foreach ($key as $k) {
				if (!isset($session[$k])) {
					$session[$k] = [];
				}
				$session = &$session[$k];
			}

			$session[$last] = $value;
		}

		session_commit();
	}

	public function unset(array|string $key): void
	{
		$this->start();

		if (is_string($key)) {
			unset($_SESSION[$key]);
		} else {
			if (empty($key)) {
				return;
			}

			$last = array_pop($key);
			$parent = &$_SESSION;

			foreach ($key as $k) {
				if (!isset($parent[$k])) {
					return;
				}
				$parent = &$parent[$k];
			}

			unset($parent[$last]);
		}

		session_commit();
	}

	public function commit(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_commit();
		}
	}

	public function destroy(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
	}

	public function isset(array|string $key): bool
	{
		$this->start();
		$session = $_SESSION ?? [];

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
}
