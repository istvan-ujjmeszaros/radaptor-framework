<?php

class RequestParamException extends Exception
{
	public string $code_id;

	public function __construct(string $code_id, string $message)
	{
		parent::__construct($message);
		$this->code_id = $code_id;
	}
}
