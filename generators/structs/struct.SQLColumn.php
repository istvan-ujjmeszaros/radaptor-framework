<?php

class structSQLColumn extends Struct
{
	public string $type_sql = '';

	public string $type_php = '';

	/** @var string The comments for each field */
	public string $comment = '';

	/** @var string|null The default value for the field */
	public ?string $default;

	/** @var string The extra information (like 'auto_increment') for the field */
	public string $extra = '';

	/** @var bool Whether the field is optional or not */
	public bool $is_optional = false;

	/** @var bool Whether the field is processable or not */
	public bool $is_processable = false;

	/** @var bool Whether the field is a primary key or not */
	public bool $is_primary_key = false;

	/** @var bool Whether the field is auto-incremented or not */
	public bool $is_auto_increment = false;

	/** @var bool Whether the field allows NULL values */
	public bool $is_nullable = false;

	public function __construct(
		public string $column_name
	) {
	}
}
