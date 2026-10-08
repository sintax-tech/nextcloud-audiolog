# Makefile for the Audiolog Nextcloud app.
#
# Targets:
#   make build      → produce build/audiolog-X.Y.Z.tar.gz ready for the
#                     Nextcloud app store / `occ app:install --url=`
#   make sign       → sign the tarball with the app's certificate (private
#                     key in ~/.nextcloud/certificates/audiolog.key)
#   make release    → create the GitHub Release v<version> with the signed-build
#                     tarball and the CHANGELOG notes (tag must be pushed first)
#   make clean      → wipe the build/ dir
#   make lint       → php -l on every .php file
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

# Files NOT to ship in the tarball: dev-only stuff, dotfiles, build output.
exclude_patterns=\
	--exclude='.git*' \
	--exclude='build' \
	--exclude='.DS_Store' \
	--exclude='.idea' \
	--exclude='.vscode' \
	--exclude='node_modules' \
	--exclude='*.log' \
	--exclude='Makefile' \
	--exclude='tests' \
	--exclude='dist' \
	--exclude='docs/screenshots'

.PHONY: all
all: build

.PHONY: build
build: clean
	@echo "→ Building $(app_name) $(version)"
	@mkdir -p $(appstore_dir)
	@# Tarball MUST contain exactly one top-level dir named after the app id.
	@# COPYFILE_DISABLE=1 impede o tar do macOS de gravar AppleDouble (._*);
	@# --exclude='._*' é rede de segurança portável (macOS + Linux).
	@cd $(CURDIR)/.. && COPYFILE_DISABLE=1 tar \
		$(exclude_patterns) --exclude='._*' \
		-czf $(appstore_dir)/$(app_name)-$(version).tar.gz \
		$(app_name)/
	@echo "→ Output: $(appstore_dir)/$(app_name)-$(version).tar.gz"
	@ls -lh $(appstore_dir)/$(app_name)-$(version).tar.gz

.PHONY: sign
sign: build
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
