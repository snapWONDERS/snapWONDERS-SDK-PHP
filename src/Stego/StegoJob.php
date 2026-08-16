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
use SnapWonders\Model\ResultFile;

/** A queued hide/reveal job; poll it with {@see wait()}, then read {@see results()}. */
final class StegoJob
{
    private const DOWNLOAD_PREFIX = '/api/job/download';

    public ?string $status = null;
    public ?string $error = null;
    public ?string $progressMessage = null;

    public function __construct(
        private readonly HttpTransport $transport,
        // Status and results are keyed by the session's upload_uid, not the job_uid. Poll and fetch
        // by upload_uid to match the API contract.
        public readonly string $uploadUid,
        public readonly string $jobUid,
        public readonly string $jobType,
    ) {
    }

    public function refresh(): self
    {
        $data = $this->transport->request('GET', "/api/job/{$this->uploadUid}", expected: [200])->json();
        $data = is_array($data) ? $data : [];
        $this->status = $data['status'] ?? null;
        $this->error = $data['error'] ?? null;
        $this->progressMessage = $data['progress_message'] ?? null;

        return $this;
    }

    /** Poll until terminal. Throws {@see \SnapWonders\Exception\JobFailedException} on `failed` (`partial` when strict). */
    public function wait(float $pollInterval = 1.5, float $timeout = 900.0, bool $strict = false): self
    {
        $final = Poller::pollJob($this->transport, "/api/job/{$this->uploadUid}", $pollInterval, $timeout);
        $this->status = $final['status'] ?? null;
        $this->error = $final['error'] ?? null;
        $this->progressMessage = $final['progress_message'] ?? null;
        Poller::checkTerminal($final, $this->jobUid, $strict);

        return $this;
    }

    /** @return list<ResultFile> */
    public function results(): array
    {
        $data = $this->transport->request('GET', "/api/job/{$this->uploadUid}/results", expected: [200])->json();
        $files = is_array($data) ? ($data['result_files'] ?? []) : [];

        return array_map(
            fn (array $file): ResultFile => ResultFile::fromJson($file, $this->transport, self::DOWNLOAD_PREFIX),
            $files,
        );
    }
}
