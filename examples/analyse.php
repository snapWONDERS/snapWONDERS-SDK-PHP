<?php

/*
 * snapWONDERS API — PHP SDK example
 *
 * Copyright (c) 2026 Kenneth Springer @ snapWONDERS. MIT Licensed — see LICENSE.
 * Author: Kenneth Springer @ snapWONDERS <kenneth@snapwonders.com> (https://kennethbspringer.au)
 *
 * Forensic analysis: grade an image A–F and download the overlay assets it produces.
 * Run:  SNAPWONDERS_API_KEY=sw_... php examples/analyse.php [path/to/image.jpg]
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
$out = $here . '/out/analyse';
$image = $argv[1] ?? $here . '/assets/sample.png';

$client = new Client($key);

echo "Analysing {$image} …\n";
$job = $client->analyse->run([$image], options: ['face_detection' => true]);
echo "  status: {$job->status}\n";

foreach ($job->results() as $item) {
    echo "\n  {$item->filename}\n";
    echo "    grade        : {$item->grade}\n";
    echo "    faces        : {$item->faceCount}\n";
    echo "    text regions : {$item->textRegionCount}\n";
    echo '    watermark    : ' . ($item->watermarkFlagged ? 'true' : 'false') . "\n";

    $v = $item->verdicts;
    if ($v !== []) {
        echo '    AI generation: ' . ($v['ai_generation']['verdict'] ?? '') . "\n";
        echo '    C2PA         : ' . ($v['c2pa']['verdict'] ?? '') . "\n";
        echo '    camera match : ' . ($v['camera_fingerprint']['encoder_name'] ?? '') . "\n";
        foreach ($v['findings'] ?? [] as $finding) {
            echo "    finding      : {$finding['label']} ({$finding['severity']})\n";
        }
    }

    foreach ($item->assets as $asset) {        // ELA map, face overlay, …
        $path = $asset->download($out . '/');
        echo "    asset        : {$asset->name} → {$path}\n";
    }
}

echo "\nDone. See {$out}\n";
