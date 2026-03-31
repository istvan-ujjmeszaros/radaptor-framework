<?php

interface iAuthorizable
{
	public function authorize(PolicyContext $policyContext): PolicyDecision;
}
