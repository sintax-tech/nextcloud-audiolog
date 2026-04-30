<?php
declare(strict_types=1);

namespace OCA\Audiolog\Controller;

use OCA\Audiolog\Service\CryptoHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCA\Audiolog\Settings\AdminSettings;

class SettingsController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private CryptoHelper $crypto
    ) {
        parent::__construct($appName, $request);
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
        string $realtime_stt_model = 'gemini-2.5-flash-native-audio-preview-12-2025',
        string $max_jobs_per_user_per_day = '50',
        string $use_google_stt_for_transcription = 'false',
        string $google_stt_api_key = '',
        string $realtime_stt_admin_only = 'true',
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
            $this->config->setAppValue($this->appName, 'realtime_stt_model', $realtime_stt_model);
            $this->config->setAppValue($this->appName, 'max_jobs_per_user_per_day', $max_jobs_per_user_per_day);
            $this->config->setAppValue($this->appName, 'use_google_stt_for_transcription', $use_google_stt_for_transcription);
            // Google STT key is encrypted at rest like the Gemini key.
            $this->config->setAppValue($this->appName, 'google_stt_api_key', $this->crypto->encrypt($google_stt_api_key));
            $this->config->setAppValue($this->appName, 'realtime_stt_admin_only', $realtime_stt_admin_only);
            $this->config->setAppValue($this->appName, 'analysis_model', $analysis_model);

            $groupsJson = json_encode($allowed_groups ?? []);
            $this->config->setAppValue($this->appName, 'allowed_groups', $groupsJson);

            return new JSONResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
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
            'realtime_stt_model' => $this->config->getAppValue($this->appName, 'realtime_stt_model', 'gemini-2.5-flash-native-audio-preview-12-2025'),
            'max_jobs_per_user_per_day' => $this->config->getAppValue($this->appName, 'max_jobs_per_user_per_day', '50'),
            'use_google_stt_for_transcription' => $this->config->getAppValue($this->appName, 'use_google_stt_for_transcription', 'false'),
            'google_stt_api_key' => $this->crypto->decrypt($this->config->getAppValue($this->appName, 'google_stt_api_key', '')),
            'realtime_stt_admin_only' => $this->config->getAppValue($this->appName, 'realtime_stt_admin_only', 'true'),
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
            'name' => 'API key configurada',
            'ok' => !empty($apiKey),
            'detail' => empty($apiKey) ? 'Configure a chave em "Chave de API"' : 'OK (' . strlen($apiKey) . ' chars)'
        ];

        // --- Gemini reachable? ---
        $aiUrl = rtrim($this->config->getAppValue($this->appName, 'ai_url', ''), '/');
        if ($aiUrl) {
            $geminiOk = false;
            $geminiDetail = '';
            try {
                $ch = curl_init($aiUrl);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 6);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                $geminiOk = $code > 0 && $code < 500;
                $geminiDetail = $geminiOk ? "HTTP {$code}" : ($err ?: "HTTP {$code}");
            } catch (\Throwable $e) {
                $geminiDetail = $e->getMessage();
            }
            $checks[] = [
                'name' => 'Conectividade ' . parse_url($aiUrl, PHP_URL_HOST),
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
                'name' => 'Extensão PHP: ' . $ext,
                'ok' => $loaded,
                'detail' => $loaded ? 'carregada' : 'não encontrada — instale php-' . $ext,
            ];
        }

        // --- disk space ---
        $tmpFree = @disk_free_space(sys_get_temp_dir());
        $checks[] = [
            'name' => 'Espaço em ' . sys_get_temp_dir(),
            'ok' => $tmpFree !== false && $tmpFree > 100 * 1024 * 1024,
            'detail' => $tmpFree !== false ? $this->humanBytes($tmpFree) . ' livres' : 'não foi possível ler'
        ];

        $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', '');
        if ($dataDir) {
            $dataFree = @disk_free_space($dataDir);
            $checks[] = [
                'name' => 'Espaço em data dir',
                'ok' => $dataFree !== false && $dataFree > 500 * 1024 * 1024,
                'detail' => $dataFree !== false ? $this->humanBytes($dataFree) . ' livres' : 'não foi possível ler'
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
            return ['name' => $bin, 'ok' => false, 'detail' => 'não encontrado no PATH'];
        }

        $proc = @proc_open([$found, $arg], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            return ['name' => $bin, 'ok' => false, 'detail' => 'falha ao executar'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $first = trim(strtok($stdout, "\n") ?: '');
        return [
            'name' => $bin,
            'ok' => $rc === 0,
            'detail' => $rc === 0 ? ($first ?: 'OK') : 'erro de execução'
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
                'gemini' => 'https://generativelanguage.googleapis.com/v1/models?key=' . $apiKey,
                default => $url
            };

            $ch = curl_init($testUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            if (!empty($apiKey) && $provider !== 'gemini') {
                $headers = match($provider) {
                    'openai' => ['Authorization: Bearer ' . $apiKey],
                    default => []
                };
                if (!empty($headers)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                }
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400) {
                return new JSONResponse([
                    'status' => 'success',
                    'message' => 'Conexao estabelecida com sucesso!'
                ]);
            } else {
                return new JSONResponse([
                    'status' => 'error',
                    'message' => "Falha na conexao. Codigo HTTP: {$httpCode}"
                ], 400);
            }

        } catch (\Exception $e) {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Erro ao testar conexao: ' . $e->getMessage()
            ], 500);
        }
    }
}
