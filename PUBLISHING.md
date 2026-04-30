# Publishing Audiolog on the Nextcloud App Store

End-to-end checklist for the first release. Follow once; subsequent releases just need `make build && upload + sign`.

## 1. One-time setup

### Create a public git repository

The store expects a public git repo. The `<repository>`, `<website>` and `<bugs>` URLs in `appinfo/info.xml` already point at:

```
https://github.com/sintax-tech/nextcloud-audiolog
```

If you use a different host/org, update those three tags in `appinfo/info.xml` and the install URL in `README.md` before pushing.

```bash
cd /path/to/audiolog
git init
git add .
git commit -m "Initial public release — Audiolog 1.0.0"
git branch -M main
git remote add origin git@github.com:sintax-tech/nextcloud-audiolog.git
git push -u origin main
```

### Request an app certificate

The Nextcloud team signs your app's first tarball, then you sign every subsequent release with that key. Open an issue at <https://github.com/nextcloud/app-certificate-requests> and they'll mail back two files:

- `~/.nextcloud/certificates/audiolog.crt` (public)
- `~/.nextcloud/certificates/audiolog.key` (private — keep this safe)

### Create the screenshots

The store requires at least one. Place under `docs/screenshots/`:

```
docs/screenshots/01-record.png    ← Nova Gravação (main page)
docs/screenshots/02-live.png      ← Live transcription in progress
docs/screenshots/03-result.png    ← Result with diarization + tasks
docs/screenshots/04-admin.png     ← Admin settings
```

PNG, 16:9, ≥ 1200px wide. The URLs in `info.xml` resolve to these once you push to `main`.

### Register on the store

1. Go to <https://apps.nextcloud.com>
2. Sign in with your Nextcloud forum account
3. Top-right menu → "Register new app"
4. App ID: `audiolog`

## 2. Build a release

```bash
make build
# → build/appstore/audiolog-1.0.0.tar.gz
```

The tarball must:
- contain exactly one top-level directory named `audiolog/`
- include `appinfo/info.xml` with the matching `<version>`
- exclude dev artefacts (handled by the Makefile's `--exclude` rules)

## 3. Sign and submit

```bash
make sign
# Prints the base64 signature
```

Then on apps.nextcloud.com:

1. Open the app page → "Releases" → "Upload new release"
2. **Download URL**: a stable HTTPS URL where the tarball lives. Two common choices:
   - GitHub release assets: `https://github.com/sintax-tech/nextcloud-audiolog/releases/download/v.1.0.0/audiolog-1.0.0.tar.gz`
   - Your own server: `https://nextcloud-audiolog.sintax.tech/audiolog-1.0.0.tar.gz`
3. **Signature**: paste the output of `make sign`
4. **Nightly / pre-release**: leave unchecked for `1.0.0`

The store fetches the URL, verifies the signature against your registered certificate, and runs an automated lint pass. Approval is usually within a few hours.

## 4. After approval

Anyone can now install with:

```
occ app:install audiolog
```

or via the Nextcloud admin UI → Apps → search for "Audiolog".

## Subsequent releases

1. Bump `<version>` in `appinfo/info.xml`
2. Tag the release: `git tag v1.0.1 && git push --tags`
3. Create a GitHub release, attach `make build` output as the asset
4. `make sign` and paste signature into the app store form

## Troubleshooting

- **"Certificate validation failed"** — your `audiolog.crt` doesn't match the signature. Rebuild from scratch (`make clean build sign`) and verify the certificate path in the Makefile.
- **"info.xml schema validation failed"** — run `xmllint --schema https://apps.nextcloud.com/schema/apps/info.xsd appinfo/info.xml`.
- **"Tarball must contain exactly one directory"** — if the build/ output looks wrong, `make clean` and rebuild.
