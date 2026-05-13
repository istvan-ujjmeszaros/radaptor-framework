<?php

/**
 * Abstract class that handles event management and execution.
 */
abstract class EventResolver implements iEvent
{
	public const string EXECUTION_CONTEXT_EVENT_PREFIX_CLI = 'CLI';
	public const string EXECUTION_CONTEXT_EVENT_PREFIX_BROWSER = 'BROWSER';

	public const string DEFAULT_CONTEXT = 'resource';
	public const string DEFAULT_EVENT = 'view';

	/**
	 * Retrieves the current event instance.
	 *
	 * @return ?iEvent The current event instance, or null if no event instance is set.
	 */
	public static function getInstance(): ?iEvent
	{
		return RequestContextHolder::current()->currentEvent;
	}

	/**
	 * Retrieves the current event instance or creates one if none exists.
	 *
	 * @return iEvent The current event instance.
	 */
	public static function getEventHandlerFromUrl(): iEvent
	{
		$ctx = RequestContextHolder::current();
		$event_name = EventResolver::getEventnameFromUrl();

		if (!is_null($ctx->currentEvent)) {
			return $ctx->currentEvent;
		}

		return $ctx->currentEvent = EventResolver::factory($event_name, self::EXECUTION_CONTEXT_EVENT_PREFIX_BROWSER);
	}

	public static function getEventHandlerFromCommandline(): iEvent
	{
		$ctx = RequestContextHolder::current();
		$event_name = EventResolver::getEventnameFromCommandline();

		if (!is_null($ctx->currentEvent)) {
			return $ctx->currentEvent;
		}

		return $ctx->currentEvent = EventResolver::factory($event_name, self::EXECUTION_CONTEXT_EVENT_PREFIX_CLI);
	}

	/**
	 * Pure parser: returns the PascalCase event class name from $argv[1] when it
	 * matches the legacy "context:event" format, or null when it does not.
	 *
	 * Safe to call from any context (autoloader, class_exists checks, boot) because
	 * it never aborts the process and has no side effects. Callers that need the
	 * strict dispatch semantics (abort on malformed argv, plus --flag parsing into
	 * $_GET) should use getEventnameFromCommandline() instead.
	 */
	public static function tryGetEventnameFromCommandline(): ?string
	{
		global $argv;

		if (!isset($argv[1])) {
			return null;
		}

		$contextEvent = explode(':', $argv[1]);

		if (count($contextEvent) !== 2) {
			return null;
		}

		return ucwords($contextEvent[0]) . ucwords($contextEvent[1]);
	}

	public static function getEventnameFromCommandline(): string
	{
		global $argv;

		$eventName = self::tryGetEventnameFromCommandline();

		if ($eventName === null) {
			if (!isset($argv[1])) {
				Kernel::abort("Context and event must be provided as the first argument.");
			}
			Kernel::abort("Invalid format. Use 'contextname:eventname'.");
		}

		// Process additional parameters
		for ($i = 2; $i < count($argv); $i += 2) {
			if (str_starts_with($argv[$i], '--')) {
				$paramName = substr($argv[$i], 2);
				$paramValue = $argv[$i + 1] ?? null;

				$_GET[$paramName] = $paramValue;
			}
		}

		return $eventName;
	}

	/**
	 * Constructs the event name from the URL parameters.
	 *
	 * @return class-string<iEvent> The constructed event name based on current context and event URL parameters.
	 */
	public static function getEventnameFromUrl(): string
	{
		$context = Request::_GET('context', self::DEFAULT_CONTEXT);

		// context is always set to default value when event is not set
		if (Request::_GET('event') == '') {
			$context = self::DEFAULT_CONTEXT;
		}

		$event = Request::_GET('event', self::DEFAULT_EVENT);

		return BrowserEventSlugHelper::slugToShortName((string) $context, (string) $event);
	}

