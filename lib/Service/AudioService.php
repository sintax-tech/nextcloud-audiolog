<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCA\Audiolog\AppInfo\Application;
use OCA\Audiolog\Db\Job;
use OCA\Audiolog\Db\JobMapper;
use OCP\Http\Client\IClientService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

// (CryptoHelper is in the same namespace, no use needed)

class AudioService {
    private string $appName = Application::APP_ID;

    public function __construct(
        private IClientService $clientService,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private IConfig $config,
        private CryptoHelper $crypto,
        private JobMapper $jobMapper
    ) {
    }

    /**
     * Hand the controller an HTTP client built on Nextcloud's IClientService
     * so the STT proxy endpoint can use the same connection pool / proxy
     * settings the rest of the service does.
     */
    public function getHttpClient(): \OCP\Http\Client\IClient {
        return $this->clientService->newClient();
    }

    /**
     * Throws RuntimeException with HTTP-friendly message if the user has hit
     * the per-day quota configured by the admin. 0 = unlimited.
     */
    private function enforceRateLimit(string $userId): void {
        $max = (int)$this->config->getAppValue($this->appName, 'max_jobs_per_user_per_day', '50');
        if ($max <= 0) {
            return;
        }
        $since = time() - (24 * 3600);
        $count = $this->jobMapper->countSince($userId, $since);
        if ($count >= $max) {
            throw new \RuntimeException(
                "Limite diário de processamentos atingido ({$count}/{$max}). Tente novamente amanhã ou peça ao admin para aumentar a cota."
            );
        }
    }

    /**
     * Logs every processing attempt to the audiolog_jobs table — feeds rate
     * limiting, the future async polling endpoint, and usage analytics.
     */
    private function recordJob(string $userId, string $type, string $status, array $extra = []): ?Job {
        try {
            $job = new Job();
            $job->setUserId($userId);
            $job->setType($type);
            $job->setStatus($status);
            $job->setSourcePath($extra['sourcePath'] ?? null);
            $job->setOutputTypes(isset($extra['outputTypes']) ? json_encode($extra['outputTypes']) : null);
            $job->setPrompt($extra['prompt'] ?? null);
            $job->setResultText($extra['resultText'] ?? null);
            $job->setError($extra['error'] ?? null);
            $job->setDurationSeconds($extra['durationSeconds'] ?? null);
            $now = time();
            $job->setCreatedAt($now);
            if ($status === 'completed' || $status === 'failed') {
                $job->setFinishedAt($now);
            }
            return $this->jobMapper->insert($job);
        } catch (\Throwable $e) {
            $this->logger->warning('Audiolog: failed to record job: ' . $e->getMessage());
            return null;
        }
    }

    private function getApiKey(): string {
        return $this->crypto->decrypt($this->config->getAppValue($this->appName, 'api_key', ''));
    }

    private function getModel(): string {
        return $this->config->getAppValue($this->appName, 'ai_model', 'whisper-large-v3');
    }

    private function getLanguage(): string {
        return $this->config->getAppValue($this->appName, 'language', 'pt');
    }

    private function shouldSaveAudio(): bool {
        return $this->config->getAppValue($this->appName, 'save_audio', 'true') === 'true';
    }

    private function getProvider(): string {
        return $this->config->getAppValue($this->appName, 'ai_provider', 'ollama');
    }

    private function getBaseUrl(): string {
        return $this->config->getAppValue($this->appName, 'ai_url', 'http://localhost:11434');
    }

    private function getFilesApiThresholdBytes(): int {
        $mb = (int)$this->config->getAppValue($this->appName, 'gemini_files_api_threshold', '18');
        if ($mb <= 0) {
            $mb = 18;
        }
        return $mb * 1024 * 1024;
    }

    private function getFilesApiForce(): bool {
        return $this->config->getAppValue($this->appName, 'gemini_files_api_force', 'false') === 'true';
    }

    /**
     * Mask API key in URLs for logging (replace ?key=... with ?key=***).
     */
    private function maskUrl(string $url): string {
        return preg_replace('/([?&]key=)[^&]+/', '$1***', $url) ?? $url;
    }

    /**
     * Human-readable language name for prompt building. Empty string when the
     * admin selected "auto" — the model should detect the language itself.
     */
    private function getLanguageName(): string {
        $lang = $this->getLanguage();
        return match($lang) {
            'pt' => 'português brasileiro',
            'en' => 'English',
            'es' => 'español',
            'auto' => '',
            default => '', // unknown → let the model auto-detect
        };
    }

    /**
     * Build the "transcribe in <lang>" instruction. When language is auto,
     * we just say "transcribe faithfully" without forcing a target language.
     */
    private function transcribeInstruction(): string {
        $name = $this->getLanguageName();
        return $name !== ''
            ? "Transcreva este áudio em {$name} de forma fiel e fluida."
            : 'Transcreva este áudio fielmente, no idioma original em que foi falado.';
    }
    /**
     * Sanitize AI response to remove infinite loops/patterns
     */
    private function sanitizeResponse(string $text): string {
        // Remove sequências repetidas de caracteres (padrão de loop)
        // Ex: ":::::::..." ou "--------..."
        $text = preg_replace('/(.)\1{50,}/', '', $text);
        
        // Remove linhas de tabela vazias ou malformadas
        $text = preg_replace('/\|[\s:|-]{100,}\|/', '', $text);
        
        // Remove sequências de | : - repetidas
        $text = preg_replace('/[:|-]{50,}/', '', $text);
        
        // Limitar tamanho máximo (100KB de texto)
        if (strlen($text) > 100000) {
            $text = substr($text, 0, 100000) . "\n\n[Resposta truncada por exceder limite de tamanho]";
        }
        
        // Limpar linhas vazias excessivas
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        
        return trim($text);
    }



    private function buildPrompt(array $outputTypes, string $customPrompt): string {
        $langName = $this->getLanguageName();
        $langClause = $langName !== '' ? " em {$langName}" : '';

        $prompts = [];

        if (in_array('transcricao', $outputTypes)) {
            $prompts[] = "Transcreva este áudio{$langClause} de forma fiel e fluida. " .
                "Identifique CLARAMENTE cada falante distinto pela voz e jeito de falar. " .
                "Use [Falante 1], [Falante 2], etc. (ou o nome se mencionado) em uma nova linha antes de cada fala. " .
                "Mantenha a pontuação correta e separe parágrafos quando o tópico mudar.";
        }

        if (in_array('ata', $outputTypes)) {
            $prompts[] = "Gere uma ata formal da reunião com: Data/Hora, Participantes, Pauta, Discussões, Decisões e Encaminhamentos.";
        }

        if (in_array('resumo', $outputTypes)) {
            $prompts[] = "Faça um resumo executivo dos principais pontos discutidos.";
        }

        if (in_array('pauta', $outputTypes)) {
            $prompts[] = "Liste os tópicos/assuntos abordados em formato de pauta.";
        }

        if (in_array('tarefas', $outputTypes)) {
            $prompts[] = "TAREFAS: Extraia SOMENTE tarefas que foram EXPLICITAMENTE delegadas ou combinadas durante a conversa.
Uma tarefa válida DEVE ter:
- Uma AÇÃO clara (fazer, enviar, criar, verificar, etc.)
- Ter sido ATRIBUÍDA a alguém OU assumida por alguém

NÃO inclua como tarefa:
- Comentários gerais ou observações
- Tópicos discutidos sem ação definida
- Sugestões ou ideias não confirmadas
- Processos já existentes ou rotineiros

Se NÃO houver tarefas explícitas, escreva apenas: Nenhuma tarefa identificada.

Se houver tarefas, use EXATAMENTE este formato de tabela:
| Tarefa | Responsável | Prazo |
| Descrição da ação | Nome | Data ou Não definido |";
        }

        $basePrompt = implode("\n\n", $prompts);

        if (!empty($customPrompt)) {
            $basePrompt .= "\n\nInstruções adicionais: " . $customPrompt;
        }

        return $basePrompt;
    }

    /**
     * Process audio with Gemini API.
     *
     * Auto-switches between two transports:
     *  - inline_data (base64) for small files (under threshold)
     *  - Files API (resumable upload + fileData reference) for large files,
     *    or when admin forces it via gemini_files_api_force=true.
     *
     * Files API is required for files larger than ~20MB; inline base64 there
     * blows past Gemini's request size limits and silently fails.
     */
    private function processWithGemini(string $audioContent, string $mimeType, string $prompt): string {
        $size = strlen($audioContent);
        $threshold = $this->getFilesApiThresholdBytes();
        $useFilesApi = $this->getFilesApiForce() || $size >= $threshold;

        if ($useFilesApi) {
            $this->logger->info("Audiolog: Using Files API (size={$size} bytes, threshold={$threshold})");
            try {
                return $this->processWithGeminiFilesApi($audioContent, $mimeType, $prompt);
            } catch (\Throwable $e) {
                // Fallback to inline only if file is small enough — inline cap is ~20MB.
                if ($size < 18 * 1024 * 1024) {
                    $this->logger->warning('Audiolog: Files API failed, falling back to inline_data: ' . $e->getMessage());
                    return $this->processWithGeminiInline($audioContent, $mimeType, $prompt);
                }
                throw $e;
            }
        }

        $this->logger->info("Audiolog: Using inline_data path (size={$size} bytes)");
        return $this->processWithGeminiInline($audioContent, $mimeType, $prompt);
    }

