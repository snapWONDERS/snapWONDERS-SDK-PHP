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
 * An upload failed, by whichever method was used.
 *
 * There are two upload paths — a single-request direct POST and the resumable TUS protocol — and
 * which one runs is chosen for the caller by file size. Catching a method-specific exception would
 * therefore mean catching one that only fires for some file sizes, so both throw this.
 *
 * `TusUploadException` extends it, so existing `catch (TusUploadException $e)` keeps working.
 */
class UploadException extends SnapWondersException
{
}
