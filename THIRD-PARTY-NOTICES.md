# Third-party notices

## Software and assets embedded in the app package

The git archive package contains no PHP vendor directory, JavaScript library bundle, downloaded fonts or raster images. The AudioWorklet (`js/pcm-extractor-worklet.js`), service worker and page scripts are project code, using browser APIs. The four SVG files in `img/` are the Sintax audio mark; CSS uses system fonts.

### Material Design Icons / Material Design icons

- **Version:** selected SVG paths copied into the original templates; the source release was not recorded. Paths were checked against the Pictogrammers upstream snapshot `2424e748e0cc63ab7b9c095a099b9fe239b737c0` on 2026-10-08; no upstream release number is asserted for the copied subset.
- **License:** Apache-2.0 (icons). The upstream MIT license applies to its non-icon code, which is not included here.
- **Copyright:** Google Inc. and Pictogrammers / Material Design Icons contributors. Individual copied paths did not carry author headers; no individual authorship is inferred.
- **Upstream:** https://github.com/Templarian/MaterialDesign and https://github.com/google/material-design-icons
- **License policy:** https://github.com/Templarian/MaterialDesign/blob/master/LICENSE
- **Used in:** inline SVG paths in `templates/main.php` (upload, folder, audio, close, record, stop, settings, transcription, document, chart, list, check, play, copy, notes, download, export, word, add, user, calendar, tasks, title, cloud, computer) and `templates/admin.php` (check, eye, eye-off, save, test). The custom circular audio/microphone mark is Sintax artwork. 27 of the 30 embedded paths match that snapshot exactly; notes, word and cloud have no exact match and are retained as adapted/legacy paths with the same conservative attribution. The SVG wrappers, sizing and colors are integrated into Audiolog.
- **License text:** [LICENSES/Apache-2.0.txt](LICENSES/Apache-2.0.txt). Attribution is preserved here and in the affected templates. The templates combine AGPL application code with Apache-2.0 icon artwork.

## Development only (excluded from the app package)

Versions and license identifiers below come from the committed lockfiles. Dependencies are used for tests, API interfaces, parsing and test support; they are not runtime components shipped by Audiolog. Composer project URLs point to the corresponding upstream projects; npm project URLs point to package metadata and upstream links.

### Composer

PHPUnit and its support packages run PHP tests; `nextcloud/ocp` supplies Nextcloud API interfaces; Doctrine DBAL and its support packages provide database types used by the test environment. Copyright notices remain in each upstream package LICENSE file, available through the upstream links.

| Package | Locked version | SPDX license | Upstream |
| --- | --- | --- | --- |
| doctrine/dbal | 3.10.6 | MIT | https://github.com/doctrine/dbal |
| doctrine/deprecations | 1.1.6 | MIT | https://github.com/doctrine/deprecations |
| doctrine/event-manager | 2.1.1 | MIT | https://github.com/doctrine/event-manager |
| myclabs/deep-copy | 1.14.0 | MIT | https://github.com/myclabs/DeepCopy |
| nextcloud/ocp | v28.0.9 | AGPL-3.0-or-later | https://github.com/nextcloud-deps/ocp |
| nikic/php-parser | v5.9.0 | BSD-3-Clause | https://github.com/nikic/PHP-Parser |
| phar-io/manifest | 2.0.4 | BSD-3-Clause | https://github.com/phar-io/manifest |
| phar-io/version | 3.2.1 | BSD-3-Clause | https://github.com/phar-io/version |
| phpunit/php-code-coverage | 10.1.16 | BSD-3-Clause | https://github.com/sebastianbergmann/php-code-coverage |
| phpunit/php-file-iterator | 4.1.0 | BSD-3-Clause | https://github.com/sebastianbergmann/php-file-iterator |
| phpunit/php-invoker | 4.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/php-invoker |
| phpunit/php-text-template | 3.0.1 | BSD-3-Clause | https://github.com/sebastianbergmann/php-text-template |
| phpunit/php-timer | 6.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/php-timer |
| phpunit/phpunit | 10.5.66 | BSD-3-Clause | https://github.com/sebastianbergmann/phpunit |
| psr/cache | 3.0.0 | MIT | https://github.com/php-fig/cache |
| psr/clock | 1.0.0 | MIT | https://github.com/php-fig/clock |
| psr/container | 2.0.2 | MIT | https://github.com/php-fig/container |
| psr/event-dispatcher | 1.0.0 | MIT | https://github.com/php-fig/event-dispatcher |
| psr/log | 1.1.4 | MIT | https://github.com/php-fig/log |
| sebastian/cli-parser | 2.0.1 | BSD-3-Clause | https://github.com/sebastianbergmann/cli-parser |
| sebastian/code-unit | 2.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/code-unit |
| sebastian/code-unit-reverse-lookup | 3.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/code-unit-reverse-lookup |
| sebastian/comparator | 5.0.5 | BSD-3-Clause | https://github.com/sebastianbergmann/comparator |
| sebastian/complexity | 3.2.0 | BSD-3-Clause | https://github.com/sebastianbergmann/complexity |
| sebastian/diff | 5.1.1 | BSD-3-Clause | https://github.com/sebastianbergmann/diff |
| sebastian/environment | 6.1.0 | BSD-3-Clause | https://github.com/sebastianbergmann/environment |
| sebastian/exporter | 5.1.4 | BSD-3-Clause | https://github.com/sebastianbergmann/exporter |
| sebastian/global-state | 6.0.2 | BSD-3-Clause | https://github.com/sebastianbergmann/global-state |
| sebastian/lines-of-code | 2.0.2 | BSD-3-Clause | https://github.com/sebastianbergmann/lines-of-code |
| sebastian/object-enumerator | 5.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/object-enumerator |
| sebastian/object-reflector | 3.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/object-reflector |
| sebastian/recursion-context | 5.0.2 | BSD-3-Clause | https://github.com/sebastianbergmann/recursion-context |
| sebastian/type | 4.0.0 | BSD-3-Clause | https://github.com/sebastianbergmann/type |
| sebastian/version | 4.0.1 | BSD-3-Clause | https://github.com/sebastianbergmann/version |
| theseer/tokenizer | 1.3.1 | BSD-3-Clause | https://github.com/theseer/tokenizer |

