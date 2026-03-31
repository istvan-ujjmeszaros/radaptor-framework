<?php

class EventSQLTableInfo extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		echo "<pre>";
		print_r(Db::getPrimaryKeys('users'));
		echo "</pre>";
	}
}
