<?php
declare(strict_types=1);

namespace OCA\Audiolog\Controller;

use OCA\Audiolog\AppInfo\Application;
use OCA\Audiolog\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\Util;

class PageController extends Controller {
    private IConfig $config;
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IL10N $l10n,
        private PermissionService $permissions
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // Users outside `allowed_groups` get a plain explanation instead of
        // the app UI (whose API calls would all answer 403 anyway).
        if (!$this->permissions->currentUserHasAccess()) {
            return new TemplateResponse(Application::APP_ID, 'access-denied', [], TemplateResponse::RENDER_AS_USER, Http::STATUS_FORBIDDEN);
        }

        Util::addScript(Application::APP_ID, 'audiolog-text-utils');
        Util::addScript(Application::APP_ID, 'audiolog-recovery');
        Util::addScript(Application::APP_ID, 'audiolog-speaker-naming');
        Util::addScript(Application::APP_ID, 'audiolog-main');
        Util::addStyle(Application::APP_ID, 'style');

        // PWA: link to dynamic manifest in <head> (NC builds <head> outside the
        // template, so we inject through Util::addHeader).
        $manifestUrl = $this->urlGenerator->linkToRoute(
            Application::APP_ID . '.page.manifest'
        );
        Util::addHeader('link', [
            'rel' => 'manifest',
            'href' => $manifestUrl,
        ]);
        // Hint mobile browsers / iOS to treat this as a standalone web app.
        Util::addHeader('meta', [
            'name' => 'mobile-web-app-capable',
            'content' => 'yes',
        ]);
        Util::addHeader('meta', [
            'name' => 'apple-mobile-web-app-capable',
            'content' => 'yes',
        ]);
        Util::addHeader('meta', [
            'name' => 'apple-mobile-web-app-title',
            'content' => 'Audiolog',
        ]);
        Util::addHeader('meta', [
            'name' => 'theme-color',
            'content' => trim($this->config->getAppValue(Application::APP_ID, 'theme_color', '#0082c9')),
        ]);

        // Get default output from admin settings
        $defaultOutput = $this->config->getAppValue(Application::APP_ID, 'default_output', 'transcricao');
        $enableRealtimeStt = $this->config->getAppValue(Application::APP_ID, 'enable_realtime_stt', 'false') === 'true';
        $realtimeProvider = $this->config->getAppValue(Application::APP_ID, 'realtime_stt_provider', 'web-speech');

        $response = new TemplateResponse(Application::APP_ID, 'main', [
            'default_output' => $defaultOutput,
            'enable_realtime_stt' => $enableRealtimeStt,
            'realtime_provider' => $realtimeProvider,
        ]);

        $csp = $this->buildContentSecurityPolicy();
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * The page never calls an AI or speech provider from the browser: uploads,
     * analysis and live Google STT all go through this app's own endpoints.
     * So the CSP opens no extra origin (and the admin's internal Ollama URL
     * never appears in a response header). Web Speech runs in the browser
     * engine and needs no connect-src either.
     */
    private function buildContentSecurityPolicy(): ContentSecurityPolicy {
        return new ContentSecurityPolicy();
    }

    /**
     * Dynamic Web App Manifest for PWA install.
     *
     * Served with the standard `application/manifest+json` content type.
     * The app is admin-restricted (group ACL handles auth at the app level),
     * so we mirror index() with NoAdminRequired + NoCSRFRequired.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function manifest(): JSONResponse {
        // linkToRoute returns the index.php-prefixed URL — keep it for start_url
        // / scope so the SW scope and the PWA scope match.
        $startUrl = $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');

        // Static assets (icons) live under /apps/<app>/img without index.php.
        $icon192 = $this->urlGenerator->linkTo(Application::APP_ID, 'img/icon-192.svg');
        $icon512 = $this->urlGenerator->linkTo(Application::APP_ID, 'img/icon-512.svg');

        // Manifest's `lang` follows the user's interface locale (BCP-47),
        // not a hardcoded value — keeps screen readers and i18n behaviour
        // consistent with whatever Nextcloud was rendering.
        $manifestLang = str_replace('_', '-', $this->l10n->getLanguageCode() ?: 'en');
        // Theme color: admin can override via `theme_color` app setting; default
        // to a neutral blue that works in light/dark and isn't tied to any brand.
        $themeColor = trim($this->config->getAppValue(Application::APP_ID, 'theme_color', '#0082c9'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
            $themeColor = '#0082c9';
        }
        $manifest = [
            'name' => (string)$this->l10n->t('Audiolog'),
            'short_name' => 'Audiolog',
            'description' => (string)$this->l10n->t('Gravação, transcrição e gestão de áudios com IA'),
            'lang' => $manifestLang,
            'dir' => 'ltr',
            'start_url' => $startUrl,
            'scope' => $startUrl,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#1c1c1c',
            'theme_color' => $themeColor,
            'categories' => ['productivity', 'utilities'],
            'icons' => [
                [
                    'src' => $icon192,
                    'sizes' => '192x192',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => $icon512,
                    'sizes' => '512x512',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
            ],
        ];

        $response = new JSONResponse($manifest);
        $response->addHeader('Content-Type', 'application/manifest+json');
        // Allow short caching; manifest rarely changes.
        $response->cacheFor(300, false, true);
        return $response;
    }
}
