<?php

class Kernel
{
	/**
	 * Letiltjuk a Kernel objektum klónozását.
	 */
	public function __clone()
	{
		self::abort("Cloning of the global 'Kernel' object is prohibited...");
	}

	/**
	 * Detektáljuk az objektum felülírási kísérletét.
	 */
	public function __destruct()
	{
		$backtrace = debug_backtrace();

		// A szkript normál befejezése esetén (ha a szkript végére érünk, vagy
		// exit történik valahol), a backtrace nem tartalmaz a hívó fájlra
		// vonatkozó bejegyzést. Tehát ha ilyen érték szerepel, akkor
		// biztosan felülírási kísérlet történt.
		if (isset($backtrace[0]['file'])) {
			// Megkísérelték az objektum felülírását!
			// Log::security("Kernel object: abnormal termination...", $backtrace);	// a Log osztály serializálja a második paramétert, ha is_object vagy is_array
			self::abort("Overwriting of the global 'Kernel' object is prohibited...");
		}

		// Ha nem volt beállítva a hívó fájl, akkor normál befejezés történt.
		// TODO: Review this method
	}

	#[JetBrains\PhpStorm\ExpectedValues(values: ['production', 'development', 'test'])]
	public static function getEnvironment(): string
	{
		$environment = getenv('ENVIRONMENT') === false ? 'production' : getenv('ENVIRONMENT');

		if ($environment === 'development') {
			if (isset($_GET['environment'])) {
				return $_GET['environment'];
			}

			if (Request::_GET('environment', false)) {
				return Request::_GET('environment');
			}
		}

		if (getenv('PHPUNIT') == '1') {
			$environment = 'test';
		}

		return $environment;
	}

	public static function ob_end_flush_all(): void
	{
		while (ob_get_level()) {
			self::ob_end_flush();
		}
	}

	public static function ob_end_clean_all(): void
	{
		while (ob_get_level()) {
			ob_end_clean();
		}
	}

	public static function ob_start(): void
	{
		ob_start();
	}

	public static function ob_end_clean(): void
	{
		if (ob_get_length() > 0 && ob_get_level()) {
			ob_end_clean();
		}
	}

	public static function ob_end_flush(): void
	{
		if (ob_get_level()) {
			ob_end_flush();
		}
	}

	/**
	 * Terminates the script execution with the specified error message.
	 *
	 * @noinspection PhpUnreachableStatementInspection
	 * @param string $message The message to be displayed or logged during abort.
	 * @return never This function does not return; it terminates the script.
	 */
	public static function abort(string $message = ''): never
	{
		EventResolver::getInstance()?->abort($message);

		if (!defined('RADAPTOR_CLI')) {
			WebpageView::header("HTTP/1.0 403 Forbidden");

			trigger_error($message);
		} else {
			// We only want to show a plain message in CLI mode when calling Kernel::abort(), don't want to trigger any error
			echo $message . "\n\0";
		}

		// Errors are converted to Exception in 'test' environment, so we are explicitly ending the script here
		exit;
	}

