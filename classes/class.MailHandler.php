<?php

class MailHandler
{
	/**
	 * Sends an email using PHPMailer.
	 *
	 * @param string $body The body of the email message
	 * @param string $subject The subject of the email (default: "-- új üzenet a weboldalról --")
	 * @param array<string> $cc_array An array of email addresses to be added as CC recipients
	 * @return bool Returns true if the email was sent successfully, false otherwise
	 */
	public static function sendMail(string $body, string $subject = "-- új üzenet a weboldalról --", array $cc_array = []): bool
	{
		// TODO: Implement email sending with a different library

		/*
		$mail = new PHPMailer\PHPMailer\PHPMailer(); // defaults to using php "mail()"

		$mail->IsSMTP();
		$mail->Host = Config::EMAIL_HOST->value();
		$mail->SMTPAuth = true;
		$mail->Port = Config::EMAIL_PORT->value();
		$mail->Username = Config::EMAIL_USERNAME->value();
		$mail->Password = Config::EMAIL_PASSWORD->value();

		try {
			$mail->AddReplyTo(Config::EMAIL_TO_ADDRESS->value(), iconv("UTF-8", "ISO-8859-2//TRANSLIT", (string) Config::EMAIL_FROM_NAME->value()));
			$mail->SetFrom(Config::EMAIL_FROM_ADDRESS->value(), Config::EMAIL_FROM_NAME->value());
			$mail->AddAddress(Config::EMAIL_TO_ADDRESS->value());
			$mail->AddBCC('styu007@gmail.com');

			foreach ($cc_array as $cc) {
				$mail->AddAddress($cc);
			}

			$mail->Subject = iconv("UTF-8", "ISO-8859-2//TRANSLIT", $subject);

			$mail->MsgHTML(iconv("UTF-8", "ISO-8859-2//TRANSLIT", $body));

			if (!$mail->Send()) {
				SystemMessages::_error($mail->ErrorInfo);

				return false;
			} else {
				return true;
			}
		} catch (Exception) {
		}
		*/

		return false;
	}
}
