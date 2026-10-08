<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Controller;

use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IRequest;
use OCA\Audiolog\Settings\AdminSettings;

class SettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private CryptoHelper $crypto,
        private IClientService $clientService,
        private SecretRedactor $redactor,
        private ITempManager $tempManager,
        private IL10N $l10n
    ) {
        parent::__construct($appName, $request);
    }

    /** Shown when Nextcloud refuses a local/private address (Ollama on localhost/LAN). */
    private function localBlockedHint(): string {
        return $this->l10n->t(
            'O Nextcloud bloqueou o acesso a um endereço local ou da rede interna. Para usar um Ollama local, defina %s no config.php.',
            ["'allow_local_remote_servers' => true"]
        );
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveSettings(
        string $ai_provider,
        string $ai_url,
        string $api_key,
        string $ai_model,
        string $language,
        string $max_file_size,
        string $save_audio,
        string $default_output,
        ?array $allowed_groups = null,
        string $gemini_files_api_threshold = '18',
        string $gemini_files_api_force = 'false',
        string $long_audio_split_threshold = '25',
        string $enable_realtime_stt = 'false',
        string $realtime_stt_provider = 'web-speech',
        string $max_jobs_per_user_per_day = '50',
        string $use_google_stt_for_transcription = 'false',
        string $google_stt_api_key = '',
        string $analysis_model = ''
    ): JSONResponse {
        try {
            $this->config->setAppValue($this->appName, 'ai_provider', $ai_provider);
            $this->config->setAppValue($this->appName, 'ai_url', $ai_url);
            // API key is encrypted at rest. Helper handles plain → encrypted migration.
            $this->config->setAppValue($this->appName, 'api_key', $this->crypto->encrypt($api_key));
            $this->config->setAppValue($this->appName, 'ai_model', $ai_model);
            $this->config->setAppValue($this->appName, 'language', $language);
            $this->config->setAppValue($this->appName, 'max_file_size', $max_file_size);
            $this->config->setAppValue($this->appName, 'save_audio', $save_audio);
            $this->config->setAppValue($this->appName, 'default_output', $default_output);

            $this->config->setAppValue($this->appName, 'gemini_files_api_threshold', $gemini_files_api_threshold);
            $this->config->setAppValue($this->appName, 'gemini_files_api_force', $gemini_files_api_force);
            $this->config->setAppValue($this->appName, 'long_audio_split_threshold', $long_audio_split_threshold);
            $this->config->setAppValue($this->appName, 'enable_realtime_stt', $enable_realtime_stt);
            $this->config->setAppValue($this->appName, 'realtime_stt_provider', $realtime_stt_provider);
            // Gemini Live model, unused since Gemini Live was dropped.
            $this->config->deleteAppValue($this->appName, 'realtime_stt_model');
            $this->config->setAppValue($this->appName, 'max_jobs_per_user_per_day', $max_jobs_per_user_per_day);
            $this->config->setAppValue($this->appName, 'use_google_stt_for_transcription', $use_google_stt_for_transcription);
            // Google STT key is encrypted at rest like the Gemini key.
            $this->config->setAppValue($this->appName, 'google_stt_api_key', $this->crypto->encrypt($google_stt_api_key));
            // Never read anywhere: dropped in 1.0.5 (the store text promised an
            // admins-only switch that did not exist).
            $this->config->deleteAppValue($this->appName, 'realtime_stt_admin_only');
            $this->config->setAppValue($this->appName, 'analysis_model', $analysis_model);

            $groupsJson = json_encode($allowed_groups ?? []);
            $this->config->setAppValue($this->appName, 'allowed_groups', $groupsJson);

            return new JSONResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new JSONResponse(['status' => 'error', 'message' => $this->l10n->t('Não foi possível salvar as configurações.')], 500);
        }
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getSettings(): JSONResponse {
        $allowedGroupsJson = $this->config->getAppValue($this->appName, 'allowed_groups', '[]');
        return new JSONResponse([
            'ai_provider' => $this->config->getAppValue($this->appName, 'ai_provider', 'ollama'),
            'ai_url' => $this->config->getAppValue($this->appName, 'ai_url', 'http://localhost:11434'),
            'api_key' => $this->crypto->decrypt($this->config->getAppValue($this->appName, 'api_key', '')),
            'ai_model' => $this->config->getAppValue($this->appName, 'ai_model', 'whisper-large-v3'),
            'language' => $this->config->getAppValue($this->appName, 'language', 'pt'),
            'max_file_size' => $this->config->getAppValue($this->appName, 'max_file_size', '100'),
            'save_audio' => $this->config->getAppValue($this->appName, 'save_audio', 'true'),
            'default_output' => $this->config->getAppValue($this->appName, 'default_output', 'transcricao'),
            'gemini_files_api_threshold' => $this->config->getAppValue($this->appName, 'gemini_files_api_threshold', '18'),
            'gemini_files_api_force' => $this->config->getAppValue($this->appName, 'gemini_files_api_force', 'false'),
            'long_audio_split_threshold' => $this->config->getAppValue($this->appName, 'long_audio_split_threshold', '25'),
            'enable_realtime_stt' => $this->config->getAppValue($this->appName, 'enable_realtime_stt', 'false'),
            'realtime_stt_provider' => $this->config->getAppValue($this->appName, 'realtime_stt_provider', 'web-speech'),
            'max_jobs_per_user_per_day' => $this->config->getAppValue($this->appName, 'max_jobs_per_user_per_day', '50'),
            'use_google_stt_for_transcription' => $this->config->getAppValue($this->appName, 'use_google_stt_for_transcription', 'false'),
            'google_stt_api_key' => $this->crypto->decrypt($this->config->getAppValue($this->appName, 'google_stt_api_key', '')),
            'analysis_model' => $this->config->getAppValue($this->appName, 'analysis_model', ''),
            'allowed_groups' => json_decode($allowedGroupsJson, true) ?: [],
        ]);
    }

    /**
     * Battery of cheap checks the admin can rely on at a glance:
     *  - Gemini reachability (HEAD on the configured base URL)
     *  - ffmpeg + ffprobe availability and version
     *  - Free disk space in /tmp and in the NC data dir
     *  - API key configured (without leaking the value)
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function healthcheck(): JSONResponse {
        $checks = [];

        // --- API key configured? ---
        $apiKeyEnc = $this->config->getAppValue($this->appName, 'api_key', '');
        $apiKey = $this->crypto->decrypt($apiKeyEnc);
        $checks[] = [
            'name' => $this->l10n->t('Chave de API configurada'),
            'ok' => !empty($apiKey),
            'detail' => empty($apiKey)
                ? $this->l10n->t('Configure a chave em "Chave de API"')
                : $this->l10n->t('OK (%s caracteres)', [(string)strlen($apiKey)])
        ];

        // --- Gemini reachable? ---
        $aiUrl = rtrim($this->config->getAppValue($this->appName, 'ai_url', ''), '/');
        if ($aiUrl) {
            $geminiOk = false;
            $geminiDetail = '';
            try {
                // Same HTTP stack as the real processing (proxy, local-address
                // protection), so a green check means processing can connect.
                $resp = $this->clientService->newClient()->head($aiUrl, [
                    'timeout' => 6,
                    'connect_timeout' => 4,
                    'http_errors' => false,
                ]);
                $code = $resp->getStatusCode();
                $geminiOk = $code > 0 && $code < 500;
                $geminiDetail = "HTTP {$code}";
            } catch (LocalServerException $e) {
                $geminiDetail = $this->localBlockedHint();
            } catch (\Throwable $e) {
                $geminiDetail = $this->redactor->redact($e->getMessage());
            }
            $checks[] = [
                'name' => $this->l10n->t('Conectividade com %s', [(string)parse_url($aiUrl, PHP_URL_HOST)]),
                'ok' => $geminiOk,
                'detail' => $geminiDetail
            ];
        }

        // --- ffmpeg / ffprobe ---
        $checks[] = $this->probeBinary('ffmpeg', '-version');
        $checks[] = $this->probeBinary('ffprobe', '-version');

        // --- PHP extensions the app actively uses ---
        // Hard requirements: curl (HTTP to providers), json (everywhere),
        // fileinfo (mime detection), mbstring (UTF-8 prompts).
        // Soft: openssl (only matters if PHP wasn't built with it, which
        // would break HTTPS regardless — list it for visibility).
        $requiredExts = ['curl', 'json', 'fileinfo', 'mbstring', 'openssl'];
        foreach ($requiredExts as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name' => $this->l10n->t('Extensão PHP: %s', [$ext]),
                'ok' => $loaded,
                'detail' => $loaded ? $this->l10n->t('carregada') : $this->l10n->t('não encontrada — instale php-%s', [$ext]),
            ];
        }

        // --- disk space ---
        $tmpDir = $this->tempManager->getTempBaseDir();
        $tmpFree = @disk_free_space($tmpDir);
        $checks[] = [
            'name' => $this->l10n->t('Espaço em %s', [$tmpDir]),
            'ok' => $tmpFree !== false && $tmpFree > 100 * 1024 * 1024,
            'detail' => $tmpFree !== false ? $this->l10n->t('%s livres', [$this->humanBytes($tmpFree)]) : $this->l10n->t('não foi possível ler')
        ];

        $dataDir = $this->config->getSystemValue('datadirectory', '');
        if ($dataDir) {
            $dataFree = @disk_free_space($dataDir);
            $checks[] = [
                'name' => $this->l10n->t('Espaço no diretório de dados'),
                'ok' => $dataFree !== false && $dataFree > 500 * 1024 * 1024,
                'detail' => $dataFree !== false ? $this->l10n->t('%s livres', [$this->humanBytes($dataFree)]) : $this->l10n->t('não foi possível ler')
            ];
        }

        $allOk = true;
        foreach ($checks as $c) {
            if (!$c['ok']) { $allOk = false; break; }
        }

        return new JSONResponse([
            'ok' => $allOk,
            'checks' => $checks,
        ]);
    }

    /**
     * Locate a binary on the configured PATH and run it with a single argument
     * to capture its version. Uses proc_open with an array argv (no shell)
     * so there's no injection surface even though all inputs here are static.
     */
    private function probeBinary(string $bin, string $arg): array {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        $found = '';
        foreach ($paths as $p) {
            $candidate = rtrim($p, '/') . '/' . $bin;
            if (is_executable($candidate)) {
                $found = $candidate;
                break;
            }
        }
        if (!$found) {
            return ['name' => $bin, 'ok' => false, 'detail' => $this->l10n->t('não encontrado no PATH')];
        }

        $proc = @proc_open([$found, $arg], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            return ['name' => $bin, 'ok' => false, 'detail' => $this->l10n->t('falha ao executar')];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $first = trim(strtok($stdout, "\n") ?: '');
        return [
            'name' => $bin,
            'ok' => $rc === 0,
            'detail' => $rc === 0 ? ($first ?: 'OK') : $this->l10n->t('erro de execução')
        ];
    }

    private function humanBytes(int|float $bytes): string {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / 1024 / 1024 / 1024, 2, ',', '.') . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 0) . ' MB';
        }
        return number_format($bytes / 1024, 0) . ' KB';
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function testConnection(): JSONResponse {
        try {
            $provider = $this->config->getAppValue($this->appName, 'ai_provider', 'ollama');
            $url = $this->config->getAppValue($this->appName, 'ai_url', 'http://localhost:11434');
            $apiKey = $this->crypto->decrypt($this->config->getAppValue($this->appName, 'api_key', ''));

            // Build test URL based on provider
            $testUrl = match($provider) {
                'ollama' => rtrim($url, '/') . '/api/tags',
                'openai' => 'https://api.openai.com/v1/models',
                'gemini' => 'https://generativelanguage.googleapis.com/v1/models',
                default => $url
            };

            // Keys travel in headers, never in the URL.
            $headers = [];
            if (!empty($apiKey)) {
                $headers = match($provider) {
                    'openai' => ['Authorization' => 'Bearer ' . $apiKey],
                    'gemini' => ['x-goog-api-key' => $apiKey],
                    default => []
                };
            }

            // IClient, like the real processing: same proxy settings and same
            // refusal of local addresses, so "OK" here means processing works.
            $response = $this->clientService->newClient()->get($testUrl, [
                'headers' => $headers,
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
            $httpCode = $response->getStatusCode();

            if ($httpCode >= 200 && $httpCode < 400) {
                return new JSONResponse([
                    'status' => 'success',
                    'message' => $this->l10n->t('Conexão estabelecida com sucesso!')
                ]);
            }
            return new JSONResponse([
                'status' => 'error',
                'message' => $this->l10n->t('Falha na conexão. Código HTTP: %s', [(string)$httpCode])
            ], 400);
        } catch (LocalServerException $e) {
            return new JSONResponse(['status' => 'error', 'message' => $this->localBlockedHint()], 400);
        } catch (\Throwable $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => $this->l10n->t('Erro ao testar a conexão: %s', [$this->redactor->redact($e->getMessage())])
            ], 500);
        }
    }
}
