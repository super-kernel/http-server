<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Swoole\Http\Response;

interface ResponseInterface extends PsrResponseInterface
{
	public function json(
		mixed  $value,
		string $charset = 'utf-8',
		int    $flags = 0,
		int    $depth = 512,
	): PsrResponseInterface;

	public function xml(mixed $data, string $charset = 'utf-8'): PsrResponseInterface;

	public function raw(mixed $data): PsrResponseInterface;

	public function redirect(string $location, int $statusCode = 302): PsrResponseInterface;

	/**
	 * The example:
	 *
	 *      $response->chunk(function () {
	 *          yield "Data chunk 1";
	 *          yield "Data chunk 2";
	 *          yield "Data chunk 3";
	 *      });
	 *
	 * @param callable $callback
	 *
	 * @return PsrResponseInterface
	 */
	public function chunk(callable $callback): PsrResponseInterface;

	public function getResponse(): PsrResponseInterface;

	public function getSwooleResponse(): Response;

	public function setSwooleResponse(Response $response): PsrResponseInterface;

}