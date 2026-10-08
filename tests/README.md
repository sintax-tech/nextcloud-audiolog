# Tests

Dev-only. `vendor/` and `node_modules/` are git-ignored and never go into the
app tarball.

## PHP (PHPUnit)

```bash
composer install          # phpunit + nextcloud/ocp (require-dev only)
composer test             # = php -d memory_limit=1G vendor/bin/phpunit -c tests/phpunit.xml
# or: make test-php
```

The suite runs standalone: `vendor/autoload.php` maps `OCA\Audiolog\` to
`lib/` and loads the public `OCP\` interfaces from `nextcloud/ocp` (pinned to
the lowest supported Nextcloud, 28). Classes of other apps the code talks to
(Deck) are minimal stubs in `tests/stubs/`, mirroring the method signatures
of Deck 1.12–1.19.

Inside a Nextcloud checkout without a local `vendor/`, `tests/bootstrap.php`
falls back to the server's own test bootstrap.

## JavaScript (Jest)

```bash
npm install
npm test                  # = npx jest --config tests/jest.config.js
# or: make test-js
```

Frontend tests cover pure helpers that live in their own files under `js/`
(for example `js/audiolog-text-utils.js`), so the tests exercise the shipped
code. DOM-heavy code is exercised manually with the dev console — Jest's
`jsdom` doesn't simulate `MediaRecorder` / `SpeechRecognition` faithfully
enough to test those code paths reliably.
