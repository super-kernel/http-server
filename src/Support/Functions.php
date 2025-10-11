<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Support;

use Laminas\Diactoros\Exception\InvalidArgumentException;
use Laminas\Diactoros\Exception\UnrecognizedProtocolVersionException;
use Laminas\Diactoros\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use function Laminas\Diactoros\createUploadedFile;
use function Laminas\Diactoros\normalizeUploadedFiles;

final class Functions
{
	/**
	 * Marshal the $_SERVER array
	 *
	 * Pre-processes and returns the $_SERVER superglobal. In particularly, it
	 * attempts to detect the Authorization header, which is often not aggregated
	 * correctly under various SAPI/http combinations.
	 *
	 * @param null|callable $apacheRequestHeaderCallback Callback that can be used to
	 *                                                   retrieve Apache request headers. This defaults to
	 *                                                   `apache_request_headers` under the Apache mod_php.
	 *
	 * @return array Either $server verbatim, or with an added HTTP_AUTHORIZATION header.
	 */
	public static function normalizeServer(array $server, ?callable $apacheRequestHeaderCallback = null): array
	{
		if (null === $apacheRequestHeaderCallback && is_callable('apache_request_headers')) {
			$apacheRequestHeaderCallback = 'apache_request_headers';
		}

		// If the HTTP_AUTHORIZATION value is already set, or the callback is not
		// callable, we return verbatim
		if (
			isset($server['HTTP_AUTHORIZATION'])
			|| !is_callable($apacheRequestHeaderCallback)
		) {
			return $server;
		}

		$apacheRequestHeaders = $apacheRequestHeaderCallback();
		if (isset($apacheRequestHeaders['Authorization'])) {
			$server['HTTP_AUTHORIZATION'] = $apacheRequestHeaders['Authorization'];
			return $server;
		}

		if (isset($apacheRequestHeaders['authorization'])) {
			$server['HTTP_AUTHORIZATION'] = $apacheRequestHeaders['authorization'];
			return $server;
		}

		return $server;
	}


	/**
	 * Normalize uploaded files
	 *
	 * Transforms each value into an UploadedFile instance, and ensures that nested
	 * arrays are normalized.
	 *
	 * @return UploadedFileInterface[]
	 * @throws InvalidArgumentException For unrecognized values.
	 */
	public static function normalizeUploadedFiles(array $files): array
	{
		/**
		 * Traverse a nested tree of uploaded file specifications.
		 *
		 * @param string[]|array[]      $tmpNameTree
		 * @param int[]|array[]         $sizeTree
		 * @param int[]|array[]         $errorTree
		 * @param string[]|array[]|null $nameTree
		 * @param string[]|array[]|null $typeTree
		 *
		 * @return UploadedFile[]|array[]
		 */
		$recursiveNormalize = static function (
			array  $tmpNameTree,
			array  $sizeTree,
			array  $errorTree,
			?array $nameTree = null,
			?array $typeTree = null,
		) use (&$recursiveNormalize): array {
			$normalized = [];
			foreach ($tmpNameTree as $key => $value) {
				if (is_array($value)) {
					// Traverse
					$normalized[$key] = $recursiveNormalize(
						$value,
						$sizeTree[$key],
						$errorTree[$key],
						$nameTree[$key] ?? null,
						$typeTree[$key] ?? null,
					);
					continue;
				}
				$normalized[$key] = createUploadedFile(
					[
						'tmp_name' => $value,
						'size'     => $sizeTree[$key],
						'error'    => $errorTree[$key],
						'name'     => $nameTree[$key] ?? null,
						'type'     => $typeTree[$key] ?? null,
					],
				);
			}
			return $normalized;
		};

		/**
		 * Normalize an array of file specifications.
		 *
		 * Loops through all nested files (as determined by receiving an array to the
		 * `tmp_name` key of a `$_FILES` specification) and returns a normalized array
		 * of UploadedFile instances.
		 *
		 * This function normalizes a `$_FILES` array representing a nested set of
		 * uploaded files as produced by the php-fpm SAPI, CGI SAPI, or mod_php
		 * SAPI.
		 *
		 * @param array $files
		 *
		 * @return UploadedFile[]
		 */
		$normalizeUploadedFileSpecification = static function (array $files = []) use (&$recursiveNormalize): array {
			if (
				!isset($files['tmp_name']) || !is_array($files['tmp_name'])
				|| !isset($files['size']) || !is_array($files['size'])
				|| !isset($files['error']) || !is_array($files['error'])
			) {
				throw new InvalidArgumentException(sprintf(
					                                   '$files provided to %s MUST contain each of the keys "tmp_name",'
					                                   . ' "size", and "error", with each represented as an array;'
					                                   . ' one or more were missing or non-array values',
					                                   __FUNCTION__,
				                                   ));
			}

			return $recursiveNormalize(
				$files['tmp_name'],
				$files['size'],
				$files['error'],
				$files['name'] ?? null,
				$files['type'] ?? null,
			);
		};

		$normalized = [];
		foreach ($files as $key => $value) {
			if ($value instanceof UploadedFileInterface) {
				$normalized[$key] = $value;
				continue;
			}

			if (is_array($value) && isset($value['tmp_name']) && is_array($value['tmp_name'])) {
				$normalized[$key] = $normalizeUploadedFileSpecification($value);
				continue;
			}

			if (is_array($value) && isset($value['tmp_name'])) {
				$normalized[$key] = createUploadedFile($value);
				continue;
			}

			if (is_array($value)) {
				$normalized[$key] = normalizeUploadedFiles($value);
				continue;
			}

			throw new InvalidArgumentException('Invalid value in files specification');
		}
		return $normalized;
	}

