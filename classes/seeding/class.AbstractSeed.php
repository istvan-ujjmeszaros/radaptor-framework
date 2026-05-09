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
	public const string RUN_POLICY_VERSIONED = 'versioned';
	public const string RUN_POLICY_BOOTSTRAP_ONCE = 'bootstrap_once';

	abstract public function getVersion(): string;

	public function getRunPolicy(): string
	{
		return self::RUN_POLICY_VERSIONED;
	}

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
