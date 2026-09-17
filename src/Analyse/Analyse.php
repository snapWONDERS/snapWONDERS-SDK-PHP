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

namespace SnapWonders\Analyse;

use SnapWonders\Http\HttpTransport;

/**
 * `$client->analyse` — forensic media analysis.
 *
 * Session factory plus a one-shot {@see run()} helper. Wraps POST /api/analyse/session →
 * TUS upload (all files at step 1) → POST /api/analyse/job → poll GET /api/analyse/job/{uid} →
 * GET /api/analyse/result/{job_uid} (per-file grades, counts, verdicts, and downloadable overlays).
 */
final class Analyse
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function createSession(): AnalyseSession
    {
        $data = $this->transport->request('POST', '/api/analyse/session', json: (object) [], expected: [200, 201])->json();

        return new AnalyseSession(
            $this->transport,
            (string) $data['upload_uid'],
            isset($data['max_upload_bytes']) ? (int) $data['max_upload_bytes'] : null,
        );
    }

    /**
     * One-shot: create a session, upload every file, run analysis to completion.
     *
     * @param list<string>        $files
     * @param array<string,mixed> $options
     */
    public function run(array $files, string $expiry = '1d', array $options = []): AnalyseJob
    {
        if ($files === []) {
            throw new \InvalidArgumentException('run() needs at least one file to analyse');
        }
        $session = $this->createSession();
        foreach ($files as $file) {
            $session->upload($file);
        }
        $session->waitForUploads();

        return $session->startJob($expiry, $options)->wait();
    }
}
