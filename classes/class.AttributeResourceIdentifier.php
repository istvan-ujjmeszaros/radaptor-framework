<?php

class AttributeResourceIdentifier implements Stringable
{
	public function __construct(
		public string $name,
		public string $id = '0'
	) {
	}

	public function __toString(): string
	{
		return "name: {$this->name}, id: {$this->id}";
	}

	public function isValid(): bool
	{
		return $this->name != '' && $this->id != '';
	}
}
