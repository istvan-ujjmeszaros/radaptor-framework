<?php

class UserBase
{
	/**
	 * Application-level pepper added to all passwords before hashing.
	 * Provides additional security layer beyond the per-password salt in bcrypt.
	 *
	 * @noinspection SpellCheckingInspection
	 */
	public const string PEPPER = "9LaR/e?OŰ\tsi2+Ra*r!\nUT&A~9crőuŁ|#!<ay;4ß";

	/**
	 * Hash a password using bcrypt with application pepper.
	 */
	public static function encodePassword(#[SensitiveParameter] string $password): string
	{
		return password_hash($password . self::PEPPER, PASSWORD_DEFAULT);
	}

	/**
	 * Verify a password against a stored hash.
	 */
	public static function verifyPassword(#[SensitiveParameter] string $password, string $hash): bool
	{
		return password_verify($password . self::PEPPER, $hash);
	}

	public static function addUser(array $savedata): ?int
	{
		$savedata = self::applyTimezoneOnCreate($savedata);

		return DbHelper::insertHelper('users', $savedata);
	}

	public static function updateUser(array $savedata, int $id): int
	{
		$savedata = self::applyTimezoneOnUpdate($savedata, $id);

		return DbHelper::updateHelper('users', $savedata, $id);
	}

	public static function getUserList(): array
	{
		return DbHelper::selectMany('users');
	}

	public static function getUserFromId(int $user_id): array
	{
		return DbHelper::selectOne('users', ['user_id' => $user_id]);
	}

	public static function getUsername(int $user_id): string
	{
		$userdata = self::getUserFromId($user_id);

		return $userdata['username'] ?? 'Anonymous';
	}

	public static function getNameById(int $id): string
	{
		return (string) DbHelper::selectOneColumn('users', ['user_id' => $id], '', 'username');
	}

	public static function getUserListForSelect(string $term = ''): array
	{
		$query = "SELECT u.username, u.user_id FROM users u WHERE u.username LIKE :term";
		$stmt = Db::instance()->prepare($query);
		$stmt->execute(['term' => "%{$term}%"]);

		$rs = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$return = [];

		foreach ($rs as $value) {
			$return[] = [
				'inputtype' => 'option',
				'value' => $value['user_id'],
				'label' => $value['username'],
			];
		}

		return $return;
	}

	public static function autoDetectUserId(string $value): int
	{
		$value = trim($value);

		if (trim($value, '_') == '') {
			return UserErrorCode::ERROR_USER_ID_EMPTY->value;
		}

		if (trim($value, '_') !== $value) {
			// find by name
			$users = DbHelper::selectMany('users', ['username' => trim($value, '_')], false, '', 'user_id');
		} else {
			// find by id (to check existence)
			$users = DbHelper::selectMany('users', ['user_id' => trim($value)], false, '', 'user_id');
		}

		if (count($users) == 0) {
			return UserErrorCode::ERROR_USER_NOT_FOUND->value;
		}

		if (count($users) > 1) {
			return UserErrorCode::ERROR_MULTIPLE_USERS->value;
		}

		return $users[0]['user_id'];
	}

	private static function applyTimezoneOnCreate(array $savedata): array
	{
		if (!self::usersTimezoneColumnExists()) {
			return $savedata;
		}

		if (isset($savedata['timezone']) && self::isValidTimezone((string) $savedata['timezone'])) {
			return $savedata;
		}

		$submittedTimezone = self::getSubmittedClientTimezone();

		if ($submittedTimezone !== null) {
			$savedata['timezone'] = $submittedTimezone;
		}

		return $savedata;
	}

	private static function applyTimezoneOnUpdate(array $savedata, int $id): array
	{
		if (!self::usersTimezoneColumnExists()) {
			return $savedata;
		}

		// Explicit profile timezone override should be respected as-is when valid.
		if (array_key_exists('timezone', $savedata)) {
			if ($savedata['timezone'] === null || trim((string) $savedata['timezone']) === '') {
				$savedata['timezone'] = null;

				return $savedata;
			}

			if (!self::isValidTimezone((string) $savedata['timezone'])) {
				unset($savedata['timezone']);
			}

			return $savedata;
		}

		$submittedTimezone = self::getSubmittedClientTimezone();

		if ($submittedTimezone === null) {
			return $savedata;
		}

		$user = self::getUserFromId($id);

		if (!empty($user['timezone'])) {
			return $savedata;
		}

		$savedata['timezone'] = $submittedTimezone;

		return $savedata;
	}

	private static function getSubmittedClientTimezone(): ?string
	{
		$submitted = trim((string) Request::_POST('client_timezone', ''));

		if (!self::isValidTimezone($submitted)) {
			return null;
		}

		return $submitted;
	}

	private static function isValidTimezone(string $timezone): bool
	{
		if ($timezone === '') {
			return false;
		}

		try {
			new DateTimeZone($timezone);
		} catch (Exception) {
			return false;
		}

		return true;
	}

	private static function usersTimezoneColumnExists(): bool
	{
		static $exists = null;

		if (is_bool($exists)) {
			return $exists;
		}

		$exists = in_array('timezone', Db::getFieldNames('users'), true);

		return $exists;
	}
}
