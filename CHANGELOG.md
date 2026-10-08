# Changelog

All notable changes to Audiolog are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
