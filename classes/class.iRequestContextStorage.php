<?php

interface iRequestContextStorage
{
	public function get(): RequestContext;
	public function initialize(): void;
}
