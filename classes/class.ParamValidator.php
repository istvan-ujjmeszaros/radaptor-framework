<?php

class ParamValidator
{
	/**
	 * Validates that a parameter is an integer.
	 * If the validation fails, it calls ResourceTreeHandler::drop400(), which terminates the script.
	 *
	 * @param string $param The parameter to validate.
	 * @return int The validated integer.
	 */
	public static function validateIntegerOrAbort(string $param): int
	{
		if (!filter_var($param, FILTER_VALIDATE_INT)) {
			ResourceTreeHandler::drop400();
		}

		return (int) $param;
	}
}
