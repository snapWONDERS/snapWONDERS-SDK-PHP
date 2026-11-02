<?php

/*
 * snapWONDERS API — PHP SDK
 * API version: 1.0
 *
 * Copyright (c) 2026 Kenneth Springer @ snapWONDERS. MIT Licensed — see LICENSE.
 * The MIT licence covers this client library only; the snapWONDERS API it calls is proprietary.
 *
 * Author: Kenneth Springer @ snapWONDERS <kenneth@snapwonders.com> (https://kennethbspringer.au)
 *
 * All the snapWONDERS API services are available over the Clearnet / Web and Dark Web Tor and I2P.
 * Read details: https://snapwonders.com/developers
 */

declare(strict_types=1);

namespace SnapWonders\Tus;

use SnapWonders\Exception\TusUploadException;
use SnapWonders\Http\HttpTransport;

/**
 * Hand-rolled TUS 1.0.0 upload — the resumable upload protocol the API uses for file transfer.
 *
 * Two-phase: POST /api/tus to create the upload resource, then chunked PATCH to stream the bytes.
 * Supports HEAD-resume (query the current offset and continue) so a dropped connection does not
 * restart the whole transfer. The create response `Location` is absolute and `Upload-Length` is sent
 * on create.
 */
final class TusUploader
{
    public const TUS_VERSION = '1.0.0';
    private const DEFAULT_CHUNK = 5 * 1024 * 1024; // 5 MiB

    /**
     * Upload one file for $uploadUid at $step. Returns the TUS upload path used.
     *
     * @throws TusUploadException on any failure.
     */
    public static function uploadFile(
        HttpTransport $transport,
        string $filePath,
        string $uploadUid,
        int $step,
        int $chunkSize = self::DEFAULT_CHUNK,
    ): string {
        if (!is_file($filePath)) {
            throw new TusUploadException("Not a file: {$filePath}");
        }
        $total = filesize($filePath);
        if ($total === false) {
            throw new TusUploadException("Cannot determine size of: {$filePath}");
        }

        // Phase 1 — create. `name` records the original filename (feeds input_original_names /
        // reveal-side filename recovery); a stable `client_upload_id` keeps a retried create from
        // creating a duplicate upload row (TusController reads both).
        $create = $transport->request('POST', '/api/tus', headers: [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Length' => (string) $total,
            'Upload-Metadata' => self::buildMetadata([
                'upload_uid' => $uploadUid,
                'step' => (string) $step,
                'name' => basename($filePath),
                'client_upload_id' => self::uuid4(),
            ]),
        ], expected: [200, 201]);

        $location = $create->header('Location');
        if ($location === null || $location === '') {
            throw new TusUploadException('TUS create returned no Location header');
        }
        $uploadPath = self::toRelative($location, $transport->baseUrl());

        // Phase 2 — stream in chunks from the current server offset (resume-safe).
        $offset = self::serverOffset($transport, $uploadPath);
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new TusUploadException("Cannot open file: {$filePath}");
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            while ($offset < $total) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $response = $transport->request('PATCH', $uploadPath, headers: [
                    'Tus-Resumable' => self::TUS_VERSION,
                    'Upload-Offset' => (string) $offset,
                    'Content-Type' => 'application/offset+octet-stream',
                ], content: $chunk, expected: [200, 204]);
                $newOffset = $response->header('Upload-Offset');
                $offset = $newOffset !== null ? (int) $newOffset : $offset + strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($offset !== $total) {
            throw new TusUploadException("Upload incomplete: sent to offset {$offset} of {$total}");
        }

        return $uploadPath;
    }

    /**
     * Encode an `Upload-Metadata` header: comma-separated `key <base64(value)>` pairs.
     *
     * @param array<string,string> $fields
     */
    public static function buildMetadata(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . ' ' . base64_encode($value);
        }

        return implode(',', $pairs);
    }

    /** Normalise a create `Location` to a base-relative path for subsequent PATCH/HEAD. */
    public static function toRelative(string $location, string $baseUrl): string
    {
        if (str_starts_with($location, $baseUrl)) {
            return substr($location, strlen($baseUrl));
        }
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            // Absolute but different host — keep the path portion only.
            $path = parse_url($location, PHP_URL_PATH);

            return is_string($path) ? $path : $location;
        }

        return str_starts_with($location, '/') ? $location : '/' . $location;
    }

    /** HEAD the upload to learn how many bytes the server already has (0 for a fresh create). */
    private static function serverOffset(HttpTransport $transport, string $uploadPath): int
    {
        $response = $transport->request(
            'HEAD',
            $uploadPath,
            headers: ['Tus-Resumable' => self::TUS_VERSION],
            expected: [200, 204],
        );

        return (int) ($response->header('Upload-Offset') ?? '0');
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
