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
use SnapWonders\Tus\TusUploader;

/** An open convert upload session — one or more files, all at step 1. */
final class ConvertSession
{
    public function __construct(
        private readonly HttpTransport $transport,
        public readonly string $uploadUid,
    ) {
    }

    public function upload(string $filePath): string
    {
        return TusUploader::uploadFile($this->transport, $filePath, $this->uploadUid, 1);
    }

    /** @return list<array<string,mixed>> */
    public function files(): array
    {
        return Poller::extractFiles(
            $this->transport->request('GET', "/api/convert/session/{$this->uploadUid}/files", expected: [200])->json(),
        );
    }

    public function waitForUploads(float $pollInterval = 1.0, float $timeout = 120.0): self
    {
        Poller::waitForUploads($this->transport, "/api/convert/session/{$this->uploadUid}/files", $pollInterval, $timeout);

        return $this;
    }

    /**
     * Queue conversion. $options are the convert encoding keys — e.g. `image_format`
     * (jpeg/png/webp/avif/heic/jxl), `video_format`, and resize/quality controls.
     *
     * @param array<string,mixed> $options
     */
    public function startJob(string $expiry = '1d', array $options = []): ConvertJob
    {
        $body = array_merge(['upload_uid' => $this->uploadUid, 'expiry' => $expiry], $options);
        $data = $this->transport->request('POST', '/api/convert/job', json: $body, expected: [200, 201])->json();

        return new ConvertJob($this->transport, $this->uploadUid, (string) $data['job_uid']);
    }
}
