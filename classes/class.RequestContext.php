<?php

class RequestContext
{
	public array $GET = [];
	public array $POST = [];
	public array $SERVER = [];
	public array $COOKIE = [];
	public bool $sessionStarted = false;
	public ?string $sessionId = null;
	public array $sessionData = [];
	public bool $requestInitialized = false;
	public bool $kernelInitialized = false;
	public ?array $currentUser = null;
	public bool $userSessionInitialized = false;
	public ?iEvent $currentEvent = null;
	public string $locale = 'en_US';
	public ?string $referer = null;
	public array $inMemoryCache = [];
	public array $userConfigCache = [];
	public bool $persistentCacheWriteEnabled = true;
}
