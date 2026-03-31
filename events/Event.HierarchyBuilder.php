<?php

class EventHierarchyBuilder extends AbstractEvent
{
	public function authorize(PolicyContext $policyContext): PolicyDecision
	{
		return PolicyDecision::allow();
	}

	public function run(): void
	{
		echo "ezt már nem használhatjuk, mert a feltöltést (valamint átnevezést és a törlést) kezelő szkriptek átvették a vfs_tree kezelését!!!!";
	}
}
