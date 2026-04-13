<?php

class NumberToStringHelperHU
{
	private ?int $_originalNumber = null;
	private int $_remainder;
	private string $_result;
	private array $_egyesStr;
	private array $_tizesStr;
	private array $_tizenStr;

	public function __construct()
	{
		$this->_egyesStr = [
			'',
			'egy',
			'kettő',
			'három',
			'négy',
			'öt',
			'hat',
			'hét',
			'nyolc',
			'kilenc',
		];
		$this->_tizesStr = [
			'',
			'tíz',
			'húsz',
			'harminc',
			'negyven',
			'ötven',
			'hatvan',
			'hetven',
			'nyolcvan',
			'kilencven',
		];
		$this->_tizenStr = [
			'',
			'tizen',
			'huszon',
			'harminc',
			'negyven',
			'ötven',
			'hatvan',
			'hetven',
			'nyolcvan',
			'kilencven',
		];
	}

	public function toString(int $number): string
	{
		$this->_originalNumber = $number;
		$this->_result = '';

		if ($number == 0) {
			$this->_result = 'Nulla';
		} else {
			$this->_remainder = abs($this->_originalNumber);

			if ($this->_remainder > 999999999999) {
				return '';
			}
			$this->_doConversion(1000000000, 'milliárd');
			$this->_doConversion(1000000, 'millió');
			$this->_doConversion(1000, 'ezer');
			$this->_doConversion(1, '');
			$this->_result = ucfirst($this->_result);

			if ($number < 0) {
				$this->_result = 'Mínusz ' . $this->_result;
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
				$this->_result = $this->_result . $this->_egyesStr[$this->_originalNumber / 100] . 'száz';
			}

			$this->_originalNumber = $this->_originalNumber % 100;

			if ($this->_originalNumber % 10 !== 0) {
				$this->_result = $this->_result . $this->_tizenStr[$this->_originalNumber / 10] . $this->_egyesStr[$this->_originalNumber % 10] . $divisorName;
			} else {
				$this->_result = $this->_result . $this->_tizesStr[$this->_originalNumber / 10] . $divisorName;
			}
		}

		$this->_remainder = $this->_remainder % $divisor;
	}
}
