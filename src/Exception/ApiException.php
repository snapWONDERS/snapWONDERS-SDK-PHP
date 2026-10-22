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

/** Any other non-2xx API response. {@see $statusCode} and {@see $body} are attached for inspection. */
class ApiException extends SnapWondersException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message);
    }
}
