<?php

interface iSessionStorage
{
	public function start(): void;
	public function get(array|string $key): mixed;
	public function set(array|string $key, mixed $value): void;
	public function unset(array|string $key): void;
	public function isset(array|string $key): bool;
	public function commit(): void;
	public function destroy(): void;
}
