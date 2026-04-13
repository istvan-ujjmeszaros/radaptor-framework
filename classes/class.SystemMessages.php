<?php

class SystemMessages
{
	public const string SESSION_KEY_SYSTEMMESSAGES = 'systemMessages';

	public static function _notice(string $message, string $header = ''): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		self::addSystemMessage($message, $header, IconNames::COMMENT, false, 'notice');
	}

	public static function _error(string $message, string $header = ''): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		self::addSystemMessage($message, $header, IconNames::REMOVE, true, 'error');
	}

	public static function _debug(string $message, string $header = 'DEBUG'): void
	{
		if (Config::DEV_APP_DEBUG_INFO->value() && Roles::hasRole(RoleList::ROLE_SYSTEM_DEVELOPER)) {
			self::addSystemMessage($message, "<i>$header</i>", IconNames::BUG, true, 'debug');
		}
	}

	public static function _warning(string $message, string $header = ''): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		self::addSystemMessage($message, $header, IconNames::WARNING, true, 'warning');
	}

	public static function _ok(string $message, string $header = '', bool $sticky = false): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		self::addSystemMessage($message, $header, IconNames::ACCEPT, $sticky, 'ok');
	}

	public static function _config(string $message, string $header = ''): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		self::addSystemMessage($message, $header, IconNames::GEAR, true, 'config');
	}

	public static function addSystemMessage(string $message, string $header = '', IconNames $icon = IconNames::INFO, bool $sticky = false, string $type = 'notice'): void
	{
		$header = $header !== '' ? $header : t('system_message.default_header');
		$message = new SystemMessage($header, $message, $icon, $sticky, $type);

		/** @var array<string, string> $existingMessages */
		$existingMessages = Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []);
		$existingMessages[$message->hash] = serialize($message);

		Request::saveSessionData([SystemMessages::SESSION_KEY_SYSTEMMESSAGES], $existingMessages);
	}

	/**
	 * Get system messages from the session.
	 *
	 * @return SystemMessage[] Array of system messages.
	 */
	public static function getSystemMessages(): array
	{
		$messages = Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []);

		if (!empty($messages)) {
			Request::saveSessionData([self::SESSION_KEY_SYSTEMMESSAGES], []);
		}

		return array_map(function ($serialized_message) {
			/** @var SystemMessage $message */
			$message = unserialize($serialized_message);

			if ($message->counter > 1) {
				$message->header = '(' . $message->counter . ') ' . $message->header;
			}

			return $message;
		}, $messages);
	}

	public static function setSystemMessagesDependencies(WebpageView $view, $forced = false): void
	{
		if ($forced || !empty(Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []))) {
			$view->registerLibrary('SYSTEMMESSAGES');
		}
	}

	public static function renderSystemMessages(): void
	{
		ApiResponse::renderSuccess(self::getSystemMessages());
	}

	public static function countSystemMessages(): int
	{
		return count(Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []));
	}

	/**
	 * Flush success messages from session.
	 *
	 * Use this in AJAX handlers that provide element-level feedback (e.g., saved indicator)
	 * instead of toast notifications. This prevents success messages from accumulating
	 * in the session and showing as toasts on page reload.
	 *
	 * Error messages are preserved so they still appear as toasts.
	 */
	public static function flushSuccessMessages(): void
	{
		/** @var array<string, string> $messages */
		$messages = Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []);

		if (empty($messages)) {
			return;
		}

		$filtered = [];

		foreach ($messages as $hash => $serialized) {
			/** @var SystemMessage $message */
			$message = unserialize($serialized);

			// Keep everything except 'ok' (success) messages
			if ($message->type !== 'ok') {
				$filtered[$hash] = $serialized;
			}
		}

		Request::saveSessionData([self::SESSION_KEY_SYSTEMMESSAGES], $filtered);
	}

	/**
	 * Flush all system messages from session.
	 */
	public static function flushAllMessages(): void
	{
		$messages = Request::_SESSION(self::SESSION_KEY_SYSTEMMESSAGES, []);

		if (empty($messages)) {
			return;
		}

		Request::saveSessionData([self::SESSION_KEY_SYSTEMMESSAGES], []);
	}
}
