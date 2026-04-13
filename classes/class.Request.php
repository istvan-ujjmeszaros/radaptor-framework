<?php

/**
 * This class handles the _GET and _POST requests.
 */
class Request
{
	public const string DEFAULT_ERROR = 'use Kernel::abort()';
	public const string METHOD_GET = '_GET';
	public const string METHOD_POST = '_POST';
	public const string METHOD_SESSION = '_SESSION';

	/**
	 * Get the value of a command line argument.
	 *
	 * @param string $param_name The name of the parameter.
	 *
	 * @return string|null The value of the parameter or null if not found.
	 */
	public static function getArg(string $param_name): ?string
	{
		global $argv;

		foreach ($argv as $arg) {
			if (str_starts_with(
				$arg,
				$param_name . '='
			)) {
				return substr(
					$arg,
					strlen($param_name) + 1
				);
			}
		}

		return null;
	}

	/**
	 * Get the first argument that appears after an argument containing a colon.
	 *
	 * @return string|null The first argument after a colon-containing argument, or null if not found.
	 */
	public static function getMainArg(): ?string
	{
		global $argv;
		$foundColon = false;

		foreach ($argv as $arg) {
			if ($foundColon) {
				return $arg;
			}

			if (str_contains(
				$arg,
				':'
			)) {
				$foundColon = true;
			}
		}

		return null;
	}

	/**
	 * Determine if a specific command-line argument is present.
	 *
	 * This method checks for the presence of a command-line argument specified by name.
	 * For example, to check if the `--force` argument is provided, you would call `hasArg('force')`.
	 *
	 * @param string $name The name of the argument to check for (without the '--' prefix).
	 *
	 * @return bool True if the argument is present, false otherwise.
	 */
	public static function hasArg(string $name): bool
	{
		global $argv;

		return array_any(
			$argv,
			fn ($arg) => $arg === '--' . $name
		);
	}

	/**
	 * Get the _GET data.
	 *
	 * @return array<string, mixed> The _GET data.
	 */
	public static function getGET(): array
	{
		return RequestContextHolder::current()->GET;
	}

	/**
	 * Get the _POST data.
	 *
	 * @return array<string, mixed> The _POST data.
	 */
	public static function getPOST(): array
	{
		return RequestContextHolder::current()->POST;
	}

	/**
	 * Get the _SESSION data.
	 *
	 * @return array<string, mixed> The _SESSION data.
	 */
	public static function getSESSION(): array
	{
		$session = SessionContextHolder::current()->get([]);

		return is_array($session) ? $session : [];
	}

	/**
	 * Starts a new session or resumes the existing session.
	 *
	 * @return void
	 */
	public static function startSession(): void
	{
		SessionContextHolder::current()->start();
	}

	/**
	 * Look for an existing session based on the session cookie.
	 *
	 * @return void
	 */
	public static function lookForSession(): void
	{
		$cookie = RequestContextHolder::current()->COOKIE;

		// Fall back to superglobals when context has not been pre-populated (FPM path)
		if (empty($cookie) && !empty($_COOKIE)) {
			$cookie = $_COOKIE;
		}

		if (isset($cookie[session_name()])) {
			SessionContextHolder::current()->start();
		}
	}

