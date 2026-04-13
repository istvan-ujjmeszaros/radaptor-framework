<?php

class Url
{
	private static function decodeHtmlEntitiesRecursively(string $value): string
	{
		do {
			$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			if ($decoded === $value) {
				return $decoded;
			}

			$value = $decoded;
		} while (true);
	}

	/**
	 * @param array<string, mixed> $parsed_url
	 */
	private static function buildUrlFromParsedParts(array $parsed_url): string
	{
		$url = '';

		if (isset($parsed_url['scheme'])) {
			$url .= $parsed_url['scheme'] . '://';
		}

		if (isset($parsed_url['user'])) {
			$url .= $parsed_url['user'];

			if (isset($parsed_url['pass'])) {
				$url .= ':' . $parsed_url['pass'];
			}

			$url .= '@';
		}

		if (isset($parsed_url['host'])) {
			$url .= $parsed_url['host'];
		}

		if (isset($parsed_url['port'])) {
			$url .= ':' . $parsed_url['port'];
		}

		$url .= $parsed_url['path'] ?? '';

		if (isset($parsed_url['query']) && $parsed_url['query'] !== '') {
			$url .= '?' . $parsed_url['query'];
		}

		if (isset($parsed_url['fragment']) && $parsed_url['fragment'] !== '') {
			$url .= '#' . $parsed_url['fragment'];
		}

		return $url !== '' ? $url : '/';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function getServerContext(): array
	{
		try {
			$context_server = RequestContextHolder::current()->SERVER ?? [];
		} catch (Throwable) {
			$context_server = [];
		}

		return !empty($context_server) ? $context_server : $_SERVER;
	}

	public static function currentEqualsToReferer(): bool
	{
		$referer = Kernel::getReferer();
		$current = Url::getCurrentUrl();

		$parsed_referer = parse_url($referer);
		$parsed_current = parse_url($current);

		if (!isset($parsed_referer['host']) && !isset($parsed_referer['path'])) {
			return false;
		}

		return (($parsed_referer['host'] == $parsed_current['host']) && ($parsed_referer['path'] == $parsed_current['path']));
	}

	public static function parseGetFromStringUrl(string $url): array
	{
		$href = str_replace('&amp;', '&', $url);
		$href = explode('?', $href);

		if (isset($href[1])) {
			$src_attribs = explode('&', $href[1]);
		} else {
			return [];
		}

		$params = [];

		foreach ($src_attribs as $param_pair) {
			$param_array = explode('=', $param_pair);

			if (count($param_array) >= 2) {
				$params[$param_array[0]] = $param_array[1];
			}
		}

		return $params;
	}

	public static function pathEqualsTo(?string $compareTo): bool
	{
		return urldecode((string) $_SERVER['REQUEST_URI']) === $compareTo;
	}

	private static function _strLeft(string $s1): string
	{
		return mb_substr($s1, 0, mb_strpos($s1, "/"));
	}

	public static function getCurrentDomain(): string
	{
		$server = self::getServerContext();

		return (string)($server['HTTP_HOST'] ?? 'localhost');
	}

	public static function getCurrentHost(bool $append_slash = true): string
	{
		$server = self::getServerContext();
		$s = empty($server["HTTPS"]) ? '' : (($server["HTTPS"] == "on") ? "s" : "");

		$protocol = self::_strLeft(mb_strtolower((string)($server["SERVER_PROTOCOL"] ?? 'HTTP/1.1'))) . $s;

		$port = (($server["SERVER_PORT"] ?? '80') == "80") ? "" : (":" . $server["SERVER_PORT"]);

		return $protocol . "://" . ($server['HTTP_HOST'] ?? 'localhost') . $port . ($append_slash ? '/' : '');
	}

	public static function getCurrentUrl(bool $cut_anchor = false): string
	{
		$server = self::getServerContext();
		$full_url = self::getCurrentHost(false) . ($server['REQUEST_URI'] ?? '/');

		if (!$cut_anchor) {
			return $full_url;
		}

		$anchor_position = mb_strrpos($full_url, "#");

		if ($anchor_position === false) {
			return $full_url;
		}

		return mb_substr($full_url, $anchor_position);
	}

	public static function sanitizeRefererUrl(string $url): string
	{
		$normalized_url = self::decodeHtmlEntitiesRecursively($url);

		if ($normalized_url === '') {
			return '';
		}

		$parsed_url = parse_url($normalized_url);

		if ($parsed_url === false) {
			return $normalized_url;
		}

		$query_params = [];

		if (isset($parsed_url['query'])) {
			parse_str($parsed_url['query'], $query_params);
		}

		unset($query_params['referer']);

		if (($query_params['context'] ?? null) === 'user' && ($query_params['event'] ?? null) === 'logout') {
			unset($query_params['context'], $query_params['event']);
		}

		if (!empty($query_params)) {
			$parsed_url['query'] = http_build_query($query_params, '', '&');
		} else {
			unset($parsed_url['query']);
		}

		return self::buildUrlFromParsedParts($parsed_url);
	}

	public static function getCurrentUrlForReferer(): string
	{
		return self::sanitizeRefererUrl(self::getCurrentUrl());
	}

	public static function reverseSeoUrl(string $url): string
	{
		$parsed = parse_url($url);

		$path = $parsed['path'];

		$pos1 = mb_strrpos($path, '/');
		$folder = urlencode(mb_substr($path, 0, $pos1 + 1));

		$resource = urlencode(mb_substr($path, $pos1 + 1));

		return "index.php?folder=$folder&resource=$resource";
	}

	public static function getSeoUrl(int $resource_id, bool $full = true, bool $skip_index = true, bool $skip_resource_name = false): ?string
	{
		$cache_key = Cache::key([$resource_id, $full, $skip_index, $skip_resource_name]);

		if (Cache::isset(self::class, $cache_key)) {
			return Cache::get(self::class, $cache_key);
		}

		$resource_data = ResourceTreeHandler::getResourceTreeEntryDataById($resource_id);

		if (!$resource_data) {
			return Cache::set(self::class, $cache_key, null);
		}

		if ($skip_resource_name || ($skip_index) && ($resource_data['resource_name'] == 'index.html') && $resource_data['node_type'] == 'webpage') {
			$uri = $resource_data['path'] . '/';
		} else {
			$uri = $resource_data['path'] . '/' . $resource_data['resource_name'];
		}

		$url = $uri;

		$domain_context = ResourceTreeHandler::getDomainContextForResourceTreeEntryData($resource_data);

		// a tripla '/' jeleket is ki kell szűrni
		do {
			$filter_url = $url;
			$url = str_replace('//', '/', $filter_url);
		} while ($filter_url != $url);

		$url = str_replace(':/', '://', $url);

		// We support multiple domains in one database router, so we need to check if the actual domain equals to the target domain
		if (Config::APP_DOMAIN_CONTEXT->value() != $domain_context) {
			$url = '//' . $domain_context . $url;
		}

		if (Config::APP_DISABLE_SEO_URL->value()) {
			return Cache::set(self::class, $cache_key, self::reverseSeoUrl($url));
		} else {
			return Cache::set(self::class, $cache_key, $url);
		}
	}

	public static function getUrl(string $eventName = '', array $customparams = [], string $ampersand = '&', string $base_href = '/'): string
	{
		if (isset($customparams['form_id'])) {
			$customparams['referer'] ??= Request::_GET('referer', false)
				? self::sanitizeRefererUrl(urldecode((string) Request::_GET('referer')))
				: self::getCurrentUrlForReferer();
		}

		foreach ($customparams as $key => $customparam) {
			if ($key == "CKEditor" && $customparam == "") {
				unset($customparams[$key]);
			}
		}

		if ($eventName == '') {
			$eventName = EventResolver::DEFAULT_CONTEXT . '.' . EventResolver::DEFAULT_EVENT;
		}

		$event_params = [];
		$extra_params = [];

		$eventData = explode('.', $eventName);

		if (count($eventData) != 2) {
			SystemMessages::_error("Invalid eventName format (must contain one dot): {$eventName}");
		}

		$context = $eventData[0];
		$event = $eventData[1];

		if ($context !== EventResolver::DEFAULT_CONTEXT) {
			$event_params['context'] = $context;
		}

		if ($context !== EventResolver::DEFAULT_CONTEXT || ($event !== EventResolver::DEFAULT_EVENT)) {
			$event_params['event'] = $event;
		}

		$fullparams = $extra_params + $customparams + $event_params;

		return count($fullparams) > 0 ? $base_href . '?' . http_build_query($fullparams, '', $ampersand) : $base_href;
	}

	public static function renderUrl(string $eventName = '', array $customparams = [], string $ampersand = '&', ?string $base_href = null): void
	{
		echo self::getUrl($eventName, $customparams, $ampersand, $base_href);
	}

	public static function getAjaxUrl(string $eventName = '', array $customparams = []): ?string
	{
		$customparams['referer'] ??= self::getCurrentUrlForReferer();

		return self::getUrl($eventName, $customparams, '&');
	}

	public static function redirect(string $location): never
	{
		if (headers_sent()) {
			echo "
                <script>window.location.href=\"{$location}\";</script>\n
                <noscript>
                    Headers already sent! Please click this link: <a href=\"{$location}\">{$location}</a>\n
                </noscript>";

			exit;
		}

		ResourceTreeHandler::setNoCacheHeaders();

		WebpageView::header("HTTP/1.1 301 Moved Permanently");
		WebpageView::header("Location: $location");

		exit;
	}

	public static function addAnchor(string $anchor, string $ampersand = '&'): string
	{
		$queryString = http_build_query(Request::getGET(), '', $ampersand);

		$uri = Url::getCurrentHost();
		$exploded = explode('?', $uri);

		return $exploded[0] . '?' . $queryString . $anchor;
	}

	public static function modifyCurrentUrl(array $params, string $ampersand = '&'): string
	{
		$current_url = self::decodeHtmlEntitiesRecursively(self::getCurrentUrl());
		$parsed_url = parse_url($current_url);
		$existing_params = [];

		if ($parsed_url !== false && isset($parsed_url['query'])) {
			parse_str($parsed_url['query'], $existing_params);
		}

		foreach ($params as $param => $value) {
			$existing_params[$param] = $value;
		}

		$queryString = http_build_query($existing_params, '', $ampersand);
		$parsed_url = is_array($parsed_url) ? $parsed_url : ['path' => '/'];
		$parsed_url['query'] = $queryString;

		return self::buildUrlFromParsedParts($parsed_url);
	}

	public static function getAnchorTextWithExtraparamList(string $param_name, string $param_value_list = '', string $delimiter = ', ', ?string $base_href = null, string $class = ''): string
	{
		if (is_null($base_href)) {
			$exploded_uri = explode('/', (string) $_SERVER['REQUEST_URI']);

			foreach ($exploded_uri as $k => $v) {
				if (mb_strpos($v, '--') !== false) {
					unset($exploded_uri[$k]);
				}
			}

			$uri = implode('/', $exploded_uri);

			$base_href = Url::getCurrentHost() . $uri;
		}

		$params = explode($delimiter, $param_value_list);

		$anchors = [];

		if ($class !== '') {
			$class = ' class="' . $class . '"';
		}

		foreach ($params as $param) {
			$href = $base_href . $param_name . '--' . urlencode($param) . '/';

			$href = str_replace([
				'//',
				':/',
			], [
				'/',
				'://',
			], $href);

			$anchors[] = "<a href=\"{$href}\"{$class}>{$param}</a>";
		}

		return implode($delimiter, $anchors);
	}

	/**
	 * Az enabledExtraParamNames egy olyan asszociatív tömb, ami így néz ki:
	 * array(
	 *   'belső_név' => 'url_paraméter_név',
	 *   'belső_név2' => 'bn',
	 * )
	 *
	 * @param array $enabledExtraParamNames
	 * @param array $extraParams
	 * @return array
	 */
	public static function getExtraParamRealValues(array $enabledExtraParamNames, array $extraParams): array
	{
		if (count($enabledExtraParamNames) != count(array_unique($enabledExtraParamNames))) {
			Kernel::abort("Duplicated values in enabled URL extraparams!");
		}

		$enabledExtraParamNames = array_flip($enabledExtraParamNames);

		$return = [];

		foreach ($extraParams['paired'] as $key => $value) {
			if (isset($enabledExtraParamNames[$key])) {
				$return[$enabledExtraParamNames[$key]] = $value;
			}
		}

		return $return;
	}

	public static function getExtraParams(iTreeBuildContext $tree_build_context): array
	{
		$extra = urldecode($_SERVER['REQUEST_URI'] ?? '');

		$current_path = $tree_build_context->getPagedata('path');
		$current_document = $tree_build_context->getPagedata('resource_name');

		if (mb_strpos($extra, (string) $current_path) === 0) {
			$extra = mb_substr($extra, mb_strlen((string) $current_path), mb_strlen($extra) - mb_strlen((string) $current_path));
		}

		if (mb_strpos($extra, (string) $current_document) === 0) {
			$extra = mb_substr($extra, mb_strlen((string) $current_document), mb_strlen($extra) - mb_strlen((string) $current_document));
		}

		$extra_params = explode('/', $extra);

		if ($extra_params[count($extra_params) - 1] === '') {
			unset($extra_params[count($extra_params) - 1]);
		}

		$return = [
			'paired' => [],
			'standalone' => [],
		];

		foreach ($extra_params as $extraparam) {
			$exploded = explode('--', $extraparam);

			if (count($exploded) >= 2) {
				$return['paired'][$exploded[0]] = $exploded[1];
			} else {
				$return['standalone'][] = $exploded[0];
			}
		}

		return $return;
	}

	public static function comparePathLevels(string $path1, string $path2, bool $fallback = false, string $menu_root = '/'): bool
	{
		$path1 = ltrim($path1, $menu_root);
		$path2 = ltrim($path2, $menu_root);

		$path1 = trim($path1, '/');
		$path2 = trim($path2, '/');

		$exploded1 = explode('/', $path1);
		$exploded2 = explode('/', $path2);

		$count1 = count($exploded1);
		$count2 = count($exploded2);

		if ($count1 <= $count2) {
			$level = $count1 - 1;
		} else {
			$level = $count2 - 1;
		}

		if ($fallback) {
			return isset($exploded1[$level]) && isset($exploded2[$level]) && $exploded1[$level] === $exploded2[$level];
		} else {
			if ($count1 != $count2) {
				return false;
			}

			return isset($exploded1[$level]) && isset($exploded2[$level]) && implode('/', $exploded1) === implode('/', $exploded2);
		}
	}
}
