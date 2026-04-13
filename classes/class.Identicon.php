<?php

/**
 * Használat:
 *
 * identicon::png($size, $hash);
 * identicon::resource($size, $hash);
 */
class Identicon
{
	public const int spriteSize = 128;
	public const string salt = "Da-";

	private GdImage $_identicon;

	public function __construct(int $size, string $hashable)
	{
		$this->_makeIdenticon($size, sha1(self::salt . $hashable));
	}

	/**
	 * Generate sprite for corners and sides.
	 */
	private function _generateOuterSprite(int $shape, int $red, int $green, int $blue, int $rotation): GdImage
	{
		$sprite = imagecreatetruecolor(self::spriteSize, self::spriteSize);

		imageantialias($sprite, true);

		$color = imagecolorallocate($sprite, $red, $green, $blue);
		$bgColor = imagecolorallocate($sprite, 255, 255, 255);

		imagefilledrectangle($sprite, 0, 0, self::spriteSize, self::spriteSize, $bgColor);

		$shape = match ($shape) {
			0 => [
				0.5,
				1,
				1,
				0,
				1,
				1,
			],
			1 => [
				0.5,
				0,
				1,
				0,
				0.5,
				1,
				0,
				1,
			],
			2 => [
				0.5,
				0,
				1,
				0,
				1,
				1,
				0.5,
				1,
				1,
				0.5,
			],
			3 => [
				0,
				0.5,
				0.5,
				0,
				1,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
			],
			4 => [
				0,
				0.5,
				1,
				0,
				1,
				1,
				0,
				1,
				1,
				0.5,
			],
			5 => [
				1,
				0,
				1,
				1,
				0.5,
				1,
				1,
				0.5,
				0.5,
				0.5,
			],
			6 => [
				0,
				0,
				1,
				0,
				1,
				0.5,
				0,
				0,
				0.5,
				1,
				0,
				1,
			],
			7 => [
				0,
				0,
				0.5,
				0,
				1,
				0.5,
				0.5,
				1,
				0,
				1,
				0.5,
				0.5,
			],
			8 => [
				0.5,
				0,
				0.5,
				0.5,
				1,
				0.5,
				1,
				1,
				0.5,
				1,
				0.5,
				0.5,
				0,
				0.5,
			],
			9 => [
				0,
				0,
				1,
				0,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				0,
				1,
			],
			10 => [
				0,
				0.5,
				0.5,
				1,
				1,
				0.5,
				0.5,
				0,
				1,
				0,
				1,
				1,
				0,
				1,
			],
			11 => [
				0.5,
				0,
				1,
				0,
				1,
				1,
				0.5,
				1,
				1,
				0.75,
				0.5,
				0.5,
				1,
				0.25,
			],
			12 => [
				0,
				0.5,
				0.5,
				0,
				0.5,
				0.5,
				1,
				0,
				1,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				0,
				1,
			],
			13 => [
				0,
				0,
				1,
				0,
				1,
				1,
				0,
				1,
				1,
				0.5,
				0.5,
				0.25,
				0.5,
				0.75,
				0,
				0.5,
				0.5,
				0.25,
			],
			14 => [
				0,
				0.5,
				0.5,
				0.5,
				0.5,
				0,
				1,
				0,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				0,
				1,
			],
			default => [
				0,
				0,
				1,
				0,
				0.5,
				0.5,
				0.5,
				0,
				0,
				0.5,
				1,
				0.5,
				0.5,
				1,
				0.5,
				0.5,
				0,
				1,
			],
		};

		// apply ratios
		for ($i = 0; $i < count($shape); $i++) {
			$shape[$i] = $shape[$i] * self::spriteSize;
		}

		imagefilledpolygon($sprite, $shape, count($shape) / 2, $color);

		// rotate the sprite
		for ($i = 0; $i < $rotation; $i++) {
			$sprite = imagerotate($sprite, 90, $bgColor);
		}

		return $sprite;
	}

