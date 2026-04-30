<?php

declare(strict_types=1);

class EventEmailSend extends AbstractEvent implements iBrowserEventDocumentable
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return $policyContext->principal->hasRole(RoleList::ROLE_EMAILS_ADMIN)
			? PolicyDecision::allow()
			: PolicyDecision::deny();
	}

	/**
	 * @return array{
	 *   event_name: string,
	 *   group: string,
	 *   name: string,
	 *   summary: string,
	 *   description: string,
	 *   request: array{
	 *     method: string,
	 *     params: list<array{
	 *       name: string,
	 *       source: string,
	 *       type: string,
	 *       required: bool,
	 *       description: string
	 *     }>
	 *   },
	 *   response: array{
	 *     kind: string,
	 *     content_type: string,
	 *     description: string
	 *   },
	 *   authorization: array{
	 *     visibility: string,
	 *     description: string
	 *   },
	 *   notes: list<string>,
	 *   side_effects: list<string>
	 * }
	 */
	public static function describeBrowserEvent(): array
	{
		return [
			'event_name' => 'email.send',
			'group' => 'Email',
			'name' => 'Enqueue transactional email',
			'summary' => 'Queues a transactional email snapshot for later delivery.',
			'description' => 'Creates one email outbox record plus one transactional queue job per recipient.',
			'request' => [
				'method' => 'POST',
				'params' => [
					[
						'name' => 'to',
						'source' => 'post',
						'type' => 'string',
						'required' => true,
						'description' => 'Comma-separated recipient email list.',
					],
					[
						'name' => 'subject',
						'source' => 'post',
						'type' => 'string',
						'required' => false,
						'description' => 'Email subject snapshot.',
					],
					[
						'name' => 'html_body',
						'source' => 'post',
						'type' => 'string',
						'required' => false,
						'description' => 'HTML body snapshot.',
					],
					[
						'name' => 'text_body',
						'source' => 'post',
						'type' => 'string',
						'required' => false,
						'description' => 'Plain-text body snapshot.',
					],
					[
						'name' => 'scheduled_at',
						'source' => 'post',
						'type' => 'string',
						'required' => false,
						'description' => 'Optional UTC schedule timestamp.',
					],
				],
			],
			'response' => [
				'kind' => 'json',
				'content_type' => 'application/json',
				'description' => 'Returns the created outbox id and queued job count.',
			],
			'authorization' => [
				'visibility' => 'protected',
				'description' => 'Requires the emails_admin role at request time.',
			],
			'notes' => [
				'Route slug: email:send',
				'Use Url::getUrl(\'email.send\') or ajax_url(\'email.send\') to generate the endpoint.',
			],
			'side_effects' => [
				'Creates transactional email outbox rows and queue rows.',
			],
		];
	}

	public function run(): void
	{
		if (Request::getMethod() !== 'POST') {
			header('Allow: POST');
			ApiResponse::renderError('METHOD_NOT_ALLOWED', 'This endpoint accepts POST requests only.', 405);

			return;
		}

		$to = trim((string) Request::_POST('to', ''));
		$subject = (string) Request::_POST('subject', '');
		$html_body = (string) Request::_POST('html_body', '');
		$text_body = (string) Request::_POST('text_body', '');
		$scheduled_at = trim((string) Request::_POST('scheduled_at', ''));

		if ($to === '') {
			ApiResponse::renderError('INVALID_INPUT', 'Missing recipient (to).', 400);

			return;
		}

		$recipients = array_map(
			static fn (string $email): array => ['email' => trim($email)],
			explode(',', $to)
		);

		try {
			$result = EmailOrchestrator::enqueueTransactionalSnapshot(
				subject: $subject,
				htmlBody: $html_body,
				textBody: $text_body,
				recipients: $recipients,
				scheduledAt: $scheduled_at !== '' ? $scheduled_at : null,
			);
		} catch (InvalidArgumentException $e) {
			ApiResponse::renderError('INVALID_INPUT', $e->getMessage(), 400);

			return;
		}

		ApiResponse::renderSuccess($result);
	}
}