    /**
     * Inline path: base64-encodes audio and sends in a single :generateContent call.
     * Only safe for small files (~<20MB total request body).
     */
    private function processWithGeminiInline(string $audioContent, string $mimeType, string $prompt): string {
        $client = $this->clientService->newClient();
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $baseUrl = rtrim($this->getBaseUrl(), '/');

        $apiUrl = "{$baseUrl}/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $audioBase64 = base64_encode($audioContent);

        $requestBody = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt ?: $this->transcribeInstruction()],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $audioBase64
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 65536
            ]
        ];

        $response = $client->post($apiUrl, [
            'json' => $requestBody,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 600
        ]);

        $result = json_decode($response->getBody(), true);

        if (isset($result['error'])) {
            throw new \RuntimeException('Erro Gemini: ' . ($result['error']['message'] ?? json_encode($result['error'])));
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->sanitizeResponse($text);
    }

    /**
     * Files API path: upload audio to Gemini Files API, wait for ACTIVE state,
     * then reference it via fileData in :generateContent. Removes the file at
     * the end (best-effort).
     *
     * Reference: https://ai.google.dev/api/files
     */
    private function processWithGeminiFilesApi(string $audioContent, string $mimeType, string $prompt): string {
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        $size = strlen($audioContent);

        $fileResource = $this->uploadGeminiFile($audioContent, $mimeType, $apiKey, $baseUrl);
        $fileName = $fileResource['name'] ?? null;
        $fileUri = $fileResource['uri'] ?? null;

        if (!$fileName || !$fileUri) {
            throw new \RuntimeException('Files API: resposta inválida no upload (sem name/uri)');
        }

        try {
            $this->waitForFileActive($fileName, $apiKey, $baseUrl);

            $client = $this->clientService->newClient();
            $apiUrl = "{$baseUrl}/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $requestBody = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt ?: $this->transcribeInstruction()],
                            [
                                'file_data' => [
                                    'mime_type' => $mimeType,
                                    'file_uri' => $fileUri
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 65536
                ]
            ];

            $response = $client->post($apiUrl, [
                'json' => $requestBody,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 900
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['error'])) {
                throw new \RuntimeException('Erro Gemini (Files API): ' . ($result['error']['message'] ?? json_encode($result['error'])));
            }

            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return $this->sanitizeResponse($text);

        } finally {
            // Best-effort cleanup; do not let a delete failure mask the real result.
            try {
                $this->deleteGeminiFile($fileName, $apiKey, $baseUrl);
            } catch (\Throwable $cleanupErr) {
                $this->logger->warning('Audiolog: file cleanup failed for ' . $fileName . ': ' . $cleanupErr->getMessage());
            }
        }
    }

    /**
     * Resumable upload to Gemini Files API.
     * Two-step: start (returns upload URL via X-Goog-Upload-URL header) then upload+finalize.
     * Returns the file resource with name and uri.
     */
    private function uploadGeminiFile(string $content, string $mimeType, string $apiKey, string $baseUrl): array {
        $client = $this->clientService->newClient();
        $size = strlen($content);

        // Step 1: start
        $startUrl = "{$baseUrl}/upload/v1beta/files?key={$apiKey}";
        $startResponse = $client->post($startUrl, [
            'headers' => [
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string)$size,
                'X-Goog-Upload-Header-Content-Type' => $mimeType,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['file' => ['display_name' => 'assistaudio_' . uniqid('', true)]]),
            'timeout' => 60
        ]);

        $uploadUrl = '';
        $headers = $startResponse->getHeaders();
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'x-goog-upload-url') {
                $uploadUrl = is_array($values) ? ($values[0] ?? '') : $values;
                break;
            }
        }

        if (empty($uploadUrl)) {
            throw new \RuntimeException('Files API: X-Goog-Upload-URL ausente na resposta de start');
        }

        // Step 2: upload bytes and finalize
        $uploadResponse = $client->post($uploadUrl, [
            'headers' => [
                'Content-Length' => (string)$size,
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ],
            'body' => $content,
            'timeout' => 900
        ]);

        $result = json_decode($uploadResponse->getBody(), true);
        if (!is_array($result) || empty($result['file'])) {
            $this->logger->error('Audiolog: Files API upload returned unexpected payload: ' . substr((string)$uploadResponse->getBody(), 0, 500));
            throw new \RuntimeException('Files API: resposta inesperada no upload');
        }

        return $result['file'];
    }

    /**
     * Poll the Files API until the file reaches ACTIVE state. Times out after
     * ~60s of polling. Audio files typically transition in <5s.
     */
    private function waitForFileActive(string $fileName, string $apiKey, string $baseUrl): void {
        $client = $this->clientService->newClient();
        $statusUrl = "{$baseUrl}/v1beta/{$fileName}?key={$apiKey}";
        $deadline = time() + 60;

        while (time() < $deadline) {
            $response = $client->get($statusUrl, ['timeout' => 10]);
            $info = json_decode($response->getBody(), true);
            $state = $info['state'] ?? 'PROCESSING';

            if ($state === 'ACTIVE') {
                return;
            }
            if ($state === 'FAILED') {
                throw new \RuntimeException('Files API: arquivo entrou em estado FAILED');
            }

            usleep(2_000_000); // 2s
        }

        throw new \RuntimeException('Files API: timeout aguardando arquivo ficar ACTIVE');
    }

    private function deleteGeminiFile(string $fileName, string $apiKey, string $baseUrl): void {
        $client = $this->clientService->newClient();
        $deleteUrl = "{$baseUrl}/v1beta/{$fileName}?key={$apiKey}";
        $client->delete($deleteUrl, ['timeout' => 30]);
    }

    /**
     * Process audio with Whisper API (Ollama/OpenAI)
     */
    private function processWithWhisper(string $audioContent, string $filename, string $mimeType, string $prompt): string {
        $client = $this->clientService->newClient();
        $provider = $this->getProvider();
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $language = $this->getLanguage();
        $baseUrl = rtrim($this->getBaseUrl(), '/');

        // Build API URL
        $apiUrl = match($provider) {
            'ollama' => "{$baseUrl}/v1/audio/transcriptions",
            'openai' => 'https://api.openai.com/v1/audio/transcriptions',
            default => "{$baseUrl}/v1/audio/transcriptions"
        };

        $multipart = [
            [
                'name' => 'file',
                'contents' => $audioContent,
                'filename' => $filename,
                'headers' => ['Content-Type' => $mimeType]
            ],
            [
                'name' => 'model',
                'contents' => $model
            ]
        ];

        // Whisper auto-detects when language is omitted. Only set it when
        // the admin chose a specific language (not 'auto'); 'pt'/'en'/'es'
        // map to the ISO 639-1 codes Whisper expects.
        if ($language !== '' && $language !== 'auto') {
            $multipart[] = [
                'name' => 'language',
                'contents' => $language,
            ];
        }

        if (!empty($prompt)) {
            $multipart[] = [
                'name' => 'prompt',
                'contents' => $prompt
            ];
        }

        $requestOptions = [
            'multipart' => $multipart,
            'timeout' => 600
        ];

        // Add Authorization header whenever the admin configured a key —
        // both OpenAI and authenticated Ollama deployments (cloud-hosted,
        // behind a gateway, etc) accept Bearer tokens. Local Ollama just
        // ignores it.
        if (!empty($apiKey)) {
            $requestOptions['headers'] = ['Authorization' => 'Bearer ' . $apiKey];
        }

        $response = $client->post($apiUrl, $requestOptions);
        $responseBody = $response->getBody();
        $result = json_decode($responseBody, true);

        if (isset($result['error'])) {
            throw new \RuntimeException('Erro API: ' . ($result['error']['message'] ?? json_encode($result['error'])));
        }

        return $result['text'] ?? '';
    }

    /**
     * Run a chat-completion against any OpenAI-compatible endpoint
     * (OpenAI itself, Ollama, vLLM, LM Studio, etc). Used to derive
     * resumo/ata/pauta/tarefas from a transcript when the active
     * provider is NOT gemini — so a user choosing OpenAI or Ollama
     * end-to-end still gets the analysis outputs without a hidden
     * Gemini round-trip.
     */
    private function processWithChatCompletion(string $transcript, string $prompt): string {
        $client = $this->clientService->newClient();
        $provider = $this->getProvider();
        $apiKey = $this->getApiKey();
        $baseUrl = rtrim($this->getBaseUrl(), '/');

        // Per provider: pick the chat URL and a sensible default model.
        $apiUrl = match($provider) {
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'ollama' => "{$baseUrl}/v1/chat/completions",
            default => "{$baseUrl}/v1/chat/completions"
        };
        // Admin can override per provider via `analysis_model`. If empty,
        // fall back to a small/cheap default per provider.
        $analysisModel = trim($this->config->getAppValue($this->appName, 'analysis_model', ''));
        if ($analysisModel === '') {
            $analysisModel = match($provider) {
                'openai' => 'gpt-4o-mini',
                'ollama' => 'llama3.1',
                default => 'llama3.1'
            };
        }

        $body = [
            'model' => $analysisModel,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $transcript],
            ],
            'temperature' => 0.3,
        ];
        $opts = [
            'json' => $body,
            'timeout' => 600,
        ];
        if (!empty($apiKey)) {
            $opts['headers'] = ['Authorization' => 'Bearer ' . $apiKey];
        }
        $response = $client->post($apiUrl, $opts);
        $resp = json_decode($response->getBody(), true);
        if (isset($resp['error'])) {
            throw new \RuntimeException('Erro API: ' . ($resp['error']['message'] ?? json_encode($resp['error'])));
        }
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Main method to process audio - routes to appropriate provider
     */
    private function transcribeAudio(string $audioContent, string $filename, string $mimeType, array $outputTypes, string $customPrompt): string {
        $provider = $this->getProvider();
        $useGoogleStt = $this->config->getAppValue($this->appName, 'use_google_stt_for_transcription', 'false') === 'true';

        // Helper: run any extras (resumo/ata/pauta/tarefas) on a transcript
        // string, using the SAME provider that did the transcription.
        // Gemini stays on Gemini, OpenAI/Ollama use their own chat-completion.
        $analyzeExtras = function (string $transcript, array $extras) use ($provider, $customPrompt) {
            if (empty($extras)) {
                return '';
            }
            $extraPrompt = $this->buildPrompt($extras, $customPrompt);
            if ($provider === 'gemini') {
                return $this->processGeminiTextOnly($transcript, $extraPrompt);
            }
            // openai / ollama / any OpenAI-compatible endpoint
            return $this->processWithChatCompletion($transcript, $extraPrompt);
        };

        // Path 1: admin opted in to Google STT for transcription.
        if ($useGoogleStt && in_array('transcricao', $outputTypes, true)) {
            $transcript = $this->processWithGoogleSTT($audioContent, $mimeType);
            $extras = array_diff($outputTypes, ['transcricao']);
            $extraText = $analyzeExtras($transcript, $extras);
            return $extraText !== '' ? ($transcript . "\n\n---\n\n" . $extraText) : $transcript;
        }

        // Path 2: Gemini handles audio + analysis natively in one call.
        if ($provider === 'gemini') {
            $prompt = $this->buildPrompt($outputTypes, $customPrompt);
            return $this->processWithGemini($audioContent, $mimeType, $prompt);
        }

        // Path 3: OpenAI/Ollama (Whisper for STT). Whisper only transcribes,
        // so we always get the transcript first; if the user asked for
        // resumo/ata/etc, we send the transcript to the same provider's
        // chat-completion endpoint. No silent Gemini round-trip.
        $whisperPrompt = '';
        if (!empty($customPrompt)) {
            // Whisper uses `prompt` as a context hint to bias recognition,
            // not as a full instruction. Pass through customPrompt verbatim.
            $whisperPrompt = $customPrompt;
        }
        $transcript = $this->processWithWhisper($audioContent, $filename, $mimeType, $whisperPrompt);
        $extras = array_diff($outputTypes, ['transcricao']);
        if (empty($extras)) {
            return $transcript;
        }
        $extraText = $analyzeExtras($transcript, $extras);
        return $extraText !== '' ? ($transcript . "\n\n---\n\n" . $extraText) : $transcript;
    }

    /**
     * Transcribe audio with Google Cloud Speech-to-Text v1p1beta1 longrunning
     * recognize. Returns text formatted with [Falante N] markers when the
     * audio has more than one speaker (native diarization).
     *
     * For audios up to ~60s we use the synchronous endpoint; longer goes
     * through longrunningrecognize with status polling.
     *
     * Requires the same Gemini API key, with "Cloud Speech-to-Text API"
     * enabled in the same Google Cloud project.
     */
    private function processWithGoogleSTT(string $audioContent, string $mimeType): string {
        // Prefer a dedicated google_stt_api_key — Google blocks a single
        // restricted Gemini key from calling Cloud Speech-to-Text.
        $sttKeyRaw = $this->config->getAppValue($this->appName, 'google_stt_api_key', '');
        $sttKey = $sttKeyRaw !== '' ? $this->crypto->decrypt($sttKeyRaw) : '';
        $apiKey = $sttKey !== '' ? $sttKey : $this->getApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException('API key não configurada');
        }
        $client = $this->clientService->newClient();

        $language = $this->getLanguage();
        $languageCode = match ($language) {
            'en' => 'en-US',
            'es' => 'es-ES',
            default => 'pt-BR',
        };

        // Encoding: most uploads land here as audio/mpeg (mp3) after the
        // ffmpeg-extracted-from-video path or directly. Map to Google's enum.
        $encoding = $this->mapEncodingForGoogleStt($mimeType);

        $config = [
            'languageCode' => $languageCode,
            'enableAutomaticPunctuation' => true,
            'enableWordTimeOffsets' => false,
            'model' => 'latest_long',
            'diarizationConfig' => [
                'enableSpeakerDiarization' => true,
                'minSpeakerCount' => 1,
                'maxSpeakerCount' => 6,
            ],
        ];
        if ($encoding !== null) {
            $config['encoding'] = $encoding;
        }

        $audioContentB64 = base64_encode($audioContent);
        $body = [
            'config' => $config,
            'audio' => ['content' => $audioContentB64],
        ];

        // Try sync first only for very small payloads; otherwise go straight
        // to longrunningrecognize. Sync caps at ~1 minute / ~10MB.
        $useSync = strlen($audioContent) < 9 * 1024 * 1024;

        if ($useSync) {
            try {
                $url = "https://speech.googleapis.com/v1p1beta1/speech:recognize?key={$apiKey}";
                $resp = $client->post($url, [
                    'json' => $body,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 120,
                ]);
                $data = json_decode($resp->getBody(), true) ?: [];
                if (!isset($data['error'])) {
                    return $this->formatGoogleSttResults($data);
                }
                $this->logger->warning('AudioLog: Google STT sync failed, falling back to longrunning: ' . json_encode($data['error']));
            } catch (\Throwable $e) {
                $this->logger->warning('AudioLog: Google STT sync threw, falling back to longrunning: ' . $e->getMessage());
            }
        }

        // Long-running: enqueue, then poll the operation until done.
        $startUrl = "https://speech.googleapis.com/v1p1beta1/speech:longrunningrecognize?key={$apiKey}";
        $startResp = $client->post($startUrl, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 120,
        ]);
        $startData = json_decode($startResp->getBody(), true) ?: [];
        $opName = $startData['name'] ?? '';
        if (!$opName) {
            throw new \RuntimeException('Google STT: resposta sem operation name');
        }

        $deadline = time() + (30 * 60); // 30min hard cap
        while (time() < $deadline) {
            sleep(5);
            $statusUrl = "https://speech.googleapis.com/v1/operations/" . urlencode($opName) . "?key={$apiKey}";
            $statusResp = $client->get($statusUrl, ['timeout' => 30]);
            $status = json_decode($statusResp->getBody(), true) ?: [];
            if (isset($status['error'])) {
                throw new \RuntimeException('Google STT erro: ' . ($status['error']['message'] ?? json_encode($status['error'])));
            }
            if (!empty($status['done'])) {
                if (isset($status['response'])) {
                    return $this->formatGoogleSttResults($status['response']);
                }
                throw new \RuntimeException('Google STT: operação concluída sem resposta');
            }
        }
        throw new \RuntimeException('Google STT: timeout aguardando transcrição');
    }

    private function mapEncodingForGoogleStt(string $mimeType): ?string {
        $mt = strtolower($mimeType);
        // Most common after our ffmpeg-extract step: audio/mpeg.
        if (str_contains($mt, 'mpeg') || str_contains($mt, 'mp3')) return 'MP3';
        if (str_contains($mt, 'flac')) return 'FLAC';
        if (str_contains($mt, 'wav')) return 'LINEAR16';
        if (str_contains($mt, 'ogg')) return 'OGG_OPUS';
        if (str_contains($mt, 'webm')) return 'WEBM_OPUS';
        // Unknown — let Google auto-detect by leaving the field unset.
        return null;
    }

    /**
     * Build a [Falante N]-prefixed transcript out of a Google STT response.
     * Google emits diarization in the LAST result's `words[]` array, where
     * each word carries a `speakerTag`.
     */
    private function formatGoogleSttResults(array $resp): string {
        $results = $resp['results'] ?? [];
        if (empty($results)) {
            return '';
        }

        // Find the last result that carries word-level data with speakerTags
        // (that's where Google puts the diarization output).
        $diarized = null;
        for ($i = count($results) - 1; $i >= 0; $i--) {
            $alt = $results[$i]['alternatives'][0] ?? null;
            if ($alt && !empty($alt['words']) && isset($alt['words'][0]['speakerTag'])) {
                $diarized = $alt;
                break;
            }
        }

        if ($diarized) {
            $out = '';
            $currentSpeaker = null;
            $buffer = '';
            foreach ($diarized['words'] as $w) {
                $sp = $w['speakerTag'] ?? 0;
                $word = $w['word'] ?? '';
                if ($sp !== $currentSpeaker) {
                    if ($buffer !== '') {
                        $out .= trim($buffer) . "\n\n";
                    }
                    $buffer = '[Falante ' . $sp . "]\n" . $word;
                    $currentSpeaker = $sp;
                } else {
                    $buffer .= ' ' . $word;
                }
            }
            if ($buffer !== '') {
                $out .= trim($buffer);
            }
            return $out;
        }

        // No diarization data — concatenate plain transcripts.
        $parts = [];
        foreach ($results as $r) {
            $t = $r['alternatives'][0]['transcript'] ?? '';
            if ($t) $parts[] = $t;
        }
        return implode(' ', $parts);
    }

    /**
     * Transcribe with automatic long-audio fallback: if the source duration
     * exceeds `long_audio_split_threshold` minutes AND we're on Gemini, run
     * processLongAudio (map-reduce) — otherwise behave like the original
     * transcribeAudio (single shot).
     */
    private function transcribeWithLongAudioFallback(
        string $audioContent,
        string $filename,
        string $mimeType,
        array $outputTypes,
        string $customPrompt
    ): string {
        $provider = $this->getProvider();
        $thresholdMin = (int)$this->config->getAppValue($this->appName, 'long_audio_split_threshold', '25');
        if ($provider !== 'gemini' || $thresholdMin <= 0) {
            return $this->transcribeAudio($audioContent, $filename, $mimeType, $outputTypes, $customPrompt);
        }

        // Probe duration. Need a temp file because ffprobe reads from disk.
        $probeFile = tempnam(sys_get_temp_dir(), 'audiolog_probe_');
        file_put_contents($probeFile, $audioContent);
        try {
            $duration = $this->probeAudioDuration($probeFile);
        } finally {
            @unlink($probeFile);
        }

        if ($duration <= 0 || $duration < $thresholdMin * 60) {
            return $this->transcribeAudio($audioContent, $filename, $mimeType, $outputTypes, $customPrompt);
        }

        $this->logger->info(sprintf(
            'Audiolog: routing through long-audio split (duration=%.1fs, threshold=%dmin)',
            $duration, $thresholdMin
        ));
        return $this->processLongAudio($audioContent, $mimeType, $outputTypes, $customPrompt, $duration);
    }

    /**
     * Probe audio duration in seconds using ffprobe. Returns 0.0 if ffprobe
     * isn't available or fails — caller should fall back to single-shot transcribe.
     */
    private function probeAudioDuration(string $audioPath): float {
        $proc = @proc_open([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $audioPath,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) {
            return 0.0;
        }
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return (float)trim($out);
    }

    /**
     * Re-encode a slice of the audio to mp3 mono 16kHz, the smallest format
     * Gemini will happily transcribe. Uses proc_open with array argv (no shell).
     */
    private function ffmpegSlice(string $inputPath, float $startSec, float $durationSec, string $outputPath): bool {
        $proc = @proc_open([
            'ffmpeg', '-nostdin', '-y',
            '-ss', sprintf('%.3f', $startSec),
            '-t', sprintf('%.3f', $durationSec),
            '-i', $inputPath,
            '-vn',
            '-acodec', 'libmp3lame',
            '-ar', '16000',
            '-ac', '1',
            '-q:a', '4',
            $outputPath,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) return false;
        // drain
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        return $rc === 0 && is_file($outputPath) && filesize($outputPath) > 0;
    }

    /**
     * Map-reduce transcription for long audio:
     *  - cut the source into N chunks of `chunkSeconds` with `overlapSeconds`
     *    between them (so a sentence cut at the boundary survives in one of
     *    the two neighbours)
     *  - transcribe each chunk independently
     *  - dedupe the overlap by matching the tail of chunk[i] against the head
     *    of chunk[i+1] (greedy: longest overlap of up to ~80 words)
     *  - if the output types include resumo/ata/pauta/tarefas, run a SECOND
     *    Gemini call against the joined transcript (not the audio) to produce
     *    the structured outputs.
     */
    private function processLongAudio(
        string $audioContent,
        string $mimeType,
        array $outputTypes,
        string $customPrompt,
        float $totalDuration
    ): string {
        $thresholdMin = (int)$this->config->getAppValue($this->appName, 'long_audio_split_threshold', '25');
        $chunkSeconds = max(60, $thresholdMin * 60 - 60); // chunk slightly smaller than threshold
        $overlapSeconds = 30.0;

        $tmpDir = sys_get_temp_dir() . '/audiolog_split_' . uniqid('', true);
        if (!@mkdir($tmpDir, 0700, true)) {
            throw new \RuntimeException('Falha ao criar diretório temporário para split');
        }
        $sourcePath = $tmpDir . '/source.bin';
        file_put_contents($sourcePath, $audioContent);

        try {
            $segments = [];
            $start = 0.0;
            $idx = 0;
            while ($start < $totalDuration) {
                $duration = min($chunkSeconds + $overlapSeconds, $totalDuration - $start);
                $chunkPath = $tmpDir . '/chunk_' . str_pad((string)$idx, 3, '0', STR_PAD_LEFT) . '.mp3';
                if (!$this->ffmpegSlice($sourcePath, $start, $duration, $chunkPath)) {
                    throw new \RuntimeException('Falha ao recortar áudio no offset ' . $start);
                }
                $segments[] = $chunkPath;
                $start += $chunkSeconds; // overlap is inside the next chunk's prefix
                $idx++;
            }

            $totalChunks = count($segments);
            $this->logger->info("Audiolog: long audio split into {$totalChunks} chunks (chunk={$chunkSeconds}s overlap={$overlapSeconds}s)");

            // Transcribe each chunk and stitch with overlap dedup.
            $langName = $this->getLanguageName();
            $langClause = $langName !== '' ? " em {$langName}" : ' no idioma original';
            $transcriptionPrompt = "Transcreva fielmente este trecho de áudio{$langClause}. "
                . "Identifique CLARAMENTE cada falante distinto pela voz. "
                . "Use [Falante 1], [Falante 2], etc. em uma nova linha antes de cada fala. "
                . "Não adicione comentários, retorne só a transcrição.";

            $partials = [];
            foreach ($segments as $i => $chunkPath) {
                $part = $i + 1;
                $contextHint = '';
                if ($i > 0 && !empty($partials[$i - 1])) {
                    $tailWords = $this->lastWords($partials[$i - 1], 60);
                    $contextHint = "\n\nContexto do trecho anterior (NÃO repita): \"{$tailWords}\"";
                }
                $chunkContent = (string)file_get_contents($chunkPath);
                $chunkPrompt = "Esta é a parte {$part} de {$totalChunks}.\n" . $transcriptionPrompt . $contextHint;
                $text = $this->processWithGemini($chunkContent, 'audio/mpeg', $chunkPrompt);
                $partials[$i] = $text;
            }

            // Dedup overlap: for each pair (i, i+1), find the longest tail of i
            // that prefixes i+1, drop that prefix from i+1.
            $merged = $partials[0] ?? '';
            for ($i = 1; $i < count($partials); $i++) {
                $next = $this->stripOverlap($merged, $partials[$i]);
                $merged .= "\n\n" . $next;
            }

            // If the user wants summary/minutes/agenda/tasks too, run a second
            // pass over the merged transcript (text-only, fast and reliable).
            $needsStructured = !empty(array_intersect($outputTypes, ['resumo', 'ata', 'pauta', 'tarefas']));
            if ($needsStructured) {
                $structuredPrompt = $this->buildPrompt(
                    array_diff($outputTypes, ['transcricao']),
                    $customPrompt
                );
                $structuredText = $this->processGeminiTextOnly($merged, $structuredPrompt);
                if (in_array('transcricao', $outputTypes, true)) {
                    return $merged . "\n\n---\n\n" . $structuredText;
                }
                return $structuredText;
            }

            return $merged;
        } finally {
            // cleanup chunks
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    private function lastWords(string $text, int $count): string {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (count($words) <= $count) {
            return implode(' ', $words);
        }
        return implode(' ', array_slice($words, -$count));
    }

    /**
     * If $next starts with a substring that overlaps with the tail of $prev,
     * remove that overlap so the merged transcript doesn't repeat itself.
     * Greedy: looks for the longest common run up to ~80 words.
     */
    private function stripOverlap(string $prev, string $next): string {
        $maxWords = 80;
        $prevWords = preg_split('/\s+/', trim($prev)) ?: [];
        $nextWords = preg_split('/\s+/', trim($next)) ?: [];
        if (empty($prevWords) || empty($nextWords)) {
            return $next;
        }
        $tail = array_slice($prevWords, -$maxWords);
        $tailLen = count($tail);
        // Try descending lengths to find largest overlap
        for ($len = min($tailLen, count($nextWords)); $len >= 6; $len--) {
            $tailSlice = array_slice($tail, -$len);
            $headSlice = array_slice($nextWords, 0, $len);
            // Compare normalized
            $a = strtolower(implode(' ', $tailSlice));
            $b = strtolower(implode(' ', $headSlice));
            if ($a === $b) {
                return implode(' ', array_slice($nextWords, $len));
            }
        }
        return $next;
    }

    /**
     * Text-only Gemini call — used after the long-audio map step to derive
     * resumo/ata/pauta/tarefas from the joined transcript without re-uploading
     * the audio.
     */
    private function processGeminiTextOnly(string $text, string $prompt): string {
        $client = $this->clientService->newClient();
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        $apiUrl = "{$baseUrl}/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt . "\n\nTranscrição:\n" . $text]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 65536,
            ],
        ];
        $response = $client->post($apiUrl, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 600,
        ]);
        $result = json_decode($response->getBody(), true);
        if (isset($result['error'])) {
            throw new \RuntimeException('Erro Gemini (text-only): ' . ($result['error']['message'] ?? 'desconhecido'));
        }
        $out = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return $this->sanitizeResponse($out);
    }

    /**
     * Check if the file is a video
     */
    private function isVideoFile(string $mimeType): bool {
        return str_starts_with($mimeType, 'video/');
    }

    /**
     * Extract audio from video file using ffmpeg
     */
    private function extractAudioFromVideo(string $videoPath): array {
        $outputPath = sys_get_temp_dir() . '/audio_' . uniqid() . '.mp3';

        // Use ffmpeg to extract audio
        $command = sprintf(
            'ffmpeg -i %s -vn -acodec libmp3lame -q:a 2 -y %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            $this->logger->error("FFmpeg extraction failed: " . implode("\n", $output));
            throw new \RuntimeException('Falha ao extrair áudio do vídeo. Verifique se ffmpeg está instalado.');
        }

        return [
            'path' => $outputPath,
            'content' => file_get_contents($outputPath),
            'mimeType' => 'audio/mpeg',
            'filename' => pathinfo($outputPath, PATHINFO_FILENAME) . '.mp3'
        ];
    }

    public function processAudio(
        string $tmpPath,
        string $filename,
        string $mimeType,
        string $prompt,
        array $outputTypes,
        string $userId,
        string $title = ''
    ): array {
        $this->enforceRateLimit($userId);
        $startTs = time();

        $audioContent = file_get_contents($tmpPath);
        $extractedAudioPath = null;

        if ($audioContent === false) {
            $this->recordJob($userId, 'process', 'failed', [
                'sourcePath' => $filename,
                'outputTypes' => $outputTypes,
                'error' => 'Falha ao ler arquivo',
            ]);
            throw new \RuntimeException('Falha ao ler arquivo');
        }

        // If it's a video, extract audio first
        if ($this->isVideoFile($mimeType)) {
            $this->logger->info("AssistAudio: Extracting audio from video file: {$filename}");
            $extracted = $this->extractAudioFromVideo($tmpPath);
            $audioContent = $extracted['content'];
            $mimeType = $extracted['mimeType'];
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.mp3';
            $extractedAudioPath = $extracted['path'];
        }

        // Transcribe/process audio — but if it's a long audio AND we're using
        // Gemini, route through the chunked map-reduce path so the model
        // doesn't truncate.
        $text = $this->transcribeWithLongAudioFallback($audioContent, $filename, $mimeType, $outputTypes, $prompt);

        // Clean up extracted audio file
        if ($extractedAudioPath && file_exists($extractedAudioPath)) {
            unlink($extractedAudioPath);
        }

        // Save audio file to user's files (if enabled)
        $savedFile = '';
        if ($this->shouldSaveAudio()) {
            $savedFile = $this->saveAudioFile($userId, $audioContent, $filename, $title);
        }

        // Extract tasks if any selected type includes tarefas or ata
        $tasks = [];
        if (in_array('tarefas', $outputTypes) || in_array('ata', $outputTypes)) {
            $tasks = $this->extractTasks($text);
        }

        // Track this run as an in-memory output entry on the saved audio's meta.
        // The actual on-disk note is created later by saveToNotes/saveToDocx,
        // which call appendOutputToMeta with the final path/fileId.
        if ($savedFile) {
            $this->appendOutputToMeta($userId, $savedFile, [
                'type' => 'transcribed',
                'outputTypes' => $outputTypes,
                'preview' => mb_substr($text, 0, 280)
            ]);
        }

        $this->recordJob($userId, 'process', 'completed', [
            'sourcePath' => $savedFile ?: $filename,
            'outputTypes' => $outputTypes,
            'prompt' => $prompt,
            'resultText' => mb_substr($text, 0, 4000),
            'durationSeconds' => time() - $startTs,
        ]);

        return [
            'success' => true,
            'text' => $text,
            'tasks' => $tasks,
            'audioPath' => $savedFile,
            'savedFile' => $savedFile,
            'outputTypes' => $outputTypes,
            'title' => $title
        ];
    }

    public function processNextcloudFile(
        string $userId,
        string $path,
        string $prompt,
        array $outputTypes,
        string $title = ''
    ): array {
        $this->enforceRateLimit($userId);
        $startTs = time();
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Get the file from Nextcloud
            $file = $userFolder->get($path);

            if (!($file instanceof \OCP\Files\File)) {
                throw new \RuntimeException('O caminho especificado não é um arquivo');
            }

            $audioContent = $file->getContent();
            $filename = $file->getName();
            $mimeType = $file->getMimeType();
            $extractedAudioPath = null;

            // Validate it's an audio or video file
            if (!str_starts_with($mimeType, 'audio/') && !str_starts_with($mimeType, 'video/')) {
                throw new \RuntimeException('O arquivo selecionado não é um arquivo de áudio ou vídeo');
            }

            // If it's a video, extract audio first
            if ($this->isVideoFile($mimeType)) {
                $this->logger->info("AssistAudio: Extracting audio from video file: {$filename}");

                // Save video to temp file for ffmpeg processing
                $tempVideoPath = sys_get_temp_dir() . '/video_' . uniqid() . '.' . pathinfo($filename, PATHINFO_EXTENSION);
                file_put_contents($tempVideoPath, $audioContent);

                $extracted = $this->extractAudioFromVideo($tempVideoPath);
                $audioContent = $extracted['content'];
                $mimeType = $extracted['mimeType'];
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.mp3';
                $extractedAudioPath = $extracted['path'];

                // Clean up temp video
                unlink($tempVideoPath);
            }

            // Transcribe/process audio — long-audio split is applied here too.
            $text = $this->transcribeWithLongAudioFallback($audioContent, $filename, $mimeType, $outputTypes, $prompt);

            // Clean up extracted audio file
            if ($extractedAudioPath && file_exists($extractedAudioPath)) {
                unlink($extractedAudioPath);
            }

            // Extract tasks if needed
            $tasks = [];
            if (in_array('tarefas', $outputTypes) || in_array('ata', $outputTypes)) {
                $tasks = $this->extractTasks($text);
            }

            // If the source file lives in Audiolog, track this run on its meta.
            if (str_starts_with($path, 'Audiolog/')) {
                $this->appendOutputToMeta($userId, $path, [
                    'type' => 'transcribed',
                    'outputTypes' => $outputTypes,
                    'preview' => mb_substr($text, 0, 280)
                ]);
            }

            $this->recordJob($userId, 'process', 'completed', [
                'sourcePath' => $path,
                'outputTypes' => $outputTypes,
                'prompt' => $prompt,
                'resultText' => mb_substr($text, 0, 4000),
                'durationSeconds' => time() - $startTs,
            ]);

            return [
                'success' => true,
                'text' => $text,
                'tasks' => $tasks,
                'savedFile' => '', // Empty - file already exists in Nextcloud, no need to save again
                'sourceFile' => $path, // Original file path for reference
                'audioPath' => str_starts_with($path, 'Audiolog/') ? $path : '',
                'outputTypes' => $outputTypes,
                'title' => $title ?: pathinfo($filename, PATHINFO_FILENAME)
            ];

        } catch (NotFoundException $e) {
            $this->recordJob($userId, 'process', 'failed', [
                'sourcePath' => $path,
                'error' => 'Arquivo não encontrado: ' . $path,
            ]);
            throw new \RuntimeException('Arquivo não encontrado: ' . $path);
        }
    }

    /**
     * Save recording to user files (public wrapper)
     */
    public function saveRecording(string $userId, string $content, string $filename, string $title = ""): array {
        try {
            $savedPath = $this->saveAudioFile($userId, $content, $filename, $title);
            if (empty($savedPath)) {
                return ["error" => "Falha ao salvar arquivo"];
            }
            return [
                "success" => true,
                "message" => "Audio salvo com sucesso",
                "path" => $savedPath
            ];
        } catch (\Exception $e) {
            $this->logger->error("Erro ao salvar gravacao: " . $e->getMessage());
            return ["error" => $e->getMessage()];
        }
    }


    /**
     * Save the recording. When a custom $title is provided, also auto-create
     * a folder Audiolog/<title>/ and drop the audio + sidecar meta inside.
     * That way every output later generated for this recording (transcrição,
     * ata, resumo, tarefas) lands in the same folder — the "project" pattern
     * the user asked for.
     */
    private function saveAudioFile(string $userId, string $content, string $originalName, string $title = ''): string {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Root Audiolog — created lazily.
            try {
                $rootAudios = $userFolder->get('Audiolog');
            } catch (NotFoundException $e) {
                $rootAudios = $userFolder->newFolder('Audiolog');
            }
            if (!($rootAudios instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Audiolog não é uma pasta');
            }

            $date = date('Y-m-d_H-i-s');
            $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'mp3';

            $hasTitle = !empty($title);
            $safeName = $hasTitle ? $this->sanitizeFilename($title) : '';
            if ($safeName === '') {
                $hasTitle = false;
            }

            // Pick destination folder: Audiolog/<title>/ if title given,
            // else the flat Audiolog root (legacy / quick recordings).
            $destFolder = $rootAudios;
            $destRelPrefix = 'Audiolog';
            if ($hasTitle) {
                $folderName = $safeName;
                $counter = 1;
                while ($rootAudios->nodeExists($folderName)) {
                    $existing = $rootAudios->get($folderName);
                    if ($existing instanceof \OCP\Files\Folder) {
                        $destFolder = $existing;
                        break;
                    }
                    $folderName = $safeName . '_' . $counter;
                    $counter++;
                }
                if ($destFolder === $rootAudios) {
                    $destFolder = $rootAudios->newFolder($folderName);
                }
                $destRelPrefix = 'Audiolog/' . $destFolder->getName();
            }

            // Filename inside the folder. With a title, name the audio after
            // the folder for consistency; without a title, use the timestamp.
            $newName = $hasTitle ? "{$safeName}.{$ext}" : "Audio_{$date}.{$ext}";

            $counter = 1;
            $finalName = $newName;
            $baseName = pathinfo($newName, PATHINFO_FILENAME);
            while ($destFolder->nodeExists($finalName)) {
                $finalName = "{$baseName}_{$counter}.{$ext}";
                $counter++;
            }

            $file = $destFolder->newFile($finalName);
            $file->putContent($content);

            $this->writeMeta($destFolder, $finalName, [
                'title' => $title ?: pathinfo($finalName, PATHINFO_FILENAME),
                'audioPath' => $destRelPrefix . '/' . $finalName,
                'createdAt' => time(),
                'outputs' => []
            ]);

            return $destRelPrefix . '/' . $finalName;

        } catch (\Exception $e) {
            $this->logger->error("Erro ao salvar arquivo: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Resolve a path inside Audiolog/, refusing anything outside it.
     * Used by the file manager listing/navigation endpoints to defend against
     * client-supplied paths trying to escape via "..".
     */
    private function resolveSafeAudiosFolder(string $userId, string $relPath = ''): \OCP\Files\Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $relPath = trim($relPath, '/');
        if ($relPath === '' || $relPath === 'Audiolog') {
            $this->migrateLegacyFolders($userFolder);
            try {
                $node = $userFolder->get('Audiolog');
            } catch (NotFoundException $e) {
                $node = $userFolder->newFolder('Audiolog');
            }
            if (!($node instanceof \OCP\Files\Folder)) {
                throw new \RuntimeException('Audiolog não é uma pasta');
            }
            return $node;
        }
        if (!str_starts_with($relPath, 'Audiolog/') && $relPath !== 'Audiolog') {
            throw new \RuntimeException('Caminho deve estar dentro de Audiolog/');
        }
        // Disallow ".." segments and absolute references.
        $parts = explode('/', $relPath);
        foreach ($parts as $p) {
            if ($p === '..' || $p === '.' || $p === '') {
                throw new \RuntimeException('Caminho inválido');
            }
        }
        $node = $userFolder->get($relPath);
        if (!($node instanceof \OCP\Files\Folder)) {
            throw new \RuntimeException('Caminho não é uma pasta');
        }
        return $node;
    }

    /**
     * One-shot lazy migration: if the user has legacy folders from the very
     * early beta (Audios_Beta, Notas_Beta, Documentos_Beta, Tarefas_Beta) and
     * does NOT yet have Audiolog/, rename the audios folder and merge content
     * from the others into it. Idempotent — re-running is a no-op.
     */
    private function migrateLegacyFolders(\OCP\Files\Folder $userFolder): void {
        try {
            $hasAudiolog = false;
            try { $userFolder->get('Audiolog'); $hasAudiolog = true; } catch (\Throwable $_) {}

            // If Audios_Beta exists and Audiolog does not, rename it.
            if (!$hasAudiolog) {
                try {
                    $legacy = $userFolder->get('Audios_Beta');
                    if ($legacy instanceof \OCP\Files\Folder) {
                        $legacy->move($userFolder->getPath() . '/Audiolog');
                        $hasAudiolog = true;
                        $this->logger->info('Audiolog: renamed legacy Audios_Beta → Audiolog');
                    }
                } catch (\Throwable $_) { /* legacy folder absent — ok */ }
            }

            // Move stragglers from old sibling folders into Audiolog/.
            if ($hasAudiolog) {
                try {
                    $audiolog = $userFolder->get('Audiolog');
                    if ($audiolog instanceof \OCP\Files\Folder) {
                        foreach (['Notas_Beta', 'Documentos_Beta', 'Tarefas_Beta'] as $legacy) {
                            try {
                                $folder = $userFolder->get($legacy);
                                if (!($folder instanceof \OCP\Files\Folder)) continue;
                                foreach ($folder->getDirectoryListing() as $node) {
                                    $name = $node->getName();
                                    $target = $audiolog->getPath() . '/' . $name;
                                    if ($audiolog->nodeExists($name)) {
                                        // Avoid clobber: rename moved item.
                                        $base = pathinfo($name, PATHINFO_FILENAME);
                                        $ext = pathinfo($name, PATHINFO_EXTENSION);
                                        $target = $audiolog->getPath() . '/' . $base . '_' . $legacy . ($ext ? '.' . $ext : '');
                                    }
                                    try { $node->move($target); } catch (\Throwable $_) {}
                                }
                                // Remove now-empty legacy folder.
                                try { $folder->delete(); } catch (\Throwable $_) {}
                            } catch (\Throwable $_) { /* not present */ }
                        }
                    }
                } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Audiolog: legacy migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Build the sidecar metadata filename next to an audio file:
     * "Audio.webm" → ".Audio.webm.meta.json"
     */
    private function metaFileName(string $audioName): string {
        return '.' . $audioName . '.meta.json';
    }

    private function writeMeta(\OCP\Files\Folder $folder, string $audioName, array $meta): void {
        try {
            $metaName = $this->metaFileName($audioName);
            $payload = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($folder->nodeExists($metaName)) {
                $folder->get($metaName)->putContent($payload);
            } else {
                $folder->newFile($metaName)->putContent($payload);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Audiolog: failed to write meta for ' . $audioName . ': ' . $e->getMessage());
        }
    }

    private function readMeta(\OCP\Files\Folder $folder, string $audioName): ?array {
        try {
            $metaName = $this->metaFileName($audioName);
            if (!$folder->nodeExists($metaName)) {
                return null;
            }
            $node = $folder->get($metaName);
            if (!$node instanceof \OCP\Files\File) {
                return null;
            }
            $data = json_decode($node->getContent(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Append (or replace) an output entry in the sidecar metadata of the audio
     * referenced by $audioPath ("Audiolog/X.webm").
     *
     * Called by processAudio/processNextcloudFile whenever a new transcript,
     * minutes, summary, etc is generated for this recording.
     */
    private function appendOutputToMeta(string $userId, string $audioPath, array $output): void {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $audioName = basename($audioPath);
            try {
                $audiosFolder = $userFolder->get('Audiolog');
            } catch (NotFoundException $e) {
                return; // No folder, nothing to update.
            }
            if (!($audiosFolder instanceof \OCP\Files\Folder)) {
                return;
            }
            if (!$audiosFolder->nodeExists($audioName)) {
                return; // Audio not in Audiolog — skip metadata.
            }

            $meta = $this->readMeta($audiosFolder, $audioName) ?? [
                'title' => pathinfo($audioName, PATHINFO_FILENAME),
                'audioPath' => $audioPath,
                'createdAt' => time(),
                'outputs' => []
            ];

            // Replace existing entry with same type, keep history otherwise.
            $existing = $meta['outputs'] ?? [];
            $filtered = array_values(array_filter($existing, fn($o) => ($o['type'] ?? '') !== ($output['type'] ?? '')));
            $filtered[] = array_merge(['generatedAt' => time()], $output);
            $meta['outputs'] = $filtered;

            $this->writeMeta($audiosFolder, $audioName, $meta);
        } catch (\Throwable $e) {
            $this->logger->warning('Audiolog: appendOutputToMeta failed: ' . $e->getMessage());
        }
    }

    private function extractTasks(string $text): array {
        $tasks = [];

        // Pattern to match tasks in table format or bullet points
        $patterns = [
            '/\|\s*\d+\s*\|\s*([^|]+)\s*\|\s*([^|]*)\s*\|\s*([^|]*)\s*\|/m',
            '/\|\s*([^|]+)\s*\|\s*([^|]*)\s*\|\s*([^|]*)\s*\|/m',
            '/[-*]\s*(.+?)(?:\s*[-–]\s*Responsável:\s*([^,\n]+))?(?:\s*[-–]\s*Prazo:\s*([^\n]+))?/mi',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $task = trim($match[1] ?? '');
                    // Skip header rows
                    if (!empty($task) && strlen($task) > 3 &&
                        !preg_match('/^(tarefa|task|#|---)/i', $task)) {
                        $tasks[] = [
                            'title' => $task,
                            'assignee' => trim($match[2] ?? ''),
                            'dueDate' => trim($match[3] ?? '')
                        ];
                    }
                }
            }
        }

        return array_slice($tasks, 0, 20);
    }

    public function getTaskStatus(int $taskId): array {
        return [
            'status' => 'completed',
            'taskId' => $taskId
        ];
    }

    public function getHistory(string $userId): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            try {
                $audiosFolder = $userFolder->get('Audiolog');
            } catch (NotFoundException $e) {
                return [];
            }

            $files = [];
            foreach ($audiosFolder->getDirectoryListing() as $node) {
                if ($node instanceof \OCP\Files\File) {
                    $name = $node->getName();
                    // Skip sidecar metadata files (starts with ".")
                    if (str_starts_with($name, '.')) {
                        continue;
                    }
                    $files[] = [
                        'name' => $name,
                        'path' => 'Audiolog/' . $name,
                        'size' => $node->getSize(),
                        'mtime' => $node->getMTime()
                    ];
                }
            }

            usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

            return array_slice($files, 0, 50);

        } catch (\Exception $e) {
            $this->logger->error("Erro ao obter historico: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mini-Files listing for the "Minhas Gravações" tab. Walks the folder at
     * $relPath inside Audiolog/, returning subfolders as navigable items
     * and files (audio + outputs like .md/.docx) as leaves with metadata.
     *
     * @return array{path: string, breadcrumb: array<int,array{name:string,path:string}>, items: array<int,array<string,mixed>>}
     */
    public function getRecordingsList(string $userId, string $relPath = ''): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder = $this->resolveSafeAudiosFolder($userId, $relPath);
            $currentPath = $relPath === '' ? 'Audiolog' : trim($relPath, '/');

            $folders = [];
            $audios = [];
            $outputs = []; // .md, .docx and other non-audio leaves living in the folder

            foreach ($folder->getDirectoryListing() as $node) {
                $name = $node->getName();
                // Hide sidecar metadata files (".audio.webm.meta.json").
                if (str_starts_with($name, '.')) continue;

                if ($node instanceof \OCP\Files\Folder) {
                    $folders[] = [
                        'type' => 'folder',
                        'name' => $name,
                        'path' => $currentPath . '/' . $name,
                        'mtime' => $node->getMTime(),
                        'fileId' => $node->getId(),
                    ];
                    continue;
                }
                if (!($node instanceof \OCP\Files\File)) continue;

                $mime = $node->getMimeType();
                $isAudio = str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');

                if ($isAudio) {
                    $meta = $this->readMeta($folder, $name) ?? [
                        'title' => pathinfo($name, PATHINFO_FILENAME),
                        'audioPath' => $currentPath . '/' . $name,
                        'createdAt' => $node->getMTime(),
                        'outputs' => []
                    ];
                    $validOutputs = [];
                    foreach (($meta['outputs'] ?? []) as $out) {
                        $outPath = $out['path'] ?? '';
                        if ($outPath && $this->fileExistsForUser($userFolder, $outPath)) {
                            $validOutputs[] = $out;
                        }
                    }
                    $audios[] = [
                        'type' => 'audio',
                        'name' => $name,
                        'path' => $currentPath . '/' . $name,
                        'size' => $node->getSize(),
                        'mtime' => $node->getMTime(),
                        'mime' => $mime,
                        'fileId' => $node->getId(),
                        'title' => $meta['title'] ?? pathinfo($name, PATHINFO_FILENAME),
                        'createdAt' => $meta['createdAt'] ?? $node->getMTime(),
                        'outputs' => $validOutputs
                    ];
                } else {
                    $outputs[] = [
                        'type' => 'output',
                        'name' => $name,
                        'path' => $currentPath . '/' . $name,
                        'size' => $node->getSize(),
                        'mtime' => $node->getMTime(),
                        'mime' => $mime,
                        'fileId' => $node->getId(),
                    ];
                }
            }

            // Folders first, audios next, outputs last — within each group,
            // most recent at the top.
            usort($folders, fn($a, $b) => ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0));
            usort($audios, fn($a, $b) => ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0));
            usort($outputs, fn($a, $b) => ($b['mtime'] ?? 0) - ($a['mtime'] ?? 0));
            $items = array_merge($folders, $audios, $outputs);

            // Build breadcrumb (root → ... → currentFolder).
            $breadcrumb = [['name' => 'Audiolog', 'path' => 'Audiolog']];
            $segments = explode('/', $currentPath);
            $accum = '';
            foreach ($segments as $seg) {
                if ($seg === 'Audiolog' || $seg === '') continue;
                $accum = ($accum === '' ? 'Audiolog' : $accum) . '/' . $seg;
                $breadcrumb[] = ['name' => $seg, 'path' => $accum];
            }

            return [
                'path' => $currentPath,
                'fileId' => $folder->getId(),
                'breadcrumb' => $breadcrumb,
                'items' => $items,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Erro ao listar gravações: ' . $e->getMessage());
            return [
                'path' => 'Audiolog',
                'fileId' => 0,
                'breadcrumb' => [['name' => 'Audiolog', 'path' => 'Audiolog']],
                'items' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a subfolder $name inside Audiolog/$parentRelPath. Defends
     * against path traversal via resolveSafeAudiosFolder.
     */
    public function createFolder(string $userId, string $parentRelPath, string $name): array {
        try {
            $parent = $this->resolveSafeAudiosFolder($userId, $parentRelPath);
            $safe = $this->sanitizeFilename($name);
            if ($safe === '') {
                return ['error' => 'Nome de pasta inválido'];
            }
            if ($parent->nodeExists($safe)) {
                return ['error' => 'Já existe uma pasta com esse nome'];
            }
            $parent->newFolder($safe);
            return [
                'success' => true,
                'name' => $safe,
                'path' => trim($parentRelPath ?: 'Audiolog', '/') . '/' . $safe,
            ];
        } catch (\Exception $e) {
            $this->logger->error('createFolder failed: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Move an item (audio + sidecar meta, OR a folder, OR an output file)
     * to another folder inside Audiolog/.
     * For audios, the .meta.json sidecar moves alongside.
     */
    public function moveItem(string $userId, string $fromRelPath, string $toFolderRelPath): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $fromRelPath = trim($fromRelPath, '/');
            $toFolderRelPath = trim($toFolderRelPath, '/');
            if (!str_starts_with($fromRelPath, 'Audiolog/') && $fromRelPath !== 'Audiolog') {
                return ['error' => 'Origem fora de Audiolog'];
            }
            // Validate destination is a real folder inside Audiolog.
            $destFolder = $this->resolveSafeAudiosFolder($userId, $toFolderRelPath);
            $node = $userFolder->get($fromRelPath);
            $name = $node->getName();
            $newPath = $destFolder->getPath() . '/' . $name;

            // If we're moving an audio file, move its sidecar meta too.
            $sourceParent = $node->getParent();
            $movedMeta = false;
            $metaName = '.' . $name . '.meta.json';
            if ($sourceParent && $sourceParent->nodeExists($metaName)) {
                $movedMeta = true;
            }

            $node->move($newPath);
            if ($movedMeta) {
                try {
                    $sourceParent->get($metaName)->move($destFolder->getPath() . '/' . $metaName);
                } catch (\Throwable $e) {
                    $this->logger->warning('moveItem: failed to move sidecar meta: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'newPath' => trim($toFolderRelPath ?: 'Audiolog', '/') . '/' . $name,
            ];
        } catch (\Exception $e) {
            $this->logger->error('moveItem failed: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * List all subfolders of Audiolog/ recursively (for the "Mover" picker).
     * Caps at 200 entries to keep the picker manageable.
     *
     * @return array<int,array{path:string,depth:int}>
     */
    public function listAllFolders(string $userId): array {
        try {
            $root = $this->resolveSafeAudiosFolder($userId, '');
            $out = [['path' => 'Audiolog', 'depth' => 0]];
            $this->walkFolders($root, 'Audiolog', 1, $out);
            return $out;
        } catch (\Exception $e) {
            return [['path' => 'Audiolog', 'depth' => 0]];
        }
    }

    private function walkFolders(\OCP\Files\Folder $folder, string $relPath, int $depth, array &$out): void {
        if (count($out) >= 200 || $depth > 4) return;
        foreach ($folder->getDirectoryListing() as $node) {
            if (!($node instanceof \OCP\Files\Folder)) continue;
            $name = $node->getName();
            if (str_starts_with($name, '.')) continue;
            $sub = $relPath . '/' . $name;
            $out[] = ['path' => $sub, 'depth' => $depth];
            $this->walkFolders($node, $sub, $depth + 1, $out);
            if (count($out) >= 200) return;
        }
    }

    private function fileExistsForUser(\OCP\Files\Folder $userFolder, string $relativePath): bool {
        try {
            $userFolder->get($relativePath);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Rename the audio file, its sidecar meta, and the related outputs.
     * Output filenames follow "{title}_{date}.ext" so we rebuild them from the
     * new title to keep things tidy in the Files UI.
     */
    public function renameRecording(string $userId, string $audioPath, string $newTitle): array {
        if (empty($newTitle)) {
            return ['error' => 'Título vazio'];
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            try {
                $audiosFolder = $userFolder->get('Audiolog');
            } catch (NotFoundException $e) {
                return ['error' => 'Pasta Audiolog não encontrada'];
            }
            if (!($audiosFolder instanceof \OCP\Files\Folder)) {
                return ['error' => 'Audiolog não é uma pasta'];
            }

            $oldName = basename($audioPath);
            if (!$audiosFolder->nodeExists($oldName)) {
                return ['error' => 'Arquivo não encontrado'];
            }

            $audioNode = $audiosFolder->get($oldName);
            $ext = pathinfo($oldName, PATHINFO_EXTENSION) ?: 'mp3';
            $safeName = $this->sanitizeFilename($newTitle);
            if (empty($safeName)) {
                return ['error' => 'Título inválido'];
            }

            $newName = $safeName . '.' . $ext;
            $counter = 1;
            while ($newName !== $oldName && $audiosFolder->nodeExists($newName)) {
                $newName = $safeName . '_' . $counter . '.' . $ext;
                $counter++;
            }

            // Move audio
            if ($newName !== $oldName) {
                $audioNode->move($audiosFolder->getPath() . '/' . $newName);
            }

            // Move/rename meta
            $oldMeta = $this->metaFileName($oldName);
            $newMeta = $this->metaFileName($newName);
            $meta = null;
            if ($audiosFolder->nodeExists($oldMeta)) {
                $metaNode = $audiosFolder->get($oldMeta);
                if ($metaNode instanceof \OCP\Files\File) {
                    $meta = json_decode($metaNode->getContent(), true) ?: [];
                    if ($oldMeta !== $newMeta) {
                        $metaNode->move($audiosFolder->getPath() . '/' . $newMeta);
                    }
                }
            }
            if (!is_array($meta)) {
                $meta = ['outputs' => []];
            }
            $meta['title'] = $newTitle;
            $meta['audioPath'] = 'Audiolog/' . $newName;
            $this->writeMeta($audiosFolder, $newName, $meta);

            return [
                'success' => true,
                'newPath' => 'Audiolog/' . $newName,
                'newName' => $newName
            ];

        } catch (\Exception $e) {
            $this->logger->error('Erro ao renomear gravação: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Delete the audio, its meta, and (optionally) its referenced outputs.
     */
    /**
     * Delete a node inside Audiolog/. Accepts:
     *  - audio file: deletes the audio + its sidecar .meta.json + (if $alsoOutputs)
     *    every output listed in that meta.
     *  - folder: deletes the whole folder recursively.
     *  - other file (output, etc.): deletes the file.
     *
     * Path traversal is blocked at the entry point — the path must start with
     * Audiolog/ and not contain "..".
     */
    public function deleteRecording(string $userId, string $relPath, bool $alsoOutputs = true): array {
        try {
            $relPath = trim($relPath, '/');
            if ($relPath === '' || (!str_starts_with($relPath, 'Audiolog/') && $relPath !== 'Audiolog')) {
                return ['error' => 'Caminho fora de Audiolog/'];
            }
            foreach (explode('/', $relPath) as $seg) {
                if ($seg === '..' || $seg === '.') {
                    return ['error' => 'Caminho inválido'];
                }
            }
            // Refuse deleting the root folder itself.
            if ($relPath === 'Audiolog') {
                return ['error' => 'Não é possível excluir a pasta raiz Audiolog'];
            }

            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node = $userFolder->get($relPath);

            $deletedOutputs = 0;
            $isAudioFile = ($node instanceof \OCP\Files\File)
                && (str_starts_with($node->getMimeType(), 'audio/') || str_starts_with($node->getMimeType(), 'video/'));

            if ($isAudioFile) {
                $parent = $node->getParent();
                $audioName = $node->getName();
                $meta = ($parent instanceof \OCP\Files\Folder) ? $this->readMeta($parent, $audioName) : null;

                // Delete linked outputs first (best-effort).
                if ($alsoOutputs && $meta && !empty($meta['outputs'])) {
                    foreach ($meta['outputs'] as $out) {
                        $outPath = $out['path'] ?? '';
                        if (!$outPath) continue;
                        try {
                            $userFolder->get($outPath)->delete();
                            $deletedOutputs++;
                        } catch (\Throwable $_) { /* missing — ignore */ }
                    }
                }
                // Delete the .meta.json sidecar.
                if ($parent instanceof \OCP\Files\Folder) {
                    $metaName = $this->metaFileName($audioName);
                    if ($parent->nodeExists($metaName)) {
                        try { $parent->get($metaName)->delete(); } catch (\Throwable $_) {}
                    }
                }
            }

            $node->delete();

            return [
                'success' => true,
                'deletedOutputs' => $deletedOutputs
            ];
        } catch (NotFoundException $e) {
            return ['error' => 'Item não encontrado'];
        } catch (\Exception $e) {
            $this->logger->error('Erro ao excluir: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Resolve the folder where outputs (notes, docx) for the given audio
     * should land:
     *  - audio inside Audiolog/<sub>/...   → save next to it (project pattern)
     *  - audio at Audiolog/<file> (root)   → fall back to Audiolog/Audiolog
     *  - no audio                             → Audiolog / Audiolog
     *
     * Returns [\OCP\Files\Folder $folder, string $relPrefix].
     */
    private function resolveOutputFolder(string $userId, string $audioPath, string $fallbackName): array {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $audioPath = trim($audioPath, '/');
        if ($audioPath !== '' && str_starts_with($audioPath, 'Audiolog/')) {
            // If there's a subfolder between Audiolog and the file, save there.
            $segments = explode('/', $audioPath);
            if (count($segments) >= 3) {
                array_pop($segments); // drop filename
                $folderRel = implode('/', $segments);
                try {
                    $folder = $this->resolveSafeAudiosFolder($userId, $folderRel);
                    return [$folder, $folderRel];
                } catch (\Throwable $e) {
                    // fall through to fallback
                }
            }
        }
        try {
            $folder = $userFolder->get($fallbackName);
        } catch (NotFoundException $e) {
            $folder = $userFolder->newFolder($fallbackName);
        }
        if (!($folder instanceof \OCP\Files\Folder)) {
            throw new \RuntimeException($fallbackName . ' não é uma pasta');
        }
        return [$folder, $fallbackName];
    }

    public function saveToNotes(string $userId, string $title, string $content, string $audioPath = ''): array {
        try {
            [$notesFolder, $relPrefix] = $this->resolveOutputFolder($userId, $audioPath, 'Audiolog');

            $date = date('Y-m-d');
            $safeName = $this->sanitizeFilename($title);
            $filename = "{$safeName}_{$date}.md";

            $counter = 1;
            $finalName = $filename;
            while ($notesFolder->nodeExists($finalName)) {
                $finalName = "{$safeName}_{$date}_{$counter}.md";
                $counter++;
            }

            $file = $notesFolder->newFile($finalName);
            $file->putContent("# {$title}\n\n{$content}");

            $savedPath = $relPrefix . '/' . $finalName;
            if ($audioPath) {
                $this->appendOutputToMeta($userId, $audioPath, [
                    'type' => 'note',
                    'format' => 'md',
                    'path' => $savedPath,
                    'fileId' => $file->getId()
                ]);
            }

            return [
                'success' => true,
                'path' => $savedPath,
                'fileId' => $file->getId(),
                'message' => 'Nota salva com sucesso'
            ];

        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao salvar nota: ' . $e->getMessage());
        }
    }

    public function saveToDocx(string $userId, string $title, string $content, bool $download = false, string $audioPath = ''): array {
        try {
            // Generate DOCX content
            $docxContent = $this->generateDocx($title, $content);

            // If download only, return base64 encoded content
            if ($download) {
                return [
                    'success' => true,
                    'downloadData' => base64_encode($docxContent),
                    'filename' => $this->sanitizeFilename($title) . '.docx'
                ];
            }

            // Otherwise save to Nextcloud — prefer the audio's own folder.
            [$docsFolder, $relPrefix] = $this->resolveOutputFolder($userId, $audioPath, 'Audiolog');

            $date = date('Y-m-d');
            $safeName = $this->sanitizeFilename($title);
            $filename = "{$safeName}_{$date}.docx";

            $counter = 1;
            $finalName = $filename;
            while ($docsFolder->nodeExists($finalName)) {
                $finalName = "{$safeName}_{$date}_{$counter}.docx";
                $counter++;
            }

            $file = $docsFolder->newFile($finalName);
            $file->putContent($docxContent);

            $savedPath = $relPrefix . '/' . $finalName;
            if ($audioPath) {
                $this->appendOutputToMeta($userId, $audioPath, [
                    'type' => 'note',
                    'format' => 'docx',
                    'path' => $savedPath,
                    'fileId' => $file->getId()
                ]);
            }

            return [
                'success' => true,
                'path' => $savedPath,
                'fileId' => $file->getId(),
                'message' => 'Documento salvo com sucesso'
            ];

        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao salvar documento: ' . $e->getMessage());
        }
    }

    private function sanitizeFilename(string $name): string {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\p{L}]/u', '_', $name);
        $safeName = preg_replace('/_+/', '_', $safeName);
        return trim($safeName, '_');
    }

    private function generateDocx(string $title, string $content): string {
        // Create temporary file for the ZIP
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');

        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar arquivo DOCX');
        }

        // [Content_Types].xml - Required for OOXML
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>' .
            '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>' .
            '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels - Root relationships
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // word/_rels/document.xml.rels - Document relationships
        $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>' .
            '</Relationships>';
        $zip->addFromString('word/_rels/document.xml.rels', $documentRels);

        // word/settings.xml - Document settings
        $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:defaultTabStop w:val="720"/>' .
            '<w:characterSpacingControl w:val="doNotCompress"/>' .
            '</w:settings>';
        $zip->addFromString('word/settings.xml', $settings);

        // word/styles.xml - Document styles
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:docDefaults>' .
            '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="24"/></w:rPr></w:rPrDefault>' .
            '</w:docDefaults>' .
            '<w:style w:type="paragraph" w:styleId="Normal" w:default="1"><w:name w:val="Normal"/></w:style>' .
            '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="Heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>' .
            '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="Heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="200" w:after="100"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>' .
            '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:jc w:val="center"/><w:spacing w:after="300"/></w:pPr><w:rPr><w:b/><w:sz w:val="48"/></w:rPr></w:style>' .
            '</w:styles>';
        $zip->addFromString('word/styles.xml', $styles);

        // word/document.xml - Main content
        $documentXml = $this->convertContentToDocxXml($title, $content);
        $zip->addFromString('word/document.xml', $documentXml);

        $zip->close();

        // Read the content and delete temp file
        $docxContent = file_get_contents($tempFile);
        unlink($tempFile);

        return $docxContent;
    }

    private function convertContentToDocxXml(string $title, string $content): string {
        $paragraphs = [];

        // Add title
        $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>' . $this->escapeXml($title) . '</w:t></w:r></w:p>';

        // Convert markdown-like content to paragraphs
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $originalLine = $line;
            $line = trim($line);

            if (empty($line)) {
                $paragraphs[] = '<w:p/>';
                continue;
            }

            // Check for headings
            if (preg_match('/^### (.+)$/', $line, $m)) {
                $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>' . $this->escapeXml($m[1]) . '</w:t></w:r></w:p>';
            } elseif (preg_match('/^## (.+)$/', $line, $m)) {
                $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . $this->escapeXml($m[1]) . '</w:t></w:r></w:p>';
            } elseif (preg_match('/^# (.+)$/', $line, $m)) {
                $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . $this->escapeXml($m[1]) . '</w:t></w:r></w:p>';
            } elseif (preg_match('/^[-*] (.+)$/', $line, $m)) {
                // Bullet point
                $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">• ' . $this->escapeXml($m[1]) . '</w:t></w:r></w:p>';
            } elseif (preg_match('/^\|(.+)\|$/', $line)) {
                // Table row - simplified as regular text with tab separation
                $cells = array_map('trim', explode('|', trim($line, '|')));
                $text = implode("\t", $cells);
                // Skip separator rows like |---|---|
                if (!preg_match('/^[\-:|\s]+$/', $line)) {
                    $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">' . $this->escapeXml($text) . '</w:t></w:r></w:p>';
                }
            } else {
                // Regular paragraph - handle bold **text** by removing markers
                $line = preg_replace('/\*\*(.+?)\*\*/', '$1', $line);

                $paragraphs[] = '<w:p><w:r><w:t xml:space="preserve">' . $this->escapeXml($line) . '</w:t></w:r></w:p>';
            }
        }

        $body = implode('', $paragraphs);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" .
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
            '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body>' .
            '</w:document>';

        return $xml;
    }

    private function escapeXml(string $text): string {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function getDeckBoards(string $userId): array {
        try {
            $this->logger->info("getDeckBoards: Starting for user {$userId}");

            // Check if Deck app is available
            if (!\OC::$server->getAppManager()->isInstalled('deck')) {
                $this->logger->warning("getDeckBoards: Deck not enabled for user {$userId}");
                return ['boards' => [], 'error' => 'Deck não está instalado ou habilitado'];
            }

            $this->logger->info("getDeckBoards: Deck is enabled, querying database");
            $db = \OC::$server->getDatabaseConnection();
            $boards = [];

            // First get boards where user is owner
            $qb = $db->getQueryBuilder();
            $qb->select('id', 'title')
                ->from('deck_boards')
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0)))
                ->orderBy('title', 'ASC');

            $this->logger->info("getDeckBoards: Executing owner query");
            $result = $qb->executeQuery();

            while ($row = $result->fetch()) {
                $this->logger->info("getDeckBoards: Found owner board: {$row['title']} (id: {$row['id']})");

                // Get stacks for this board
                $stackQb = $db->getQueryBuilder();
                $stackQb->select('id', 'title')
                        ->from('deck_stacks')
                        ->where($stackQb->expr()->eq('board_id', $stackQb->createNamedParameter($row['id'])))
                        ->andWhere($stackQb->expr()->eq('deleted_at', $stackQb->createNamedParameter(0)))
                        ->orderBy('order', 'ASC');

                $stackResult = $stackQb->executeQuery();
                $stacks = [];
                while ($stackRow = $stackResult->fetch()) {
                    $stacks[] = [
                        'id' => (int)$stackRow['id'],
                        'title' => $stackRow['title']
                    ];
                }
                $stackResult->closeCursor();

                $boards[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'stacks' => $stacks
                ];
            }
            $result->closeCursor();

            // Also get boards shared with user via ACL
            $existingIds = array_column($boards, 'id');

            $qb2 = $db->getQueryBuilder();
            $qb2->select('b.id', 'b.title')
               ->from('deck_boards', 'b')
               ->innerJoin('b', 'deck_board_acl', 'acl', 'b.id = acl.board_id')
               ->where($qb2->expr()->eq('acl.participant', $qb2->createNamedParameter($userId)))
               ->andWhere($qb2->expr()->eq('acl.type', $qb2->createNamedParameter(0)))
               ->andWhere($qb2->expr()->eq('b.deleted_at', $qb2->createNamedParameter(0)))
               ->orderBy('b.title', 'ASC');

            $this->logger->info("getDeckBoards: Executing ACL query");
            $result2 = $qb2->executeQuery();

            while ($row = $result2->fetch()) {
                if (!in_array((int)$row['id'], $existingIds)) {
                    $this->logger->info("getDeckBoards: Found shared board: {$row['title']} (id: {$row['id']})");

                    // Get stacks
                    $stackQb = $db->getQueryBuilder();
                    $stackQb->select('id', 'title')
                            ->from('deck_stacks')
                            ->where($stackQb->expr()->eq('board_id', $stackQb->createNamedParameter($row['id'])))
                            ->andWhere($stackQb->expr()->eq('deleted_at', $stackQb->createNamedParameter(0)))
                            ->orderBy('order', 'ASC');

                    $stackResult = $stackQb->executeQuery();
                    $stacks = [];
                    while ($stackRow = $stackResult->fetch()) {
                        $stacks[] = [
                            'id' => (int)$stackRow['id'],
                            'title' => $stackRow['title']
                        ];
                    }
                    $stackResult->closeCursor();

                    $boards[] = [
                        'id' => (int)$row['id'],
                        'title' => $row['title'],
                        'stacks' => $stacks
                    ];
                }
            }
            $result2->closeCursor();

            $this->logger->info("getDeckBoards: Total boards found: " . count($boards));
            return ['boards' => $boards];

        } catch (\Exception $e) {
            $this->logger->error("getDeckBoards: Error - " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return ['boards' => [], 'error' => $e->getMessage()];
        }
    }

    public function createDeckCards(string $userId, array $tasks, ?int $boardId, ?int $stackId): array {
        try {
            // If no boardId provided, save to file instead
            if (!$boardId || !$stackId) {
                return $this->saveTasksToFile($userId, $tasks);
            }

            // Create cards in Deck
            $db = \OC::$server->getDatabaseConnection();
            $createdCards = 0;

            foreach ($tasks as $task) {
                $title = $task['title'] ?? '';
                if (empty($title)) continue;

                $description = '';
                if (!empty($task['assignee'])) {
                    $description .= "Responsável: " . $task['assignee'] . "\n";
                }
                if (!empty($task['dueDate'])) {
                    $description .= "Prazo: " . $task['dueDate'];
                }

                // Get max order for this stack
                $qb = $db->getQueryBuilder();
                $qb->select($qb->func()->max('order'))
                   ->from('deck_cards')
                   ->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId)));
                $result = $qb->executeQuery();
                $maxOrder = (int)$result->fetchOne() + 1;
                $result->closeCursor();

                // Insert card
                $qb = $db->getQueryBuilder();
                $qb->insert('deck_cards')
                   ->values([
                       'title' => $qb->createNamedParameter($title),
                       'description' => $qb->createNamedParameter($description),
                       'stack_id' => $qb->createNamedParameter($stackId),
                       'type' => $qb->createNamedParameter('plain'),
                       'last_modified' => $qb->createNamedParameter(time()),
                       'created_at' => $qb->createNamedParameter(time()),
                       'owner' => $qb->createNamedParameter($userId),
                       'order' => $qb->createNamedParameter($maxOrder),
                       'archived' => $qb->createNamedParameter(0),
                       'deleted_at' => $qb->createNamedParameter(0),
                       'done' => $qb->createNamedParameter(null),
                   ]);
                $qb->executeStatement();
                $createdCards++;
            }

            return [
                'success' => true,
                'count' => $createdCards,
                'message' => $createdCards . ' tarefas criadas no Deck'
            ];

        } catch (\Exception $e) {
            $this->logger->error("Erro ao criar cards no Deck: " . $e->getMessage());
            // Fallback to file
            return $this->saveTasksToFile($userId, $tasks);
        }
    }

    private function saveTasksToFile(string $userId, array $tasks): array {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        try {
            $tasksFolder = $userFolder->get('Audiolog');
        } catch (NotFoundException $e) {
            $tasksFolder = $userFolder->newFolder('Audiolog');
        }

        $date = date('Y-m-d_H-i');
        $filename = "Tarefas_Reuniao_{$date}.md";

        $content = "# Tarefas da Reunião - " . date('d/m/Y H:i') . "\n\n";
        $content .= "| # | Tarefa | Responsável | Prazo |\n";
        $content .= "|---|--------|-------------|-------|\n";

        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $title = $task['title'] ?? '';
            $assignee = $task['assignee'] ?? 'A definir';
            $dueDate = $task['dueDate'] ?? 'A definir';
            $content .= "| {$num} | {$title} | {$assignee} | {$dueDate} |\n";
        }

        $file = $tasksFolder->newFile($filename);
        $file->putContent($content);

        return [
            'success' => true,
            'path' => "Audiolog/{$filename}",
            'count' => count($tasks),
            'message' => count($tasks) . ' tarefas exportadas para arquivo'
        ];
    }
}
