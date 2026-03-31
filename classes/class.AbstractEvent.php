<?php

abstract class AbstractEvent implements iEvent, iAuthorizable
{
	/**
	 * Authorization gate — must be implemented by every concrete event class.
	 *
	 * PHPStan will report a static error for any concrete subclass that
	 * does not implement this method.
	 *
	 * Public events return PolicyDecision::allow().
	 * Protected events return PolicyDecision::allow() or ::deny() based on
	 * role, ownership, or group membership checks.
	 */
	abstract public function authorize(PolicyContext $policyContext): PolicyDecision;

	/**
	 * Aborts the current event handling process.
	 * This method is called by Kernel::abort() to allow custom handling of abort processes
	 * specific to the event currently being processed. It should be overridden in derived classes
	 * to implement specific abort logic, thereby customizing the event's termination behavior in case of critical failures.
	 *
	 * Usage of this method ensures that each event can have tailored cleanup, logging, and response behaviors,
	 * which are essential for maintaining application stability and providing meaningful error feedback.
	 */
	public function abort(string $message = ''): void
	{
		// Implement default abort behavior if necessary
	}
}
