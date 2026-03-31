<?php

class FpmRequestContextStorage implements iRequestContextStorage
{
	private static ?RequestContext $context = null;

	public function get(): RequestContext
	{
		return self::$context ??= new RequestContext();
	}

	public function initialize(): void
	{
		self::$context = new RequestContext();
	}
}
