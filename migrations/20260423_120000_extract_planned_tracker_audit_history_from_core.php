<?php

declare(strict_types=1);

class Migration_20260423_120000_extract_planned_tracker_audit_history_from_core
{
	/** @var list<string> */
	private const TRACKER_TABLES = [
		'comment_audit_connections',
		'companies_contactpersons_connections',
		'contactpersons',
		'companies',
		'project_states',
		'projects',
		'ticket_priorities',
		'ticket_states',
		'ticket_types',
		'tickets',
		'timetracker',
	];

	/** @var list<string> */
	private const TRACKER_RESOURCE_NAMES = [
		'companies',
		'company',
		'contact-person',
		'contact-persons',
		'project-states',
		'projects(1)',
		'projects',
		'ticket',
		'ticket-types',
		'ticket-priorities',
		'ticket-states',
		'tickets',
		'timetracker',
	];

	/** @var list<string> */
	private const TRACKER_TAG_CONTEXTS = [
		'tracker_project',
		'tracker_ticket',
	];

	/** @var list<string> */
	private const TRACKER_ROLE_SLUGS = [
		'timetracker_administrator',
		'timetracker_viewer',
	];

	/** @var list<string> */
	private const TRACKER_DOMAINS = [
		'company',
		'contact',
		'project',
		'ticket',
		'timetracker',
	];

	/** @var list<string> */
	private const TRACKER_FORM_KEYS = [
		'company.description',
		'company.name',
		'contact_person.description',
		'contact_person.name',
		'project.description',
		'project.name',
		'project_state.description',
		'project_state.name',
		'ticket.description',
		'ticket.name',
		'ticket_type.description',
		'ticket_type.name',
		'time_tracker_entry.description',
		'time_tracker_entry.name',
	];

	/** @var list<string> */
	private const TRACKER_WIDGET_KEYS = [
		'company_description.description',
		'company_description.name',
		'company_list.description',
		'company_list.name',
		'contact_person_description.description',
		'contact_person_description.name',
		'contact_person_list.description',
		'contact_person_list.name',
		'project_list.description',
		'project_list.name',
		'project_state_list.description',
		'project_state_list.name',
		'ticket_description.description',
		'ticket_description.name',
		'ticket_list.description',
		'ticket_list.name',
		'ticket_priority_list.description',
		'ticket_priority_list.name',
		'ticket_state_list.description',
		'ticket_state_list.name',
		'ticket_type_list.description',
		'ticket_type_list.name',
		'time_tracker_list.description',
		'time_tracker_list.name',
	];

	/** @var list<string> */
	private const TRACKER_THEME_KEYS = [
		'tracker.description',
		'tracker.name',
	];

	public function run(): void
	{
		$pdo = Db::instance();

		$this->deleteTrackerSharedData($pdo);
		$this->deleteTrackerResources($pdo);
		$this->deleteTrackerRoles($pdo);
		$this->dropTrackerTables($pdo);
	}

	private function deleteTrackerSharedData(PDO $pdo): void
	{
		$this->deleteFromTableWhenColumnExists($pdo, 'comments', 'subject_type', self::TRACKER_TAG_CONTEXTS);
		$this->deleteFromTableWhenColumnExists($pdo, 'tag_connections', 'context', self::TRACKER_TAG_CONTEXTS);
		$this->deleteFromTableWhenColumnExists($pdo, 'tags', 'context', self::TRACKER_TAG_CONTEXTS);

		if ($this->tableExists($pdo, 'i18n_tm_entries')) {
			$this->deleteI18nTmEntries($pdo, 'domain', self::TRACKER_DOMAINS);
			$this->deleteI18nTmEntries($pdo, 'source_key', self::TRACKER_FORM_KEYS, 'form');
			$this->deleteI18nTmEntries($pdo, 'source_key', self::TRACKER_WIDGET_KEYS, 'widget');
			$this->deleteI18nTmEntries($pdo, 'source_key', self::TRACKER_THEME_KEYS, 'theme');
		}

		if ($this->tableExists($pdo, 'i18n_messages')) {
			$this->deleteI18nMessages($pdo, self::TRACKER_DOMAINS);
			$this->deleteI18nMessages($pdo, ['form'], self::TRACKER_FORM_KEYS);
			$this->deleteI18nMessages($pdo, ['widget'], self::TRACKER_WIDGET_KEYS);
			$this->deleteI18nMessages($pdo, ['theme'], self::TRACKER_THEME_KEYS);
		}
	}

	private function deleteTrackerResources(PDO $pdo): void
	{
		if (!$this->tableExists($pdo, 'resource_tree')) {
			return;
		}

		$placeholders = implode(',', array_fill(0, count(self::TRACKER_RESOURCE_NAMES), '?'));
		$stmt = $pdo->prepare(
			"SELECT node_id, lft, rgt
			FROM resource_tree
			WHERE path = '/'
				AND resource_name IN ({$placeholders})
			ORDER BY lft DESC"
		);
		$stmt->execute(self::TRACKER_RESOURCE_NAMES);
		$roots = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($roots as $root) {
			$node_ids = $this->getResourceSubtreeNodeIds($pdo, (int) $root['lft'], (int) $root['rgt']);

			if ($node_ids === []) {
				continue;
			}

			$this->deleteResourceAttributes($pdo, $node_ids);
			$this->deleteResourceSubtree($pdo, (int) $root['node_id']);
		}
	}

