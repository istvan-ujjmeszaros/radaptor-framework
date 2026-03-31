<?php

/**
 * Resolves and dispatches CLI commands.
 *
 * Replaces EventResolver::getEventHandlerFromCommandline() for the CLI path.
 * CLI commands follow the CLICommand* naming convention and extend AbstractCLICommand.
 * Authorization is opt-in: commands implementing iAuthorizable are checked before run().
 */
class CLICommandResolver
{
	/**
	 * Determine whether the current argv requests CLI help output.
	 */
	public static function isHelpRequest(): bool
	{
		$argv = self::getArgv();

		if (!isset($argv[1]) || trim((string) $argv[1]) === '') {
			return true;
		}

		if ($argv[1] === 'help') {
			return true;
		}

		for ($i = 2; $i < count($argv); ++$i) {
			if ($argv[$i] === '--help') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the help target command slug from argv when help mode is active.
	 */
	public static function getHelpTargetFromArgv(): ?string
	{
		$argv = self::getArgv();

		if (($argv[1] ?? null) === 'help') {
			$target = trim((string) ($argv[2] ?? ''));

			return $target !== '' ? $target : null;
		}

		for ($i = 2; $i < count($argv); ++$i) {
			if ($argv[$i] === '--help') {
				$target = trim((string) ($argv[1] ?? ''));

				return $target !== '' ? $target : null;
			}
		}

		return null;
	}

	/**
	 * Render CLI help text for either the full command catalog or one command.
	 */
	public static function renderHelp(?string $command_slug = null): string
	{
		if ($command_slug === null || trim($command_slug) === '') {
			return self::renderGeneralHelp();
		}

		$meta = self::resolveCommandMetaForSlug($command_slug);

		if ($meta === null) {
			return "Unknown command: {$command_slug}\n\n" . self::renderGeneralHelp();
		}

		$title = $meta['slug'] . ' - ' . $meta['name'];
		$docs = self::normalizeDocs((string) $meta['docs']);

		return implode("\n", [
			$title,
			str_repeat('=', strlen($title)),
			'',
			$docs,
			'',
		]);
	}

	/**
	 * Parse the command name from argv.
	 *
	 * Input formats:
	 *   - "context:command" (e.g. "migrate:run" → "CLICommandMigrateRun")
	 *   - "command" for top-level commands (e.g. "install" → "CLICommandInstall")
	 *
	 * Hyphens in either segment are treated as word separators for PascalCase conversion:
	 *   "i18n:tm-reindex" → "CLICommandI18nTmReindex"
	 *
	 * Named args injected into $_GET via:
	 *   --flag value       (e.g. --locale hu_HU)
	 *   key=value          (e.g. locale=hu_HU)  ← handled by Request::initValues()
	 */
	public static function getCommandNameFromArgv(): string
	{
		$argv = self::getArgv();

		if (!isset($argv[1])) {
			Kernel::abort("Command must be provided as the first argument.");
		}

		$parts = explode(':', $argv[1]);

		if (count($parts) > 2) {
			Kernel::abort("Invalid format. Use 'contextname:commandname' or a supported top-level command.");
		}

		// Inject --flag value pairs into $_GET (booleans when no value follows)
		for ($i = 2; $i < count($argv); $i++) {
			if (str_starts_with($argv[$i], '--')) {
				$paramName = substr($argv[$i], 2);
				$next = $argv[$i + 1] ?? null;

				if ($next !== null && !str_starts_with($next, '--') && !str_contains($next, '=')) {
					$_GET[$paramName] = $next;
					$i++;
				} else {
					// Boolean flag
					$_GET[$paramName] = '1';
				}
			}
		}

		if (count($parts) === 1) {
			return self::_toPascalCase($parts[0]);
		}

		return self::_toPascalCase($parts[0]) . self::_toPascalCase($parts[1]);
	}

	/**
	 * Convert a hyphen/underscore-separated slug to PascalCase.
	 * Preserves numeric sequences: "i18n" → "I18n", "tm-reindex" → "TmReindex".
	 */
	private static function _toPascalCase(string $slug): string
	{
		$words = preg_split('/[-_]/', $slug);
		$result = '';

		foreach ($words as $word) {
			if ($word === '') {
				continue;
			}

			$result .= strtoupper($word[0]) . substr($word, 1);
		}

		return $result;
	}

	/**
	 * Convert an invocation slug like "webpage:list" into a short class suffix.
	 */
	public static function shortNameFromSlug(string $slug): ?string
	{
		$slug = trim($slug);

		if ($slug === '') {
			return null;
		}

		$parts = explode(':', $slug);

		if (count($parts) === 1) {
			return self::_toPascalCase($parts[0]);
		}

		if (count($parts) !== 2) {
			return null;
		}

		return self::_toPascalCase($parts[0]) . self::_toPascalCase($parts[1]);
	}

	/**
	 * Instantiate a CLICommand class by its short name.
	 *
	 * @param string $shortName e.g. "MigrateRun"
	 */
	public static function factory(string $shortName): AbstractCLICommand
	{
		$className = 'CLICommand' . $shortName;

		if (!AutoloaderFromGeneratedMap::autoloaderClassExists($className)) {
			AutoloaderFailsafe::init();
		}

		if (!class_exists($className)) {
			Kernel::abort('Unknown command: <i>' . $className . '</i>');
		}

		$command = new $className();

		if (!($command instanceof AbstractCLICommand)) {
			Kernel::abort('Class does not extend AbstractCLICommand: <i>' . $className . '</i>');
		}

		return $command;
	}

	/**
	 * Full CLI dispatch cycle: resolve → (optional authorize) → run.
	 */
	public static function dispatch(): void
	{
		if (self::isHelpRequest()) {
			$target = self::getHelpTargetFromArgv();
			$output = self::renderHelp($target);
			echo $output;

			if ($target !== null && self::resolveCommandMetaForSlug($target) === null) {
				exit(1);
			}

			return;
		}

		$shortName = self::getCommandNameFromArgv();
		$command   = self::factory($shortName);

		if ($command instanceof iAuthorizable) {
			$policyContext = PolicyContext::fromCli($command);
			$decision      = $command->authorize($policyContext);

			if (!$decision->allow) {
				echo "Access denied: " . $decision->reason . "\n";

				exit(1);
			}
		}

		$command->run();
	}

	/**
	 * @return array<int, string>
	 */
	private static function getArgv(): array
	{
		return $GLOBALS['argv'] ?? [];
	}

	/**
	 * @return array{slug: string, name: string, docs: string, params: list<array<string, mixed>>, risk_level: string, timeout: int, category: string}|null
	 */
	private static function resolveCommandMetaForSlug(string $command_slug): ?array
	{
		$short_name = self::shortNameFromSlug($command_slug);

		if ($short_name === null) {
			return null;
		}

		return CLICommandRegistry::getAnyCommandMetaByShortName($short_name);
	}

	private static function renderGeneralHelp(): string
	{
		$commands = CLICommandRegistry::getAllGroupedCommands();
		$lines = [
			'Radaptor CLI',
			'',
			'Usage:',
			'  radaptor <command|context:command> [args] [--options]',
			'  radaptor help [command|context:command]',
			'  radaptor <command|context:command> --help',
			'',
			'Available commands:',
		];

		foreach ($commands as $category => $items) {
			$lines[] = "  {$category}:";

			foreach ($items as $item) {
				$lines[] = '    ' . str_pad($item['slug'], 30) . $item['name'];
			}

			$lines[] = '';
		}

		$lines[] = 'Use `radaptor help <command|context:command>` for detailed command help.';

		return implode("\n", $lines) . "\n";
	}

	private static function normalizeDocs(string $docs): string
	{
		$docs = trim(str_replace("\r", '', $docs));

		if ($docs === '') {
			return '';
		}

		$lines = explode("\n", $docs);
		$indents = [];

		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}

			preg_match('/^[ \t]*/', $line, $matches);
			$indents[] = strlen($matches[0] ?? '');
		}

		$dedent = $indents === [] ? 0 : min($indents);

		if ($dedent > 0) {
			$lines = array_map(
				static fn (string $line): string => preg_replace('/^[ \t]{0,' . $dedent . '}/', '', $line, 1) ?? $line,
				$lines
			);
		}

		return trim(implode("\n", $lines));
	}
}
