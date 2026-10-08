<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Quality;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use PHPUnit\Framework\TestCase;

/** B5: code with no caller (and the unsupported Gemini Live client) stays out. */
class DeadCodeTest extends TestCase {
    private function root(): string {
        return dirname(__DIR__, 3);
    }

    public function testUnroutedOrUncalledMethodsAreGone(): void {
        $this->assertFalse(method_exists(ApiController::class, 'ncFile'));
        $this->assertFalse(method_exists(AudioService::class, 'getTaskStatus'));
        $this->assertFalse(method_exists(JobMapper::class, 'findStaleRunning'));
        $this->assertFalse(method_exists(AudioService::class, 'maskUrl'));
    }

    public function testGeminiLiveClientIsGone(): void {
        $js = file_get_contents($this->root() . '/js/audiolog-main.js');
        $this->assertStringNotContainsString('new WebSocket(', $js);
        $this->assertStringNotContainsString('startLiveGeminiLive', $js);
    }

    public function testUnusedRealtimeSettingsAreNotHandled(): void {
        foreach (['lib/Controller/SettingsController.php', 'lib/Settings/AdminSettings.php', 'js/audiolog-admin.js'] as $f) {
            $src = file_get_contents($this->root() . '/' . $f);
            $this->assertDoesNotMatchRegularExpression("/['\"]realtime_stt_model['\"]\\s*(=>|:|,)/", $src, $f);
        }
    }
}