	/**
	 * Initializes the request values based on the current request.
	 *
	 * This method processes the request URI, extracts query parameters, determines the requested
	 * folder and resource, and starts a session if a session cookie is present.
	 */
	public static function initValues(): void
	{
		$ctx = RequestContextHolder::current();

		if ($ctx->requestInitialized) {
			return;
		}

		$ctx->requestInitialized = true;

		// Fall back to superglobals when context has not been pre-populated
		// (FPM path before Kernel is refactored to call initializeRequest()).
		if (empty($ctx->POST) && !empty($_POST)) {
			$ctx->POST = $_POST;
		}

		$server = !empty($ctx->SERVER) ? $ctx->SERVER : $_SERVER;
		$existingGet = $ctx->GET;

		$requestUri = self::getServerValue($server, 'REQUEST_URI') ?? '/index.html';
		$query_string_pos = strpos($requestUri, '?');

		if ($query_string_pos !== false) {
			$query_string = substr($requestUri, $query_string_pos + 1);
			parse_str($query_string, $built_params);
		} else {
			$built_params = [];
		}

		$page_path = $existingGet['folder']
			?? $built_params['folder']
			?? self::getServerValue($server, 'PATH_INFO')
			?? parse_url($requestUri, PHP_URL_PATH)
			?? '';

		if (mb_substr(
			(string)$page_path,
			0,
			mb_strlen((string)Config::PATH_GENERATED_WEBPAGES_DIRECTORY->value())
		) == Config::PATH_GENERATED_WEBPAGES_DIRECTORY->value()) {
			$page_path = substr_replace(
				$page_path,
				'',
				0,
				mb_strlen((string)Config::PATH_GENERATED_WEBPAGES_DIRECTORY->value())
			);
		}

		// We need to add the default resource name preemptively so pathinfo will properly return the full path
		if (str_ends_with(
			(string)$page_path,
			'/'
		)) {
			$page_path .= "index.html";
		}

		// Normalize slashes and remove traversal attempts
		$page_path = preg_replace(
			'#[/\\\\]+#',
			'/',
			$page_path
		);
		$page_path = preg_replace(
			'#\.{2,}/#',
			'',
			$page_path
		);
		$page_path = ltrim(
			$page_path,
			'./'
		);

		$pathinfo = pathinfo($page_path);

		// Ensure the folder starts and ends with a slash
		$folder = "/" . ltrim(
			trim(
				$pathinfo['dirname'] ?? '/',
				'/'
			),
			'.'
		) . "/";

		// Special case for root
		$folder = ($folder === "//") ? "/" : $folder;

		$resource = $existingGet['resource'] ?? (empty($pathinfo['basename']) ? "index.html" : $pathinfo['basename']);

		$resource = str_replace(
			"/",
			"",
			$resource
		);

		$ctx->GET = array_merge($built_params, $existingGet);
		// Always use normalized folder (security: prevents path traversal via ?folder=../)
		$ctx->GET['folder'] = $folder;
		$ctx->GET['resource'] ??= $resource;

		self::lookForSession();
	}

	/**
	 * Get the default value for a given parameter.
	 *
	 * @param mixed $default The default value to return if the parameter is not set.
	 * @param string $name The name of the parameter.
	 * @param string $method The method (GET or POST) from which the parameter is expected.
	 *
	 * @return mixed The default value or the value of the parameter if it is set.
	 */
	private static function _getDefaultValue(mixed $default, string $name, string $method): mixed
	{
		if ($default === self::DEFAULT_ERROR) {
			Kernel::abort('Requested ' . $method . ' parameter is not set: ' . $name);
		}

		return $default;
	}

	/**
	 * Get a value from the GET parameters.
	 *
	 * @param string $name The name of the GET parameter to retrieve.
	 * @param mixed $default The default value to return if the parameter is not set.
	 * @param array<string>|null $allowed_values An optional array of allowed values for the parameter.
	 *
	 * @return mixed The value of the GET parameter, or the default value if not set.
	 */
	public static function _GET(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		// Note: parse_str() in initValues() already URL-decodes GET parameters
		return self::_getValue(
			$name,
			$default,
			self::METHOD_GET,
			$allowed_values
		);
	}

	/**
	 * Check if a GET parameter exists and is not empty.
	 */
	public static function hasGet(string $name): bool
	{
		$get = RequestContextHolder::current()->GET;

		return isset($get[$name]) && $get[$name] !== '';
	}

	/**
	 * Get a value from the POST parameters.
	 *
	 * @param string $name The name of the POST parameter to retrieve.
	 * @param mixed $default The default value to return if the parameter is not set.
	 * @param array<string>|null $allowed_values An optional array of allowed values for the parameter.
	 *
	 * @return mixed The value of the POST parameter, or the default value if not set.
	 */
	public static function _POST(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		return self::_getValue(
			$name,
			$default,
			self::METHOD_POST,
			$allowed_values
		);
	}

	/**
	 * Check if a POST parameter exists and is not empty.
	 */
	public static function hasPost(string $name): bool
	{
		$post = RequestContextHolder::current()->POST;

		return isset($post[$name]) && $post[$name] !== '';
	}

	/**
	 * Require a GET parameter or throw RequestParamException.
	 *
	 * @throws RequestParamException
	 */
	public static function getRequired(string $name, ?array $allowed_values = null): mixed
	{
		return self::apiParam($name, self::METHOD_GET, true, null, $allowed_values);
	}

	/**
	 * Require a POST parameter or throw RequestParamException.
	 *
	 * @throws RequestParamException
	 */
	public static function postRequired(string $name, ?array $allowed_values = null): mixed
	{
		return self::apiParam($name, self::METHOD_POST, true, null, $allowed_values);
	}