	/**
	 * Factory method to create an event instance based on the event name.
	 *
	 * @param string $eventName The name of the event to create.
	 * @return iEvent An instance of the requested event.
	 */
	public static function factory(string $eventName, string $run_mode = ''): iEvent
	{
		$exploded = explode('_', $eventName);

		foreach ($exploded as &$word) {
			$word = ucwords($word);
		}

		$eventName = implode('', $exploded);

		$eventClassName = 'Event' . $eventName;

		if (!empty($run_mode)) {
			$runModeSpecificEventClassName = $run_mode . $eventClassName;

			if (AutoloaderFromGeneratedMap::autoloaderClassExists($runModeSpecificEventClassName)) {
				$return = new $runModeSpecificEventClassName();

				if ($return instanceof iEvent) {
					return $return;
				} else {
					Kernel::abort("Run mode specific event doesn't implement iEvent: <i>" . $runModeSpecificEventClassName . '</i>');
				}
			}
		}

		if (!AutoloaderFromGeneratedMap::autoloaderClassExists($eventClassName)) {
			Kernel::abort('Unknown event: <i>' . $eventClassName . '</i>');
		}

		$return = new $eventClassName();

		if ($return instanceof iEvent) {
			return $return;
		} else {
			Kernel::abort("Event doesn't implement iEvent: <i>" . $eventClassName . '</i>');
		}
	}

	/**
	 * Full web dispatch cycle: resolve event → authorize → run.
	 *
	 * Replaces the inline `getEventHandlerFromUrl()->run()` call in index.php.
	 * Authorization is enforced here; CLI dispatch (radaptor.php) is unaffected.
	 */
	public static function dispatch(): void
	{
		$event = self::getEventHandlerFromUrl();

		if (!($event instanceof iAuthorizable)) {
			// Fail-closed: if the event doesn't implement iAuthorizable, deny.
			$logger = new Monolog\Logger('authorization');
			$logger->pushHandler(new Monolog\Handler\StreamHandler(DEPLOY_ROOT . '.logs/authorization.log', Monolog\Level::Warning));
			$logger->warning('Event does not implement iAuthorizable — denying', [
				'event_class' => get_class($event),
			]);
			self::_denyResponse(PolicyDecision::deny('event does not implement iAuthorizable'));

			return;
		}

		try {
			$policyContext = PolicyContext::fromEvent($event);
			$decision      = $event->authorize($policyContext);
		} catch (\Throwable $e) {
			// Fail-closed: any exception in authorize() results in a deny.
			$logger = new Monolog\Logger('authorization');
			$logger->pushHandler(new Monolog\Handler\StreamHandler(DEPLOY_ROOT . '.logs/authorization.log', Monolog\Level::Warning));
			$logger->warning('authorize() threw an exception — denying', [
				'event_class' => get_class($event),
				'exception'   => $e->getMessage(),
			]);
			self::_denyResponse(PolicyDecision::deny('authorize() exception'));

			return;
		}

		if (!$decision->allow) {
			$logger = new Monolog\Logger('authorization');
			$logger->pushHandler(new Monolog\Handler\StreamHandler(DEPLOY_ROOT . '.logs/authorization.log', Monolog\Level::Warning));
			$logger->warning('Access denied', [
				'reason'   => $decision->reason,
				'user_id'  => $policyContext->principal->id,
				'action'   => $policyContext->action,
			]);
			self::_denyResponse($decision);

			return;
		}

		if (class_exists(RuntimeSiteCutoverGuard::class) && RuntimeSiteCutoverGuard::shouldBlockWebEvent($event)) {
			self::_cutoverReadonlyResponse();

			return;
		}

		$event->run();
	}

	/**
	 * Send an appropriate deny response based on the request type.
	 *
	 * Returns a JSON error envelope for AJAX/API requests (Accept: application/json),
	 * or a 403 HTTP response for standard page requests.
	 * The user never sees $decision->reason — it is for the audit log only.
	 */
	public static function _denyResponse(PolicyDecision $decision): void
	{
		if (Request::wantsNonHtmlResponse()) {
			http_response_code(403);
			ApiResponse::renderError('ACCESS_DENIED', t('response_error.access_denied'), 403);
		} else {
			http_response_code(403);
			echo '<h1>403 ' . e(t('response_error.forbidden.title')) . '</h1><p>' . e(t('response_error.forbidden.message')) . '</p>';
		}
	}

	private static function _cutoverReadonlyResponse(): void
	{
		$message = RuntimeSiteCutoverGuard::readonlyMessage();

		if (Request::wantsNonHtmlResponse()) {
			http_response_code(423);
			ApiResponse::renderError('SITE_CUTOVER_READONLY', $message, 423);

			return;
		}

		http_response_code(423);
		echo '<h1>423 Site is read-only</h1><p>' . e($message) . '</p>';
	}
}
