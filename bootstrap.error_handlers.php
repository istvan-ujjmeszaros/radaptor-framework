<?php

if (getenv('ENVIRONMENT') !== 'test' && getenv('PHPUNIT') !== '1') {
	set_error_handler(errorHandler(...));
} else {
	set_error_handler(testingEnvironmentErrorToExceptionConversionHandler(...));
}

/**
 * @throws Exception
 */
function testingEnvironmentErrorToExceptionConversionHandler(int $error_number, string $error_string): bool
{
	// Special case, PHPStorm is generating a notice when trying to run all the tests due to
	// https://youtrack.jetbrains.com/issue/WI-71499/
	if ($error_string == 'preg_match(): Delimiter must not be alphanumeric, backslash, or NUL') {
		return true;
	}

	throw new Exception($error_string, $error_number);
}

// @codeCoverageIgnoreStart

// Set custom exception handler to use Kernel::unhandled_exception
set_exception_handler(function ($exception) {
	Kernel::abort_unexpectedly($exception);
});

function errorHandler(int $error_number, string $error_string, string $erroneous_file, int $erroneous_line): bool
{
	// The Config class may not exist while the generators are running
	if (class_exists('Config')) {
		if (Config::DEV_APP_DEBUG_INFO->value() === false) {
			echo "<!-- see error log for details! -->\n";

			return false;
		}
	}

	$out = '-->"></a></li></ul></ol></div></script><div style="background-color:yellow;">';
	$strpos = mb_stripos((string) $error_string, 'getimagesize');

	if ($strpos !== false) {
		return true;
	}

	$strpos = mb_stripos((string) $error_string, 'filesize');

	if ($strpos !== false) {
		return true;
	}

	if (class_exists('ConsoleWriter')) {
		ConsoleWriter::debug([
			'error_number' => $error_number,
			'error_string' => $error_string,
			'erroneous_file' => $erroneous_file,
			'erroneous_line' => $erroneous_line,
			'trace' => debug_backtrace(),
		], "errorHandler");
	}

	$debug_backtrace = debug_backtrace();
	//var_dump($debug_backtrace);

	if (defined('RADAPTOR_CLI')) {
		$out = "Error {$error_number}, {$error_string}\n";

		foreach ($debug_backtrace as $debug) {
			if (isset($debug['file'])) {
				$out .= basename($debug['file']) . ', line ' . $debug['line'] . "\n";
			}
		}

		$out .= "===\n";

		switch ($error_number) {
			case E_USER_ERROR:
				Kernel::ob_end_clean_all();
				$out .= "FATAL_ERROR {$error_number} {$error_string}\n";
				$out .= "Fatal error on line {$erroneous_line} in file {$erroneous_file}\n";
				fwrite(STDERR, $out);

				exit(1);

			case E_USER_WARNING:
				$out .= "WARNING {$error_number} {$error_string}\n";
				$out .= "On line {$erroneous_line} in file {$erroneous_file}\n";

				break;

			case E_USER_NOTICE:
				$out .= "NOTICE {$error_number} {$error_string}\n";
				$out .= "On line {$erroneous_line} in file {$erroneous_file}\n";

				break;

			default:
				$out .= "Unknown {$error_number} {$error_string}\n";
				$out .= "On line {$erroneous_line} in file {$erroneous_file}\n";

				break;
		}

		fwrite(STDERR, $out);

		return true;
	}

	$out .= "Error {$error_number}, {$error_string}<br>\n";

	foreach ($debug_backtrace as $debug) {
		if (isset($debug['file'])) {
			$out .= '<i>' . basename($debug['file']) . '</i>, line ' . $debug['line'] . "<br>\n";
		}
	}

	$out .= "===<br>\n";

	switch ($error_number) {
		case E_USER_ERROR:
			Kernel::ob_end_clean_all();
			$out .= "<b>FATAL_ERROR</b> <i>$error_number</i> $error_string<br>\n";
			$out .= "Fatal error on line $erroneous_line in file $erroneous_file";
			//		$out .= ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br>\n";
			//		$out .= "Aborting...<br>";
			echo "<!--></script>$out"; // we may be in a <script> tag so adding a closing tag to be on the safe side

			echo "<pre>";
			$backtrace = debug_backtrace();
			$simple_backtrace = array_map(
				fn ($trace) => [
					'file' => $trace['file'] ?? null,
					'line' => $trace['line'] ?? null,
					'function' => $trace['function'],
					'class' => $trace['class'] ?? null,
				],
				$backtrace
			);

			var_export($simple_backtrace);
			echo "</pre>";

			exit(1);

		case E_USER_WARNING:
			$out .= "<b>WARNING</b> <i>$error_number</i> $error_string<br>\n";
			$out .= "On line $erroneous_line in file $erroneous_file<br>\n";
			$out .= '</div>';
			echo "$out";

			//		$out .= ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br>";
			break;

		case E_USER_NOTICE:
			$out .= "<b>NOTICE</b> <i>$error_number</i> $error_string<br>\n";
			$out .= "On line $erroneous_line in file $erroneous_file<br>\n";
			$out .= '</div>';
			echo "$out";

			//		$out .= ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br>";
			break;

		default:
			$out .= "<b>Unknown</b> <i>$error_number</i> $error_string<br>\n";
			$out .= "On line $erroneous_line in file $erroneous_file<br>\n";
			$out .= '</div>';
			echo "$out";

			//		$out .= ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br>";
			break;
	}

	//	systemMessages::addSystemMessage($out);

	if (!class_exists('ConsoleWriter')) {
		echo $out;
	}

	//	exit;
	/* Don't execute PHP internal error handler */

	return true;
}
// @codeCoverageIgnoreEnd
