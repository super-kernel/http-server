<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\ServerRequestFilter\FilterServerRequestInterface;
use Laminas\Diactoros\ServerRequestFilter\FilterUsingXForwardedHeaders;
use Laminas\Diactoros\UriFactory;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Support\Functions;

/**
 * Class for marshaling a request object from the current PHP environment.
 *
 * @mixin \Laminas\Diactoros\ServerRequestFactory
 */
final class ServerRequestFactory implements ServerRequestFactoryInterface
{
	/**
	 * Function to use to get apache request headers; present only to simplify mocking.
	 *
	 * @var callable|string
	 */
	private static $apacheRequestHeaders = 'apache_request_headers';

	/**
	 * Create a request from the supplied superglobal values.
	 *
	 * If any argument is not supplied, the corresponding superglobal value will
	 * be used.
	 *
	 * The ServerRequest created is then passed to the fromServer() method in
	 * order to marshal the request URI and headers.
	 *
	 * @param null|array                        $server        $_SERVER superglobal
	 * @param null|array                        $query         $_GET superglobal
	 * @param null|array                        $body          $_POST superglobal
	 * @param null|array                        $cookies       $_COOKIE superglobal
	 * @param null|array                        $files         $_FILES superglobal
	 * @param null|FilterServerRequestInterface $requestFilter If present, the
	 *                                                         generated request will be passed to this instance and
	 *                                                         the result returned by this method. When not present, a
	 *                                                         default instance of FilterUsingXForwardedHeaders is
	 *                                                         created, using the `trustReservedSubnets()` constructor.
	 *
	 * @see fromServer()
	 *
	 */
	public static function fromGlobals(
		?array                        $server = null,
		?array                        $query = null,
		?array                        $body = null,
		?array                        $cookies = null,
		?array                        $files = null,
		?FilterServerRequestInterface $requestFilter = null,
	): ServerRequestInterface
	{
		$requestFilter ??= FilterUsingXForwardedHeaders::trustReservedSubnets();

		$server  = Functions::normalizeServer(
			$server ?? $_SERVER,
			is_callable(self::$apacheRequestHeaders) ? self::$apacheRequestHeaders : null,
		);
		$files   = Functions::normalizeUploadedFiles($files ?? $_FILES);
		$headers = Functions::marshalHeadersFromSapi($server);

		if (null === $cookies && array_key_exists('cookie', $headers)) {
			$cookies = Functions::parseCookieHeader($headers['cookie']);
		}

		return $requestFilter(
			new ServerRequest(
				$server,
				$files,
				UriFactory::createFromSapi($server, $headers),
				Functions::marshalMethodFromSapi($server),
				'php://input',
				$headers,
				$cookies ?? $_COOKIE,
				$query ?? $_GET,
				$body ?? $_POST,
				Functions::marshalProtocolVersionFromSapi($server),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
	{
		$uploadedFiles = [];

		return new ServerRequest(
			$serverParams,
			$uploadedFiles,
			$uri,
			$method,
			'php://temp',
		);
	}
}