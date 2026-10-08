# Changelog

All notable changes to Audiolog are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.0.5 - Unreleased

### Security
- Google API keys (Gemini and Cloud Speech-to-Text) are now sent in the `x-goog-api-key` header instead of the `?key=` query string. Before, a provider error (for example a 400 from Gemini) put the full URL, key included, in the exception message, which reached `nextcloud.log` and, for background jobs, the `error` field returned by `/api/status` to the user. Provider error messages are now masked before they reach the log, the job record or the HTTP response. **As a precaution, rotate the Gemini and Google STT API keys** configured in Audiolog, since they may already be in old log files.
- Exporting tasks to Deck no longer writes straight into Deck's database tables. Any user of the app could create cards on any board of the instance by sending another board's list id. Cards are now created through Deck's own services (`CardService`, `StackService`, `BoardService`, `PermissionService`), which apply Deck's sharing rules, activity and notifications; Audiolog also checks that the list belongs to the chosen board and that the user can edit it, and refuses otherwise (HTTP 403). Checked against Deck 1.12 to 1.19 (Nextcloud 28 to 35). Deleted lists and archived boards are refused with a clear message, and if Deck fails in the middle of an export the answer lists the cards already created, so a retry does not duplicate them.
- The live transcription proxy (`/api/stt/recognize`), which spends the admin's Google key, is now limited to 30 requests per minute per user through Nextcloud's rate limiter (a normal session sends about 12).
- `ffmpeg` and `ffprobe` now run with `-protocol_whitelist file,pipe` and `-format_whitelist` limited to media demuxers, and files whose content is not audio or video (checked on the bytes) or is a playlist or manifest (`#EXTM3U`, `ffconcat`, XML such as a DASH `<MPD>`) are refused even when named like audio or video. Before, a manifest saved as `.mp4` could point ffmpeg to `file://` paths of other users' recordings. Before, a playlist saved as `.mp4` in Nextcloud could make older ffmpeg versions (for example the one in Debian 12) fetch URLs of the internal network from the server. Every ffmpeg call goes through `proc_open` with an argument list, without a shell. Their error output goes to a private temporary file instead of a pipe, so a corrupted file that makes ffmpeg print an error per frame can no longer leave the request or the background job stuck, and every call has a time limit after which the process is killed.

### Changed
- The store description (English, Portuguese and Spanish) no longer says that live transcription with an external API key is restricted to admins: that option never existed. It now describes the server-side proxy and the per-user limit. The unused `realtime_stt_admin_only` setting is removed from the stored configuration on the next save of the admin settings.

- The page no longer adds Gemini, OpenAI, Google Speech or the Ollama URL to its Content Security Policy: the browser never calls a provider directly (everything goes through Audiolog's own endpoints), and the internal Ollama address no longer appears in a response header.

- Interface translations: about 85 strings that showed in Portuguese for English users and 170 for Spanish users are now translated, the admin page script uses `t()` for every text, and dates follow the user's locale. Every string in use exists in English, Portuguese and Spanish (checked by a test).
- The live transcription refine pass no longer sends a prompt fixed in Brazilian Portuguese from the browser; the server builds it in the language the admin configured, so English and Spanish users get the refined transcript in their language.

### Removed
- Dead code: the Gemini Live WebSocket client in the page script (about 230 lines that nothing called; `gemini-live` already fell back to Web Speech), the unrouted `ncFile` endpoint method, `getTaskStatus`, `findStaleRunning`, and the unused `realtime_stt_model` setting (removed from the stored configuration on the next save).

### Fixed
- Users outside the allowed groups now get a translated "Access denied" page (HTTP 403) instead of the app interface, whose actions all failed.
- "Test connection" and the healthcheck now use Nextcloud's HTTP client, like the processing itself. Before, they used raw cURL and reported "OK" for a local Ollama that the processing could not reach, because Nextcloud blocks local addresses unless `allow_local_remote_servers` is enabled. The blocked case now shows that setting, and the README documents it. This also removes `curl_close()`, deprecated in PHP 8.5.
- Temporary copies of the audio (video extraction, duration probe, long-audio chunks, DOCX export, background-job uploads) are created through Nextcloud's `ITempManager` with `0600` permissions and deleted in every case. Before, they stayed in `/tmp` whenever the provider failed.
- Long Google Speech-to-Text transcriptions poll the operation on `v1p1beta1`, the API version that created it (it used `v1`).
- Task extraction (for Deck export) only reads the task table the prompt asks for, or a bullet list under a tasks heading. Before, any three-column table of the minutes (participants, for example) became tasks, and bullet tasks such as "- Send report - Owner: Ana" were dropped.
- The admin's "maximum file size" setting is now enforced (it was saved but never checked) when audio is processed, for uploads and for files picked from Nextcloud (HTTP 413). It does not apply to saving the user's own recording, so long meetings are always stored.
- When saving a recording fails, the page shows the server's reason and keeps the audio in the browser (IndexedDB) for the recovery prompt. Before, the live transcription flow dropped the audio and kept only the fast transcript, and the recorder's local copy was not offered by the recovery prompt. Uploaded files must be audio or video according to their content, not only to the type the browser declares (HTTP 415).
- The Deck board picker lists every board the user can edit, including boards shared through a group or a circle, and no longer lists archived boards or boards the user can only read. Board titles are no longer written to the log.
- Live transcription with Google Cloud Speech-to-Text never started ("Invalid configuration"): the page still expected the API key in the browser after the key moved to the server-side proxy.
- The app tarball is now built with `git archive` from an explicit list of versioned paths and checked before signing. The 1.0.3 and 1.0.4 tarballs also contained the developer's local `.remember/` session notes (no credentials).
- Renaming a recording saved with a title (stored in `Audiolog/<title>/`) failed with "File not found", or renamed a file with the same name at the `Audiolog/` root. Notes and DOCX saved for those recordings were not linked to them either, so "delete with outputs" left them behind. Each saved note or document is now linked separately, instead of replacing the previous one.

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
