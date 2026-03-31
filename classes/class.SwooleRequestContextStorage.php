<?php

class SwooleRequestContextStorage implements iRequestContextStorage
{
	public function get(): RequestContext
	{
		$ctx = \Swoole\Coroutine::getContext();

		return $ctx['radaptor_request'] ??= new RequestContext();
	}

	public function initialize(): void
	{
		$ctx = \Swoole\Coroutine::getContext();
		$ctx['radaptor_request'] = new RequestContext();
	}
}
