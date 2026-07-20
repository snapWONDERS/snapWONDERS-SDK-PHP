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
use SnapWonders\Internal\Poller;
use SnapWonders\Model\ResultFile;

/** A queued convert job; poll with {@see wait()}, then read {@see results()}. */
final class ConvertJob
{
    private const DOWNLOAD_PREFIX = '/api/convert/download';

    public ?string $status = null;
    public ?string $error = null;

    public function __construct(
        private readonly HttpTransport $transport,
        // Both status and results are keyed by the session's upload_uid, not the job_uid (which
        // differs for convert). Poll and fetch by upload_uid to match the API contract.
        public readonly string $uploadUid,
        public readonly string $jobUid,
    ) {
    }

    public function wait(float $pollInterval = 1.5, float $timeout = 900.0, bool $strict = false): self
    {
        $final = Poller::pollJob($this->transport, "/api/convert/job/{$this->uploadUid}", $pollInterval, $timeout);
        $this->status = $final['status'] ?? null;
        $this->error = $final['error'] ?? null;
        Poller::checkTerminal($final, $this->jobUid, $strict);

        return $this;
    }

    /** @return list<ResultFile> */
    public function results(): array
    {
        $data = $this->transport->request('GET', "/api/convert/job/{$this->uploadUid}/results", expected: [200])->json();
        // The API returns `result_files`; `items` is kept as a fallback.
        $items = is_array($data) ? ($data['result_files'] ?? $data['items'] ?? []) : [];

        $out = [];
        foreach ($items as $item) {
            if (!isset($item['asset_id'])) {
                continue;
            }
            $out[] = ResultFile::fromJson($item, $this->transport, self::DOWNLOAD_PREFIX);
        }

        return $out;
    }
}
