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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SnapWonders\Analyse\Analyse;
use SnapWonders\Analyse\AnalyseJob;
use SnapWonders\Client;
use SnapWonders\Convert\Convert;
use SnapWonders\Convert\ConvertJob;
use SnapWonders\Exception\JobFailedException;
use SnapWonders\Http\HttpTransport;
use SnapWonders\Http\Response;
use SnapWonders\Internal\Poller;
use SnapWonders\Model\AnalyseItem;
use SnapWonders\Model\ResultFile;
use SnapWonders\Stego\Stego;
use SnapWonders\Stego\StegoJob;
use SnapWonders\Tus\TusUploader;

/**
 * Offline smoke tests — the pure logic that needs no live API or key.
 *
 * Integration tests that hit the live API are run separately with a real key. Run with
 * `composer test` from the package root.
 */
final class SmokeTest extends TestCase
{
    public function testVersionPresent(): void
    {
        $this->assertStringStartsWith('0.', Client::VERSION);
    }

    public function testBuildMetadataIncludesAllFields(): void
    {
        // upload_uid <b64>,step <b64>,name <b64>,client_upload_id <b64>  — API.md §4.
        $meta = TusUploader::buildMetadata([
            'upload_uid' => 'u',
            'step' => '1',
            'name' => 'a.jpg',
            'client_upload_id' => 'cid',
        ]);
        $pairs = [];
        foreach (explode(',', $meta) as $part) {
            [$key, $value] = explode(' ', $part);
            $pairs[$key] = $value;
        }
        $this->assertSame(['upload_uid', 'step', 'name', 'client_upload_id'], array_keys($pairs));
        $this->assertSame('a.jpg', base64_decode($pairs['name']));
        $this->assertSame('cid', base64_decode($pairs['client_upload_id']));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function toRelativeCases(): array
    {
        return [
            ['https://snapwonders.com/api/tus/abc', '/api/tus/abc'],
            ['/api/tus/abc', '/api/tus/abc'],
            ['api/tus/abc', '/api/tus/abc'],
            ['https://other.host/api/tus/abc', '/api/tus/abc'],
        ];
    }

    #[DataProvider('toRelativeCases')]
    public function testToRelative(string $location, string $expected): void
    {
        $this->assertSame($expected, TusUploader::toRelative($location, 'https://snapwonders.com'));
    }

    public function testClientDefaultsToCanonicalBase(): void
    {
        $client = new Client('sw_test');
        $this->assertSame('https://snapwonders.com', $client->transport()->baseUrl());
    }

    public function testHideRequiresCoverAndSecret(): void
    {
        $client = new Client('sw_test');
        $this->expectException(\InvalidArgumentException::class);
        $client->stego->hide(['only-one-file.jpg'], password: 'Str0ng!Pass');
    }

    public function testAllThreeNamespacesPresent(): void
    {
        $client = new Client('sw_test');
        $this->assertInstanceOf(Stego::class, $client->stego);
        $this->assertInstanceOf(Analyse::class, $client->analyse);
        $this->assertInstanceOf(Convert::class, $client->convert);
    }

    public function testAnalyseRequiresFiles(): void
    {
        $client = new Client('sw_test');
        $this->expectException(\InvalidArgumentException::class);
        $client->analyse->run([]);
    }

    public function testConvertRequiresFiles(): void
    {
        $client = new Client('sw_test');
        $this->expectException(\InvalidArgumentException::class);
        $client->convert->run([]);
    }

    public function testCreateSessionRejectsBadType(): void
    {
        $client = new Client('sw_test');
        $this->expectException(\InvalidArgumentException::class);
        $client->stego->createSession('nonsense');
    }

    public function testExtractFilesNormalises(): void
    {
        $this->assertSame([['status' => 'completed']], Poller::extractFiles(['files' => [['status' => 'completed']]]));
        $this->assertSame([['status' => 'x']], Poller::extractFiles([['status' => 'x']]));
        $this->assertSame([], Poller::extractFiles('garbage'));
        $this->assertSame(['completed', 'partial', 'failed', 'terminated'], Poller::TERMINAL_STATES);
    }

    public function testConvertResultPrefersOutputName(): void
    {
        // Convert entries carry original in `name`, converted in `output_name` — download the converted.
        $rf = ResultFile::fromJson(
            ['asset_id' => 'a1', 'name' => 'photo.jpg', 'output_name' => 'photo.webp', 'size_bytes' => 12],
            self::recordingTransport(),
            '/api/convert/download',
        );
        $this->assertSame('photo.webp', $rf->name);
        $this->assertSame(12, $rf->fileSize);
    }

    public function testAnalyseAssetUsesCategory(): void
    {
        $item = AnalyseItem::fromJson(
            ['name' => 'p.jpg', 'grade' => 'B', 'assets' => [
                ['asset_id' => 'x1', 'category' => 'ela_map', 'mime_type' => 'image/png', 'file_size' => 5],
            ]],
            self::recordingTransport(),
        );
        $asset = $item->assets[0];
        $this->assertSame('ela_map', $asset->name);        // category, not the bare UID
        $this->assertSame('image/png', $asset->mimeType);  // passed through
        $this->assertSame(5, $asset->fileSize);
    }

    public function testCheckTerminalSurfacesProgressMessageReason(): void
    {
        // The failure reason lives in `progress_message`, not `error` (which is null).
        $body = ['status' => 'failed', 'progress_message' => 'This job requires a Pro account.', 'error' => null];
        try {
            Poller::checkTerminal($body, 'x', false);
            $this->fail('expected JobFailedException');
        } catch (JobFailedException $e) {
            $this->assertStringContainsString('Pro account', $e->getMessage());
            $this->assertSame('This job requires a Pro account.', $e->error);
        }
    }

    public function testCheckTerminalErrorFieldWins(): void
    {
        $body = ['status' => 'failed', 'error' => 'sanitised detail', 'progress_message' => 'generic'];
        try {
            Poller::checkTerminal($body, 'x', false);
            $this->fail('expected JobFailedException');
        } catch (JobFailedException $e) {
            $this->assertSame('sanitised detail', $e->error);
        }
    }

    public function testNextWaitRespectsServerHint(): void
    {
        // A server-sent retry_after/poll_after overrides the client cadence (central throttle).
        [$sleep, $next] = Poller::nextWait(2.0, ['status' => 'processing', 'retry_after' => 30]);
        $this->assertSame(30.0, $sleep);
        $this->assertSame(30.0, $next);
        [$sleep2] = Poller::nextWait(2.0, ['status' => 'processing', 'poll_after' => '12']);
        $this->assertSame(12.0, $sleep2);
    }

    public function testNextWaitBacksOffAndCaps(): void
    {
        $interval = 1.5;
        $seen = [];
        for ($i = 0; $i < 12; $i++) {
            [$sleep, $interval] = Poller::nextWait($interval, ['status' => 'processing']);
            $seen[] = $sleep;
        }
        $this->assertGreaterThan($seen[0], $seen[array_key_last($seen)]);   // grows overall
        $this->assertLessThanOrEqual(15.0 * 1.25 + 0.01, max($seen));        // capped (allow +jitter)
    }

    public function testDownloadToNonexistentDirectoryCreatesDirNotFile(): void
    {
        // `download("out/")` must create `out/<name>`, not a FILE named `out`.
        $rf = new ResultFile(
            'abc',
            'cover-share.avif',
            'image/avif',
            11,
            self::cannedBytesTransport("\x89PNG\r\n\x1a\n-fake"),
            '/api/job/download/abc',
        );
        $dir = sys_get_temp_dir() . '/sw_php_test_' . bin2hex(random_bytes(4));
        $this->assertDirectoryDoesNotExist($dir);

        $written = $rf->download($dir . '/');

        $this->assertDirectoryExists($dir);
        $this->assertSame($dir . \DIRECTORY_SEPARATOR . 'cover-share.avif', $written);
        $this->assertSame("\x89PNG\r\n\x1a\n-fake", file_get_contents($written));

        @unlink($written);
        @rmdir($dir);
    }

    /**
     * VDP report 2026-11-03 (CWE-22): a server-supplied `name` containing `../`, `..\` or a drive
     * prefix must not let download(dir) write outside dir. Every hostile name must land as a single
     * file directly inside dir, and nothing may appear in the sandbox outside dir.
     */
    public function testDownloadCannotEscapeDestinationDirectory(): void
    {
        $sandbox = sys_get_temp_dir() . '/sw_php_trav_' . bin2hex(random_bytes(4));
        $dir     = $sandbox . '/inbox';
        mkdir($dir, 0o777, true);

        foreach (self::HOSTILE_NAMES as $hostile) {
            $rf = new ResultFile('a1', $hostile, null, 4, self::cannedBytesTransport('evil'), '/api/job/download/a1');
            $written = $rf->download($dir . '/');

            $this->assertSame(realpath($dir), realpath(\dirname($written)), "name {$hostile} escaped to {$written}");
        }
        $this->assertSame(['inbox'], array_values(array_diff(scandir($sandbox), ['.', '..'])), 'a file was written outside inbox/');

        foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
        @rmdir($sandbox);
    }

    public function testSafeNameKeepsOnlyTheFinalComponent(): void
    {
        $this->assertSame('escape.txt', ResultFile::safeName('../escape.txt'));
        $this->assertSame('escape.txt', ResultFile::safeName('..\\..\\escape.txt'));
        $this->assertSame('escape.txt', ResultFile::safeName('C:escape.txt'));
        $this->assertSame('download', ResultFile::safeName('..'));
        $this->assertSame('download', ResultFile::safeName(''));
        $this->assertSame('photo.jpg', ResultFile::safeName('photo.jpg'));
    }

    /** Names a malicious sender (or response) could put in `name`. Shared shape across all four SDK tests. */
    private const HOSTILE_NAMES = [
        '../escape-1.txt',
        '../../escape-2.txt',
        '..\\escape-3.txt',
        '..\\..\\escape-4.txt',
        'sub/../../escape-5.txt',
        '/tmp/escape-6.txt',
        'C:escape-7.txt',
        '..',
        '',
    ];

    public function testStegoPollsStatusByUploadUidNotJobUid(): void
    {
        $t = self::recordingTransport(['status' => 'completed'], ['result_files' => []]);
        $job = new StegoJob($t, 'UPLOAD-111', 'JOB-999', 'hide');
        $job->wait(pollInterval: 0.0, timeout: 5.0);
        $job->results();
        $this->assertContains('/api/job/UPLOAD-111', $t->paths);
        foreach ($t->paths as $path) {
            $this->assertStringNotContainsString('JOB-999', $path);
        }
    }

    public function testAnalysePollsByUploadUidButResultsByJobUid(): void
    {
        $t = self::recordingTransport(['status' => 'completed'], ['files' => []]);
        $job = new AnalyseJob($t, 'UPLOAD-222', 'JOB-888');
        $job->wait(pollInterval: 0.0, timeout: 5.0);
        $job->results();
        $this->assertContains('/api/analyse/job/UPLOAD-222', $t->paths);   // status → upload_uid
        $this->assertContains('/api/analyse/result/JOB-888', $t->paths);   // result → job_uid
        $this->assertNotContains('/api/analyse/job/JOB-888', $t->paths);   // never poll by job_uid
    }

    public function testConvertPollsAndFetchesByUploadUid(): void
    {
        $t = self::recordingTransport(['status' => 'completed'], ['result_files' => []]);
        $job = new ConvertJob($t, 'UPLOAD-333', 'JOB-777');
        $job->wait(pollInterval: 0.0, timeout: 5.0);
        $job->results();
        $this->assertContains('/api/convert/job/UPLOAD-333', $t->paths);
        $this->assertContains('/api/convert/job/UPLOAD-333/results', $t->paths);
        foreach ($t->paths as $path) {
            $this->assertStringNotContainsString('JOB-777', $path);
        }
    }

    /**
     * A transport that records request paths and returns canned status/result JSON — no network.
     *
     * @param array<string,mixed> $statusBody
     * @param array<string,mixed> $resultBody
     */
    private static function recordingTransport(
        array $statusBody = ['status' => 'completed'],
        array $resultBody = ['result_files' => [], 'files' => []],
    ): HttpTransport {
        return new class ('sw_x', $statusBody, $resultBody) extends HttpTransport {
            /** @var list<string> */
            public array $paths = [];

            /**
             * @param array<string,mixed> $statusBody
             * @param array<string,mixed> $resultBody
             */
            public function __construct(string $key, private array $statusBody, private array $resultBody)
            {
                parent::__construct($key);
            }

            public function request(
                string $method,
                string $path,
                mixed $json = null,
                array $headers = [],
                ?string $content = null,
                array $expected = [200, 201],
            ): Response {
                $this->paths[] = $path;
                $body = (str_ends_with($path, '/results') || str_contains($path, '/result/'))
                    ? $this->resultBody
                    : $this->statusBody;

                return new Response(200, [], json_encode($body, \JSON_THROW_ON_ERROR));
            }
        };
    }

    /** A transport whose every request returns fixed bytes — for download tests. */
    private static function cannedBytesTransport(string $bytes): HttpTransport
    {
        return new class ('sw_x', $bytes) extends HttpTransport {
            public function __construct(string $key, private string $bytes)
            {
                parent::__construct($key);
            }

            public function request(
                string $method,
                string $path,
                mixed $json = null,
                array $headers = [],
                ?string $content = null,
                array $expected = [200, 201],
            ): Response {
                return new Response(200, [], $this->bytes);
            }
        };
    }
}