	/**
	 * @param array $server Values obtained from the SAPI (generally `$_SERVER`).
	 *
	 * @return array<non-empty-string, mixed> Header/value pairs
	 */
	public static function marshalHeadersFromSapi(array $server): array
	{
		$contentHeaderLookup = isset($server['LAMINAS_DIACTOROS_STRICT_CONTENT_HEADER_LOOKUP'])
			? static function (string $key): bool {
				static $contentHeaders = [
					'CONTENT_TYPE'   => true,
					'CONTENT_LENGTH' => true,
					'CONTENT_MD5'    => true,
				];
				return isset($contentHeaders[$key]);
			}
			: static fn(string $key): bool => str_starts_with($key, 'CONTENT_');

		$headers = [];
		foreach ($server as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}

			if ($value === '') {
				continue;
			}

			// Apache prefixes environment variables with REDIRECT_
			// if they are added by rewrite rules
			if (str_starts_with($key, 'REDIRECT_')) {
				$key = substr($key, 9);

				// We will not overwrite existing variables with the
				// prefixed versions, though
				if (array_key_exists($key, $server)) {
					continue;
				}
			}

			if (str_starts_with($key, 'HTTP_')) {
				$name           = strtr(strtolower(substr($key, 5)), '_', '-');
				$headers[$name] = $value;
				continue;
			}

			if ($contentHeaderLookup($key)) {
				$name           = strtr(strtolower($key), '_', '-');
				$headers[$name] = $value;
				continue;
			}
		}

		// Filter out integer keys.
		// These can occur if the translated header name is a string integer.
		// PHP will cast those to integers when assigned to an array.
		// This filters them out.
		return array_filter($headers, fn(string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * Parse a cookie header according to RFC 6265.
	 *
	 * PHP will replace special characters in cookie names, which results in other cookies not being available due to
	 * overwriting. Thus, the server request should take the cookies from the request header instead.
	 *
	 * @param string $cookieHeader A string cookie header value.
	 *
	 * @return array<non-empty-string, string> key/value cookie pairs.
	 */
	public static function parseCookieHeader(string $cookieHeader): array
	{
		preg_match_all('(
        (?:^\\n?[ \t]*|;[ ])
        (?P<name>[!#$%&\'*+-.0-9A-Z^_`a-z|~]+)
        =
        (?P<DQUOTE>"?)
            (?P<value>[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]*)
        (?P=DQUOTE)
        (?=\\n?[ \t]*$|;[ ])
    )x', $cookieHeader, $matches, PREG_SET_ORDER);

		$cookies = [];

		foreach ($matches as $match) {
			$cookies[$match['name']] = rawurldecode($match['value']);
		}

		return $cookies;
	}

	/**
	 * Retrieve the request method from the SAPI parameters.
	 */
	public static function marshalMethodFromSapi(array $server): string
	{
		return $server['REQUEST_METHOD'] ?? 'GET';
	}


	/**
	 * Return HTTP protocol version (X.Y) as discovered within a `$_SERVER` array.
	 *
	 * @throws UnrecognizedProtocolVersionException If the
	 *     $server['SERVER_PROTOCOL'] value is malformed.
	 */
	public static function marshalProtocolVersionFromSapi(array $server): string
	{
		if (!isset($server['SERVER_PROTOCOL'])) {
			return '1.1';
		}

		if (!preg_match('#^(HTTP/)?(?P<version>[1-9]\d*(?:\.\d)?)$#', $server['SERVER_PROTOCOL'], $matches)) {
			throw UnrecognizedProtocolVersionException::forVersion(
				(string)$server['SERVER_PROTOCOL'],
			);
		}

		return $matches['version'];
	}

}