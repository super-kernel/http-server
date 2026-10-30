<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Request;

use Psr\Http\Message\UploadedFileInterface;
use SuperKernel\HttpMessage\UploadedFile;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

use const UPLOAD_ERR_NO_FILE;

/**
 * Normalizes a copied Swoole files array into a tree of PSR-7 uploaded files.
 *
 * @internal
 */
final class UploadedFileNormalizer
{
    /**
     * Skips non-array entries and treats arrays without an error field as nested groups.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, UploadedFileInterface|array>
     */
    public static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (!array_key_exists('error', $file)) {
                $normalized[$name] = self::normalize($file);

                continue;
            }

            $error = $file['error'];
            $error = is_int($error) ? $error : UPLOAD_ERR_NO_FILE;
            $temporaryName = $file['tmp_name'] ?? '';

            $normalized[$name] = new UploadedFile(
                is_string($temporaryName) ? $temporaryName : '',
                is_int($file['size'] ?? null) ? $file['size'] : null,
                $error,
                is_string($file['name'] ?? null) ? $file['name'] : null,
                is_string($file['type'] ?? null) ? $file['type'] : null,
            );
        }

        return $normalized;
    }
}
