<?php

declare(strict_types=1);

class EmailJobProcessingException extends RuntimeException
{
	private string $_errorCode;
	private bool $_retryable;

	public function __construct(string $error_code, string $message, bool $retryable = false, ?Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
		$this->_errorCode = $error_code;
		$this->_retryable = $retryable;
	}

	public function getErrorCodeString(): string
	{
		return $this->_errorCode;
	}

	public function isRetryable(): bool
	{
		return $this->_retryable;
	}
}
