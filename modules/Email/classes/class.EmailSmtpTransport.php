<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @phpstan-type ShapeEmailRecipient array{email: string, name?: string|null}
 * @phpstan-type ShapeResolvedTransportSettings array{
 *     host: string,
 *     port: int,
 *     username: string,
 *     password: string,
 *     use_starttls: bool,
 *     ehlo_host: string,
 *     from_address: string,
 *     from_name: string,
 *     using_catcher: bool
 * }
 */
class EmailSmtpTransport
{
	/** @var null|callable */
	private static $_testSender = null;

	/**
	 * @param list<ShapeEmailRecipient> $to
	 */
	public static function send(string $subject, string $htmlBody, string $textBody, array $to): void
	{
		if ($to === []) {
			throw new EmailJobProcessingException('MISSING_RECIPIENT', 'No recipient was provided for the email job.', false);
		}

		if (trim($htmlBody) === '' && trim($textBody) === '') {
			throw new EmailJobProcessingException('EMPTY_BODY', 'Email body is empty.', false);
		}

		$settings = self::resolveEffectiveSettings();

		if (is_callable(self::$_testSender)) {
			$sender = self::$_testSender;

			try {
				$sender($subject, $htmlBody, $textBody, $to, $settings);

				return;
			} catch (EmailJobProcessingException $e) {
				throw $e;
			} catch (Throwable $e) {
				throw new EmailJobProcessingException('TEST_SENDER_FAILED', $e->getMessage(), false, $e);
			}
		}

		$transport = new EsmtpTransport(
			$settings['host'],
			$settings['port'],
			$settings['use_starttls']
		);

		if ($settings['username'] !== '') {
			$transport->setUsername($settings['username']);
			$transport->setPassword($settings['password']);
		}

		if ($settings['ehlo_host'] !== '') {
			$transport->setLocalDomain($settings['ehlo_host']);
		}

		$mailer = new Mailer($transport);
		$message = (new Email())
			->subject($subject)
			->from(self::buildAddress($settings['from_address'], $settings['from_name']));

		foreach ($to as $recipient) {
			$email = trim((string) ($recipient['email'] ?? ''));

			if ($email === '') {
				continue;
			}

			$message->addTo(self::buildAddress($email, trim((string) ($recipient['name'] ?? ''))));
		}

		if (trim($htmlBody) !== '') {
			$message->html($htmlBody);
		}

		if (trim($textBody) !== '') {
			$message->text($textBody);
		}

		try {
			$mailer->send($message);
		} catch (TransportExceptionInterface $e) {
			throw new EmailJobProcessingException('SMTP_SEND_FAILED', $e->getMessage(), true, $e);
		}
	}

	/**
	 * @return ShapeResolvedTransportSettings
	 */
	public static function resolveEffectiveSettings(?string $environment = null): array
	{
		$environment ??= Kernel::getEnvironment();
		$use_catcher = $environment !== 'production' && Config::EMAIL_FORCE_CATCHER_IN_NON_PROD->value();
		$from_address = trim((string) Config::EMAIL_FROM_ADDRESS->value());
		$from_name = trim((string) Config::EMAIL_FROM_NAME->value());

		if ($use_catcher) {
			if ($from_address === '') {
				$from_address = 'no-reply@localhost';
			}

			return [
				'host' => trim((string) Config::EMAIL_CATCHER_HOST->value()),
				'port' => max(1, (int) Config::EMAIL_CATCHER_SMTP_PORT->value()),
				'username' => '',
				'password' => '',
				'use_starttls' => false,
				'ehlo_host' => '',
				'from_address' => $from_address,
				'from_name' => $from_name,
				'using_catcher' => true,
			];
		}

		$host = trim((string) Config::EMAIL_SMTP_HOST->value());

		if ($host === '') {
			throw new EmailJobProcessingException('SMTP_CONFIG_MISSING_HOST', 'EMAIL_SMTP_HOST is not configured.', false);
		}

		if ($from_address === '') {
			throw new EmailJobProcessingException('SMTP_CONFIG_MISSING_FROM', 'EMAIL_FROM_ADDRESS is not configured.', false);
		}

		return [
			'host' => $host,
			'port' => max(1, (int) Config::EMAIL_SMTP_PORT->value()),
			'username' => trim((string) Config::EMAIL_SMTP_USERNAME->value()),
			'password' => (string) Config::EMAIL_SMTP_PASSWORD->value(),
			'use_starttls' => (bool) Config::EMAIL_SMTP_USE_STARTTLS->value(),
			'ehlo_host' => trim((string) Config::EMAIL_SMTP_EHLO_HOST->value()),
			'from_address' => $from_address,
			'from_name' => $from_name,
			'using_catcher' => false,
		];
	}

	/**
	 * @param null|callable $test_sender
	 */
	public static function setTestSender($test_sender): void
	{
		self::$_testSender = $test_sender;
	}

	private static function buildAddress(string $email, string $name = ''): Address
	{
		return $name === ''
			? new Address($email)
			: new Address($email, $name);
	}
}
