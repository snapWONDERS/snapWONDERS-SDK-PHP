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

namespace SnapWonders\Upload;

use SnapWonders\Exception\UploadException;
use SnapWonders\Http\HttpTransport;

/**
 * Direct upload — the whole file in one request, for anything under the server's size cap.
 *
 * The alternative to `TusUploader`. TUS costs a create, one or more chunked PATCHes and sometimes a
 * HEAD; this is a single POST with the file as the request body. For the common case — one photo,
 * well under the cap — that is one round trip instead of three, which matters most on the
 * high-latency links this API is often used over (Tor and I2P).
 *
 * Callers do not choose between the two. `UploadRouter::uploadFile()` picks, using the
 * `max_upload_bytes` the server reports when the session is created.
 */
final class DirectUploader
{
    /**
     * Mirrors the server's own fallback (`upload.direct.max_bytes`). Only used when a session
     * response did not carry `max_upload_bytes` — prefer the server's figure, which can move
     * without an SDK release.
     */
    public const DEFAULT_MAX_BYTES = 99614720; // 95 MiB

    /**
     * Upload one file for $uploadUid at $step in a single request.
     *
     * Returns the `storage_uid` the server assigned.
     *
     * @throws UploadException on any failure — the same base `TusUploadException` extends, so
     *                         callers can treat both upload paths identically.
     */
    public static function uploadFile(
        HttpTransport $transport,
        string $filePath,
        string $uploadUid,
        int $step,
        string $contentType = 'application/octet-stream',
    ): string {
        if (!is_file($filePath)) {
            throw new UploadException("Not a file: {$filePath}");
        }

        // Read the whole file into memory rather than streaming it.
        //
        // This is deliberate and must not be "optimised" into a stream. A string body lets cURL
        // set Content-Length; a stream makes it fall back to Transfer-Encoding: chunked — and the
        // server refuses chunked uploads with 411, because without a declared length a body
        // truncated in transit cannot be told apart from a complete one. Analysing a truncated
        // file would return confident findings about a file the user never sent, which is why that
        // rule exists. Streaming here looks like a clear win in review (less memory!) and silently
        // breaks every upload.
        //
        // The memory cost is bounded by the size check the router already made — this path only
        // runs for files under max_upload_bytes.
        $body = @file_get_contents($filePath);
        if ($body === false) {
            throw new UploadException("Cannot read file: {$filePath}");
        }

        $response = $transport->request(
            'POST',
            '/api/upload',
            headers: [
                'X-Upload-Uid'  => $uploadUid,
                'X-Upload-Step' => (string) $step,
                'X-Filename'    => basename($filePath),
                'Content-Type'  => $contentType,
                // Same idempotency contract as the TUS path: a retry after a lost response returns
                // the original result instead of storing the file twice.
                'X-Client-Upload-Id' => self::uuid4(),
            ],
            content: $body,
            expected: [200, 201],
        );

        $payload = $response->json();
        $storageUid = is_array($payload) && isset($payload['file']['storage_uid'])
            ? (string) $payload['file']['storage_uid']
            : '';

        if ($storageUid === '') {
            throw new UploadException('Direct upload returned no storage_uid');
        }

        return $storageUid;
    }

    /** RFC 4122 v4, without pulling in a dependency for one identifier. */
    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
