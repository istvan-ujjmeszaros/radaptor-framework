<?php

/**
 * Base class for test fixtures.
 *
 * Each fixture class represents a single database table and provides
 * the data to be loaded during test setup.
 *
 * Usage:
 * - Extend this class and implement getTableName() and getData()
 * - Override getReferenceBy() to specify a column for automatic references
 * - Use @table.refname syntax in data to reference other fixture rows
 * - Use '_' key for tree structures (automatically calculates lft/rgt/parent_id)
 */
abstract class AbstractFixture
{
	/**
	 * Returns the database table name for this fixture.
	 */
	abstract public function getTableName(): string;

	/**
	 * Returns the fixture data as an array of rows.
	 * Each row is an associative array of column => value.
	 *
	 * Special keys:
	 * - '_' : Array of child rows for nested tree structures
	 *
	 * Special values:
	 * - '@table.refname' : Reference to another fixture's row PK
	 *
	 * @return list<array<string, mixed>>
	 */
	abstract public function getData(): array;

	/**
	 * Returns the fixture classes this fixture depends on.
	 * Dependencies are loaded before this fixture.
	 *
	 * @return list<class-string<AbstractFixture>>
	 */
	public function getDependencies(): array
	{
		return [];
	}

	/**
	 * Returns the column name used for automatic references.
	 * Override this method to enable references for this fixture.
	 *
	 * The column MUST have a UNIQUE constraint in the database.
	 *
	 * Example: return 'username' to enable @users.john_doe references
	 */
	public function getReferenceBy(): string
	{
		return '';
	}
}
