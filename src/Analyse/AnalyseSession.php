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
use SnapWonders\Internal\Poller;
use SnapWonders\Upload\UploadRouter;

/** An open analyse upload session — one or more files, all at step 1. */
final class AnalyseSession
{
    public function __construct(
        private readonly HttpTransport $transport,
        public readonly string $uploadUid,
        /**
         * Reported by the server at session creation. Kept so the direct-upload cap can move
         * server-side without an SDK release; null falls back to the built-in figure.
         */
        public readonly ?int $maxUploadBytes = null,
    ) {
    }

    public function upload(string $filePath): string
    {
        return UploadRouter::uploadFile($this->transport, $filePath, $this->uploadUid, 1, $this->maxUploadBytes);
    }

    /** @return list<array<string,mixed>> */
    public function files(): array
    {
        return Poller::extractFiles(
            $this->transport->request('GET', "/api/analyse/session/{$this->uploadUid}/files", expected: [200])->json(),
        );
    }

    public function waitForUploads(float $pollInterval = 1.0, float $timeout = 120.0): self
    {
        Poller::waitForUploads($this->transport, "/api/analyse/session/{$this->uploadUid}/files", $pollInterval, $timeout);

        return $this;
    }

    /**
     * Queue analysis. $options = `face_detection`, `text_detection`,
     * `face_sensitivity` (standard/thorough), `forensic_depth` (standard/deep).
     *
     * @param array<string,mixed> $options
     */
    public function startJob(string $expiry = '1d', array $options = []): AnalyseJob
    {
        $body = array_merge(['upload_uid' => $this->uploadUid, 'expiry' => $expiry], $options);
        $data = $this->transport->request('POST', '/api/analyse/job', json: $body, expected: [200, 201])->json();

        return new AnalyseJob($this->transport, $this->uploadUid, (string) $data['job_uid']);
    }
}