	/**
	 * Return optional GET parameter with default value.
	 *
	 * @throws RequestParamException when present but not in allowed_values
	 */
	public static function getOptional(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		return self::apiParam($name, self::METHOD_GET, false, $default, $allowed_values);
	}

	/**
	 * Return optional POST parameter with default value.
	 *
	 * @throws RequestParamException when present but not in allowed_values
	 */
	public static function postOptional(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		return self::apiParam($name, self::METHOD_POST, false, $default, $allowed_values);
	}

	/**
	 * Get a value from the SESSION parameters.
	 *
	 * @param string $name The name of the SESSION parameter to retrieve.
	 * @param mixed $default The default value to return if the parameter is not set.
	 * @param array<string>|null $allowed_values An optional array of allowed values for the parameter.
	 *
	 * @return mixed The value of the SESSION parameter, or the default value if not set.
	 */
	public static function _SESSION(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		return self::_getValue(
			$name,
			$default,
			self::METHOD_SESSION,
			$allowed_values
		);
	}

	/**
	 * Save data to the session using a key path.
	 *
	 * @param array<int, string> $keyPath The path of keys to traverse in the session array.
	 * @param mixed $value The value to set at the specified key path.
	 *
	 * @return mixed The value that was set.
	 */
	public static function saveSessionData(array $keyPath, mixed $value): mixed
	{
		SessionContextHolder::current()->set($keyPath, $value);

		return $value;
	}

	/**
	 * Retrieve data from the session using a key path.
	 *
	 * @param array<int, string> $keyPath The path of keys to traverse in the session array.
	 *
	 * @return mixed The value at the specified key path, or null if the key path does not exist.
	 */
	public static function getSessionData(array $keyPath): mixed
	{
		return SessionContextHolder::current()->get($keyPath);
	}

	/**
	 * Check if data is set in the session using a key path.
	 *
	 * @param array<int, string> $keyPath The path of keys to traverse in the session array.
	 *
	 * @return bool True if the key path exists and is not null, false otherwise.
	 */
	public static function isSessionDataSet(array $keyPath): bool
	{
		return SessionContextHolder::current()->isset($keyPath);
	}

	/**
	 * Unset data from the session using a key path.
	 *
	 * @param array<int, string> $keyPath The path of keys to traverse in the session array.
	 *
	 * @return void
	 */
	public static function unsetSessionData(array $keyPath): void
	{
		if (empty($keyPath)) {
			return;
		}

		SessionContextHolder::current()->unset($keyPath);
	}

	/**
	 * Get a configuration value.
	 *
	 * @param string $name The name of the configuration value.
	 * @param mixed $default The default value to return if the configuration value is not found.
	 * @param array<mixed>|null $allowed_values An optional array of allowed values.
	 *
	 * @return mixed The configuration value.
	 */
	public static function _CONFIG(string $name, mixed $default = null, ?array $allowed_values = null): mixed
	{
		$return = User::getConfig($name) ?? $default;

		if (!self::_checkValueIsAllowed(
			$return,
			$allowed_values
		)) {
			Kernel::abort("Value of `{$name}` is not in the list of allowed values: `{$return}`");
		}

		return $return;
	}

	/**
	 * Check if a value is allowed based on an array of allowed values.
	 *
	 * @param mixed $value The value to check.
	 * @param array<int|string, mixed>|null $allowed_values An array of allowed values or null.
	 *
	 * @return bool Returns true if the value is allowed, false otherwise.
	 */
	private static function _checkValueIsAllowed(mixed $value, ?array $allowed_values): bool
	{
		return !is_array($allowed_values) || in_array(
			$value,
			$allowed_values
		);
	}

