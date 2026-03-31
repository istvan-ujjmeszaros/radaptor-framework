<?php

class SystemMessage
{
	public string $header;
	public string $body;
	public string $icon;
	public bool $sticky;
	public string $type;
	public int $counter = 1;
	public readonly string $hash;

	public function __construct(string $header, string $body, IconNames $icon, bool $sticky, string $type = 'notice')
	{
		$this->header = $header;
		$this->body = $body;
		$this->icon = Icons::path($icon, 'large');
		$this->sticky = $sticky;
		$this->type = $type;

		$this->hash = md5($body . $header . $icon->value . $sticky);

		$existing_messages = Request::_SESSION(SystemMessages::SESSION_KEY_SYSTEMMESSAGES, []);

		if (isset($existing_messages[$this->hash])) {
			/** @var SystemMessage $existing_message */
			$existing_message = unserialize($existing_messages[$this->hash]);
			$this->counter = $existing_message->counter + 1;
		}
	}
}
