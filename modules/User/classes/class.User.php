<?php

class User extends UserConfig
{
	public const string SESSION_KEY_CURRENT_USER = 'currentuser';

	/**
	 * Sets the @application_id MySQL session variable for audit logging.
	 * Called on every request regardless of login status.
	 */
	protected static function _setDBApplicationVariable(): void
	{
		$query = "SET @application_id = ?;";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([
			Config::APP_APPLICATION_IDENTIFIER->value(),
		]);
	}

	/**
	 * Sets the @application_user_id MySQL session variable and updates last_seen.
	 * Called only when a user is logged in.
	 */
	protected static function _setDBUserVariables(int $user_id): void
	{
		$query = "SET @application_user_id = ?;";

		$stmt = Db::instance()->prepare($query);
		$stmt->execute([$user_id]);

		$query = "UPDATE users SET last_seen = NOW() WHERE user_id=? LIMIT 1";

		DbHelper::runCustomQuery($query, [$user_id]);
	}

	public static function getCurrentUser(): ?array
	{
		self::initUserSession();

		return RequestContextHolder::current()->currentUser;
	}

	/**
	 * Returns the user ID of the currently logged-in user, or -1 if no user is logged in.
	 *
	 * This method first checks if the user ID is cached using the Cache::get() method. If the cached
	 * value is not null, it returns the cached user ID.
	 *
	 * If the user ID is not cached, it initializes the user session by calling the initUserSession()
	 * method. If the $_currentUser array does not have a 'user_id' key set (indicating no user is logged in),
	 * it caches the value -1 as the user ID using the Cache::set() method and returns -1.
	 *
	 * If a user is logged in, it caches the user ID from the $_currentUser array using the Cache::set()
	 * method and returns the user ID.
	 *
	 * @return int The user ID of the currently logged-in user, or -1 if no user is logged in.
	 */
	public static function getCurrentUserId(): int
	{
		$cached = Cache::get(self::class, 'currentUser_user_id');

		if (!is_null($cached)) {
			return $cached;
		}

		self::initUserSession();

		$currentUser = RequestContextHolder::current()->currentUser;

		if (!isset($currentUser['user_id'])) {
			// no logged-in user, setting the user_id to -1
			return Cache::set(self::class, 'currentUser_user_id', -1);
		}

		return Cache::set(self::class, 'currentUser_user_id', $currentUser['user_id']);
	}

	public static function initUserSession($force_reinit = false): void
	{
		$ctx = RequestContextHolder::current();

		if ($force_reinit) {
			$ctx->userSessionInitialized = false;
		}

		if ($ctx->userSessionInitialized) {
			return;
		}

		$ctx->userSessionInitialized = true;

		// Always set application identifier for audit logging
		self::_setDBApplicationVariable();

		if (defined('RADAPTOR_CLI')) {
			$trustedUser = CLIWebRunnerUserBridge::resolveTrustedCurrentUserFromEnvironment();

			if ($trustedUser !== null) {
				self::bootstrapTrustedCurrentUser($trustedUser);

				return;
			}
		}

		if (Request::_SESSION(self::SESSION_KEY_CURRENT_USER, false)) {
			$ctx->currentUser = unserialize(Request::_SESSION(self::SESSION_KEY_CURRENT_USER));

			if (is_null(self::validateSessionCredentials($ctx->currentUser['username'], $ctx->currentUser['password']))) {
				$ctx->currentUser = null;
			}
		}

		if (isset($ctx->currentUser['user_id'])) {
			self::_setDBUserVariables($ctx->currentUser['user_id']);
			self::backfillCurrentUserTimezoneFromRequest();
		}
	}

