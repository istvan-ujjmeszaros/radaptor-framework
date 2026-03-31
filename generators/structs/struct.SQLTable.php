<?php

class structSQLTable extends Struct
{
	/** @var list<structSQLColumn> The columns of the table - calling them fields to be consistent with inputs representing them */
	public array $fields = [];

	/** @var list<string> The names of the columns of the table */
	public array $field_names = [];

	/** @var list<string> The primary key columns of the table */
	public array $pkeys = [];

	/** @var array<string, string> The processable fields of the table */
	public array $processable_fields = [];

	/** @var bool Whether the table has an auto-incrementing primary key or not */
	public bool $is_auto_increment = false;

	/** @var string The table comment (e.g., '__noaudit' to skip audit triggers) */
	public string $comment = '';

	public function __construct(
		public string $table_name
	) {
	}
}
