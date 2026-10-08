# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Makefile for the Audiolog Nextcloud app.
#
# Targets:
#   make build      → produce build/appstore/audiolog-X.Y.Z.tar.gz from the
#                     committed HEAD (git archive of an explicit path list)
#   make sign       → sign the tarball with the app's certificate (private
#                     key in ~/.nextcloud/certificates/audiolog.key)
#   make release    → create the GitHub Release v<version> with the signed-build
#                     tarball and the CHANGELOG notes (tag must be pushed first)
#   make check-package → verify the tarball (also run by build and sign)
#   make clean      → wipe the build/ dir
#   make lint       → php -l on every .php file
#   make test       → phpunit + jest (after composer install / npm install)
#
# Convention: the version that ends up in the tarball name comes from
# appinfo/info.xml so we can't ship a mismatch.

app_name=audiolog
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
appstore_dir=$(build_dir)/appstore

# Pull the version straight out of info.xml — single source of truth.
version=$(shell xmllint --xpath "string(/info/version)" appinfo/info.xml 2>/dev/null \
              || grep -oE '<version>[^<]+' appinfo/info.xml | head -1 | sed 's/<version>//')

# What ships: an explicit list of VERSIONED paths, packed with `git archive`
# from HEAD. Untracked or ignored files (.remember/, .DS_Store, ._*, vendor/,
# node_modules/, build/, dist/) can never end up in the tarball, and the
# result is checked by tools/check-tarball.py before signing.
ship_paths=appinfo lib templates js css img l10n LICENSE NOTICE THIRD-PARTY-NOTICES.md LICENSES REUSE.toml CHANGELOG.md README.md

.PHONY: all
all: build

.PHONY: build
build: clean
	@echo "→ Building $(app_name) $(version) from HEAD"
	@# Uncommitted changes in shipped paths would silently stay out of the
	@# tarball (git archive packs HEAD): refuse instead.
	@test -z "$$(git status --porcelain -- $(ship_paths))" || { \
		echo "✗ Uncommitted changes in shipped paths — commit them first:"; \
		git status --short -- $(ship_paths); exit 1; }
	@mkdir -p $(appstore_dir)
	@# Tarball MUST contain exactly one top-level dir named after the app id.
	@git archive --format=tar --prefix=$(app_name)/ HEAD $(ship_paths) \
		| gzip -n -9 > $(appstore_dir)/$(app_name)-$(version).tar.gz
	@echo "→ Output: $(appstore_dir)/$(app_name)-$(version).tar.gz"
	@ls -lh $(appstore_dir)/$(app_name)-$(version).tar.gz
	@$(MAKE) --no-print-directory check-package

# Fails on ._*, .DS_Store, .remember/, tests/, build/, dist/, dev tooling,
# wrong top-level dir or a version mismatch with info.xml.
.PHONY: check-package
check-package:
	@python3 tools/check-tarball.py $(appstore_dir)/$(app_name)-$(version).tar.gz $(version)

.PHONY: sign
sign: build
	@$(MAKE) --no-print-directory check-package
	@echo "→ Signing $(app_name) $(version)"
	@mkdir -p $(sign_dir)
	@openssl dgst -sha512 -sign ~/.nextcloud/certificates/$(app_name).key \
		$(appstore_dir)/$(app_name)-$(version).tar.gz \
		| openssl base64 > $(sign_dir)/$(app_name)-$(version).sig
	@echo "→ Signature: $(sign_dir)/$(app_name)-$(version).sig"
	@echo ""
	@echo "Submission payload (paste this into the app store form):"
	@echo "  Tarball URL: https://github.com/sintax-tech/nextcloud-$(app_name)/releases/download/v$(version)/$(app_name)-$(version).tar.gz (after make release)"
	@echo "  Signature:"
	@cat $(sign_dir)/$(app_name)-$(version).sig

# Requires: make sign done, tag v<version> pushed, gh authenticated.
# Attaches only the tarball (never the .sig); refuses empty release notes.
.PHONY: release
release:
	@test -f $(appstore_dir)/$(app_name)-$(version).tar.gz || { echo "Run 'make sign' first"; exit 1; }
	@awk -v v="$(version)" '$$0 ~ "^## "v" " {f=1; next} /^## /{f=0} f' CHANGELOG.md > $(build_dir)/release-notes.md
	@test -s $(build_dir)/release-notes.md || { echo "No CHANGELOG section for $(version)"; exit 1; }
	@gh release create v$(version) $(appstore_dir)/$(app_name)-$(version).tar.gz \
		--title "Audiolog $(version)" --notes-file $(build_dir)/release-notes.md

.PHONY: clean
clean:
	@rm -rf $(build_dir)
	@echo "→ Cleaned $(build_dir)"

.PHONY: lint
lint:
	@find lib/ -name '*.php' -exec php -l {} \; 2>&1 | grep -v "No syntax errors" || echo "All PHP files OK"

.PHONY: test
test: test-php test-js

.PHONY: test-php
test-php:
	@test -x vendor/bin/phpunit || { echo "Run 'composer install' first (dev-only, vendor/ is git-ignored)"; exit 1; }
	@php -d memory_limit=1G vendor/bin/phpunit -c tests/phpunit.xml

.PHONY: test-js
test-js:
	@test -d node_modules/jest || { echo "Run 'npm install' first (dev-only, node_modules/ is git-ignored)"; exit 1; }
	@npx jest --config tests/jest.config.js

.PHONY: version
version:
	@echo $(version)
