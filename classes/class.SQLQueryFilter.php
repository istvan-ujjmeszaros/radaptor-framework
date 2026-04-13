<?php

/**
 * Class SQLQueryFilter.
 *
 * Filters SQL queries based on provided filters and allowed filter keys.
 */
class SQLQueryFilter
{
	/** @var array<string> $where */
	private array $where = [];

	/** @var array<mixed> $values */
	private array $values = [];

	/** @var string $HAVING */
	private string $HAVING = '';
	private bool $initialized = false;

	/**
	 * SQLQueryFilter constructor.
	 *
	 * @param array<string, mixed> $filters        The filters to apply
	 * @param array<string, mixed> $allowed_filters The allowed filter keys
	 * @param array<string>        $skip_filters    The filter keys to skip
	 */
	public function __construct(private array $filters, private array $allowed_filters, private array $skip_filters = [])
	{
	}

	/**
	 * Initialize the filter.
	 *
	 * @return void
	 */
	private function init(): void
	{
		if ($this->initialized) {
			return;
		}

		$this->initialized = true;

		foreach ($this->filters as $key => $value) {
			if (!array_key_exists($key, $this->allowed_filters)) {
				throw new InvalidArgumentException("Unsupported filter parameter: {$key}");
			}

			if (in_array($key, $this->skip_filters)) {
				continue;
			}

			$this->where[] = " {$key}=?";
			$this->values[] = $value;
		}

		$HAVING = implode(" AND ", $this->where);

		if (count($this->where) > 0) {
			$this->HAVING = "HAVING {$HAVING}";
		}
	}

	/**
	 * Get the WHERE clauses.
	 *
	 * @return array<string>
	 */
	public function getWhere(): array
	{
		$this->init();

		return $this->where;
	}

	/**
	 * Get the values for the WHERE clauses.
	 *
	 * @return array<mixed>
	 */
	public function getValues(): array
	{
		$this->init();

		return $this->values;
	}

	/**
	 * Get the HAVING clause.
	 *
	 * @return string
	 */
	public function getHaving(): string
	{
		$this->init();

		return $this->HAVING;
	}
}
