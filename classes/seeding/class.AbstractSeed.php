<?php

/**
 * Imperative data seed.
 *
 * Seeds are for data mutations only. Schema changes belong in migrations.
 * Dry-run support relies on transaction rollback, so seeds that execute DDL
 * must not assume their changes can be rolled back safely on MariaDB.
 */
abstract class AbstractSeed
{
	abstract public function getVersion(): string;

	/**
	 * @return list<class-string<AbstractSeed>>
	 */
	public function getDependencies(): array
	{
		return [];
	}

	public function getDescription(): string
	{
		return '';
	}

	/**
	 * Run the seed idempotently against the current database state.
	 */
	abstract public function run(SeedContext $context): void;
}
