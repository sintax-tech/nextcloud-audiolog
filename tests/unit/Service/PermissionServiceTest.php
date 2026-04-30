<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\PermissionService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
    private IConfig $config;
    private IGroupManager $groupManager;
    private IUserSession $userSession;
    private PermissionService $svc;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->svc = new PermissionService($this->config, $this->groupManager, $this->userSession);
    }

    public function testEmptyAllowedGroupsLetsEveryoneIn(): void {
        $this->config->method('getAppValue')->willReturn('[]');
        $this->assertTrue($this->svc->userHasAccess('any-user'));
    }

    public function testUserNotInAnyAllowedGroupIsRejected(): void {
        $this->config->method('getAppValue')->willReturn('["managers", "support"]');
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->assertFalse($this->svc->userHasAccess('joe'));
    }

    public function testUserInOneAllowedGroupGetsAccess(): void {
        $this->config->method('getAppValue')->willReturn('["managers", "support"]');
        $this->groupManager->method('isInGroup')->willReturnCallback(
            fn ($uid, $gid) => $gid === 'support'
        );
        $this->assertTrue($this->svc->userHasAccess('joe'));
    }

    public function testCorruptedAllowedGroupsJsonFallsBackToOpenAccess(): void {
        // Bad JSON should not lock everyone out — defaults to "no list = open".
        $this->config->method('getAppValue')->willReturn('not-json');
        $this->assertTrue($this->svc->userHasAccess('joe'));
    }
}
