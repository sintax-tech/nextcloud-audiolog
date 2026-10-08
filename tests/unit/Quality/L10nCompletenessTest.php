<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;

/**
 * M7: every string passed to t()/$l->t() exists, non-empty, in en, pt_BR and
 * es; l10n/*.js matches its .json (regenerate with `tools/l10n.py sync`).
 */
class L10nCompletenessTest extends TestCase {
    private const LANGS = ['en', 'pt_BR', 'es'];

    private function root(): string {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> string => first file using it */
    private function usedStrings(): array {
        $files = array_merge(
            glob($this->root() . '/js/*.js'),
            glob($this->root() . '/templates/*.php'),
            $this->phpFiles($this->root() . '/lib')
        );
        $patterns = [
            '/\bt\(\s*[\'"]audiolog[\'"]\s*,\s*(?<q>[\'"])(?<s>(?:\\\\.|(?!\k<q>).)*)\k<q>/s',
            '/(?:\$l|\$this->l10n|\$l10n|\$this->l)->t\(\s*(?<q>[\'"])(?<s>(?:\\\\.|(?!\k<q>).)*)\k<q>/s',
        ];
        $found = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            foreach ($patterns as $p) {
                preg_match_all($p, $src, $m, PREG_SET_ORDER);
                foreach ($m as $hit) {
                    $s = str_replace(['\\' . $hit['q'], '\\\\'], [$hit['q'], '\\'], $hit['s']);
                    $found[$s] ??= basename($file);
                }
            }
        }
        return $found;
    }

    private function phpFiles(string $dir): array {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    public function testEveryUsedStringIsTranslatedInAllLanguages(): void {
        $used = $this->usedStrings();
        $this->assertGreaterThan(200, count($used));
        $missing = [];
        foreach (self::LANGS as $lang) {
            $tr = json_decode(file_get_contents($this->root() . "/l10n/{$lang}.json"), true)['translations'];
            foreach ($used as $s => $file) {
                if (($tr[$s] ?? '') === '') {
                    $missing[] = "{$lang}: \"{$s}\" ({$file})";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function testJsCatalogsMatchJson(): void {
        foreach (self::LANGS as $lang) {
            $json = json_decode(file_get_contents($this->root() . "/l10n/{$lang}.json"), true);
            $js = file_get_contents($this->root() . "/l10n/{$lang}.js");
            foreach ($json['translations'] as $k => $v) {
                $line = json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',';
                $this->assertStringContainsString($line, $js, "{$lang}.js out of date for \"{$k}\" — run tools/l10n.py sync");
            }
        }
    }

    /**
     * R5: user-facing texts in lib/ (API errors and messages, input errors)
     * must go through IL10N. Internal-only values carry "// l10n-ignore".
     */
    public function testNoUntranslatedUserFacingLiteralsInLib(): void {
        $offenders = [];
        foreach ($this->phpFiles($this->root() . '/lib') as $file) {
            foreach (file($file) as $n => $line) {
                if (str_contains($line, 'l10n-ignore')) {
                    continue;
                }
                if (preg_match('/[\'"](error|message|note)[\'"]\s*=>\s*[\'"]/', $line)
                    || preg_match('/new InvalidInputException\(\s*[\'"]/', $line)) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . ' ' . trim($line);
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    /**
     * N5: Portuguese text in the page scripts must go through t(). Heuristic:
     * a string literal with a Portuguese accented letter, outside t() calls
     * (removed first, even when split over lines), comments and console.*.
     */
    public function testNoPortugueseLiteralsOutsideTInJs(): void {
        $offenders = [];
        foreach (glob($this->root() . '/js/*.js') as $file) {
            $src = file_get_contents($file);
            // Blank out t('audiolog', '...') / n(...) keeping the line count.
            $src = preg_replace_callback(
                '/\b[tn]\(\s*[\'"]audiolog[\'"]\s*,\s*(?<q>[\'"])(?:\\\\.|(?!\k<q>).)*\k<q>/s',
                fn ($m) => str_repeat("\n", substr_count($m[0], "\n")) . 't()',
                $src
            );
            foreach (explode("\n", $src) as $n => $line) {
                $trim = ltrim($line);
                if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*') || str_contains($line, 'console.')) {
                    continue;
                }
                $code = preg_replace('#\s//.*$#', '', $line);
                preg_match_all('/([\'"`])((?:\\\\.|(?!\1).)*?)\1/u', $code, $m);
                foreach ($m[2] as $literal) {
                    if (preg_match('/[ãõçáéíóúâêôàÃÕÇÁÉÍÓÚÂÊÔ]/u', $literal)) {
                        $offenders[] = basename($file) . ':' . ($n + 1) . ' ' . $literal;
                    }
                }
            }
        }
        $this->assertSame([], $offenders);
    }
}
