<?php

declare(strict_types=1);

/**
 * Executes CLI commands in a subprocess from the web context.
 *
 * Uses proc_open to run `radaptor.php` in an isolated process, avoiding
 * issues with Kernel::abort() calling exit() and conflicting global state.
 */
class CLICommandWebRunner
{
	/**
	 * Execute a CLI command via subprocess.
	 *
	 * @param string $command_slug  e.g. 'db:schema'
	 * @param string $main_arg     Positional argument (table name, path, etc.)
	 * @param array<string, string> $options  Named options (key => value for key=value)
	 * @param list<string> $flags   Boolean flags (e.g. ['json', 'dry-run'])
	 * @param int $timeout_seconds  Max execution time
	 * @param list<string> $extra_args  Additional positional arguments after main_arg
	 * @return array{ok: bool, output: string, output_html: string, error: string, exit_code: int, duration_ms: int, json_data: mixed}
	 */
	public static function execute(
		string $command_slug,
		string $main_arg = '',
		array $options = [],
		array $flags = [],
		int $timeout_seconds = 30,
		array $extra_args = []
	): array {
		$radaptor_php = self::resolveRadaptorPhpPath();

		if (!is_file($radaptor_php)) {
			return [
				'ok' => false,
				'output' => '',
				'output_html' => '',
				'error' => "CLI entrypoint not found: {$radaptor_php}",
				'exit_code' => -1,
				'duration_ms' => 0,
				'json_data' => null,
			];
		}

		$cmd = ['php', $radaptor_php, $command_slug];

		if ($main_arg !== '') {
			$cmd[] = $main_arg;
		}

		foreach ($extra_args as $arg) {
			$cmd[] = $arg;
		}

		foreach ($flags as $flag) {
			$cmd[] = '--' . $flag;
		}

		foreach ($options as $key => $value) {
			$cmd[] = $key . '=' . $value;
		}

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];

		$start_time = hrtime(true);

		$env = self::buildSubprocessEnv(array_merge($_ENV, $_SERVER));
		// Web requests often run with an unwritable HOME. Use an app-local HOME so
		// CLIStorage-backed commands can persist ~/.radaptor state across requests.
		$env['HOME'] = self::resolveCliHome($env['HOME'] ?? null);
		$template_cache_root = self::resolveTemplateCacheRoot($env['HOME']);

		if ($template_cache_root !== null) {
			$env['RADAPTOR_TEMPLATE_CACHE_ROOT'] = $template_cache_root;
		}

		$env = [...$env, ...CLIWebRunnerUserBridge::exportCurrentUserEnvironment()];
		$working_directory = null;

		if (defined('DEPLOY_ROOT')) {
			$env['RADAPTOR_APP_ROOT'] = rtrim(DEPLOY_ROOT, '/');
			$working_directory = is_dir(DEPLOY_ROOT) ? DEPLOY_ROOT : null;
		}

		$process = proc_open(
			self::buildCommandString($cmd),
			$descriptors,
			$pipes,
			$working_directory,
			$env
		);

		if (!is_resource($process)) {
			return [
				'ok' => false,
				'output' => '',
				'output_html' => '',
				'error' => 'Failed to start subprocess',
				'exit_code' => -1,
				'duration_ms' => 0,
				'json_data' => null,
			];
		}

		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$timed_out = false;

		$deadline = time() + $timeout_seconds;

		while (true) {
			$status = proc_get_status($process);

			if (!$status['running']) {
				// Process finished, read remaining output
				$stdout .= stream_get_contents($pipes[1]);
				$stderr .= stream_get_contents($pipes[2]);

				break;
			}

			if (time() >= $deadline) {
				$timed_out = true;
				proc_terminate($process, 9);

				break;
			}

			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;

			if (stream_select($read, $write, $except, 1) > 0) {
				foreach ($read as $pipe) {
					$chunk = fread($pipe, 8192);

					if ($chunk !== false) {
						if ($pipe === $pipes[1]) {
							$stdout .= $chunk;
						} else {
							$stderr .= $chunk;
						}
					}
				}
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);

		$exit_code = $timed_out ? -1 : $status['exitcode'];

		if (!$timed_out) {
			proc_close($process);
		}

		$duration_ms = (int) ((hrtime(true) - $start_time) / 1_000_000);

		// Clean trailing newlines/null bytes that radaptor.php appends
		$stdout = rtrim($stdout, "\n\0");
		$stderr = rtrim($stderr, "\n\0");

		if ($timed_out) {
			return [
				'ok' => false,
				'output' => $stdout,
				'output_html' => self::ansiToHtml($stdout),
				'error' => "Command timed out after {$timeout_seconds}s",
				'exit_code' => -1,
				'duration_ms' => $duration_ms,
				'json_data' => null,
			];
		}

		// Try to parse JSON output
		$json_data = null;

		if ($stdout !== '') {
			$decoded = json_decode($stdout, true);

			if (json_last_error() === JSON_ERROR_NONE) {
				$json_data = $decoded;
			}
		}

		return [
			'ok' => $exit_code === 0,
			'output' => $stdout,
			'output_html' => self::ansiToHtml($stdout),
			'error' => $stderr,
			'exit_code' => $exit_code,
			'duration_ms' => $duration_ms,
			'json_data' => $json_data,
		];
	}

