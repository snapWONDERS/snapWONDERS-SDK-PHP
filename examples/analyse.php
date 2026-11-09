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
    if ($item->faceCountByConfidence !== null) {
        echo '      by confidence: ' . json_encode($item->faceCountByConfidence) . "\n";
    }
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

        // forensic / audio_splice / video_tamper carry the WHY behind the grade.
        $forensic = $v['forensic'] ?? null;
        if (is_array($forensic)) {
            $ela = $forensic['ela'] ?? null;
            if (is_array($ela)) {
                echo "    ELA          : {$ela['high_fraction']} high-residual, {$ela['anomaly_tile_count']} anomaly tile(s)\n";
            }
            $noise = $forensic['noise_inconsistency'] ?? null;
            if (is_array($noise)) {
                echo "    noise map    : {$noise['anomaly_tile_count']} anomaly tile(s)\n";
            }
        }
        $audioSplice = $v['audio_splice'] ?? null;
        if (is_array($audioSplice)) {
            echo "    audio splice : {$audioSplice['verdict']} ({$audioSplice['spike_count']} spike(s), noise CoV {$audioSplice['noise_cov']})\n";
        }
        $videoTamper = $v['video_tamper'] ?? null;
        if (is_array($videoTamper)) {
            $interFrame = $videoTamper['inter_frame_tamper'] ?? null;
            if (is_array($interFrame)) {
                echo "    inter-frame  : {$interFrame['verdict']} ({$interFrame['spike_count']} spike(s) / {$interFrame['frame_count_checked']} frames checked)\n";
            }
            $frameDup = $videoTamper['frame_duplicate'] ?? null;
            if (is_array($frameDup)) {
                echo "    dup frames   : {$frameDup['dup_verdict']} ({$frameDup['dup_count']} duplicate(s))\n";
            }
            $gpsTriangle = $videoTamper['gps_triangle'] ?? null;
            if (is_array($gpsTriangle) && ($gpsTriangle['applicable'] ?? false)) {
                echo "    GPS triangle : {$gpsTriangle['verdict']} (Δ{$gpsTriangle['delta_secs']}s, {$gpsTriangle['timezone']})\n";
            }
        }
    }

    foreach ($item->assets as $asset) {        // ELA map, face overlay, …
        $path = $asset->download($out . '/');
        echo "    asset        : {$asset->name} → {$path}\n";
    }
}

echo "\nDone. See {$out}\n";
