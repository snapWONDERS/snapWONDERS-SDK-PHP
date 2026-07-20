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

namespace SnapWonders\Model;

use SnapWonders\Http\HttpTransport;

/**
 * Forensic verdict for one analysed file, plus its downloadable overlay assets.
 *
 * Field names are read leniently (`overall_grade`/`grade`, etc.) — confirm against the live
 * `/api/analyse/result/{job_uid}` shape; exact field names can vary.
 */
final class AnalyseItem
{
    /**
     * @param array<string,mixed> $verdicts Forensic verdicts, when the API includes them:
     *                                       `ai_generation` (AI-generation verdict), `c2pa` (Content
     *                                       Credentials), `camera_fingerprint` (device match),
     *                                       `findings` (key findings). A plain array — the keys grow
     *                                       over time; read what you need.
     * @param list<ResultFile>    $assets
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $filename,
        public readonly ?string $grade,
        public readonly ?int $faceCount,
        public readonly ?int $textRegionCount,
        public readonly ?bool $watermarkFlagged,
        public readonly ?bool $steganographySuspected,
        public readonly array $verdicts,
        public readonly array $assets,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromJson(array $data, HttpTransport $transport): self
    {
        // Analyse assets are keyed by `category` (e.g. "ela_map", "face_overlay") — not name/type —
        // and also carry mime_type/file_size. Pass the whole asset through so those flow into
        // ResultFile, injecting a sensible display name.
        $assets = [];
        foreach (($data['assets'] ?? []) as $asset) {
            if (!isset($asset['asset_id'])) {
                continue;
            }
            $asset['name'] = $asset['name'] ?? $asset['category'] ?? $asset['type'] ?? $asset['asset_id'];
            $assets[] = ResultFile::fromJson($asset, $transport, '/api/analyse/asset');
        }

        return new self(
            filename: $data['filename'] ?? $data['name'] ?? null,
            grade: $data['overall_grade'] ?? $data['grade'] ?? null,
            faceCount: isset($data['face_count']) ? (int) $data['face_count'] : null,
            textRegionCount: isset($data['text_region_count']) ? (int) $data['text_region_count'] : null,
            watermarkFlagged: isset($data['watermark_flagged']) ? (bool) $data['watermark_flagged'] : null,
            steganographySuspected: isset($data['steganography_suspected']) ? (bool) $data['steganography_suspected'] : null,
            verdicts: is_array($data['verdicts'] ?? null) ? $data['verdicts'] : [],
            assets: $assets,
            raw: $data,
        );
    }
}
