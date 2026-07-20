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

namespace SnapWonders\Convert;

use SnapWonders\Http\HttpTransport;

/**
 * `$client->convert` — media conversion.
 *
 * Session factory plus a one-shot {@see run()} helper. Wraps POST /api/convert/session →
 * TUS upload (all files at step 1) → POST /api/convert/job → poll GET /api/convert/job/{uid} →
 * GET /api/convert/job/{uid}/results → GET /api/convert/download/{asset_id}.
 */
final class Convert
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function createSession(): ConvertSession
    {
        $data = $this->transport->request('POST', '/api/convert/session', json: (object) [], expected: [200, 201])->json();

        return new ConvertSession($this->transport, (string) $data['upload_uid']);
    }

    /**
     * One-shot: create a session, upload every file, convert to completion.
     *
     * @param list<string>        $files
     * @param array<string,mixed> $options e.g. `['image_format' => 'webp']`.
     */
    public function run(array $files, string $expiry = '1d', array $options = []): ConvertJob
    {
        if ($files === []) {
            throw new \InvalidArgumentException('run() needs at least one file to convert');
        }
        $session = $this->createSession();
        foreach ($files as $file) {
            $session->upload($file);
        }
        $session->waitForUploads();

        return $session->startJob($expiry, $options)->wait();
    }
}
