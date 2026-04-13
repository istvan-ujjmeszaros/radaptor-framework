<?php

interface iPersistentCache
{
	public function setConfig(array $config): void;

	public function initConnection(): void;

	/**
	 * Set a value in the collection.
	 *
	 * @template T
	 * @param string $key The key to set.
	 * @param T $value The value to set.
	 * @return T The value associated with the key.
	 */
	public function set(string $key, mixed $value): mixed;

	/**
	 * Set a value in the collection.
	 *
	 * @template T
	 * @param string $key The key to set.
	 * @param int $ttl The time-to-live (expiration time) in seconds.
	 * @param T $value The value to set.
	 * @return T The value associated with the key.
	 */
	public function setEx(string $key, int $ttl, mixed $value): mixed;

	public function get(string $key): mixed;

	public function exists(string $key): bool;
}
