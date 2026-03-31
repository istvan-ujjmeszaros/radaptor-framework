<?php

/**
 * Find all pages where a form is placed.
 *
 * Usage: radaptor form:urls <FormName> [--json]
 *
 * Examples:
 *   radaptor form:urls User
 *   radaptor form:urls ContactPerson --json
 */
class CLICommandFormUrls extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Find pages using a form';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Find all pages where a form is placed.

			Usage: radaptor form:urls <FormName> [--json]

			Examples:
			  radaptor form:urls User
			  radaptor form:urls ContactPerson --json
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Form name', 'type' => 'main_arg', 'required' => true],
		];
	}

	public function run(): void
	{
		$form_name = Request::getMainArg();

		if (is_null($form_name)) {
			Kernel::abort("Usage: radaptor form:urls <FormName> [--json]");
		}

		// Query Form widgets with matching form_id attribute
		$sql = <<<SQL
				SELECT wsc.*, a.param_value as form_id
				FROM widget_connections wsc
				JOIN attributes a ON a.resource_id = wsc.connection_id
				WHERE wsc.widget_name = 'Form'
				  AND a.resource_name = 'widget_connection'
				  AND a.param_name = 'form_id'
				  AND a.param_value = ?
			SQL;

		$stmt = DbHelper::prexecute($sql, [$form_name]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$json_mode = Request::hasArg('json');

		if (empty($rows)) {
			if ($json_mode) {
				echo json_encode([
					'form' => $form_name,
					'pages' => [],
				], JSON_PRETTY_PRINT) . "\n";
			} else {
				echo "Form \"{$form_name}\" not found on any pages.\n";
			}

			return;
		}

		$pages = [];

		foreach ($rows as $row) {
			$path = ResourceTreeHandler::getPathFromId((int) $row['page_id']);

			$pages[] = [
				'page_id' => (int) $row['page_id'],
				'path' => $path,
				'slot' => $row['slot_name'] ?? '',
				'seq' => (int) ($row['seq'] ?? 0),
				'connection_id' => (int) $row['connection_id'],
			];
		}

		if ($json_mode) {
			echo json_encode([
				'form' => $form_name,
				'pages' => $pages,
			], JSON_PRETTY_PRINT) . "\n";
		} else {
			echo "Form \"{$form_name}\" found on " . count($pages) . " page(s):\n";

			foreach ($pages as $page) {
				echo "  - {$page['path']} (page_id: {$page['page_id']}, slot: {$page['slot']})\n";
			}
		}
	}
}
