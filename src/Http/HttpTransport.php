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

namespace SnapWonders\Http;

use SnapWonders\Exception\ApiException;
use SnapWonders\Exception\AuthException;
use SnapWonders\Exception\MaintenanceException;
use SnapWonders\Exception\NetworkException;
use SnapWonders\Exception\ProRequiredException;
use SnapWonders\Exception\RateLimitException;
use SnapWonders\Exception\SessionExpiredException;

/**
 * Low-level HTTP transport: auth header, retries, and error mapping.
 *
 * The single place the SDK talks to the network (via ext-curl — no third-party dependency).
 * Everything else composes calls through this class. Deliberately thin: no endpoint knowledge lives
 * here. Non-2xx responses are mapped to typed exceptions in {@see raiseForResponse()}.
 *
 * Not final so tests can subclass it and stub {@see request()} without touching the network.
 */
class HttpTransport
{
    public const DEFAULT_BASE_URL = 'https://snapwonders.com';

    /** @var list<int> */
    private const RETRY_STATUSES = [500, 502, 503, 504];
    private const MAX_RETRIES = 2;      // retries after the first try — 3 attempts total
    private const BACKOFF_CAP_S = 30.0;

    private readonly string $baseUrl;

    public function __construct(
        private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 30.0,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Send a request, retrying transient 5xx (not maintenance) with capped exponential backoff.
     *
     * $path is relative to the base URL (e.g. "/api/status"). Raises a typed exception on any
     * non-$expected status.
     *
     * @param array<string,string> $headers
     * @param list<int>            $expected
     */
    public function request(
        string $method,
        string $path,
        mixed $json = null,
        array $headers = [],
        ?string $content = null,
        array $expected = [200, 201],
    ): Response {
        $body = $content;
        if ($json !== null) {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->apiKey !== null) {
            $headers['X-Api-Key'] = $this->apiKey;
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            [$response, $networkError] = $this->send($method, $this->baseUrl . $path, $headers, $body);

            if ($networkError !== null) {
                // Connection refused / DNS / timeout — retry, then surface as a typed error (never
                // let a raw transport failure escape the SDK's own exception hierarchy).
                if ($attempt <= self::MAX_RETRIES) {
                    $this->sleepBackoff($attempt);
                    continue;
                }
                throw new NetworkException("Network error contacting {$this->baseUrl}: {$networkError}");
            }

            // A maintenance 503 is deliberate, not transient — the server asks for ~300s. Burning the
            // retry budget over a few seconds cannot help, so surface it immediately.
            if (
                in_array($response->statusCode, self::RETRY_STATUSES, true)
                && $attempt <= self::MAX_RETRIES
                && !$this->isMaintenance($response)
            ) {
                $this->sleepBackoff($attempt);
                continue;
            }

            if (!in_array($response->statusCode, $expected, true)) {
                $this->raiseForResponse($response);
            }

            return $response;
        }
    }

    /**
     * Perform a single cURL request.
     *
     * @param array<string,string> $headers
     * @return array{0: ?Response, 1: ?string} [response, networkErrorMessage]
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init();
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->timeout),
            // No hard total cap — large uploads/downloads must not be cut off. Guard against a hung
            // connection with a low-speed stall detector instead.
            CURLOPT_TIMEOUT => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => max(30, (int) ceil($this->timeout)),
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // No curl_close(): since PHP 8.0 the handle is a CurlHandle object, freed automatically when
        // $ch leaves scope, and curl_close() is a deprecated no-op.
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);

            return [null, $error !== '' ? $error : 'unknown transport error'];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [new Response($status, $responseHeaders, is_string($raw) ? $raw : ''), null];
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private function sleepBackoff(int $attempt): void
    {
        $seconds = min(self::BACKOFF_CAP_S, 2.0 ** ($attempt - 1));
        usleep((int) ($seconds * 1_000_000));
    }

    private function isMaintenance(Response $response): bool
    {
        if ($response->statusCode !== 503) {
            return false;
        }
        $body = $response->jsonOrNull();

        return is_array($body) && ($body['status'] ?? null) === 'MAINTENANCE';
    }

    /** Map a non-2xx response to the correct typed exception. */
    private function raiseForResponse(Response $response): never
    {
        $code = $response->statusCode;
        $body = $response->jsonOrNull() ?? $response->body;
        $message = $this->extractMessage($body) ?? "HTTP {$code}";

        if ($this->isMaintenance($response)) {
            $retryAfter = $response->header('Retry-After');
            $wait = $retryAfter !== null ? " Retry after {$retryAfter}s." : ' Try again shortly.';
            throw new MaintenanceException(
                'snapWONDERS is temporarily unavailable for maintenance.' . $wait
                . ' Your request was not processed and nothing is wrong with it.',
                $retryAfter !== null ? (float) $retryAfter : null,
            );
        }
        if ($code === 401 || $code === 403) {
            throw new AuthException($message);
        }
        if ($code === 402) {
            throw new ProRequiredException($message);
        }
        if ($code === 410) {
            throw new SessionExpiredException($message);
        }
        if ($code === 429) {
            $retryAfter = $response->header('Retry-After');
            throw new RateLimitException($message, $retryAfter !== null ? (float) $retryAfter : null);
        }

        throw new ApiException($message, $code, $body);
    }

    private function extractMessage(mixed $body): ?string
    {
        if (is_array($body)) {
            $message = $body['message'] ?? $body['error'] ?? null;

            return is_string($message) && $message !== '' ? $message : null;
        }
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }
}
