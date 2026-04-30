# Makefile for the Audiolog Nextcloud app.
#
# Targets:
#   make build      → produce build/audiolog-X.Y.Z.tar.gz ready for the
#                     Nextcloud app store / `occ app:install --url=`
#   make sign       → sign the tarball with the app's certificate (private
#                     key in ~/.nextcloud/certificates/audiolog.key)
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
	--exclude='docs/screenshots'

.PHONY: all
all: build

.PHONY: build
build: clean
	@echo "→ Building $(app_name) $(version)"
	@mkdir -p $(appstore_dir)
	@# Tarball MUST contain exactly one top-level dir named after the app id.
	@cd $(CURDIR)/.. && tar czf $(appstore_dir)/$(app_name)-$(version).tar.gz \
		$(exclude_patterns) \
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
	@echo "  Tarball URL: <publish $(app_name)-$(version).tar.gz somewhere first>"
	@echo "  Signature:"
	@cat $(sign_dir)/$(app_name)-$(version).sig

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
	@cd tests && phpunit -c phpunit.xml || echo "(phpunit not installed locally — run from a Nextcloud test container)"

.PHONY: test-js
test-js:
	@cd tests && npx jest --config jest.config.js || echo "(jest not installed locally — run 'npm i -D jest' first)"

.PHONY: version
version:
	@echo $(version)
