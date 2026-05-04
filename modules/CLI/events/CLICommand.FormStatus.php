<?php

class CLICommandFormStatus extends AbstractCLICommand
{
	public function getName(): string
	{
		return 'Show form integrity status';
	}

	public function getDocs(): string
	{
		return <<<'DOC'
			Show registered forms and their URL placement status.

			Usage: radaptor form:status [FormName] [--json]
			DOC;
	}

	public function isWebRunnable(): bool
	{
		return true;
	}

	public function getWebParams(): array
	{
		return [
			['name' => 'main_arg', 'label' => 'Form name', 'type' => 'main_arg', 'required' => false],
			['name' => 'json', 'label' => 'JSON output', 'type' => 'flag'],
		];
	}

	public function run(): void
	{
		$form = Request::getMainArg();
		$form = is_string($form) && !str_starts_with($form, '--') ? $form : null;

		try {
			if (!class_exists(CmsIntegrityInspector::class)) {
				throw new RuntimeException('CMS integrity inspector is not available. Install or enable core:cms to use form diagnostics.');
			}

			$result = CmsIntegrityInspector::inspectForms($form);
		} catch (Throwable $exception) {
			self::renderError($exception);

			return;
		}

		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson($result);

			return;
		}

		echo "Form status: {$result['status']}\n";

		foreach ($result['forms'] as $form_row) {
			$url = $form_row['url'] ?? '(no URL)';
			echo "  - {$form_row['form']}: {$form_row['status']} {$url}\n";
		}
	}

	private static function renderError(Throwable $exception): void
	{
		if (Request::hasArg('json')) {
			CLIOptionHelper::writeJson(['status' => 'error', 'message' => $exception->getMessage()]);

			return;
		}

		echo "Form status check failed: {$exception->getMessage()}\n";
	}
}
