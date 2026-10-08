# Publishing Audiolog

Release workflow for Audiolog. Since 1.0.5 the tarball is an asset of the GitHub Release `vX.Y.Z`, and the app store downloads it from the release URL:

```
https://github.com/sintax-tech/nextcloud-audiolog/releases/download/vX.Y.Z/audiolog-X.Y.Z.tar.gz
```

**Why:** the app store only shows a download count for assets of a GitHub Release. Until 1.0.4 the tarball was committed under `dist/` and served from a raw URL, so the store showed "not available". The model is the same used by the MCP for Nextcloud app.

**History:** versions 1.0.0 to 1.0.4 stay in `dist/`. **Never delete those files:** the store keeps their raw URLs. From 1.0.5 on, the tarball is **not** committed to `dist/` anymore.

## Release steps

Replace `X.Y.Z` with the new version.

1. **Bump the version** in `appinfo/info.xml` (`<version>X.Y.Z</version>`) and validate it against the XSD (see below). When the store text changes, update all three languages together. If the supported Nextcloud range changes, update `<nextcloud min-version=... max-version=...>` and the range in `README.md` as well.
2. **Update `CHANGELOG.md` and `README.md`** (a `## X.Y.Z - <date>` section in the changelog; it becomes the release notes). Commit steps 1 and 2:
   ```bash
   git add appinfo/info.xml README.md CHANGELOG.md
   git commit -m "X.Y.Z: <summary>"
   ```
3. **Build the tarball** from the committed `HEAD` (commit steps 1 and 2 first; the build refuses uncommitted changes in shipped paths):
   ```bash
   make build
   # → build/appstore/audiolog-X.Y.Z.tar.gz, then runs make check-package
   ```
   Since 1.0.5 the tarball is made with `git archive` from an explicit list of versioned paths (`appinfo lib templates js css img l10n LICENSE CHANGELOG.md README.md`). Untracked or ignored files (`.remember/`, `.DS_Store`, `._*`, `vendor/`, `node_modules/`, `build/`, `dist/`) cannot get in. Until 1.0.4 the build packed the whole working directory, and 1.0.3 and 1.0.4 shipped the local `.remember/` session notes.

   `make check-package` (run by `make build` and again by `make sign`) runs `tools/check-tarball.py`, which fails unless the tarball has:
   - exactly one top-level directory, `audiolog/`;
   - no `._*` entries and no `.DS_Store`;
   - no `.remember/`, `tests/`, `build/`, `dist/`, `docs/`, `tools/`, `vendor/`, `node_modules/` and no dev files (`Makefile`, `PUBLISHING.md`, `composer.*`, `package*.json`);
   - `<version>X.Y.Z</version>` in the inner `appinfo/info.xml`.

4. **Sign:**
   ```bash
   make sign
   # → rebuilds, re-checks, then build/sign/audiolog-X.Y.Z.sig (base64), also printed on screen
   ```
   It uses `~/.nextcloud/certificates/audiolog.key`. Never copy or print that key. Verify the signature against the certificate:
   ```bash
   openssl x509 -in ~/.nextcloud/certificates/audiolog.crt -pubkey -noout > /tmp/audiolog-pub.pem
   openssl base64 -d -in build/sign/audiolog-X.Y.Z.sig -out /tmp/audiolog.sig.bin
   openssl dgst -sha512 -verify /tmp/audiolog-pub.pem -signature /tmp/audiolog.sig.bin build/appstore/audiolog-X.Y.Z.tar.gz
   ```
   It must print `Verified OK`.
5. **Push to `main`** the commit with the version bump, README and CHANGELOG (no tarball), made before step 3:
   ```bash
   git push origin main
   ```
