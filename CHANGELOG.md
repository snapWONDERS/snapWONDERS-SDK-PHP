# Changelog

All notable changes to the `snapwonders/sdk` PHP client are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.5] — 2026-11-03

### Security
- **Path traversal in `download($dir)` fixed (CWE-22).** When downloading into a directory, the
  server-supplied asset name was joined to the path unchanged, so a name containing `../` or `..\`
  could write a file outside the chosen directory. The name is now reduced to its final component
  (`ResultFile::safeName()`): `/`, `\` and `:` are all treated as separators, and `.`, `..` or an empty name
  becomes `download`. Downloading to an explicit file path is unchanged. **Upgrade recommended.**
  Reported under our Vulnerability Disclosure Policy.

## [0.1.4] — 2026-10-23

### Fixed
- **`terminated` is now a terminal job state.** Waiting on such a job stops and raises the
  job-failed error, the same as `failed`, instead of polling until the timeout.

## [0.1.1] — 2026-08-16

### Added
- **Direct upload.** Files at or under the server's cap (95 MB by default) now go up in a single
  `POST /api/upload` instead of the TUS create/PATCH sequence — one round trip instead of three,
  which matters most over Tor and I2P.
- `UploadException`, a protocol-neutral base that `TusUploadException` now extends. Both upload
  paths throw it, so a caller no longer has to know which one ran to catch a failure.

### Changed
- `AnalyseSession`, `ConvertSession` and `StegoSession` now carry `$maxUploadBytes`, read from the
  session-create response, so the cap can move server-side without an SDK release.
- `$session->upload()` returns the server's `storage_uid` for direct uploads and the TUS path for
  large ones. Previously it was always the TUS path. Neither value is needed for the normal flow —
  uploads are confirmed with `files()` and jobs are keyed by `$uploadUid`.

### Unchanged
- `TusUploader` is untouched. TUS remains the path for files over the cap, and is still what
  handles resumption on an unreliable connection.

## [0.1.0] — 2026

Initial release.

- Official PHP client for the snapWONDERS API, covering all three product areas:
  `$client->stego` (hide & reveal), `$client->analyse` (forensic media analysis), and
  `$client->convert` (media conversion).
- Resumable upload and the session → job → poll → download flow wrapped internally, so a whole job
  is a single call (e.g. `$client->stego->hide([...], password: '...')`). One-shot helpers plus
  step-by-step session/job control.
- Polling backs off with jitter and honours a server-supplied poll interval, to stay light under load.
- Typed exceptions (`SnapWondersException` base): `AuthException`, `ProRequiredException`,
  `SessionExpiredException`, `RateLimitException`, `MaintenanceException`, `JobFailedException`,
  `TusUploadException`, `NetworkException`, `ApiException`.
- Zero third-party runtime dependencies — uses only the bundled `ext-curl` and `ext-json`.
