<?php

class NumberToStringHelperEN
{
	private ?int $_originalNumber = null;
	private int $_remainder;
	private string $_result;
	private array $_onesStr;
	private array $_tensStr;
	private array $_teensStr;

	public function __construct()
	{
		$this->_onesStr = [
			'',
			'one',
			'two',
			'three',
			'four',
			'five',
			'six',
			'seven',
			'eight',
			'nine',
		];
		$this->_tensStr = [
			'',
			'ten',
			'twenty',
			'thirty',
			'forty',
			'fifty',
			'sixty',
			'seventy',
			'eighty',
			'ninety',
		];
		$this->_teensStr = [
			'',
			'teen',
			'twenty',
			'thirty',
			'forty',
			'fifty',
			'sixty',
			'seventy',
			'eighty',
			'ninety',
		];
	}

	public function toString(int $number): string
	{
		$this->_originalNumber = $number;
		$this->_result = '';

		if ($number == 0) {
			$this->_result = 'Zero';
		} else {
			$this->_remainder = abs($this->_originalNumber);

			if ($this->_remainder > 999999999999) {
				return '';
			}
			$this->_doConversion(1000000000, 'billion');
			$this->_doConversion(1000000, 'million');
			$this->_doConversion(1000, 'thousand');
			$this->_doConversion(1, '');
			$this->_result = ucfirst($this->_result);

			if ($number < 0) {
				$this->_result = 'Minus ' . $this->_result;
			}
		}

		return $this->_result;
	}

	protected function _doConversion(int $divisor, string $divisorName): void
	{
		if ($this->_remainder >= $divisor) {
			if (mb_strlen($this->_result) > 0) {
				$this->_result = $this->_result . '-';
			}

			$this->_originalNumber = $this->_remainder / $divisor;

			if ($this->_originalNumber >= 100) {
				$this->_result = $this->_result . $this->_onesStr[$this->_originalNumber / 100] . ' hundred';
			}

			$this->_originalNumber = $this->_originalNumber % 100;

			if ($this->_originalNumber % 10 !== 0) {
				$this->_result = $this->_result . $this->_teensStr[$this->_originalNumber / 10] . $this->_onesStr[$this->_originalNumber % 10] . $divisorName;
			} else {
				$this->_result = $this->_result . $this->_tensStr[$this->_originalNumber / 10] . $divisorName;
			}
		}

		$this->_remainder = $this->_remainder % $divisor;
	}
}
