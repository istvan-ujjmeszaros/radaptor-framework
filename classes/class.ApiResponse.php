<?php

declare(strict_types=1);

final class ApiResponse
{
	private function __construct(
		private readonly bool $ok,
		private readonly mixed $data = null,
		private readonly ?array $meta = null,
		private readonly ?string $message = null,
		private readonly ?ApiError $error = null,
		private readonly int $httpCode = 200,
	) {
	}

	public static function success(mixed $data = null, ?array $meta = null, ?string $message = null, int $httpCode = 200): self
	{
		return new self(true, $data, $meta, $message, null, $httpCode);
	}

	public static function error(ApiError $error, int $httpCode = 400, ?array $meta = null, ?string $message = null): self
	{
		return new self(false, null, $meta, $message, $error, $httpCode);
	}

	public static function renderResponse(self $response): void
	{
		(new TemplateJson($response))->render();
	}

	public static function renderSuccess(mixed $data = null, ?array $meta = null, ?string $message = null, int $httpCode = 200): void
	{
		self::renderResponse(self::success($data, $meta, $message, $httpCode));
	}

	public static function renderError(string $code_id, string $message, int $httpCode = 400): void
	{
		self::renderResponse(self::error(new ApiError($code_id, $message), $httpCode));
	}

	public static function renderErrorObj(ApiError $error, int $httpCode = 400, ?array $meta = null, ?string $message = null): void
	{
		self::renderResponse(self::error($error, $httpCode, $meta, $message));
	}

	public function getHttpCode(): int
	{
		return $this->httpCode;
	}

	public function toArray(): array
	{
		$result = ['ok' => $this->ok];

		if ($this->ok) {
			$result['data'] = $this->data;
		}

		if ($this->meta !== null) {
			$result['meta'] = $this->meta;
		}

		if ($this->message !== null) {
			$result['message'] = $this->message;
		}

		if ($this->error !== null) {
			$result['error'] = $this->error->toArray();
		}

		return $result;
	}
}
