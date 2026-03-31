<?php

/**
 * This class in intended to emulate struct behavior from other languages.
 */
abstract class Struct
{
	public function __set(string $name, mixed $value): void
	{
		Kernel::abort('This class is immutable to emulate structs from other languages, use a normal class if you need it to be mutable.');
	}
}
