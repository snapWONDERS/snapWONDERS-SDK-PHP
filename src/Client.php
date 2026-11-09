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

namespace SnapWonders;

use SnapWonders\Analyse\Analyse;
use SnapWonders\Convert\Convert;
use SnapWonders\Http\HttpTransport;
use SnapWonders\Stego\Stego;

/**
 * Authenticated client for the snapWONDERS API — the single entry point.
 *
 *     use SnapWonders\Client;
 *
 *     $client = new Client('sw_...');
 *     $client->status();
 *     $job = $client->stego->hide(['secret.pdf', 'cover.jpg'], password: 'Str0ng!Pass');
 *     $report = $client->analyse->run(['photo.jpg']);
 *     $out = $client->convert->run(['photo.jpg'], options: ['image_format' => 'webp']);
 *
 * Three product namespaces, all sharing the session → job → poll → download shape:
 * {@see $stego} (hide/reveal), {@see $analyse} (forensics), {@see $convert} (media).
 */
final class Client
{
    public const VERSION = '0.1.6';

    private readonly HttpTransport $transport;

    public readonly Stego $stego;
    public readonly Analyse $analyse;
    public readonly Convert $convert;

    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = HttpTransport::DEFAULT_BASE_URL,
        float $timeout = 30.0,
    ) {
        $this->transport = new HttpTransport($apiKey, $baseUrl, $timeout);
        $this->stego = new Stego($this->transport);
        $this->analyse = new Analyse($this->transport);
        $this->convert = new Convert($this->transport);
    }

    /** GET /api/status — no API key required. Returns the decoded status body. */
    public function status(): mixed
    {
        return $this->transport->request('GET', '/api/status', expected: [200, 503])->json();
    }

    /** The underlying transport, exposed for advanced/raw calls. */
    public function transport(): HttpTransport
    {
        return $this->transport;
    }
}
