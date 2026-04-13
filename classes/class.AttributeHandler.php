<?php

class AttributeHandler
{
	public static function getAttributeArray(AttributeResourceIdentifier $resource, array $attributes): array
	{
		$return = AttributeHandler::getAttributes($resource);

		foreach ($attributes as $attributeName) {
			$return[$attributeName] ??= '';
		}

		return $return;
	}

	public static function addAttribute(AttributeResourceIdentifier $resource, array $savedata, bool $delete_empty = false): int
	{
		if (!$resource->isValid()) {
			Kernel::abort('Invalid resource: ' . $resource);
		}

		$modified = 0;

		foreach ($savedata as $param_name => $param_value) {
			if ($delete_empty && $param_value == '') {
				$query = "DELETE FROM attributes WHERE resource_name=? AND resource_id=? AND param_name=?";
				$stmt = Db::instance()->prepare($query);
				$stmt->execute([
					$resource->name,
					$resource->id,
					$param_name,
				]);

				$modified += $stmt->rowCount();
			} else {
				$modified += DbHelper::insertOrUpdateHelper('attributes', [
					'resource_name' => $resource->name,
					'resource_id' => $resource->id,
					'param_name' => $param_name,
					'param_value' => $param_value,
				]);
			}
		}

		Cache::flush();

		return $modified;
	}

	public static function getAttributes(AttributeResourceIdentifier $resource): array
	{
		if (!$resource->isValid()) {
			Kernel::abort('Invalid resource: ' . $resource);
		}

		$return = [];

		$data = DbHelper::selectMany('attributes', [
			'resource_name' => $resource->name,
			'resource_id' => $resource->id,
		]);

		foreach ($data as $value) {
			$return[$value['param_name']] = $value['param_value'];
		}

		return $return;
	}

	public static function deleteAttributes(AttributeResourceIdentifier $resource): void
	{
		if (!$resource->isValid()) {
			Kernel::abort('Invalid resource: ' . $resource);
		}

		$query = "DELETE FROM attributes WHERE resource_name=? AND resource_id=?";
		$stmt = Db::instance()->prepare($query);
		$stmt->execute([
			$resource->name,
			$resource->id,
		]);
	}
}