	/**
	 * Convert ANSI escape codes to HTML spans with CSS classes.
	 *
	 * Splits the input on ANSI sequences, HTML-escapes each plain text segment,
	 * then inserts the appropriate <span> tags in between.
	 */
	public static function ansiToHtml(string $text): string
	{
		$ansi_map = [
			'0'  => '</span>',
			'1'  => '<span class="ansi-bold">',
			'30' => '<span class="ansi-black">',
			'31' => '<span class="ansi-red">',
			'32' => '<span class="ansi-green">',
			'33' => '<span class="ansi-yellow">',
			'34' => '<span class="ansi-blue">',
			'35' => '<span class="ansi-magenta">',
			'36' => '<span class="ansi-cyan">',
			'37' => '<span class="ansi-white">',
			'40' => '<span class="ansi-bg-black">',
			'41' => '<span class="ansi-bg-red">',
			'42' => '<span class="ansi-bg-green">',
			'43' => '<span class="ansi-bg-yellow">',
			'44' => '<span class="ansi-bg-blue">',
			'45' => '<span class="ansi-bg-magenta">',
			'46' => '<span class="ansi-bg-cyan">',
			'47' => '<span class="ansi-bg-white">',
		];

		// Split on ANSI escape sequences, capturing the code groups
		$parts = preg_split('/(\x1b\[[0-9;]+m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

		if ($parts === false) {
			return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}

		$html = '';

		foreach ($parts as $part) {
			// Check if this is an ANSI escape sequence
			if (preg_match('/^\x1b\[([0-9;]+)m$/', $part, $matches)) {
				$codes = explode(';', $matches[1]);

				foreach ($codes as $code) {
					if (isset($ansi_map[$code])) {
						$html .= $ansi_map[$code];
					}
				}
			} else {
				// Plain text — escape for HTML safety
				$html .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			}
		}

		return $html;
	}

	/**
	 * Resolve the CLI entrypoint path for the current deployment.
	 */
	private static function resolveRadaptorPhpPath(): string
	{
		if (defined('DEPLOY_ROOT')) {
			return rtrim(DEPLOY_ROOT, '/') . '/radaptor.php';
		}

		return dirname(__DIR__, 5) . '/radaptor.php';
	}

	/**
	 * Build a shell-safe command line for proc_open().
	 *
	 * @param list<string> $args
	 */
	private static function buildCommandString(array $args): string
	{
		return implode(' ', array_map('escapeshellarg', $args));
	}

	/**
	 * Drop non-scalar env entries before passing them to proc_open().
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, string>
	 */
	private static function buildSubprocessEnv(array $values): array
	{
		$env = [];

		foreach ($values as $key => $value) {
			if ($key === '' || !is_scalar($value)) {
				continue;
			}

			$env[$key] = (string) $value;
		}

		return $env;
	}

	/**
	 * Pick a writable HOME for the subprocess.
	 */
	private static function resolveCliHome(?string $currentHome): string
	{
		if (is_string($currentHome) && $currentHome !== '' && is_dir($currentHome) && is_writable($currentHome)) {
			return $currentHome;
		}

		if (defined('DEPLOY_ROOT')) {
			$appHome = rtrim(DEPLOY_ROOT, '/') . '/storage/cli-home';
			$resolved = self::ensureWritableDirectory($appHome);

			if ($resolved !== null) {
				return $resolved;
			}
		}

		$fallbackHome = rtrim(sys_get_temp_dir(), '/') . '/radaptor-cli-home';
		$resolved = self::ensureWritableDirectory($fallbackHome);

		return $resolved ?? sys_get_temp_dir();
	}

	/**
	 * Pick an isolated compiled-template cache root for the subprocess.
	 */
	private static function resolveTemplateCacheRoot(string $cliHome): ?string
	{
		$preferred = rtrim($cliHome, '/') . '/template-cache';
		$resolved = self::ensureWritableDirectory($preferred);

		if ($resolved !== null) {
			return $resolved;
		}

		$fallback = rtrim(sys_get_temp_dir(), '/') . '/radaptor-template-cache';

		return self::ensureWritableDirectory($fallback);
	}

	/**
	 * Create the directory if needed and return it when writable.
	 */
	private static function ensureWritableDirectory(string $directory): ?string
	{
		if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
			return null;
		}

		@chmod($directory, 0o770);

		return is_writable($directory) ? $directory : null;
	}
}