6. **Tag the release** and push the tag:
   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```
   The tag convention is `vX.Y.Z` (for example `v1.0.5`). `v.1.0.0` was a historical exception.
7. **Create the GitHub Release** with the tarball as the only asset. Do **not** attach the `.sig`. The notes are the `X.Y.Z` section of the CHANGELOG, and they must not be empty:
   ```bash
   make release        # extracts the notes and runs gh release create
   ```
   or by hand:
   ```bash
   awk '/^## X\.Y\.Z /{f=1;next} /^## /{f=0} f' CHANGELOG.md > build/release-notes.md
   test -s build/release-notes.md   # must not be empty
   gh release create vX.Y.Z build/appstore/audiolog-X.Y.Z.tar.gz \
     --title "Audiolog X.Y.Z" --notes-file build/release-notes.md
   ```
8. **Check the release URL.** Download it and compare the sha256 and the signature with the local file:
   ```bash
   curl -sL https://github.com/sintax-tech/nextcloud-audiolog/releases/download/vX.Y.Z/audiolog-X.Y.Z.tar.gz -o /tmp/dl.tar.gz
   shasum -a 256 /tmp/dl.tar.gz build/appstore/audiolog-X.Y.Z.tar.gz
   openssl dgst -sha512 -verify /tmp/audiolog-pub.pem -signature /tmp/audiolog.sig.bin /tmp/dl.tar.gz
   ```
9. **Upload the release on <https://apps.nextcloud.com>:** app page → Releases → Upload new release.
   - **Download URL:** the release URL from step 8.
   - **Signature:** the contents of `build/sign/audiolog-X.Y.Z.sig`.
   - **Nightly / pre-release:** leave unchecked.

## Watch out for macOS `._*` files

Until 1.0.4 the build used the macOS `tar`, which adds AppleDouble files (`._*`) without `COPYFILE_DISABLE=1`. They broke the Nextcloud updater, which tried to handle `apps/._audiolog` as an app directory (fixed in 1.0.2). Since 1.0.5 the build uses `git archive`, which never adds them, and `make check-package` refuses any `._*` entry anyway.

## Validating `info.xml` against the XSD

Before building, check the file against the store schema:

```bash
curl -sL https://apps.nextcloud.com/schema/apps/info.xsd -o /private/tmp/info.xsd
xmllint --noout --schema /private/tmp/info.xsd appinfo/info.xml
```

It must print `validates`. The XSD fixes the element order (for example `<settings>` before `<navigations>`), and this has been respected since 1.0.4. `<summary>` and `<description>` are kept in English (no `lang`, the default), `lang="pt-br"` and `lang="es"`, with the same content; the XSD accepts the lowercase code `pt-br`, not `pt_BR`. `<name>` stays single, without `lang`. If the download answers `429 Too Many Requests`, wait and try again.

## Screenshots

The store requires at least one. They live in `docs/screenshots/` and are referenced by `info.xml` through raw GitHub URLs on `main`:

```
docs/screenshots/01-record.png
docs/screenshots/02-live-running.png
docs/screenshots/03-recordings.png
docs/screenshots/04-live-setup.png
```

## One-time setup (done)

These steps were already done for the first release and are kept for reference.

- **Public repository:** `https://github.com/sintax-tech/nextcloud-audiolog`. The `<repository>`, `<website>` and `<bugs>` tags in `info.xml` point to it.
- **App certificate:** requested through <https://github.com/nextcloud/app-certificate-requests>. The files `~/.nextcloud/certificates/audiolog.crt` (public) and `~/.nextcloud/certificates/audiolog.key` (private, keep it safe) are used by `make sign`.
- **App registration:** the app `audiolog` is registered on <https://apps.nextcloud.com>.

## Troubleshooting

- **"Certificate validation failed"**: the certificate does not match the signature. Rebuild everything (`make clean build sign`), check that you uploaded the file from the release URL, and check the certificate path in the Makefile.
- **"info.xml schema validation failed"**: run the XSD check above; compare against the previous accepted version.
- **"Tarball must contain exactly one directory"**: run `make clean build`; `make check-package` reports what is wrong.
