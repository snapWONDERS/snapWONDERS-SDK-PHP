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
 * A hide/reveal/analyse/convert job finished as `failed` (or `partial` when strict).
 *
 * {@see $error} carries the safe, server-sanitised description from the job status response.
 */
class JobFailedException extends SnapWondersException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?string $status = null,
    ) {
        parent::__construct($message);
    }
}
