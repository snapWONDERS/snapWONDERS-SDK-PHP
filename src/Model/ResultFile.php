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

namespace SnapWonders\Model;

use SnapWonders\Http\HttpTransport;

/**
 * One downloadable output (a stego image, recovered secret, converted file, analyse asset…).
 *
 * A thin view over the JSON the API returns, with a convenience {@see download()} method. No
 * validation logic; the API is authoritative. Field parsing is lenient because the exact result JSON
 * keys can differ slightly per product area.
 */
final class ResultFile
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $name,
        public readonly ?string $mimeType,
        public readonly ?int $fileSize,
        private readonly HttpTransport $transport,
        private readonly string $downloadPath, // e.g. "/api/job/download/{asset_id}"
    ) {
    }

    /**
     * Stream this asset to $dest and return the written path.
     *
     * $dest may be a file path ("out/photo.avif") or a directory ("out/", or "out" when it already
     * exists), in which case the server-supplied {@see $name} is appended.
     *
     * A trailing separator means "directory" even when it does not exist yet — a plain is_dir() is
     * false for a path yet to be created, so a trailing "/" must be honoured explicitly or the asset
     * is written to a file literally named e.g. "out".
     */
    public function download(string $dest): string
    {
        $isDir = str_ends_with($dest, '/') || str_ends_with($dest, '\\') || is_dir($dest);
        $target = $isDir
            ? rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . $this->name
            : $dest;

        $dir = \dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $response = $this->transport->request('GET', $this->downloadPath, expected: [200]);
        file_put_contents($target, $response->body);

        return $target;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromJson(array $data, HttpTransport $transport, string $downloadPrefix): self
    {
        $assetId = (string) $data['asset_id'];
        // Convert results carry the converted filename in `output_name` and the original in `name` —
        // prefer the converted name so a WebP output isn't saved as `photo.jpg`. Stego uses `name`;
        // analyse assets inject a name.
        $name = $data['output_name'] ?? $data['name'] ?? $data['filename'] ?? $assetId;
        $size = $data['file_size'] ?? $data['size_bytes'] ?? null;

        return new self(
            assetId: $assetId,
            name: (string) $name,
            mimeType: isset($data['mime_type']) ? (string) $data['mime_type'] : null,
            fileSize: $size !== null ? (int) $size : null,
            transport: $transport,
            downloadPath: "{$downloadPrefix}/{$assetId}",
        );
    }
}
