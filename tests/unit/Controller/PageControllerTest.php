<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\PageController;
use OCA\Audiolog\Service\PermissionService;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
    private function controller(array $config, bool $hasAccess = true): PageController {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('currentUserHasAccess')->willReturn($hasAccess);
        return new PageController(
            $this->createMock(IRequest::class),
            TestHelpers::config($this, $config),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IL10N::class),
            $permissions,
        );
    }

    /** B3: users outside allowed_groups get the access-denied page, not the app UI. */
    public function testUserOutsideAllowedGroupsGetsAccessDeniedPage(): void {
        $response = $this->controller([], false)->index();
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('access-denied', $response->getTemplateName());
        $this->assertSame(403, $response->getStatus());
    }

    /**
     * B4: the browser never talks to AI providers (everything goes through
     * the server), so the page CSP opens no provider origin — and the
     * internal Ollama URL never shows up in a response header.
     *
     * @dataProvider providers
     */
    public function testCspOpensNoProviderOrigin(array $config): void {
        $c = $this->controller($config);
        $csp = (new \ReflectionMethod($c, 'buildContentSecurityPolicy'))->invoke($c);
        $this->assertInstanceOf(ContentSecurityPolicy::class, $csp);
        $policy = $csp->buildPolicy();
        foreach (['generativelanguage.googleapis.com', 'speech.googleapis.com', 'api.openai.com', '10.0.0.5', 'wss:'] as $origin) {
            $this->assertStringNotContainsString($origin, $policy);
        }
    }

    public static function providers(): array {
        return [
            'gemini + gemini-live' => [['ai_provider' => 'gemini', 'enable_realtime_stt' => 'true', 'realtime_stt_provider' => 'gemini-live']],
            'openai + google-stt' => [['ai_provider' => 'openai', 'enable_realtime_stt' => 'true', 'realtime_stt_provider' => 'google-stt']],
            'ollama on LAN' => [['ai_provider' => 'ollama', 'ai_url' => 'http://10.0.0.5:11434']],
        ];
    }
}
