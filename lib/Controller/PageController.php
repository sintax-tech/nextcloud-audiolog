<?php
declare(strict_types=1);

namespace OCA\Audiolog\Controller;

use OCA\Audiolog\AppInfo\Application;
use OCP\AppFramework\Controller;
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
        IL10N $l10n
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
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

        // CSP: only whitelist the domains the chosen provider actually needs.
        // Adding everything-for-everyone makes the app look like it's calling
        // Google even when the admin picked OpenAI or Ollama.
        $csp = new ContentSecurityPolicy();
        $aiProvider = $this->config->getAppValue(Application::APP_ID, 'ai_provider', 'gemini');
        if ($aiProvider === 'gemini') {
            $csp->addAllowedConnectDomain('https://generativelanguage.googleapis.com');
            $csp->addAllowedConnectDomain('wss://generativelanguage.googleapis.com');
        } elseif ($aiProvider === 'openai') {
            $csp->addAllowedConnectDomain('https://api.openai.com');
        } elseif ($aiProvider === 'ollama') {
            // Ollama URL is admin-configurable (LAN/cloud). Whitelist just
            // the host that's currently set — anything else is rejected.
            $aiUrl = $this->config->getAppValue(Application::APP_ID, 'ai_url', '');
            if ($aiUrl !== '') {
                $parsed = parse_url($aiUrl);
                if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                    $port = !empty($parsed['port']) ? (':' . $parsed['port']) : '';
                    $csp->addAllowedConnectDomain($parsed['scheme'] . '://' . $parsed['host'] . $port);
                }
            }
        }
        // Realtime STT: only add the domain the admin actually picked.
        if ($enableRealtimeStt) {
            if ($realtimeProvider === 'gemini-live') {
                $csp->addAllowedConnectDomain('wss://generativelanguage.googleapis.com');
                $csp->addAllowedConnectDomain('https://generativelanguage.googleapis.com');
            } elseif ($realtimeProvider === 'google-stt') {
                $csp->addAllowedConnectDomain('https://speech.googleapis.com');
            }
            // 'web-speech' runs entirely inside the browser — no extra origin.
        }
        $response->setContentSecurityPolicy($csp);

        return $response;
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
