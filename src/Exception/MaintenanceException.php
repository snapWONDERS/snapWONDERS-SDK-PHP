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

namespace SnapWonders\Exception;

/**
 * snapWONDERS is temporarily unavailable for maintenance (HTTP 503 + `status: MAINTENANCE`).
 *
 * Distinct from a transient 5xx: the service is deliberately unavailable and there is nothing wrong
 * with the caller's request. The API sends a `Retry-After`, surfaced here as {@see $retryAfter} so
 * the caller can wait properly or fail fast.
 */
class MaintenanceException extends SnapWondersException
{
    public function __construct(string $message, public readonly ?float $retryAfter = null)
    {
        parent::__construct($message);
    }
}
