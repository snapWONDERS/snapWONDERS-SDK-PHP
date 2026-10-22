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
use SnapWonders\Model\AnalyseItem;

/** A queued analyse job; poll with {@see wait()}, then read {@see results()}. */
final class AnalyseJob
{
    public ?string $status = null;
    public ?string $error = null;

    public function __construct(
        private readonly HttpTransport $transport,
        // The status endpoint is keyed by the session's upload_uid; only the /result/{jobUid}
        // endpoint is keyed by job_uid. These two IDs differ, so status is polled by upload_uid.
        public readonly string $uploadUid,
        public readonly string $jobUid,
    ) {
    }

    public function wait(float $pollInterval = 1.5, float $timeout = 900.0, bool $strict = false): self
    {
        $final = Poller::pollJob($this->transport, "/api/analyse/job/{$this->uploadUid}", $pollInterval, $timeout);
        $this->status = $final['status'] ?? null;
        $this->error = $final['error'] ?? null;
        Poller::checkTerminal($final, $this->jobUid, $strict);

        return $this;
    }

    /**
     * Per-file forensic verdicts (grade, face/text counts, flags) plus downloadable assets.
     *
     * @return list<AnalyseItem>
     */
    public function results(): array
    {
        // This endpoint is keyed by job_uid, unlike the status poll above.
        $data = $this->transport->request('GET', "/api/analyse/result/{$this->jobUid}", expected: [200])->json();

        return array_map(
            fn (array $item): AnalyseItem => AnalyseItem::fromJson($item, $this->transport),
            self::extractItems($data),
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function extractItems(mixed $payload): array
    {
        if (is_array($payload)) {
            if (array_is_list($payload)) {
                return $payload;
            }
            foreach (['files', 'items', 'results'] as $key) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    return array_values($payload[$key]);
                }
            }
        }

        return [];
    }
}
