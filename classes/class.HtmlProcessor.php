<?php

class HtmlProcessor implements Stringable
{
	protected ?DOMDocument $_dom = null;
	protected ?DOMXPath $_dom_xpath = null;

	public function __construct(
		protected string $_htmlText
	) {
		$this->_dom = new DOMDocument();
		// DOMDocument still can't work with UTF8 encoding by default, so we need a workaround
		$encodedHtml = mb_encode_numericentity($this->_htmlText, [0x80, 0x10FFFF, 0, ~0], "UTF-8");
		$this->_dom->loadHtml($encodedHtml);
		$this->_dom_xpath = new DOMXPath($this->_dom);
	}

	public static function processHtmlContent(string $html_text): string
	{
		if (trim($html_text) == '') {
			return '';
		}

		$processor = new HtmlProcessor($html_text);

		$processor->rewriteAll();

		return self::TidyUpHtmlString($processor);
	}

	public static function TidyUpHtmlString(string $html_fragment): string
	{
		$tidy_config = [
			'clean' => false,
			'output-xhtml' => false,
			'output-html' => 'yes',
			'show-body-only' => true,
			'wrap' => 0,
		];

		$tidy = tidy_parse_string($html_fragment, $tidy_config, 'UTF8');
		$tidy->cleanRepair();

		return tidy_get_output($tidy);
	}

	public function __toString(): string
	{
		$this->rewriteAll();

		return mb_substr((string) $this->_dom->saveXML($this->_dom_xpath->query('//body')->item(0)), 6, -7, "UTF-8");
	}

	public function rewriteAnchor(): void
	{
		// oldalaknál odaugrós url
		// fájloknál letöltős url
		// mappáknál letöltés lista generálás
		foreach ($this->_dom_xpath->query('//a') as $node) {
			if ($node instanceof DOMElement) {
				if (!$node->hasAttribute('href')) {
					continue;
				}

				$get = Url::parseGetFromStringUrl($node->getAttribute('href'));
				$href = $node->getAttribute('href');

				########################################################################
				# belső weboldalra mutató link
				########################################################################
				if (isset($get['direction']) && $get['direction'] === 'in' && isset($get['id'])) {
					$node->setAttribute('href', Url::getSeoUrl($get['id'], false));
				}
				########################################################################
				# külső weboldalra mutató link, vagy belső oldalra mutat, de kézzel be
				# van írva a SEO url, ami egyébként kerülendő...
				########################################################################
				else {
					$parsed_url = parse_url($href);

					if (isset($parsed_url['host']) && $parsed_url['host'] != Url::getCurrentDomain()) {
						if (!$node->hasAttribute('target')) {
							$node->setAttribute('target', '_blank');
						}
					}
				}
			}
		}
	}

	private static function _getStyleProperties(string $style): array
	{
		$return = [];

		$properties = explode(';', $style);

		foreach ($properties as $property) {
			$paired = explode(':', $property);

			if (count($paired) != 2) {
				continue;
			}

			$return[trim($paired[0])] = trim($paired[1]);
		}

		return $return;
	}

	private static function _generateStylePropertiesString(array $properties): string
	{
		$return = '';

		foreach ($properties as $name => $value) {
			$return .= "{$name}:{$value};";
		}

		return $return;
	}

	private static function _moveStyledSizeToAttributes(DOMElement $node): void
	{
		$styles = self::_getStyleProperties($node->getAttribute('style'));

		if (isset($styles['width']) && isset($styles['height'])) {
			$node->setAttribute('width', $styles['width']);
			$node->setAttribute('height', $styles['height']);

			unset($styles['width']);
			unset($styles['height']);
		}
		$attributes = self::_generateStylePropertiesString($styles);

		if ($attributes == '') {
			$node->removeAttribute('style');
		} else {
			$node->setAttribute('style', $attributes);
		}
	}

	private static function _rewriteImgSrcToSizedSrc(string $src, string $width, string $height): string
	{
		$width = str_replace('px', '', $width);
		$height = str_replace('px', '', $height);

		$pathinfo = pathinfo($src);

		return "{$pathinfo['dirname']}/{$pathinfo['filename']}.{$width}x{$height}.{$pathinfo['extension']}";
	}

	public function rewriteImg(): void
	{
		// ha engedélyezve van az átméretezés, akkor az átméretezett képre mutasson
		foreach ($this->_dom_xpath->query('//img') as $node) {
			if ($node instanceof DOMElement) {
				if (!$node->hasAttribute('src')) {
					continue;
				}

				$get = Url::parseGetFromStringUrl($node->getAttribute('src'));

				########################################################################
				# belső képre mutató link
				########################################################################
				if (isset($get['direction']) && $get['direction'] === 'in' && isset($get['id'])) {
					$node->setAttribute('src', Url::getSeoUrl($get['id'], false));

					self::_moveStyledSizeToAttributes($node);

					if ($node->hasAttribute('width') && $node->hasAttribute('height')) {
						$width = $node->getAttribute('width');
						$height = $node->getAttribute('height');

						if (mb_strrpos((string) $width, '%') === false && mb_strrpos((string) $height, '%') === false) {
							$node->setAttribute('src', self::_rewriteImgSrcToSizedSrc($node->getAttribute('src'), $width, $height));
						}
					}
				}
			}
		}
	}

	public function rewriteAll(): void
	{
		$this->rewriteAnchor();
		$this->rewriteImg();
	}

	public static function Html2Text(string $html): string
	{
		/* ami itt szóköznek néz ki, az egy UTF-8 C2A0 karakter (&acirc;) */
		return trim(str_replace([
			"\u{a0}",
			"\n",
			"\r",
			"\t",
			"&nbsp;",
		], " ", strip_tags($html)));
	}

	/**
	 * Removes all unnecessary white space characters from text, like
	 * tabs, newlines, duplicated spaces inside text and spaces at the
	 * beginning and end of text.
	 */
	public static function cleanText(string $text): string
	{
		$from = [
			'û',
			'õ',
			'Û',
			'Õ',
		];
		$to = [
			'ű',
			'ő',
			'Ű',
			'Ő',
		];
		$text = str_replace($from, $to, $text);

		return html_entity_decode(trim((string) preg_replace('/\s\s+/', ' ', $text)), ENT_NOQUOTES, "UTF-8");
	}
}
