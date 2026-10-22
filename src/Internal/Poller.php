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

namespace SnapWonders\Internal;

use SnapWonders\Exception\JobFailedException;
use SnapWonders\Exception\SnapWondersException;
use SnapWonders\Http\HttpTransport;

/**
 * Shared job/upload polling primitives used by every product namespace.
 *
 * Static helpers rather than a class hierarchy — the polling loops are the bug-prone part, so they
 * live in exactly one place and are unit-testable, while each namespace keeps its own explicit,
 * readable session/job classes on top.
 */
final class Poller
{
    /** @var list<string> */
    // `terminated` is terminal: without it here, waiting on such a job polled until the timeout.
    public const TERMINAL_STATES = ['completed', 'partial', 'failed', 'terminated'];

    // Polling cadence. Fixed-interval polling is a scaling hazard: N clients that start together poll
    // in lockstep, so the server sees a synchronised burst every interval (a thundering herd).
    // Instead we back off (fast first checks catch quick jobs; long jobs poll progressively less) and
    // jitter each wait so concurrent clients desynchronise. The server can override the cadence
    // centrally by returning `retry_after`/`poll_after` (seconds) in the status body.
    private const POLL_BACKOFF = 1.6;       // each wait grows by this factor …
    private const POLL_MAX_INTERVAL = 15.0; // … up to this ceiling
    private const POLL_JITTER = 0.25;       // ± this fraction of random jitter on every wait

    /**
     * Return [sleepSeconds, nextBaseInterval] for one poll cycle.
     *
     * A server-supplied `retry_after`/`poll_after` (seconds) wins — it lets the API throttle all SDK
     * clients centrally under load. Otherwise use the backed-off base with ± jitter.
     *
     * @param array<string,mixed> $statusBody
     * @return array{0: float, 1: float}
     */
    public static function nextWait(float $current, array $statusBody): array
    {
        $hint = $statusBody['retry_after'] ?? $statusBody['poll_after'] ?? null;
        if ($hint !== null && is_numeric($hint)) {
            $base = (float) $hint;

            return [$base, $base]; // honour the server; do not keep growing while it dictates
        }
        $sleep = $current * self::randFloat(1.0 - self::POLL_JITTER, 1.0 + self::POLL_JITTER);

        return [$sleep, min($current * self::POLL_BACKOFF, self::POLL_MAX_INTERVAL)];
    }

    /**
     * Normalise a `session/{uid}/files` response to a list of file arrays.
     *
     * @return list<array<string,mixed>>
     */
    public static function extractFiles(mixed $payload): array
    {
        if (is_array($payload)) {
            if (isset($payload['files']) && is_array($payload['files'])) {
                return array_values($payload['files']);
            }
            if (array_is_list($payload)) {
                return $payload;
            }
        }

        return [];
    }

    /**
     * Poll $filesPath until every uploaded file reports `completed`.
     *
     * @return list<array<string,mixed>>
     */
    public static function waitForUploads(
        HttpTransport $transport,
        string $filesPath,
        float $pollInterval = 1.0,
        float $timeout = 120.0,
    ): array {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $files = self::extractFiles($transport->request('GET', $filesPath, expected: [200])->json());
            if ($files !== [] && self::allCompleted($files)) {
                return $files;
            }
            if (microtime(true) > $deadline) {
                throw new SnapWondersException("Uploads at {$filesPath} not complete within " . (int) $timeout . 's');
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    /**
     * Poll $statusPath until the job reaches a terminal state; return the final status body.
     *
     * $pollInterval is the initial gap; it backs off (× ~1.6, capped at 15s) with ± jitter so many
     * concurrent clients do not hammer the API in lockstep. A server-sent `retry_after`/`poll_after`
     * overrides the cadence.
     *
     * @return array<string,mixed>
     */
    public static function pollJob(
        HttpTransport $transport,
        string $statusPath,
        float $pollInterval = 1.5,
        float $timeout = 900.0,
    ): array {
        $deadline = microtime(true) + $timeout;
        $interval = $pollInterval;
        while (true) {
            $data = $transport->request('GET', $statusPath, expected: [200])->json();
            $data = is_array($data) ? $data : [];
            if (in_array($data['status'] ?? null, self::TERMINAL_STATES, true)) {
                return $data;
            }
            if (microtime(true) > $deadline) {
                throw new SnapWondersException("Job at {$statusPath} did not finish within " . (int) $timeout . 's');
            }
            [$sleep, $interval] = self::nextWait($interval, $data);
            // Never sleep past the deadline — keeps the timeout honest under a long backoff/hint.
            $remaining = max(0.0, $deadline - microtime(true));
            usleep((int) (min($sleep, $remaining) * 1_000_000));
        }
    }

    /**
     * Throw {@see JobFailedException} on `failed` (and on `partial` when $strict).
     *
     * The human-readable reason lives in `progress_message`, not `error` (which may be null). Prefer
     * `error` (the sanitised field when the API sets it) and fall back to `progress_message`.
     *
     * @param array<string,mixed> $statusBody
     */
    public static function checkTerminal(array $statusBody, string $uid, bool $strict): void
    {
        $status = $statusBody['status'] ?? null;
        if ($status === 'failed' || $status === 'terminated' || ($strict && $status === 'partial')) {
            $reason = $statusBody['error'] ?? $statusBody['progress_message'] ?? null;
            $message = "Job {$uid} ended as {$status}";
            if ($reason) {
                $message .= ": {$reason}";
            }
            throw new JobFailedException(
                $message,
                is_string($reason) ? $reason : null,
                is_string($status) ? $status : null,
            );
        }
    }

    /** @param list<array<string,mixed>> $files */
    private static function allCompleted(array $files): bool
    {
        foreach ($files as $file) {
            if (($file['status'] ?? null) !== 'completed') {
                return false;
            }
        }

        return true;
    }

    private static function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
