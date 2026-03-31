<?php

interface iConsoleWriterWrapper
{
	public function init(): void;

	public function info(mixed $object, ?string $label = null): void;

	public function warning(mixed $object, ?string $label = null): void;

	public function error(mixed $object, ?string $label = null): void;

	public function debug(mixed $object, ?string $label = null): void;

	public function trace(string $label = 'trace'): void;
}
