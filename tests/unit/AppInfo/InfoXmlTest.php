<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

class InfoXmlTest extends TestCase {
    private \SimpleXMLElement $xml;

    protected function setUp(): void {
        $this->xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
    }

    /** A3: the store must not promise an "admins only" live STT that does not exist. */
    public function testStoreTextDoesNotClaimAdminOnlyLiveTranscription(): void {
        foreach ($this->xml->description as $description) {
            $text = (string)$description;
            $this->assertDoesNotMatchRegularExpression('/restricted to admins|restrita a administradores|restringida a administradores/i', $text);
        }
    }

    public function testSummaryAndDescriptionComeInTheSameThreeLanguages(): void {
        $langs = fn (string $tag) => array_map(fn ($n) => (string)($n['lang'] ?? ''), iterator_to_array($this->xml->{$tag}, false));
        $this->assertSame(['', 'pt-br', 'es'], $langs('summary'));
        $this->assertSame(['', 'pt-br', 'es'], $langs('description'));
    }

    public function testSupportedRangeStaysNextcloud28To35AndPhp81(): void {
        $this->assertSame('28', (string)$this->xml->dependencies->nextcloud['min-version']);
        $this->assertSame('35', (string)$this->xml->dependencies->nextcloud['max-version']);
        $this->assertSame('8.1', (string)$this->xml->dependencies->php['min-version']);
    }
}
