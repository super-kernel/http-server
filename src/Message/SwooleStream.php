<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Message;

use Psr\Http\Message\StreamInterface;
use Stringable;
use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

final class SwooleStream implements StreamInterface, Stringable
{
	private string $data;

	private int $position;

	/** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
	private int $size;

	public function __construct(string $data)
	{
		$this->data     = $data;
		$this->position = 0;
		$this->size     = strlen($data);
	}

	public function __toString(): string
	{
		return $this->data;
	}

	public function close(): void
	{
	}

	public function detach(): string
	{
		return $this->data;
	}

	public function getSize(): ?int
	{
		return $this->size;
	}

	public function tell(): int
	{
		return $this->position;
	}

	public function eof(): bool
	{
		return $this->position >= $this->size;

	}

	public function isSeekable(): bool
	{
		return true;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		$this->position = max(0, min(match ($whence) {
			SEEK_SET => $offset,
			SEEK_CUR => $this->position + $offset,
			SEEK_END => $this->size + $offset,
		}, $this->size));
	}

	public function rewind(): void
	{
		$this->position = 0;
	}

	public function isWritable(): bool
	{
		return false;
	}

	public function write(string $string): int
	{
		return 0;
	}

	public function isReadable(): bool
	{
		return true;
	}

	public function read(int $length): string
	{
		$result         = substr($this->data, $this->position, $length);
		$this->position += strlen($result);
		return $result;
	}

	public function getContents(): string
	{
		$remaining      = substr($this->data, $this->position);
		$this->position = $this->size;
		return $remaining;
	}

	public function getMetadata(?string $key = null)
	{
		$metadata = [
			'seekable' => $this->isSeekable(),
			'size'     => $this->size,
			'readable' => $this->isReadable(),
			'writable' => $this->isWritable(),
		];

		return $key === null ? $metadata : ($metadata[$key] ?? null);
	}
}