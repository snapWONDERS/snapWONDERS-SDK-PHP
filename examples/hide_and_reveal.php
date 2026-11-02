<?php

/*
 * snapWONDERS API — PHP SDK example
 *
 * Copyright (c) 2026 Kenneth Springer @ snapWONDERS. MIT Licensed — see LICENSE.
 * Author: Kenneth Springer @ snapWONDERS <kenneth@snapwonders.com> (https://kennethbspringer.au)
 *
 * Steganography: hide a secret file inside a cover image, then reveal it back out.
 * Run:  SNAPWONDERS_API_KEY=sw_... php examples/hide_and_reveal.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SnapWonders\Client;

$key = getenv('SNAPWONDERS_API_KEY');
if ($key === false || $key === '') {
    fwrite(STDERR, "Set SNAPWONDERS_API_KEY (get one at https://snapwonders.com/sign-up)\n");
    exit(1);
}

$here = __DIR__;
$out = $here . '/out';

$client = new Client($key);

// A "secret" (any media file) and a "cover" image to hide it inside. The secret and cover must be
// two different files, and the cover's shortest side must be at least 512px.
$secret = $here . '/assets/secret.png';
$cover = $here . '/assets/sample.png';

echo "Hiding — the SDK creates a session, uploads both files, runs the job, and waits …\n";
$job = $client->stego->hide([$secret, $cover], password: 'Str0ng!Pass');
echo "  status: {$job->status}\n";

$stego = null;
foreach ($job->results() as $result) {
    $stego = $result->download($out . '/');   // a trailing "/" writes into the directory
    echo "  stego image → {$stego}\n";
}

echo "Revealing the hidden file back out …\n";
$revealed = $client->stego->reveal($stego, password: 'Str0ng!Pass');
foreach ($revealed->results() as $result) {
    $path = $result->download($out . '/recovered/');
    echo "  recovered → {$path}\n";
}

echo "Done. See {$out}\n";
