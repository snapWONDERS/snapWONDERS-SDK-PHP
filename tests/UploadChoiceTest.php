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

namespace SnapWonders\Tests;

use PHPUnit\Framework\TestCase;
use SnapWonders\Exception\TusUploadException;
use SnapWonders\Exception\UploadException;
use SnapWonders\Http\HttpTransport;
use SnapWonders\Http\Response;
use SnapWonders\Upload\DirectUploader;
use SnapWonders\Upload\UploadRouter;

/**
 * Which upload method runs, and why. Offline — the transport is a stub, nothing leaves the machine.
 *
 * The choice is invisible to callers, which is the point of the router but also means a wrong
 * choice fails somewhere far from its cause: a large file sent direct is rejected by the server,
 * and a small file sent over TUS just quietly costs three round trips instead of one. These pin
 * the boundary so neither can regress unnoticed.
 */
final class UploadChoiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tempFiles = [];
    }

    private function tempFile(int $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sw_upload_');
        self::assertIsString($path);
        file_put_contents($path, str_repeat("\0", $bytes));
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Records the request the router made without performing one. */
    private function stubTransport(?string $storageUid = 'stg_abc123'): HttpTransport
    {
        return new class ($storageUid) extends HttpTransport {
            /** @var list<array{method:string,path:string,headers:array<string,string>}> */
            public array $calls = [];

            public function __construct(private readonly ?string $storageUid)
            {
                parent::__construct();
            }

            public function request(
                string $method,
                string $path,
                mixed $json = null,
                array $headers = [],
                ?string $content = null,
                array $expected = [200, 201],
            ): Response {
                $this->calls[] = ['method' => $method, 'path' => $path, 'headers' => $headers];
                $body = $this->storageUid === null
                    ? '{}'
                    : json_encode(['file' => ['storage_uid' => $this->storageUid]], JSON_THROW_ON_ERROR);

                return new Response(200, [], $body);
            }
        };
    }

    public function testASmallFileGoesDirectInOneRequest(): void
    {
        $t = $this->stubTransport();
        $out = UploadRouter::uploadFile($t, $this->tempFile(1024), 'upl_1', 1);

        self::assertSame('stg_abc123', $out);
        self::assertCount(1, $t->calls, 'direct upload is a single request');
        self::assertSame('POST', $t->calls[0]['method']);
        self::assertSame('/api/upload', $t->calls[0]['path']);
    }

    public function testTheServersCapWinsOverTheBuiltInDefault(): void
    {
        // 4 KB file, but the server says the cap is 1 KB — must not go direct.
        $t = $this->stubTransport();

        try {
            UploadRouter::uploadFile($t, $this->tempFile(4096), 'upl_1', 1, 1024);
        } catch (\Throwable) {
            // Falls through to TUS, which the stub cannot satisfy. The point is that it did not
            // take the direct path, which the stub would have answered happily.
        }

        foreach ($t->calls as $call) {
            self::assertNotSame('/api/upload', $call['path'], 'over the cap, direct must not be attempted');
        }
    }

    public function testExactlyAtTheCapStillGoesDirect(): void
    {
        $t = $this->stubTransport();
        UploadRouter::uploadFile($t, $this->tempFile(2048), 'upl_1', 1, 2048);

        self::assertSame('/api/upload', $t->calls[0]['path'], 'the boundary is inclusive');
    }

    public function testDirectUploadSendsTheHeadersTheEndpointRequires(): void
    {
        $t = $this->stubTransport();
        UploadRouter::uploadFile($t, $this->tempFile(16), 'upl_xyz', 2);
        $h = $t->calls[0]['headers'];

        // X-Upload-Uid is required — without it the endpoint answers 404 unknown_session.
        self::assertSame('upl_xyz', $h['X-Upload-Uid']);
        self::assertSame('2', $h['X-Upload-Step']);
        // Idempotency: a retry after a lost response must not store the file twice.
        self::assertNotEmpty($h['X-Client-Upload-Id'] ?? '');
    }

    public function testAResponseWithoutStorageUidIsAnErrorNotASilentSuccess(): void
    {
        $t = $this->stubTransport(null);

        $this->expectException(UploadException::class);
        UploadRouter::uploadFile($t, $this->tempFile(16), 'upl_1', 1);
    }

    public function testAMissingFileFailsBeforeAnyRequestIsMade(): void
    {
        $t = $this->stubTransport();

        try {
            UploadRouter::uploadFile($t, '/definitely/not/here.bin', 'upl_1', 1);
            self::fail('expected UploadException');
        } catch (UploadException) {
            self::assertCount(0, $t->calls);
        }
    }

    public function testTusUploadExceptionIsCatchableAsUploadException(): void
    {
        // Both paths are chosen for the caller by file size, so catching only the TUS-specific
        // exception would mean catching one that fires for some file sizes and not others.
        self::assertInstanceOf(UploadException::class, new TusUploadException('x'));
    }

    public function testTheBuiltInCapMatchesTheServersDocumentedFallback(): void
    {
        self::assertSame(99614720, DirectUploader::DEFAULT_MAX_BYTES);
    }
}
