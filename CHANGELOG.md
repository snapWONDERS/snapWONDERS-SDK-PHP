# Changelog

All notable changes to the `snapwonders/sdk` PHP client are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