	/**
	 * Generate sprite for center block.
	 */
	private function _generateCenterSprite(int $shape, int $red, int $green, int $blue, int $bgRed, int $bgGreen, int $bgBlue, int $applyBackgroundColor): GdImage
	{
		$sprite = imagecreatetruecolor(self::spriteSize, self::spriteSize);

		imageantialias($sprite, true);

		$color = imagecolorallocate($sprite, $red, $green, $blue);

		// make sure there's enough contrast before we use background color of side sprite
		if ($applyBackgroundColor > 0 && (abs($red - $bgRed) > 127 || abs($green - $bgGreen) > 127 || abs($blue - $bgBlue) > 127)) {
			$bgColor = imagecolorallocate($sprite, $bgRed, $bgGreen, $bgBlue);
		} else {
			$bgColor = imagecolorallocate($sprite, 255, 255, 255);
		}

		imagefilledrectangle($sprite, 0, 0, self::spriteSize, self::spriteSize, $bgColor);

		switch ($shape) {
			case 0: // empty
				$shape = [];

				break;

			case 1: // fill
				$shape = [
					0,
					0,
					1,
					0,
					1,
					1,
					0,
					1,
				];

				break;

			case 2: // diamond
				$shape = [
					0.5,
					0,
					1,
					0.5,
					0.5,
					1,
					0,
					0.5,
				];

				break;

			case 3: // reverse diamond
				$shape = [
					0,
					0,
					1,
					0,
					1,
					1,
					0,
					1,
					0,
					0.5,
					0.5,
					1,
					1,
					0.5,
					0.5,
					0,
					0,
					0.5,
				];

				break;

			case 4: // cross
				$shape = [
					0.25,
					0,
					0.75,
					0,
					0.5,
					0.5,
					1,
					0.25,
					1,
					0.75,
					0.5,
					0.5,
					0.75,
					1,
					0.25,
					1,
					0.5,
					0.5,
					0,
					0.75,
					0,
					0.25,
					0.5,
					0.5,
				];

				break;

			case 5: // morning star
				$shape = [
					0,
					0,
					0.5,
					0.25,
					1,
					0,
					0.75,
					0.5,
					1,
					1,
					0.5,
					0.75,
					0,
					1,
					0.25,
					0.5,
				];

				break;

			case 6: // small square
				$shape = [
					0.33,
					0.33,
					0.67,
					0.33,
					0.67,
					0.67,
					0.33,
					0.67,
				];

				break;

			case 7: // checkerboard
				$shape = [
					0,
					0,
					0.33,
					0,
					0.33,
					0.33,
					0.66,
					0.33,
					0.67,
					0,
					1,
					0,
					1,
					0.33,
					0.67,
					0.33,
					0.67,
					0.67,
					1,
					0.67,
					1,
					1,
					0.67,
					1,
					0.67,
					0.67,
					0.33,
					0.67,
					0.33,
					1,
					0,
					1,
					0,
					0.67,
					0.33,
					0.67,
					0.33,
					0.33,
					0,
					0.33,
				];

				break;
		}

		// apply ratios
		for ($i = 0; $i < count($shape); $i++) {
			$shape[$i] = $shape[$i] * self::spriteSize;
		}

		if (count($shape) > 0) {
			imagefilledpolygon($sprite, $shape, count($shape) / 2, $color);
		}

		return $sprite;
	}

