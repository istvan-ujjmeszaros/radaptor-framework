<?php

final class PolicyContext
{
	public function __construct(
		public readonly PolicyPrincipal $principal,
		public readonly string          $action,
	) {
	}

	/**
	 * Build a PolicyContext from a web event.
	 * The action is derived from the event class name — correct for both
	 * URL-dispatched and programmatically invoked events.
	 */
	public static function fromEvent(iEvent $event): self
	{
		return new self(
			principal: PolicyPrincipal::fromCurrentUser(),
			action:    get_class($event),
		);
	}

	/**
	 * Build a PolicyContext for a CLI command.
	 */
	public static function fromCli(AbstractCLICommand $command): self
	{
		return new self(
			principal: PolicyPrincipal::fromCurrentUser(),
			action:    get_class($command),
		);
	}
}
