<?php

class EventSystemmessagesRenderSystemMessages extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		SystemMessages::renderSystemMessages();
	}
}