	/**
	 * Persist submitted client timezone to the current user only if the stored value is empty.
	 */
	public static function backfillCurrentUserTimezoneFromRequest(): void
	{
		$currentUser = RequestContextHolder::current()->currentUser;

		if (!isset($currentUser['user_id'])) {
			return;
		}

		if (!in_array('timezone', Db::getFieldNames('users'), true)) {
			return;
		}

		if (!empty($currentUser['timezone'])) {
			return;
		}

		$submittedTimezone = trim((string) Request::_POST('client_timezone', ''));

		if ($submittedTimezone === '') {
			return;
		}

		try {
			new DateTimeZone($submittedTimezone);
		} catch (Exception) {
			return;
		}

		DbHelper::updateHelper('users', ['timezone' => $submittedTimezone], (int) $currentUser['user_id']);

		$currentUser['timezone'] = $submittedTimezone;
		RequestContextHolder::current()->currentUser = $currentUser;
		self::setUserSession($currentUser);
	}

	public static function loginUser(string $username, #[SensitiveParameter] string $password): bool
	{
		$user_data = self::getUserByUsernameAndPassword($username, $password);

		if (is_array($user_data)) {
			Request::startSession();
			self::setUserSession($user_data);
			self::initUserSession(true);

			return true;
		} else {
			self::logout();
			self::initUserSession(true);

			return false;
		}
	}

	/**
	 * Authenticate user by username and password.
	 *
	 * @param string $username The username
	 * @param string $password The plain text password
	 * @return array|null User data if authenticated, null otherwise
	 */
	public static function getUserByUsernameAndPassword(string $username, #[SensitiveParameter] string $password): ?array
	{
		$user = DbHelper::selectOne('users', ['username' => $username]);

		if (is_null($user)) {
			return null;
		}

		if (!self::verifyPassword($password, $user['password'])) {
			return null;
		}

		return $user;
	}

	/**
	 * Validate session by comparing stored password hash with database.
	 * Used to invalidate sessions when password changes.
	 *
	 * @param string $username The username
	 * @param string $storedHash The password hash stored in session
	 * @return array|null User data if valid, null otherwise
	 */
	public static function validateSessionCredentials(string $username, string $storedHash): ?array
	{
		$user = DbHelper::selectOne('users', ['username' => $username]);

		if (is_null($user)) {
			return null;
		}

		// Direct hash comparison - session becomes invalid if password changed
		if ($user['password'] !== $storedHash) {
			return null;
		}

		return $user;
	}

	public static function setUserSession($user_data): void
	{
		Request::saveSessionData([self::SESSION_KEY_CURRENT_USER], serialize($user_data));
	}

	/**
	 * Install a validated trusted user for the lifetime of the current request.
	 *
	 * Used by the CLI runner bridge so a web-started subprocess can evaluate
	 * permissions as the current admin user without mutating the shared CLI session.
	 *
	 * @param array<string, mixed> $user_data
	 */
	public static function bootstrapTrustedCurrentUser(array $user_data): void
	{
		$ctx = RequestContextHolder::current();
		$ctx->currentUser = $user_data;
		Cache::set(self::class, 'currentUser_user_id', (int) ($user_data['user_id'] ?? -1));

		if (isset($user_data['user_id'])) {
			self::_setDBUserVariables((int) $user_data['user_id']);
		}
	}

	public static function getCurrentUserUsername(): ?string
	{
		$userData = self::getCurrentUser();

		return $userData['username'] ?? '';
	}

	/**
	 * Returns the preferred timezone for a user, falling back to UTC when not set.
	 */
	public static function getPreferredTimezone(?int $userId = null): string
	{
		$userData = $userId === null
			? self::getCurrentUser()
			: self::getUserFromId($userId);

		$timezone = trim((string) ($userData['timezone'] ?? ''));

		if ($timezone === '') {
			return 'UTC';
		}

		try {
			new DateTimeZone($timezone);
		} catch (Exception) {
			return 'UTC';
		}

		return $timezone;
	}

	public static function refreshUserSession(): void
	{
		$user_data = User::getUserFromId(User::getCurrentUserId());

		User::setUserSession($user_data);
	}

	public static function logout(): void
	{
		Request::saveSessionData([self::SESSION_KEY_CURRENT_USER], null);
		$ctx = RequestContextHolder::current();
		$ctx->currentUser = null;
		$ctx->userSessionInitialized = false;
	}

	public static function getUserByName($username): ?array
	{
		return DbHelper::selectOne('users', ['username' => $username]);
	}
}
