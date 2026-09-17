<?php

/*
 * snapWONDERS API — PHP SDK example
 *
 * Copyright (c) 2026 Kenneth Springer @ snapWONDERS. MIT Licensed — see LICENSE.
 * Author: Kenneth Springer @ snapWONDERS <kenneth@snapwonders.com> (https://kennethbspringer.au)
 *
 * Media conversion: re-encode an image to another format.
 * Run:  SNAPWONDERS_API_KEY=sw_... php examples/convert.php [path/to/image] [format]
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
$out = $here . '/out/convert';
$image = $argv[1] ?? $here . '/assets/sample.png';
$format = $argv[2] ?? 'webp';   // jpeg | png | webp | avif | heic | jxl

$client = new Client($key);

echo "Converting {$image} → {$format} …\n";
$job = $client->convert->run([$image], options: ['image_format' => $format]);
foreach ($job->results() as $result) {
    $path = $result->download($out . '/');
    echo "  converted → {$path}\n";
}

echo "Done. See {$out}\n";
