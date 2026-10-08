<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Controller\SettingsController;
use OCA\Audiolog\Service\CryptoHelper;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A3 (1.0.5 part): the Google STT proxy spends the admin's paid key, so each
 * user gets a per-minute cap through Nextcloud's own rate limiter; the dead
 * "realtime_stt_admin_only" setting is gone.
 */
class SttProxyLimitsTest extends TestCase {
    public function testSttProxyHasPerUserRateLimit(): void {
        $attrs = (new \ReflectionMethod(ApiController::class, 'sttRecognize'))->getAttributes(UserRateLimit::class);
        $this->assertCount(1, $attrs, 'sttRecognize must carry #[UserRateLimit]');
        $limit = $attrs[0]->newInstance();
        $this->assertSame(60, $limit->getPeriod());
        $this->assertLessThanOrEqual(30, $limit->getLimit());
        // The client sends a chunk every ~5 s (12/min): the cap must not cut a normal session.
        $this->assertGreaterThanOrEqual(15, $limit->getLimit());
    }

    public function testSaveSettingsNoLongerStoresAdminOnlyFlagAndDropsTheOldValue(): void {
        $config = $this->createMock(IConfig::class);
        $written = [];
        $config->method('setAppValue')->willReturnCallback(function ($app, $key, $value) use (&$written) {
            $written[$key] = $value;
        });
        $config->expects($this->atLeastOnce())->method('deleteAppValue')
            ->with('audiolog', $this->logicalOr('realtime_stt_admin_only', 'realtime_stt_model'));
        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('encrypt')->willReturnArgument(0);

        $controller = new SettingsController(
            'audiolog', $this->createMock(IRequest::class), $config, $crypto,
            $this->createMock(\OCP\Http\Client\IClientService::class),
            $this->createMock(\OCA\Audiolog\Service\SecretRedactor::class),
            $this->createMock(\OCP\ITempManager::class),
            \OCA\Audiolog\Tests\Unit\Support\TestHelpers::l10n($this),
        );
        $controller->saveSettings('gemini', 'https://x', '', 'm', 'pt', '100', 'true', 'transcricao');

        $this->assertArrayNotHasKey('realtime_stt_admin_only', $written);
        $this->assertArrayHasKey('ai_provider', $written);
    }
}