### npm

Jest and `jest-environment-jsdom` (both 29.7.0, MIT, Meta Platforms, Inc. and affiliates) run frontend tests in a browser-like DOM. Transitive package copyright notices remain in their upstream LICENSE files.

All locked npm dependencies are development only, including transitive dependencies of Jest and jsdom. Exact versions, licenses and registry origins are preserved in `package-lock.json`; the complete locked inventory follows.

| Package | Locked version | SPDX license | Upstream |
| --- | --- | --- | --- |
| @babel/code-frame | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/code-frame |
| @babel/compat-data | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/compat-data |
| @babel/core | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/core |
| @babel/generator | 7.29.8 | MIT | https://www.npmjs.com/package/@babel/generator |
| @babel/helper-compilation-targets | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-compilation-targets |
| @babel/helper-globals | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-globals |
| @babel/helper-module-imports | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-module-imports |
| @babel/helper-module-transforms | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-module-transforms |
| @babel/helper-plugin-utils | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-plugin-utils |
| @babel/helper-string-parser | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-string-parser |
| @babel/helper-validator-identifier | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-validator-identifier |
| @babel/helper-validator-option | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/helper-validator-option |
| @babel/helpers | 7.29.10 | MIT | https://www.npmjs.com/package/@babel/helpers |
| @babel/parser | 7.29.9 | MIT | https://www.npmjs.com/package/@babel/parser |
| @babel/plugin-syntax-async-generators | 7.8.4 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-async-generators |
| @babel/plugin-syntax-bigint | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-bigint |
| @babel/plugin-syntax-class-properties | 7.12.13 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-class-properties |
| @babel/plugin-syntax-class-static-block | 7.14.5 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-class-static-block |
| @babel/plugin-syntax-import-attributes | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-import-attributes |
| @babel/plugin-syntax-import-meta | 7.10.4 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-import-meta |
| @babel/plugin-syntax-json-strings | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-json-strings |
| @babel/plugin-syntax-jsx | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-jsx |
| @babel/plugin-syntax-logical-assignment-operators | 7.10.4 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-logical-assignment-operators |
| @babel/plugin-syntax-nullish-coalescing-operator | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-nullish-coalescing-operator |
| @babel/plugin-syntax-numeric-separator | 7.10.4 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-numeric-separator |
| @babel/plugin-syntax-object-rest-spread | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-object-rest-spread |
| @babel/plugin-syntax-optional-catch-binding | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-optional-catch-binding |
| @babel/plugin-syntax-optional-chaining | 7.8.3 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-optional-chaining |
| @babel/plugin-syntax-private-property-in-object | 7.14.5 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-private-property-in-object |
| @babel/plugin-syntax-top-level-await | 7.14.5 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-top-level-await |
| @babel/plugin-syntax-typescript | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/plugin-syntax-typescript |
| @babel/template | 7.29.7 | MIT | https://www.npmjs.com/package/@babel/template |
| @babel/traverse | 7.29.10 | MIT | https://www.npmjs.com/package/@babel/traverse |
| @babel/types | 7.29.8 | MIT | https://www.npmjs.com/package/@babel/types |
| @bcoe/v8-coverage | 0.2.3 | MIT | https://www.npmjs.com/package/@bcoe/v8-coverage |
| @istanbuljs/load-nyc-config | 1.1.0 | ISC | https://www.npmjs.com/package/@istanbuljs/load-nyc-config |
| @istanbuljs/schema | 0.1.6 | MIT | https://www.npmjs.com/package/@istanbuljs/schema |
| @jest/console | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/console |
| @jest/core | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/core |
| @jest/environment | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/environment |
| @jest/expect | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/expect |
| @jest/expect-utils | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/expect-utils |
| @jest/fake-timers | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/fake-timers |
| @jest/globals | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/globals |
| @jest/reporters | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/reporters |
| @jest/schemas | 29.6.3 | MIT | https://www.npmjs.com/package/@jest/schemas |
| @jest/source-map | 29.6.3 | MIT | https://www.npmjs.com/package/@jest/source-map |
| @jest/test-result | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/test-result |
| @jest/test-sequencer | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/test-sequencer |
| @jest/transform | 29.7.0 | MIT | https://www.npmjs.com/package/@jest/transform |
| @jest/types | 29.6.3 | MIT | https://www.npmjs.com/package/@jest/types |
| @jridgewell/gen-mapping | 0.3.13 | MIT | https://www.npmjs.com/package/@jridgewell/gen-mapping |
| @jridgewell/remapping | 2.3.5 | MIT | https://www.npmjs.com/package/@jridgewell/remapping |
| @jridgewell/resolve-uri | 3.1.2 | MIT | https://www.npmjs.com/package/@jridgewell/resolve-uri |
| @jridgewell/sourcemap-codec | 1.6.0 | MIT | https://www.npmjs.com/package/@jridgewell/sourcemap-codec |
| @jridgewell/trace-mapping | 0.3.31 | MIT | https://www.npmjs.com/package/@jridgewell/trace-mapping |
| @sinclair/typebox | 0.27.12 | MIT | https://www.npmjs.com/package/@sinclair/typebox |
| @sinonjs/commons | 3.0.1 | BSD-3-Clause | https://www.npmjs.com/package/@sinonjs/commons |
| @sinonjs/fake-timers | 10.3.0 | BSD-3-Clause | https://www.npmjs.com/package/@sinonjs/fake-timers |
| @tootallnate/once | 2.0.1 | MIT | https://www.npmjs.com/package/@tootallnate/once |
| @types/babel__core | 7.20.5 | MIT | https://www.npmjs.com/package/@types/babel__core |
| @types/babel__generator | 7.27.0 | MIT | https://www.npmjs.com/package/@types/babel__generator |
| @types/babel__template | 7.4.4 | MIT | https://www.npmjs.com/package/@types/babel__template |
| @types/babel__traverse | 7.28.0 | MIT | https://www.npmjs.com/package/@types/babel__traverse |
| @types/graceful-fs | 4.1.9 | MIT | https://www.npmjs.com/package/@types/graceful-fs |
| @types/istanbul-lib-coverage | 2.0.6 | MIT | https://www.npmjs.com/package/@types/istanbul-lib-coverage |
| @types/istanbul-lib-report | 3.0.3 | MIT | https://www.npmjs.com/package/@types/istanbul-lib-report |
| @types/istanbul-reports | 3.0.4 | MIT | https://www.npmjs.com/package/@types/istanbul-reports |
| @types/jsdom | 20.0.1 | MIT | https://www.npmjs.com/package/@types/jsdom |
| @types/node | 26.6.4 | MIT | https://www.npmjs.com/package/@types/node |
| @types/stack-utils | 2.0.3 | MIT | https://www.npmjs.com/package/@types/stack-utils |
| @types/tough-cookie | 4.0.5 | MIT | https://www.npmjs.com/package/@types/tough-cookie |
| @types/yargs | 17.0.35 | MIT | https://www.npmjs.com/package/@types/yargs |
| @types/yargs-parser | 21.0.3 | MIT | https://www.npmjs.com/package/@types/yargs-parser |
| abab | 2.0.6 | BSD-3-Clause | https://www.npmjs.com/package/abab |
| acorn | 8.19.0 | MIT | https://www.npmjs.com/package/acorn |
| acorn-globals | 7.0.1 | MIT | https://www.npmjs.com/package/acorn-globals |
| acorn-walk | 8.3.5 | MIT | https://www.npmjs.com/package/acorn-walk |
| agent-base | 6.0.2 | MIT | https://www.npmjs.com/package/agent-base |
| ansi-escapes | 4.3.2 | MIT | https://www.npmjs.com/package/ansi-escapes |
| ansi-regex | 5.0.1 | MIT | https://www.npmjs.com/package/ansi-regex |
| ansi-styles | 4.3.0 | MIT | https://www.npmjs.com/package/ansi-styles |
| anymatch | 3.1.3 | ISC | https://www.npmjs.com/package/anymatch |
| argparse | 1.0.10 | MIT | https://www.npmjs.com/package/argparse |
| asynckit | 0.4.0 | MIT | https://www.npmjs.com/package/asynckit |
| babel-jest | 29.7.0 | MIT | https://www.npmjs.com/package/babel-jest |
| babel-plugin-istanbul | 6.1.1 | BSD-3-Clause | https://www.npmjs.com/package/babel-plugin-istanbul |
| istanbul-lib-instrument | 5.2.1 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-lib-instrument |
| babel-plugin-jest-hoist | 29.6.3 | MIT | https://www.npmjs.com/package/babel-plugin-jest-hoist |
| babel-preset-current-node-syntax | 1.2.0 | MIT | https://www.npmjs.com/package/babel-preset-current-node-syntax |
| babel-preset-jest | 29.6.3 | MIT | https://www.npmjs.com/package/babel-preset-jest |
| balanced-match | 1.0.2 | MIT | https://www.npmjs.com/package/balanced-match |
| baseline-browser-mapping | 2.11.27 | Apache-2.0 | https://www.npmjs.com/package/baseline-browser-mapping |
| brace-expansion | 1.1.21 | MIT | https://www.npmjs.com/package/brace-expansion |
| braces | 3.0.3 | MIT | https://www.npmjs.com/package/braces |
| browserslist | 4.29.3 | MIT | https://www.npmjs.com/package/browserslist |
| bser | 2.1.1 | Apache-2.0 | https://www.npmjs.com/package/bser |
| buffer-from | 1.1.2 | MIT | https://www.npmjs.com/package/buffer-from |
| call-bind-apply-helpers | 1.0.2 | MIT | https://www.npmjs.com/package/call-bind-apply-helpers |
| callsites | 3.1.0 | MIT | https://www.npmjs.com/package/callsites |
| camelcase | 5.3.1 | MIT | https://www.npmjs.com/package/camelcase |
| caniuse-lite | 1.0.30001815 | CC-BY-4.0 | https://www.npmjs.com/package/caniuse-lite |
| chalk | 4.1.2 | MIT | https://www.npmjs.com/package/chalk |
| char-regex | 1.0.2 | MIT | https://www.npmjs.com/package/char-regex |
| ci-info | 3.9.0 | MIT | https://www.npmjs.com/package/ci-info |
| cjs-module-lexer | 1.4.3 | MIT | https://www.npmjs.com/package/cjs-module-lexer |
| cliui | 8.0.1 | ISC | https://www.npmjs.com/package/cliui |
| co | 4.6.0 | MIT | https://www.npmjs.com/package/co |
| collect-v8-coverage | 1.0.3 | MIT | https://www.npmjs.com/package/collect-v8-coverage |
| color-convert | 2.0.1 | MIT | https://www.npmjs.com/package/color-convert |
| color-name | 1.1.4 | MIT | https://www.npmjs.com/package/color-name |
| combined-stream | 1.0.8 | MIT | https://www.npmjs.com/package/combined-stream |
| concat-map | 0.0.1 | MIT | https://www.npmjs.com/package/concat-map |
| convert-source-map | 2.0.0 | MIT | https://www.npmjs.com/package/convert-source-map |
| create-jest | 29.7.0 | MIT | https://www.npmjs.com/package/create-jest |
| cross-spawn | 7.0.6 | MIT | https://www.npmjs.com/package/cross-spawn |
| cssom | 0.5.0 | MIT | https://www.npmjs.com/package/cssom |
| cssstyle | 2.3.0 | MIT | https://www.npmjs.com/package/cssstyle |
| cssom | 0.3.8 | MIT | https://www.npmjs.com/package/cssom |
| data-urls | 3.0.2 | MIT | https://www.npmjs.com/package/data-urls |
| debug | 4.4.3 | MIT | https://www.npmjs.com/package/debug |
| decimal.js | 10.6.0 | MIT | https://www.npmjs.com/package/decimal.js |
| dedent | 1.7.2 | MIT | https://www.npmjs.com/package/dedent |
| deepmerge | 4.3.1 | MIT | https://www.npmjs.com/package/deepmerge |
| delayed-stream | 1.0.0 | MIT | https://www.npmjs.com/package/delayed-stream |
| detect-newline | 3.1.0 | MIT | https://www.npmjs.com/package/detect-newline |
| diff-sequences | 29.6.3 | MIT | https://www.npmjs.com/package/diff-sequences |
| domexception | 4.0.0 | MIT | https://www.npmjs.com/package/domexception |
| dunder-proto | 1.0.1 | MIT | https://www.npmjs.com/package/dunder-proto |
| electron-to-chromium | 1.5.451 | ISC | https://www.npmjs.com/package/electron-to-chromium |
| emittery | 0.13.1 | MIT | https://www.npmjs.com/package/emittery |
| emoji-regex | 8.0.0 | MIT | https://www.npmjs.com/package/emoji-regex |
| entities | 6.0.1 | BSD-2-Clause | https://www.npmjs.com/package/entities |
| error-ex | 1.3.4 | MIT | https://www.npmjs.com/package/error-ex |
| es-define-property | 1.0.1 | MIT | https://www.npmjs.com/package/es-define-property |
| es-errors | 1.3.0 | MIT | https://www.npmjs.com/package/es-errors |
| es-object-atoms | 1.1.2 | MIT | https://www.npmjs.com/package/es-object-atoms |
| es-set-tostringtag | 2.1.0 | MIT | https://www.npmjs.com/package/es-set-tostringtag |
| escalade | 3.2.0 | MIT | https://www.npmjs.com/package/escalade |
| escape-string-regexp | 2.0.0 | MIT | https://www.npmjs.com/package/escape-string-regexp |
| escodegen | 2.1.0 | BSD-2-Clause | https://www.npmjs.com/package/escodegen |
| esprima | 4.0.1 | BSD-2-Clause | https://www.npmjs.com/package/esprima |
| estraverse | 5.3.0 | BSD-2-Clause | https://www.npmjs.com/package/estraverse |
| esutils | 2.0.3 | BSD-2-Clause | https://www.npmjs.com/package/esutils |
| execa | 5.1.1 | MIT | https://www.npmjs.com/package/execa |
| exit | 0.1.2 | Not declared in lockfile | https://www.npmjs.com/package/exit |
| expect | 29.7.0 | MIT | https://www.npmjs.com/package/expect |
| fast-json-stable-stringify | 2.1.0 | MIT | https://www.npmjs.com/package/fast-json-stable-stringify |
| fb-watchman | 2.0.2 | Apache-2.0 | https://www.npmjs.com/package/fb-watchman |
| fill-range | 7.1.1 | MIT | https://www.npmjs.com/package/fill-range |
| find-up | 4.1.0 | MIT | https://www.npmjs.com/package/find-up |
| form-data | 4.0.6 | MIT | https://www.npmjs.com/package/form-data |
| fs.realpath | 1.0.0 | ISC | https://www.npmjs.com/package/fs.realpath |
| fsevents | 2.3.3 | MIT | https://www.npmjs.com/package/fsevents |
| function-bind | 1.1.2 | MIT | https://www.npmjs.com/package/function-bind |
| gensync | 1.0.0-beta.2 | MIT | https://www.npmjs.com/package/gensync |
| get-caller-file | 2.0.5 | ISC | https://www.npmjs.com/package/get-caller-file |
| get-intrinsic | 1.3.0 | MIT | https://www.npmjs.com/package/get-intrinsic |
| get-package-type | 0.1.0 | MIT | https://www.npmjs.com/package/get-package-type |
| get-proto | 1.0.1 | MIT | https://www.npmjs.com/package/get-proto |
| get-stream | 6.0.1 | MIT | https://www.npmjs.com/package/get-stream |
| glob | 7.2.3 | ISC | https://www.npmjs.com/package/glob |
| gopd | 1.2.0 | MIT | https://www.npmjs.com/package/gopd |
| graceful-fs | 4.2.11 | ISC | https://www.npmjs.com/package/graceful-fs |
| has-flag | 4.0.0 | MIT | https://www.npmjs.com/package/has-flag |
| has-symbols | 1.1.0 | MIT | https://www.npmjs.com/package/has-symbols |
| has-tostringtag | 1.0.2 | MIT | https://www.npmjs.com/package/has-tostringtag |
| hasown | 2.0.4 | MIT | https://www.npmjs.com/package/hasown |
| html-encoding-sniffer | 3.0.0 | MIT | https://www.npmjs.com/package/html-encoding-sniffer |
| html-escaper | 2.0.2 | MIT | https://www.npmjs.com/package/html-escaper |
| http-proxy-agent | 5.0.0 | MIT | https://www.npmjs.com/package/http-proxy-agent |
| https-proxy-agent | 5.0.1 | MIT | https://www.npmjs.com/package/https-proxy-agent |
| human-signals | 2.1.0 | Apache-2.0 | https://www.npmjs.com/package/human-signals |
| iconv-lite | 0.6.3 | MIT | https://www.npmjs.com/package/iconv-lite |
| import-local | 3.2.0 | MIT | https://www.npmjs.com/package/import-local |
| imurmurhash | 0.1.4 | MIT | https://www.npmjs.com/package/imurmurhash |
| inflight | 1.0.6 | ISC | https://www.npmjs.com/package/inflight |
| inherits | 2.0.4 | ISC | https://www.npmjs.com/package/inherits |
| is-arrayish | 0.2.1 | MIT | https://www.npmjs.com/package/is-arrayish |
| is-core-module | 2.17.0 | MIT | https://www.npmjs.com/package/is-core-module |
| is-fullwidth-code-point | 3.0.0 | MIT | https://www.npmjs.com/package/is-fullwidth-code-point |
| is-generator-fn | 2.1.0 | MIT | https://www.npmjs.com/package/is-generator-fn |
| is-number | 7.0.0 | MIT | https://www.npmjs.com/package/is-number |
| is-potential-custom-element-name | 1.0.1 | MIT | https://www.npmjs.com/package/is-potential-custom-element-name |
| is-stream | 2.0.1 | MIT | https://www.npmjs.com/package/is-stream |
| isexe | 2.0.0 | ISC | https://www.npmjs.com/package/isexe |
| istanbul-lib-coverage | 3.2.2 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-lib-coverage |
| istanbul-lib-instrument | 6.0.3 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-lib-instrument |
| semver | 7.8.5 | ISC | https://www.npmjs.com/package/semver |
| istanbul-lib-report | 3.0.1 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-lib-report |
| istanbul-lib-source-maps | 4.0.1 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-lib-source-maps |
| istanbul-reports | 3.2.0 | BSD-3-Clause | https://www.npmjs.com/package/istanbul-reports |
| jest | 29.7.0 | MIT | https://www.npmjs.com/package/jest |
| jest-changed-files | 29.7.0 | MIT | https://www.npmjs.com/package/jest-changed-files |
| jest-circus | 29.7.0 | MIT | https://www.npmjs.com/package/jest-circus |
| jest-cli | 29.7.0 | MIT | https://www.npmjs.com/package/jest-cli |
| jest-config | 29.7.0 | MIT | https://www.npmjs.com/package/jest-config |
| jest-diff | 29.7.0 | MIT | https://www.npmjs.com/package/jest-diff |
| jest-docblock | 29.7.0 | MIT | https://www.npmjs.com/package/jest-docblock |
| jest-each | 29.7.0 | MIT | https://www.npmjs.com/package/jest-each |
| jest-environment-jsdom | 29.7.0 | MIT | https://www.npmjs.com/package/jest-environment-jsdom |
| jest-environment-node | 29.7.0 | MIT | https://www.npmjs.com/package/jest-environment-node |
| jest-get-type | 29.6.3 | MIT | https://www.npmjs.com/package/jest-get-type |
| jest-haste-map | 29.7.0 | MIT | https://www.npmjs.com/package/jest-haste-map |
| jest-leak-detector | 29.7.0 | MIT | https://www.npmjs.com/package/jest-leak-detector |
| jest-matcher-utils | 29.7.0 | MIT | https://www.npmjs.com/package/jest-matcher-utils |
| jest-message-util | 29.7.0 | MIT | https://www.npmjs.com/package/jest-message-util |
| jest-mock | 29.7.0 | MIT | https://www.npmjs.com/package/jest-mock |
| jest-pnp-resolver | 1.2.3 | MIT | https://www.npmjs.com/package/jest-pnp-resolver |
| jest-regex-util | 29.6.3 | MIT | https://www.npmjs.com/package/jest-regex-util |
| jest-resolve | 29.7.0 | MIT | https://www.npmjs.com/package/jest-resolve |
| jest-resolve-dependencies | 29.7.0 | MIT | https://www.npmjs.com/package/jest-resolve-dependencies |
| jest-runner | 29.7.0 | MIT | https://www.npmjs.com/package/jest-runner |
| jest-runtime | 29.7.0 | MIT | https://www.npmjs.com/package/jest-runtime |
| jest-snapshot | 29.7.0 | MIT | https://www.npmjs.com/package/jest-snapshot |
| semver | 7.8.5 | ISC | https://www.npmjs.com/package/semver |
| jest-util | 29.7.0 | MIT | https://www.npmjs.com/package/jest-util |
| jest-validate | 29.7.0 | MIT | https://www.npmjs.com/package/jest-validate |
| camelcase | 6.3.0 | MIT | https://www.npmjs.com/package/camelcase |
| jest-watcher | 29.7.0 | MIT | https://www.npmjs.com/package/jest-watcher |
| jest-worker | 29.7.0 | MIT | https://www.npmjs.com/package/jest-worker |
| supports-color | 8.1.1 | MIT | https://www.npmjs.com/package/supports-color |
| js-tokens | 4.0.0 | MIT | https://www.npmjs.com/package/js-tokens |
| js-yaml | 3.15.2 | MIT | https://www.npmjs.com/package/js-yaml |
| jsdom | 20.0.3 | MIT | https://www.npmjs.com/package/jsdom |
| jsesc | 3.1.0 | MIT | https://www.npmjs.com/package/jsesc |
| json-parse-even-better-errors | 2.3.1 | MIT | https://www.npmjs.com/package/json-parse-even-better-errors |
| json5 | 2.2.3 | MIT | https://www.npmjs.com/package/json5 |
| kleur | 3.0.3 | MIT | https://www.npmjs.com/package/kleur |
| leven | 3.1.0 | MIT | https://www.npmjs.com/package/leven |
| lines-and-columns | 1.2.4 | MIT | https://www.npmjs.com/package/lines-and-columns |
| locate-path | 5.0.0 | MIT | https://www.npmjs.com/package/locate-path |
| lru-cache | 5.1.1 | ISC | https://www.npmjs.com/package/lru-cache |
| make-dir | 4.0.0 | MIT | https://www.npmjs.com/package/make-dir |
| semver | 7.8.5 | ISC | https://www.npmjs.com/package/semver |
| makeerror | 1.0.12 | BSD-3-Clause | https://www.npmjs.com/package/makeerror |
| math-intrinsics | 1.1.0 | MIT | https://www.npmjs.com/package/math-intrinsics |
| merge-stream | 2.0.0 | MIT | https://www.npmjs.com/package/merge-stream |
| micromatch | 4.0.8 | MIT | https://www.npmjs.com/package/micromatch |
| mime-db | 1.52.0 | MIT | https://www.npmjs.com/package/mime-db |
| mime-types | 2.1.35 | MIT | https://www.npmjs.com/package/mime-types |
| mimic-fn | 2.1.0 | MIT | https://www.npmjs.com/package/mimic-fn |
| minimatch | 3.1.5 | ISC | https://www.npmjs.com/package/minimatch |
| ms | 2.1.3 | MIT | https://www.npmjs.com/package/ms |
| natural-compare | 1.4.0 | MIT | https://www.npmjs.com/package/natural-compare |
| node-int64 | 0.4.0 | MIT | https://www.npmjs.com/package/node-int64 |
| node-releases | 2.0.58 | MIT | https://www.npmjs.com/package/node-releases |
| normalize-path | 3.0.0 | MIT | https://www.npmjs.com/package/normalize-path |
| npm-run-path | 4.0.1 | MIT | https://www.npmjs.com/package/npm-run-path |
| nwsapi | 2.2.28 | MIT | https://www.npmjs.com/package/nwsapi |
| once | 1.4.0 | ISC | https://www.npmjs.com/package/once |
| onetime | 5.1.2 | MIT | https://www.npmjs.com/package/onetime |
| p-limit | 3.1.0 | MIT | https://www.npmjs.com/package/p-limit |
| p-locate | 4.1.0 | MIT | https://www.npmjs.com/package/p-locate |
| p-limit | 2.3.0 | MIT | https://www.npmjs.com/package/p-limit |
| p-try | 2.2.0 | MIT | https://www.npmjs.com/package/p-try |
| parse-json | 5.2.0 | MIT | https://www.npmjs.com/package/parse-json |
| parse5 | 7.3.0 | MIT | https://www.npmjs.com/package/parse5 |
| path-exists | 4.0.0 | MIT | https://www.npmjs.com/package/path-exists |
| path-is-absolute | 1.0.1 | MIT | https://www.npmjs.com/package/path-is-absolute |
| path-key | 3.1.1 | MIT | https://www.npmjs.com/package/path-key |
| path-parse | 1.0.7 | MIT | https://www.npmjs.com/package/path-parse |
| picocolors | 1.1.1 | ISC | https://www.npmjs.com/package/picocolors |
| picomatch | 2.3.2 | MIT | https://www.npmjs.com/package/picomatch |
| pirates | 4.0.7 | MIT | https://www.npmjs.com/package/pirates |
| pkg-dir | 4.2.0 | MIT | https://www.npmjs.com/package/pkg-dir |
| pretty-format | 29.7.0 | MIT | https://www.npmjs.com/package/pretty-format |
| ansi-styles | 5.2.0 | MIT | https://www.npmjs.com/package/ansi-styles |
| prompts | 2.4.2 | MIT | https://www.npmjs.com/package/prompts |
| psl | 1.15.0 | MIT | https://www.npmjs.com/package/psl |
| punycode | 2.3.1 | MIT | https://www.npmjs.com/package/punycode |
| pure-rand | 6.1.0 | MIT | https://www.npmjs.com/package/pure-rand |
| querystringify | 2.2.0 | MIT | https://www.npmjs.com/package/querystringify |
| react-is | 18.3.1 | MIT | https://www.npmjs.com/package/react-is |
| require-directory | 2.1.1 | MIT | https://www.npmjs.com/package/require-directory |
| requires-port | 1.0.0 | MIT | https://www.npmjs.com/package/requires-port |
| resolve | 1.22.13 | MIT | https://www.npmjs.com/package/resolve |
| resolve-cwd | 3.0.0 | MIT | https://www.npmjs.com/package/resolve-cwd |
| resolve-from | 5.0.0 | MIT | https://www.npmjs.com/package/resolve-from |
| resolve.exports | 2.0.3 | MIT | https://www.npmjs.com/package/resolve.exports |
| safer-buffer | 2.1.2 | MIT | https://www.npmjs.com/package/safer-buffer |
| saxes | 6.0.0 | ISC | https://www.npmjs.com/package/saxes |
| semver | 6.3.1 | ISC | https://www.npmjs.com/package/semver |
| shebang-command | 2.0.0 | MIT | https://www.npmjs.com/package/shebang-command |
| shebang-regex | 3.0.0 | MIT | https://www.npmjs.com/package/shebang-regex |
| signal-exit | 3.0.7 | ISC | https://www.npmjs.com/package/signal-exit |
| sisteransi | 1.0.5 | MIT | https://www.npmjs.com/package/sisteransi |
| slash | 3.0.0 | MIT | https://www.npmjs.com/package/slash |
| source-map | 0.6.1 | BSD-3-Clause | https://www.npmjs.com/package/source-map |
| source-map-support | 0.5.13 | MIT | https://www.npmjs.com/package/source-map-support |
| sprintf-js | 1.0.3 | BSD-3-Clause | https://www.npmjs.com/package/sprintf-js |
| stack-utils | 2.0.6 | MIT | https://www.npmjs.com/package/stack-utils |
| string-length | 4.0.2 | MIT | https://www.npmjs.com/package/string-length |
| string-width | 4.2.3 | MIT | https://www.npmjs.com/package/string-width |
| strip-ansi | 6.0.1 | MIT | https://www.npmjs.com/package/strip-ansi |
| strip-bom | 4.0.0 | MIT | https://www.npmjs.com/package/strip-bom |
| strip-final-newline | 2.0.0 | MIT | https://www.npmjs.com/package/strip-final-newline |
| strip-json-comments | 3.1.1 | MIT | https://www.npmjs.com/package/strip-json-comments |
| supports-color | 7.2.0 | MIT | https://www.npmjs.com/package/supports-color |
| supports-preserve-symlinks-flag | 1.0.0 | MIT | https://www.npmjs.com/package/supports-preserve-symlinks-flag |
| symbol-tree | 3.2.4 | MIT | https://www.npmjs.com/package/symbol-tree |
| test-exclude | 6.0.0 | ISC | https://www.npmjs.com/package/test-exclude |
| tmpl | 1.0.5 | BSD-3-Clause | https://www.npmjs.com/package/tmpl |
| to-regex-range | 5.0.1 | MIT | https://www.npmjs.com/package/to-regex-range |
| tough-cookie | 4.1.4 | BSD-3-Clause | https://www.npmjs.com/package/tough-cookie |
| tr46 | 3.0.0 | MIT | https://www.npmjs.com/package/tr46 |
| type-detect | 4.0.8 | MIT | https://www.npmjs.com/package/type-detect |
| type-fest | 0.21.3 | (MIT OR CC0-1.0) | https://www.npmjs.com/package/type-fest |
| undici-types | 8.9.0 | MIT | https://www.npmjs.com/package/undici-types |
| universalify | 0.2.0 | MIT | https://www.npmjs.com/package/universalify |
| update-browserslist-db | 1.3.4 | MIT | https://www.npmjs.com/package/update-browserslist-db |
| url-parse | 1.5.10 | MIT | https://www.npmjs.com/package/url-parse |
| v8-to-istanbul | 9.3.0 | ISC | https://www.npmjs.com/package/v8-to-istanbul |
| w3c-xmlserializer | 4.0.0 | MIT | https://www.npmjs.com/package/w3c-xmlserializer |
| walker | 1.0.8 | Apache-2.0 | https://www.npmjs.com/package/walker |
| webidl-conversions | 7.0.0 | BSD-2-Clause | https://www.npmjs.com/package/webidl-conversions |
| whatwg-encoding | 2.0.0 | MIT | https://www.npmjs.com/package/whatwg-encoding |
| whatwg-mimetype | 3.0.0 | MIT | https://www.npmjs.com/package/whatwg-mimetype |
| whatwg-url | 11.0.0 | MIT | https://www.npmjs.com/package/whatwg-url |
| which | 2.0.2 | ISC | https://www.npmjs.com/package/which |
| wrap-ansi | 7.0.0 | MIT | https://www.npmjs.com/package/wrap-ansi |
| wrappy | 1.0.2 | ISC | https://www.npmjs.com/package/wrappy |
| write-file-atomic | 4.0.2 | ISC | https://www.npmjs.com/package/write-file-atomic |
| ws | 8.22.0 | MIT | https://www.npmjs.com/package/ws |
| xml-name-validator | 4.0.0 | Apache-2.0 | https://www.npmjs.com/package/xml-name-validator |
| xmlchars | 2.2.0 | MIT | https://www.npmjs.com/package/xmlchars |
| y18n | 5.0.8 | ISC | https://www.npmjs.com/package/y18n |
| yallist | 3.1.1 | ISC | https://www.npmjs.com/package/yallist |
| yargs | 17.7.3 | MIT | https://www.npmjs.com/package/yargs |
| yargs-parser | 21.1.1 | ISC | https://www.npmjs.com/package/yargs-parser |
| yocto-queue | 0.1.0 | MIT | https://www.npmjs.com/package/yocto-queue |

## External requirements (not embedded software)

- Nextcloud and PHP provide the host environment and APIs. Nextcloud Deck is optional for task export. They are installed separately and are not bundled in the tarball.
- `ffmpeg` and `ffprobe` are invoked as external processes for media conversion and inspection. Their versions and build licenses depend on the server installation; Audiolog does not distribute their binaries or link their libraries. FFmpeg licensing and configuration: https://ffmpeg.org/legal.html
- Browser Web Audio, MediaRecorder, IndexedDB and Web Speech APIs are platform features, not bundled libraries.

Remote AI/STT services are not embedded software and are not part of this software credit inventory.