	/**
	 * Retrieve a value based on the specified method (GET, POST, SESSION).
	 *
	 * @param string $name The name of the value to retrieve.
	 * @param mixed $default The default value to return if the value is not found.
	 * @param string $method The method to use for retrieving the value (GET, POST, SESSION).
	 * @param array<string, mixed>|null $allowed_values An optional array of allowed values.
	 *
	 * @return mixed The retrieved value.
	 */
	private static function _getValue(string $name, mixed $default = null, string $method = self::METHOD_GET, ?array $allowed_values = null): mixed
	{
		$return = null;

		switch ($method) {
			case self::METHOD_GET:

				$get = RequestContextHolder::current()->GET;

				if (isset($get[$name]) && $get[$name] !== '') {
					$return = $get[$name];
				} else {
					$return = self::_getDefaultValue(
						$default,
						$name,
						$method
					);
				}

				break;

			case self::METHOD_POST:

				$post = RequestContextHolder::current()->POST;

				if (isset($post[$name]) && $post[$name] !== '') {
					$return = $post[$name];
				} else {
					$return = self::_getDefaultValue(
						$default,
						$name,
						$method
					);
				}

				break;

			case self::METHOD_SESSION:

				$sessionStorage = SessionContextHolder::current();

				if ($sessionStorage->isset($name) && $sessionStorage->get($name) !== '') {
					$return = $sessionStorage->get($name);
				} else {
					$return = self::_getDefaultValue(
						$default,
						$name,
						$method
					);
				}

				break;
		}

		// Converting the type of the return value to match the type of the default value
		// Skip type coercion for arrays - they should be returned as-is
		if (!is_null($default) && !is_null($return) && $default !== self::DEFAULT_ERROR && !is_array($return)) {
			$returnType = gettype($default);

			if ($returnType === "integer") {
				// Check if value can be safely converted to integer
				// Compare string representations to handle '0', '42', etc.
				if (!is_numeric($return) || (string)(int)$return !== (string)$return) {
					$return = $default; // Revert to default if not a valid integer
				} else {
					$return = (int)$return;
				}
			} else {
				settype(
					$return,
					$returnType
				); // Cast to the desired type if valid
			}
		}

		if (!self::_checkValueIsAllowed(
			$return,
			$allowed_values
		)) {
			Kernel::abort("Value of `{$name}` is not in the list of allowed values: `{$return}`");
		}

		return $return;
	}

	/**
	 * Shared API-parameter implementation.
	 *
	 * @param string $name
	 * @param string $method Request::METHOD_GET or Request::METHOD_POST
	 * @param bool $required
	 * @param mixed $default
	 * @param array<int|string, mixed>|null $allowed_values
	 * @return mixed
	 * @throws RequestParamException
	 */
	private static function apiParam(
		string $name,
		string $method,
		bool $required,
		mixed $default = null,
		?array $allowed_values = null
	): mixed {
		$methodLabel = match ($method) {
			self::METHOD_GET => 'GET',
			self::METHOD_POST => 'POST',
			default => throw new InvalidArgumentException("Unsupported request method: {$method}"),
		};

		$isPresent = match ($method) {
			self::METHOD_GET => self::hasGet($name),
			self::METHOD_POST => self::hasPost($name),
			default => throw new InvalidArgumentException("Unsupported request method: {$method}"),
		};

		if (!$isPresent) {
			if ($required) {
				throw new RequestParamException('MISSING_PARAM', "Missing {$methodLabel} param: {$name}");
			}

			return $default;
		}

		$value = match ($method) {
			self::METHOD_GET => self::_GET($name, $default, null),
			self::METHOD_POST => self::_POST($name, $default, null),
			default => throw new InvalidArgumentException("Unsupported request method: {$method}"),
		};

		if ($allowed_values !== null && !in_array($value, $allowed_values, true)) {
			throw new RequestParamException('INVALID_PARAM', "Invalid {$methodLabel} param: {$name}");
		}

		return $value;
	}

	/**
	 * Fetch a server value using both uppercase and lowercase keys.
	 *
	 * @param array<string, mixed> $server
	 */
	private static function getServerValue(array $server, string $key): mixed
	{
		if (array_key_exists($key, $server)) {
			return $server[$key];
		}

		$lower = strtolower($key);

		if (array_key_exists($lower, $server)) {
			return $server[$lower];
		}

		return null;
	}

	/**
	 * Check which required GET parameters are missing.
	 *
	 * @param array<string, string> $params Parameter names => descriptions
	 * @return array<string, string> Missing params with descriptions (empty if all present)
	 */
	public static function getMissingParams(array $params): array
	{
		$missing = [];

		foreach ($params as $param => $description) {
			if (self::_GET($param) === null) {
				$missing[$param] = $description;
			}
		}

		return $missing;
	}

	/**
	 * Disallow the mutation of request values.
	 *
	 * @param string $name The name of the request value.
	 * @param mixed $value The value to set.
	 *
	 * @return void
	 */
	public function __set(string $name, mixed $value): void
	{
		Kernel::abort('Can not set request value...');
	}

	/**
	 * Disallow the direct access of request values.
	 *
	 * @param string $name The name of the request value.
	 *
	 * @return void
	 */
	public function __get(string $name): void
	{
		Kernel::abort('Can not get value directly. Use _GET(), _POST() or _SESSION() instead...');
	}
}
