# Tests

## PHP (PHPUnit)

```bash
cd <nextcloud-root>/apps/audiolog/tests
phpunit -c phpunit.xml
```

Tests assume the app is installed under a Nextcloud checkout (so the bootstrap
can pull `tests/bootstrap.php` from Nextcloud core). For a standalone dev
loop use [`nextcloud/server`](https://github.com/nextcloud/server)'s test
container.

## JavaScript (Jest)

```bash
cd tests
npx jest --config jest.config.js
```

Frontend tests cover pure helpers (dedup, IDB plumbing, format helpers).
DOM-heavy code is exercised manually with the dev console — Jest's `jsdom`
doesn't simulate `MediaRecorder` / `SpeechRecognition` faithfully enough to
test those code paths reliably.
