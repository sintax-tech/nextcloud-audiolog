<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LicensingTest extends TestCase {
    public function testEveryLibFileHasSpdxHeader(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/lib'));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $header = file_get_contents($file->getPathname(), false, null, 0, 1024);
            $this->assertStringContainsString('SPDX-' . 'FileCopyrightText:', $header, $file->getPathname());
            $this->assertStringContainsString('SPDX-' . 'License-Identifier: AGPL-3.0-or-later', $header, $file->getPathname());
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public static function footerLanguages(): array {
        return [
            ['en', 'Developed by Sintax · sintax.tech'],
            ['pt_BR', 'Desenvolvido pela Sintax · sintax.tech'],
            ['es', 'Desarrollado por Sintax · sintax.tech'],
        ];
    }

    #[DataProvider('footerLanguages')]
    public function testAdminFooterIsVisibleAndTranslated(string $language, string $expected): void {
        $root = dirname(__DIR__, 3);
        $template = file_get_contents($root . '/templates/admin.php');
        $this->assertMatchesRegularExpression('#</form>\s*<footer class="audiolog-attribution">#', $template);
        preg_match('#<footer class="audiolog-attribution">(.*?)</footer>#s', $template, $matches);
        $translations = json_decode(file_get_contents($root . "/l10n/{$language}.json"), true)['translations'];
        $l = new class($translations) {
            public function __construct(private array $translations) {}
            public function t(string $key): string { return $this->translations[$key]; }
        };
        // Render the actual footer PHP, using Nextcloud's escaped output semantics.
        $fragment = str_replace('p($l->t(', 'echo htmlspecialchars($l->t(', $matches[0]);
        ob_start();
        try {
            eval('?>' . $fragment);
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($html));
        $footer = $dom->getElementsByTagName('footer')->item(0);
        $this->assertSame($expected, preg_replace('/\s+/u', ' ', trim($footer->textContent)));
        $link = $footer->getElementsByTagName('a')->item(0);
        $this->assertSame('https://sintax.tech', $link->getAttribute('href'));
        $this->assertSame('sintax.tech', $link->textContent);
        $this->assertFalse($footer->hasAttribute('hidden'));
        $this->assertFalse($footer->hasAttribute('style'));
    }

    public function testDeclaredLicenseUsesSpdxIdentifier(): void {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
        $this->assertSame('AGPL-3.0-or-later', (string)$xml->licence);
    }
}
