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

/**
 * `$client->stego` — steganography (hide / reveal), the flagship flow.
 *
 * Session factory plus the one-shot {@see hide()} / {@see reveal()} helpers. Wraps
 * POST /api/session → TUS upload → POST /api/job → poll GET /api/job/{uid} →
 * GET /api/job/{uid}/results → GET /api/job/download/{asset_id}.
 */
final class Stego
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function createSession(string $sessionType): StegoSession
    {
        if ($sessionType !== 'hide' && $sessionType !== 'reveal') {
            throw new \InvalidArgumentException("sessionType must be 'hide' or 'reveal'");
        }
        $data = $this->transport->request('POST', '/api/session', json: ['type' => $sessionType], expected: [200, 201])->json();

        return new StegoSession($this->transport, (string) $data['upload_uid'], $sessionType);
    }

    /**
     * One-shot: create a hide session, upload secret(s) then cover, run to completion.
     *
     * Convention: the LAST path is the cover (step 2); all earlier paths are secrets (step 1).
     *
     * @param list<string>        $files
     * @param array<string,mixed> $options Hide-only encoding keys.
     */
    public function hide(array $files, string $password, string $expiry = '1d', array $options = []): StegoJob
    {
        if (count($files) < 2) {
            throw new \InvalidArgumentException('hide() needs at least one secret and one cover (>=2 files)');
        }
        $session = $this->createSession('hide');
        $cover = array_pop($files);
        foreach ($files as $secret) {
            $session->upload($secret, 1);
        }
        $session->upload($cover, 2);
        $session->waitForUploads();

        return $session->startJob($password, $expiry, $options)->wait();
    }

    /** One-shot: create a reveal session, upload the stego file, run to completion. */
    public function reveal(string $stegoFile, string $password, string $expiry = '1d'): StegoJob
    {
        $session = $this->createSession('reveal');
        $session->upload($stegoFile, 1);
        $session->waitForUploads();

        return $session->startJob($password, $expiry)->wait();
    }
}
