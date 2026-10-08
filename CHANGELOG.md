# Changelog

All notable changes to Audiolog are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.0.5 - Unreleased

### Security
- Google API keys (Gemini and Cloud Speech-to-Text) are now sent in the `x-goog-api-key` header instead of the `?key=` query string. Before, a provider error (for example a 400 from Gemini) put the full URL, key included, in the exception message, which reached `nextcloud.log` and, for background jobs, the `error` field returned by `/api/status` to the user. Provider error messages are now masked before they reach the log, the job record or the HTTP response. **As a precaution, rotate the Gemini and Google STT API keys** configured in Audiolog, since they may already be in old log files.
- Exporting tasks to Deck no longer writes straight into Deck's database tables. Any user of the app could create cards on any board of the instance by sending another board's list id. Cards are now created through Deck's own services (`CardService`, `StackService`, `BoardService`, `PermissionService`), which apply Deck's sharing rules, activity and notifications; Audiolog also checks that the list belongs to the chosen board and that the user can edit it, and refuses otherwise (HTTP 403). Checked against Deck 1.12 to 1.19 (Nextcloud 28 to 35).

### Fixed
- The Deck board picker lists every board the user can edit, including boards shared through a group or a circle, and no longer lists archived boards or boards the user can only read. Board titles are no longer written to the log.

## 1.0.4 - 2026-10-08

### Changed
- The store description no longer lists Gemini Live as a live transcription option. The live options are the browser's Web Speech API and Google Cloud Speech-to-Text; `gemini-live` is a legacy value that falls back to Web Speech.
- `<summary>` and `<description>` in `appinfo/info.xml` now come in English (default), Portuguese (`pt-br`) and Spanish (`es`), with the same content in the three.

### Fixed
- `appinfo/info.xml` elements are in the order the app store XSD expects (`<settings>` before `<navigations>`), so `xmllint` against `https://apps.nextcloud.com/schema/apps/info.xsd` passes.

## 1.0.3 - 2026-10-08

### Added
- Declares compatibility with Nextcloud 34 and 35 (`min-version="28" max-version="35"`).

### Changed
- `AudioService` now receives `IAppManager` and `IDBConnection` through dependency injection instead of calling `\OC::$server`, which no longer exposes `getAppManager()` and `getDatabaseConnection()` in Nextcloud 34 (this broke the Deck integration).
- User notifications use `OCP.Toast`, with `OC.Notification` kept as a fallback. `OC.Notification` was removed in Nextcloud 34, which would have silenced every notification, errors included.

### Removed
- The `core/dist/files_fileinfo`, `core/dist/files_client` and `files/dist/files-main` script loads in `templates/main.php`. Those bundles do not exist in the official 33.0.9, 34.0.4 and 35.0.1 packages and the app does not use them.

## 1.0.2 - 2026-09-30

### Fixed
- `make build` no longer packs macOS AppleDouble files (`._*`) into the tarball. They broke the Nextcloud updater when installing from the app store. The build now sets `COPYFILE_DISABLE=1` and excludes `._*`.

## 1.0.1 - 2026-09-30

### Added
- `ai` app store category.

### Changed
- `IConfig` is injected into the controllers instead of being fetched through `\OC::$server->getConfig()`.
- The `dist/` directory is excluded from the app tarball.

## 1.0.0 - 2026-04-30

First public release.

### Added
- In-browser audio recording with pause/resume and a size/duration indicator, with auto-recovery through IndexedDB.
- Live transcription through the Web Speech API or Google Cloud Speech-to-Text (server-side proxy).
- Transcription and analysis (summary, minutes, agenda, tasks) with Gemini, OpenAI/Whisper or Ollama.
- Speaker diarization in the post-stop refine pass, with a UI to name speakers.
- Large audio support through the Gemini Files API.
- Recordings stored under `Audiolog/` in the Files app, with Markdown and DOCX export.
- Admin settings page with a healthcheck, API keys encrypted with `OCP\Security\ICrypto`, and per-group access control.
- App store screenshots.

### Fixed
- Admin settings: a stray `%` in the realtime-STT hint crashed the L10N rendering.
- Admin settings: defensive lookups so the save form survives missing fields.
