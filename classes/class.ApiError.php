<?php

declare(strict_types=1);

final class ApiError
{
	public function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly array $fields = [],
		public readonly array $details = [],
	) {
	}

	public function toArray(): array
	{
		$result = [
			'code' => $this->code,
			'message' => $this->message,
		];

		if (!empty($this->fields)) {
			$result['fields'] = $this->fields;
		}

		if (!empty($this->details)) {
			$result['details'] = $this->details;
		}

		return $result;
	}
}
