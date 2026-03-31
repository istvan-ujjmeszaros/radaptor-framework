<?php

abstract class AbstractPlugin
{
	abstract public function getId(): string;
	abstract public function getBasePath(): string;

	/** @return string[] Tag context identifiers (e.g. ['tracker_project', 'tracker_ticket']) */
	public function getTagContexts(): array
	{
		return [];
	}

	/** @return string[] Comment subject_type values (e.g. ['tracker_ticket']) */
	public function getCommentContexts(): array
	{
		return [];
	}

	/**
	 * @param array<string, structSQLTable> $db_data
	 */
	public function afterBuildDb(string $dsn, array $db_data): void
	{
	}

	public function afterSync(): void
	{
	}

	public function beforeUninstall(): void
	{
	}
}
