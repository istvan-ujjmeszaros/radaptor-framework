<?php

/**
 * Manages the caching of data to improve performance and reduce resource load.
 *
 * The Cache class provides a simple yet powerful way to store and retrieve data across different
 * contexts within the application. It uses a static array to hold cached data, allowing easy access
 * and management of cached entries. This class supports basic cache operations such as setting,
 * getting, checking for existence, and flushing data, either globally or within a specified context.
 *
 * Contexts are used to logically separate cache entries, which helps in organizing and managing the
 * cache more effectively. Each function in this class is static, meaning it can be called on the class
 * itself without creating an instance. This design is suitable for a simple shared cache accessed
 * across different parts of the application.
 *
 * Usage:
 * - Use `set` to add data to the cache.
 * - Use `get` to retrieve data from the cache.
 * - Use `isset` to check if a specific key exists within a context.
 * - Use `flush` to clear the cache globally or within a specific context.
 * - Use `key` to generate a serialized key from an array of parameters for consistent cache key creation.
 *
 * Example:
 * ```php
 * // Store data under the 'Url' context with a descriptive key 'page_id_1'
 * Cache::set('Url', 'page_id_1', 'http://example.com/page');
 *
 * // Retrieve cached data using the same context and key
 * $data = Cache::get('Url', 'page_id_1');
 *
 * // Check if the data is available in the cache
 * if (Cache::isset('Url', 'page_id_1')) {
 *     echo "Data is cached.";
 * }
 *
 * // Flushes only the 'Url' context, leaving other contexts intact
 * Cache::flush('Url');
 * ```
 */
class Cache
{
	/**
	 * Stores a value in the cache under a specified context and key.
	 *
	 * This method allows for the setting of any type of data within a specific context and under a unique key.
	 * It stores the value in the cache and returns the value after it is set. This can be useful for chaining
	 * operations or for verifying that the value has been correctly stored in the cache.
	 *
	 * @param string $context The context within which to store the data. Contexts are used to group
	 *                        related cache entries, making the cache more organized and accessible.
	 * @param mixed $key The key under which the value should be stored. Keys must uniquely identify
	 *                   the cache entry within the context.
	 * @param mixed $value The data to be stored in the cache. This method is designed to handle any
	 *                     type of data that can be cached.
	 *
	 * @return mixed Returns the value that was stored in the cache, allowing for immediate use or
	 *               verification of the stored data.
	 *
	 * Example Usage:
	 * ```php
	 * // Store and immediately retrieve data for a URL in the 'Url' context
	 * $urlData = 'Example webpage content...';
	 * $storedData = Cache::set('Url', 'http://example.com/page', $urlData);
	 * if ($storedData === $urlData) {
	 *     // Confirm that the data was stored correctly
	 *     echo "Data stored successfully!";
	 * }
	 * ```
	 */
	public static function set(string $context, mixed $key, mixed $value): mixed
	{
		RequestContextHolder::current()->inMemoryCache[$context][$key] = $value;

		return $value;
	}

	/**
	 * Retrieves the cached value for the specified key within a given context.
	 *
	 * This method fetches the value associated with a specified key from the cache. If the key does
	 * not exist within the specified context, it returns null. This allows for easy retrieval of
	 * cached data while safely handling cases where the key might not be set, avoiding errors.
	 *
	 * @param string $context The context of the cache from which to retrieve the data. Contexts
	 *                        are used to segregate cache data into logical groups, making the
	 *                        cache more manageable and coherent.
	 * @param mixed $key The key for the cache entry to retrieve. This key must uniquely identify
	 *                   the cached data within the given context.
	 *
	 * @return mixed Returns the value stored in the cache for the given key within the specified
	 *               context, or null if no such key exists. This method supports returning any type
	 *               of data that can be cached, reflecting the mixed type hint.
	 *
	 * Example Usage:
	 * ```php
	 * // Retrieve a cached URL's data from the 'Url' context
	 * $urlData = Cache::get('Url', 'http://example.com/page');
	 * if ($urlData !== null) {
	 *     // Use the cached data
	 * } else {
	 *     // Handle the absence of data, possibly fetching and caching it
	 * }
	 * ```
	 */
	public static function get(string $context, mixed $key): mixed
	{
		return RequestContextHolder::current()->inMemoryCache[$context][$key] ?? null;
	}