	private function deleteTrackerRoles(PDO $pdo): void
	{
		if (!$this->tableExists($pdo, 'roles_tree')) {
			return;
		}

		$placeholders = implode(',', array_fill(0, count(self::TRACKER_ROLE_SLUGS), '?'));
		$stmt = $pdo->prepare(
			"SELECT node_id
			FROM roles_tree
			WHERE role IN ({$placeholders})
			ORDER BY lft DESC"
		);
		$stmt->execute(self::TRACKER_ROLE_SLUGS);

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			Roles::deleteRole((int) $row['node_id']);
		}
	}

	private function dropTrackerTables(PDO $pdo): void
	{
		$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

		try {
			foreach (self::TRACKER_TABLES as $table) {
				$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
			}
		} finally {
			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
		}
	}

	/**
	 * @return list<int>
	 */
	private function getResourceSubtreeNodeIds(PDO $pdo, int $lft, int $rgt): array
	{
		$stmt = $pdo->prepare(
			"SELECT node_id
			FROM resource_tree
			WHERE lft >= ? AND rgt <= ?"
		);
		$stmt->execute([$lft, $rgt]);

		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * @param list<int> $node_ids
	 */
	private function deleteResourceAttributes(PDO $pdo, array $node_ids): void
	{
		if ($node_ids === [] || !$this->tableExists($pdo, 'attributes')) {
			return;
		}

		$placeholders = implode(',', array_fill(0, count($node_ids), '?'));
		$params = [
			ResourceNames::RESOURCE_DATA,
			...array_map('strval', $node_ids),
		];

		$stmt = $pdo->prepare(
			"DELETE FROM attributes
			WHERE resource_name = ?
				AND resource_id IN ({$placeholders})"
		);
		$stmt->execute($params);
	}

	private function deleteResourceSubtree(PDO $pdo, int $node_id): void
	{
		$result = ResourceTreeHandler::deleteResourceEntriesRecursive($node_id);

		if (($result['success'] ?? false) !== true || (int) ($result['erroneous'] ?? 0) > 0) {
			throw new RuntimeException("Unable to delete planned tracker resource subtree {$node_id}.");
		}
	}

	/**
	 * @param list<string> $values
	 */
	private function deleteFromTableWhenColumnExists(PDO $pdo, string $table, string $column, array $values): void
	{
		if ($values === [] || !$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, $column)) {
			return;
		}

		$placeholders = implode(',', array_fill(0, count($values), '?'));
		$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
		$stmt->execute($values);
	}

	/**
	 * @param list<string> $domains
	 * @param list<string>|null $keys
	 */
	private function deleteI18nMessages(PDO $pdo, array $domains, ?array $keys = null): void
	{
		if ($domains === []) {
			return;
		}

		$domain_placeholders = implode(',', array_fill(0, count($domains), '?'));
		$sql = "DELETE FROM i18n_messages WHERE domain IN ({$domain_placeholders})";
		$params = $domains;

		if ($keys !== null && $keys !== []) {
			$key_placeholders = implode(',', array_fill(0, count($keys), '?'));
			$sql .= " AND `key` IN ({$key_placeholders})";
			$params = [...$params, ...$keys];
		}

		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	}

	/**
	 * @param list<string> $values
	 */
	private function deleteI18nTmEntries(PDO $pdo, string $column, array $values, ?string $domain = null): void
	{
		if ($values === [] || !$this->columnExists($pdo, 'i18n_tm_entries', $column)) {
			return;
		}

		$placeholders = implode(',', array_fill(0, count($values), '?'));
		$sql = "DELETE FROM i18n_tm_entries WHERE {$column} IN ({$placeholders})";
		$params = $values;

		if ($domain !== null && $this->columnExists($pdo, 'i18n_tm_entries', 'domain')) {
			$sql .= ' AND domain = ?';
			$params[] = $domain;
		}

		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	}

	private function tableExists(PDO $pdo, string $table): bool
	{
		$stmt = $pdo->prepare(
			'SELECT COUNT(*)
			FROM information_schema.tables
			WHERE table_schema = DATABASE()
				AND table_name = ?'
		);
		$stmt->execute([$table]);

		return (int) $stmt->fetchColumn() > 0;
	}

	private function columnExists(PDO $pdo, string $table, string $column): bool
	{
		$stmt = $pdo->prepare(
			'SELECT COUNT(*)
			FROM information_schema.columns
			WHERE table_schema = DATABASE()
				AND table_name = ?
				AND column_name = ?'
		);
		$stmt->execute([$table, $column]);

		return (int) $stmt->fetchColumn() > 0;
	}
}
