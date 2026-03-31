<?php

class EventSQLSchemaInfo extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		echo "<pre>";
		print_r(DbSchemaData::getSchemaInfo());
		echo "</pre>";
	}
}