	/**
	 * Checks if a specific key is set within the specified cache context.
	 *
	 * This method determines whether a cache entry exists for a given key within a specific context.
	 * It is useful for verifying the presence of a cache entry before attempting to retrieve it,
	 * which can help avoid unnecessary re-computation or cache misses.
	 *
	 * @param string $context The context within which to check the cache. Contexts are used to
	 *                        group related cache entries together, allowing for more organized
	 *                        and efficient caching mechanisms.
	 * @param mixed $key The key for the cache entry to check. Keys are unique identifiers within
	 *                   their context for cached data.
	 *
	 * @return bool Returns true if the key exists in the specified context, otherwise false.
	 *
	 * Example Usage:
	 * ```php
	 * // Check if a specific URL is cached under the 'Url' context
	 * if (Cache::isset('Url', 'http://example.com/page')) {
	 *     // Perform actions if the cache entry exists
	 * } else {
	 *     // Maybe compute and cache the result if it does not exist
	 * }
	 * ```
	 */
	public static function isset(string $context, mixed $key): bool
	{
		return isset(RequestContextHolder::current()->inMemoryCache[$context][$key]);
	}

	/**
	 * Generates a JSON encoded string from the provided parameters to be used as a cache key.
	 *
	 * This method standardizes the creation of cache keys by JSON encoding
	 * an array of parameters. It is particularly useful for caching mechanisms where a consistent,
	 * unique identifier is required for stored data. The input parameters should
	 * include all relevant data that differentiates one cache entry from another.
	 *
	 * The function can also be used in conjunction with `func_get_args()` to dynamically
	 * capture arguments passed explicitly to a method, ignoring any default values not explicitly passed.
	 *
	 * @param array $params Associative array of parameters that uniquely identify the cache entry.
	 *                      These could include identifiers like resource IDs, state flags, configuration settings,
	 *                      or any other data relevant to the caching logic.
	 *
	 * @return string A JSON encoded string of the provided parameters that can be used as a cache key.
	 *
	 * Example Usage:
	 * ```php
	 * // Direct usage with explicit parameters
	 * $params = ['user_id' => 123, 'settings' => ['theme' => 'dark', 'notifications' => true]];
	 * $cacheKey = Cache::key($params);
	 * // Use $cacheKey for caching operations
	 *
	 * // Using func_get_args() to capture explicitly passed arguments in another function
	 * function generateCacheKey(...$args) {
	 *     $params = func_get_args();
	 *     return Cache::key($params);
	 * }
	 *
	 * // Calling generateCacheKey with explicit arguments
	 * $explicitKey = generateCacheKey('user_id', 123, 'data', ['item1', 'item2']);
	 * // The $explicitKey will include only the explicitly passed arguments, not any defaults.
	 * ```
	 */
	public static function key(array $params): string
	{
		return json_encode($params);
	}

	/**
	 * Flushes the entire cache or a specific context within the cache.
	 *
	 * This method clears cached data stored within the static cache array. If a context
	 * is specified, only that particular context's cache will be cleared; otherwise,
	 * the entire cache is flushed. This functionality is useful for selectively
	 * clearing cached data without affecting other cached entries.
	 *
	 * @param string|null $context Optional. The specific context to flush. If null or not provided,
	 *                             the entire cache will be flushed.
	 *
	 * Example Usage:
	 * ```php
	 * // Flush all cached data
	 * Cache::flush();
	 *
	 * // Flush only the cache for a specific context
	 * Cache::flush('Url');
	 * ```
	 */
	public static function flush(?string $context = null): void
	{
		$cache = &RequestContextHolder::current()->inMemoryCache;

		if (is_null($context)) {
			$cache = [];
		} else {
			if (isset($cache[$context])) {
				unset($cache[$context]);
			}
		}
	}
}
