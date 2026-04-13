<?php

interface iListable
{
	public static function getName(): string;

	public static function getDescription(): string;

	public static function getListVisibility(): bool;
}