	/**
	 * Aborts execution due to an unexpected error, whether caught or uncaught.
	 *
	 * This method is your go-to for dealing with unexpected exceptions, whether they
	 * bubble up without being caught or are explicitly passed in when you hit a snag.
	 * It sets the HTTP response code to 500 to signal a server error.
	 * In development mode, it spills the beans by showing detailed error info
	 * right on the page, so you know exactly what's going wrong and where.
	 * In production, it plays it cool by logging the error details to a file
	 * and showing a generic "oops" message to the user, keeping sensitive info safe.
	 * It even redacts sensitive session data like passwords before logging.
	 * After doing its thing, it stops the script dead in its tracks.
	 *
	 * @param Throwable $e The exception that was thrown or caught.
	 * @return never This method exits the script, so it never returns.
	 */
	public static function abort_unexpectedly(Throwable $e): never
	{
		$error_map = self::mapExceptionToApiError($e);
		$trace_id = self::generateTraceId();
		$expose_details = self::shouldExposeErrorDetails();

		if (!defined('RADAPTOR_CLI')) {
			http_response_code($error_map['status']);
		}

		// In production, log the error and show a generic message
		$logger = new Monolog\Logger('app');
		$logger->pushHandler(new Monolog\Handler\StreamHandler(DEPLOY_ROOT . '.logs/app_errors.log', Monolog\Level::Error));

		$redacted_session_data = self::safeGetSessionDataForLogging();

		if (isset($redacted_session_data['password'])) {
			$redacted_session_data['password'] = '<redacted>';
		}

		$logger->error("Uncaught exception: " . $e->getMessage(), [
			'trace_id' => $trace_id,
			'exception' => $e,
			'SESSION' => $redacted_session_data,
		]);

		if (defined('RADAPTOR_CLI')) {
			$details = self::buildExceptionDetails($e);

			fwrite(STDERR, "Trace ID: {$trace_id}\n");
			fwrite(STDERR, "Exception: {$details['message']}\n");
			fwrite(STDERR, "File: {$details['file']}\n");
			fwrite(STDERR, "Line: {$details['line']}\n");
			fwrite(STDERR, "Stack trace:\n{$details['trace']}\n");

			exit(1);
		}

		// Determine if the request is an API call
		$server = !empty(RequestContextHolder::current()->SERVER) ? RequestContextHolder::current()->SERVER : $_SERVER;
		$accept = $server['HTTP_ACCEPT'] ?? $server['http_accept'] ?? '';
		$isApiRequest = is_string($accept) && strpos($accept, 'application/json') !== false;

		if ($isApiRequest) {
			// Respond with JSON for API requests
			header('Content-Type: application/json');
			echo json_encode(
				self::buildApiErrorPayload(
					error_code: $error_map['code'],
					user_message: $error_map['message'],
					trace_id: $trace_id,
					e: $e,
					expose_details: $expose_details
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		} else {
			// Respond with HTML for standard web requests
			if ($expose_details) {
				$details = self::buildExceptionDetails($e);

				echo "<pre>";
				echo "Trace ID: " . $trace_id . "\n";
				echo "Exception: " . $details['message'] . "\n";
				echo "File: " . $details['file'] . "\n";
				echo "Line: " . $details['line'] . "\n";
				echo "Stack trace:\n" . $details['trace'];
				echo "</pre>";
			} else {
				echo '<h1>' . e(t('response_error.internal.title')) . '</h1>';
				echo '<p>' . e(t('response_error.internal.intro')) . '</p>';
				echo '<p>' . e(t('response_error.internal.reference_label')) . ': ' . e($trace_id) . '</p>';
				echo '<p>' . e(t('response_error.internal.try_actions')) . '</p>';
				echo "<ul>";
				echo '  <li>' . e(t('response_error.internal.go_back_previous_page')) . "</li>";
				echo '  <li>' . e(t('response_error.internal.refresh_page')) . "</li>";
				echo '  <li>' . e(t('response_error.internal.try_again_in_a_few_minutes')) . "</li>";
				echo "</ul>";
				echo '<p>' . e(t('response_error.internal.contact_support')) . '</p>';
			}
		}

		exit;
	}

	/**
	 * @return array{status: int, code: string, message: string}
	 */
	private static function mapExceptionToApiError(Throwable $e): array
	{
		if ($e instanceof EntitySaveException) {
			return [
				'status' => 422,
				'code' => 'entity_save_failed',
				'message' => t('common.error_save'),
			];
		}

		return [
			'status' => 500,
			'code' => 'internal_error',
			'message' => t('response_error.internal.title'),
		];
	}

	private static function shouldExposeErrorDetails(): bool
	{
		$raw_environment = getenv('ENVIRONMENT') === false ? 'production' : getenv('ENVIRONMENT');

		if ($raw_environment === 'development') {
			return true;
		}

		try {
			return Roles::hasRole(RoleList::ROLE_SYSTEM_DEVELOPER);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * @return array{error: array{code: string, message: string, trace_id: string, details?: array<string, mixed>}}
	 */
	private static function buildApiErrorPayload(string $error_code, string $user_message, string $trace_id, Throwable $e, bool $expose_details): array
	{
		$payload = [
			'error' => [
				'code' => $error_code,
				'message' => $user_message,
				'trace_id' => $trace_id,
			],
		];

		if ($expose_details) {
			$payload['error']['details'] = self::buildExceptionDetails($e);
		}

		return $payload;
	}

	private static function generateTraceId(): string
	{
		return bin2hex(random_bytes(8));
	}

	/**
	 * Read session data for logging without throwing when session storage is not initialized.
	 *
	 * @return array<string, mixed>
	 */
	private static function safeGetSessionDataForLogging(): array
	{
		if (!SessionContextHolder::hasStorage()) {
			return [];
		}

		try {
			$session = Request::getSESSION();

			return $session;
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function buildExceptionDetails(Throwable $e): array
	{
		$details = [
			'type' => $e::class,
			'message' => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString(),
		];

		if ($e instanceof EntitySaveException) {
			$details['entityClass'] = $e->entityClass;
			$details['data'] = $e->data;
		}

		return $details;
	}

	public static function setLocale(string $locale): void
	{
		RequestContextHolder::current()->locale = $locale;

		putenv("LC_ALL={$locale}");
		setlocale(LC_ALL, "{$locale}");
		bindtextdomain("messages", "./locale");
		textdomain("messages");
	}

	/**
	 * Set locale for the current request only.
	 * Updates RequestContext — does NOT touch process-global locale state.
	 * Used by the i18n runtime and locale resolution.
	 */
	public static function setRequestLocale(string $locale): void
	{
		RequestContextHolder::current()->locale = $locale;
	}

	public static function getLocale(): string
	{
		return RequestContextHolder::current()->locale;
	}

	/**
	 * Ellenőrzi a felhasználói állapotot.
	 */
	public static function initialize(string $locale = 'en_US'): void
	{
		$ctx = RequestContextHolder::current();

		/**
		 * Csak egyszer inicializáljuk a user adatokat.
		 */
		if ($ctx->kernelInitialized) {
			return;
		}

		$ctx->kernelInitialized = true;

		if (!defined('RADAPTOR_CLI')) {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Headers: X-Requested-With');
		}

		Request::initValues();

		self::setRequestLocale($locale);

		/**
		 * Elvégezzük a környezeti változók beállítását.
		 */
		self::_setReferer();

		User::initUserSession();

		self::_resolveLocale();
	}

	/**
	 * Resolve the request locale from user preference or Accept-Language header.
	 * Must be called after User::initUserSession().
	 */
	private static function _resolveLocale(): void
	{
		// 1. Authenticated user preference
		$user = User::getCurrentUser();

		if ($user !== null) {
			$locale = $user['locale'] ?? '';

			if ($locale !== '') {
				self::setRequestLocale($locale);

				return;
			}
		}

		// 2. Accept-Language header
		$accept = RequestContextHolder::current()->SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

		if ($accept !== '') {
			$lang = substr($accept, 0, 2);
			$map = ['hu' => 'hu_HU', 'en' => 'en_US'];

			if (isset($map[$lang])) {
				self::setRequestLocale($map[$lang]);

				return;
			}
		}

		// 3. Keep default (en_US set at start of initialize())
	}

	/**
	 * Beállítja a megfelelő referer-t.
	 */
	private static function _setReferer(): void
	{
		$ctx = RequestContextHolder::current();
		$server = !empty($ctx->SERVER) ? $ctx->SERVER : $_SERVER;

		if (isset($server['HTTP_REFERER'])) {
			$ctx->referer = $server['HTTP_REFERER'];
		}

		if (Request::_GET('referer')) {
			$ctx->referer = Request::_GET('referer');
		}
	}

	public static function redirectToReferer(): never
	{
		Url::redirect(self::getReferer());
	}

	public static function getReferer(): string
	{
		return RequestContextHolder::current()->referer ?? '';
	}
}
