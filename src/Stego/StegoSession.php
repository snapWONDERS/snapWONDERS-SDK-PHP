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

namespace SnapWonders\Stego;

use SnapWonders\Http\HttpTransport;
use SnapWonders\Internal\Poller;
use SnapWonders\Upload\UploadRouter;

/** An open hide/reveal upload session. Upload files, then start a job. */
final class StegoSession
{
    public function __construct(
        private readonly HttpTransport $transport,
        public readonly string $uploadUid,
        public readonly string $sessionType, // "hide" | "reveal"
        /**
         * Reported by the server at session creation. Kept so the direct-upload cap can move
         * server-side without an SDK release; null falls back to the built-in figure.
         */
        public readonly ?int $maxUploadBytes = null,
    ) {
    }

    /** Upload one file at $step (1 = secret/stego input, 2 = cover for hide). Returns the TUS path. */
    public function upload(string $filePath, int $step): string
    {
        return UploadRouter::uploadFile($this->transport, $filePath, $this->uploadUid, $step, $this->maxUploadBytes);
    }

    /** @return list<array<string,mixed>> */
    public function files(): array
    {
        return Poller::extractFiles(
            $this->transport->request('GET', "/api/session/{$this->uploadUid}/files", expected: [200])->json(),
        );
    }

    public function waitForUploads(float $pollInterval = 1.0, float $timeout = 120.0): self
    {
        Poller::waitForUploads($this->transport, "/api/session/{$this->uploadUid}/files", $pollInterval, $timeout);

        return $this;
    }

    /**
     * Queue the job. $options are the hide-only encoding keys (ignored for reveal).
     *
     * @param array<string,mixed> $options
     */
    public function startJob(string $password, string $expiry = '1d', array $options = []): StegoJob
    {
        $body = array_merge(
            ['upload_uid' => $this->uploadUid, 'password' => $password, 'expiry' => $expiry],
            $options,
        );
        $data = $this->transport->request('POST', '/api/job', json: $body, expected: [200, 201])->json();

        return new StegoJob(
            $this->transport,
            $this->uploadUid,
            (string) $data['job_uid'],
            (string) ($data['job_type'] ?? $this->sessionType),
        );
    }
}