	private function _makeIdenticon(int $size, string $hash): void
	{
		$cornerSpriteShape = hexdec(mb_substr($hash, 0, 1));
		$sideSpriteShape = hexdec(mb_substr($hash, 1, 1));
		$centerSpriteShape = hexdec(mb_substr($hash, 2, 1)) & 7;

		$cornerSpriteRotation = hexdec(mb_substr($hash, 3, 1)) & 3;
		$sideSpriteRotation = hexdec(mb_substr($hash, 4, 1)) & 3;
		$centerSpriteRotation = hexdec(mb_substr($hash, 5, 1)) % 2;

		$cornerSpriteRed = hexdec(mb_substr($hash, 6, 2));
		$cornerSpriteGreen = hexdec(mb_substr($hash, 8, 2));
		$cornerSpriteBlue = hexdec(mb_substr($hash, 10, 2));

		$sideSpriteRed = hexdec(mb_substr($hash, 12, 2));
		$sideSpriteGreen = hexdec(mb_substr($hash, 14, 2));
		$sideSpriteBlue = hexdec(mb_substr($hash, 16, 2));

		// start with blank 3x3 identicon
		$tmp_identicon = imagecreatetruecolor(self::spriteSize * 3, self::spriteSize * 3);
		imageantialias($tmp_identicon, true);

		// assign white as background
		$bgColor = imagecolorallocate($tmp_identicon, 255, 255, 255);
		imagefilledrectangle($tmp_identicon, 0, 0, self::spriteSize, self::spriteSize, $bgColor);

		// generate corner sprites
		$cornerSprite = self::_generateOuterSprite($cornerSpriteShape, $cornerSpriteRed, $cornerSpriteGreen, $cornerSpriteBlue, $cornerSpriteRotation);

		imagecopy($tmp_identicon, $cornerSprite, 0, 0, 0, 0, self::spriteSize, self::spriteSize);
		$cornerSprite = imagerotate($cornerSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $cornerSprite, 0, self::spriteSize * 2, 0, 0, self::spriteSize, self::spriteSize);
		$cornerSprite = imagerotate($cornerSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $cornerSprite, self::spriteSize * 2, self::spriteSize * 2, 0, 0, self::spriteSize, self::spriteSize);
		$cornerSprite = imagerotate($cornerSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $cornerSprite, self::spriteSize * 2, 0, 0, 0, self::spriteSize, self::spriteSize);

		// generate side sprites
		$sideSprite = $this->_generateOuterSprite($sideSpriteShape, $sideSpriteRed, $sideSpriteGreen, $sideSpriteBlue, $sideSpriteRotation);

		imagecopy($tmp_identicon, $sideSprite, self::spriteSize, 0, 0, 0, self::spriteSize, self::spriteSize);
		$sideSprite = imagerotate($sideSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $sideSprite, 0, self::spriteSize, 0, 0, self::spriteSize, self::spriteSize);
		$sideSprite = imagerotate($sideSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $sideSprite, self::spriteSize, self::spriteSize * 2, 0, 0, self::spriteSize, self::spriteSize);
		$sideSprite = imagerotate($sideSprite, 90, $bgColor);
		imagecopy($tmp_identicon, $sideSprite, self::spriteSize * 2, self::spriteSize, 0, 0, self::spriteSize, self::spriteSize);

		// generate center sprite
		$centerSprite = $this->_generateCenterSprite($centerSpriteShape, $cornerSpriteRed, $cornerSpriteGreen, $cornerSpriteBlue, $sideSpriteRed, $sideSpriteGreen, $sideSpriteBlue, $centerSpriteRotation);
		imagecopy($tmp_identicon, $centerSprite, self::spriteSize, self::spriteSize, 0, 0, self::spriteSize, self::spriteSize);

		// make white transparent
		imagecolortransparent($tmp_identicon, $bgColor);

		// create blank image according to specified dimensions
		$this->_identicon = imagecreatetruecolor($size, $size);
		imageantialias($this->_identicon, true);

		// assign white as background
		$bgColor = imagecolorallocate($this->_identicon, 255, 255, 255);
		imagefilledrectangle($this->_identicon, 0, 0, $size, $size, $bgColor);

		// resize identicon according to specification
		imagecopyresampled($this->_identicon, $tmp_identicon, 0, 0, (imagesx($tmp_identicon) - self::spriteSize * 3) / 2, (imagesx($tmp_identicon) - self::spriteSize * 3) / 2, $size, $size, self::spriteSize * 3, self::spriteSize * 3);

		// make white transparent
		imagecolortransparent($this->_identicon, $bgColor);
	}

	public function write(string $outFile, int $quality = 9): void
	{
		imagepng($this->_identicon, $outFile, $quality);
	}

	public static function png(int $size, string $hash, string $outFile, int $quality = 9): void
	{
		$identicon = new Identicon($size, $hash);

		$identicon->write($outFile, $quality);
	}

	public static function resource(int $size, string $hash): GdImage
	{
		$identicon = new Identicon($size, $hash);

		return $identicon->_identicon;
	}
}

/*
$emails[] = "styu007@gmail.com";

$size = 32;
?>
<table>
<?php foreach($emails as $i=>$email): ?>
<?php
$outFile = "identicons/" . $i . ".png";
identicon::png($size, mb_strtolower($email), $outFile, 9);
?>
	<tr>
		<td>
			<?php echo $email; ?>
		</td>
		<td>
			<img src="<?php echo $outFile; ?>" alt="<?php echo $email; ?>"  title="" width="<?php echo $size; ?>" height="<?php echo $size; ?>">
		</td>
	</tr>
<?php endforeach; ?>
</table>
*/
