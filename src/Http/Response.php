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

namespace SnapWonders\Http;

/**
 * A thin, immutable view over one HTTP response: status, headers, and the raw body.
 *
 * Header keys are lowercased so lookups are case-insensitive.
 */
final class Response
{
    /** @param array<string,string> $headers Lowercased header keys. */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /** Case-insensitive header lookup; null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decode the body as an associative JSON structure.
     *
     * @throws \JsonException on invalid JSON.
     */
    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Like {@see json()} but returns null on empty or invalid JSON instead of throwing. */
    public function jsonOrNull(): mixed
    {
        try {
            return $this->json();
        } catch (\JsonException) {
            return null;
        }
    }
}
