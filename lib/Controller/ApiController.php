<?php
declare(strict_types=1);

namespace OCA\Audiolog\Controller;

use OCA\Audiolog\AppInfo\Application;
use OCA\Audiolog\BackgroundJob\ProcessAudioJob;
use OCA\Audiolog\Db\Job;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ApiController extends Controller {
    public function __construct(
        IRequest $request,
        private AudioService $audioService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private CryptoHelper $crypto,
        private JobMapper $jobMapper,
        private IJobList $jobList,
        private PermissionService $permissions,
        private IGroupManager $groupManager
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * One-shot auth+authorization gate. Returns the userId on success, or a
     * JSONResponse with the appropriate status on failure (caller should
     * `return` it immediately). Centralizes the check so we never forget the
     * `allowed_groups` enforcement that was previously bypassed.
     *
     * Usage:
     *   $check = $this->requireAccess();
     *   if ($check instanceof JSONResponse) { return $check; }
     *   $userId = $check;
     */
    private function requireAccess(): JSONResponse|string {
        $userId = $this->userSession->getUser()?->getUID();
        if (!$userId) {
            return new JSONResponse(['error' => 'Nao autenticado'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->permissions->userHasAccess($userId)) {
            return new JSONResponse(['error' => 'Acesso negado pelas configurações de grupo do app.'], Http::STATUS_FORBIDDEN);
        }
        return $userId;
    }

    /**
     * Wrap an unexpected exception into a generic JSON response. Logs the
     * full exception (with stacktrace) for the admin to inspect, but returns
     * a SAFE message to the client — never echoing $e->getMessage(), which
     * may carry SQL fragments, absolute paths, or internal implementation
     * details that don't belong on the wire.
     *
     * `$context` is a short tag the admin will see in the log to know which
     * endpoint blew up.
     */
    private function errorResponse(\Throwable $e, string $context): JSONResponse {
        $this->logger->error('Audiolog ' . $context . ': ' . $e->getMessage(), ['exception' => $e]);
        return new JSONResponse(
            ['error' => 'Erro interno ao processar sua solicitação. Verifique os logs do servidor para detalhes.'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Path-traversal guard for the user-supplied paths that target files/folders
     * inside Audiolog/. Rejects anything that:
     *   - is not under Audiolog/ (or the literal "Audiolog" root)
     *   - contains "." or ".." segments after normalization
     *   - contains backslash, NUL byte, or scheme prefixes
     *
     * `$allowRoot=true` accepts the literal "Audiolog" (used when listing or
     * creating subfolders at root). Most write paths should use false.
     *
     * Returns null on success, JSONResponse with the error otherwise.
     */
    private function validateAudiologPath(string $path, bool $allowRoot = false): ?JSONResponse {
        $path = trim($path, '/');
        if ($path === '') {
            return new JSONResponse(['error' => 'Caminho obrigatório'], Http::STATUS_BAD_REQUEST);
        }
        // Reject scheme prefixes, backslashes, NUL bytes — none of these
        // belong in a NextCloud relative path.
        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('#^[a-z][a-z0-9+\-.]*://#i', $path)) {
            return new JSONResponse(['error' => 'Caminho inválido'], Http::STATUS_BAD_REQUEST);
        }
        // Must live under Audiolog/.
        $isRoot = $path === 'Audiolog';
        if (!$isRoot && !str_starts_with($path, 'Audiolog/')) {
            return new JSONResponse(['error' => 'Caminho fora de Audiolog/'], Http::STATUS_BAD_REQUEST);
        }
        if ($isRoot && !$allowRoot) {
            return new JSONResponse(['error' => 'Operação não permitida na raiz Audiolog'], Http::STATUS_BAD_REQUEST);
        }
        // No "." or ".." segments after normalization.
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..' || $seg === '.' || $seg === '') {
                return new JSONResponse(['error' => 'Caminho inválido'], Http::STATUS_BAD_REQUEST);
            }
        }
        return null;
    }

    #[NoAdminRequired]
    public function process(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $ncPath = $this->request->getParam('ncPath', '');
            $prompt = $this->request->getParam('prompt', '');
            $title = $this->request->getParam('title', '');
            $async = $this->request->getParam('async', false);
            // Coerce async param ("true"/"1"/true → true)
            $async = $async === true || $async === 'true' || $async === '1' || $async === 1;

            $outputTypesParam = $this->request->getParam('outputTypes', '["transcricao"]');
            if (is_string($outputTypesParam)) {
                $outputTypes = json_decode($outputTypesParam, true);
            } else {
                $outputTypes = $outputTypesParam;
            }
            if (!is_array($outputTypes) || empty($outputTypes)) {
                $outputTypes = ['transcricao'];
            }

            // ---- Async path: enqueue a background job and return a jobId ----
            if ($async) {
                $job = new Job();
                $job->setUserId($userId);
                $job->setType('process');
                $job->setStatus('pending');
                $job->setOutputTypes(json_encode($outputTypes));
                $job->setPrompt($prompt);
                $job->setCreatedAt(time());

                $argument = [
                    'userId' => $userId,
                    'prompt' => $prompt,
                    'outputTypes' => $outputTypes,
                    'title' => $title,
                ];

                if (!empty($ncPath)) {
                    $job->setSourcePath($ncPath);
                    $argument['kind'] = 'process_nc';
                    $argument['ncPath'] = $ncPath;
                } else {
                    $file = $this->request->getUploadedFile('audio');
                    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                        return new JSONResponse(['error' => 'Arquivo de áudio não fornecido'], Http::STATUS_BAD_REQUEST);
                    }
                    // Move the uploaded file to a stable location since the
                    // request's tmp file goes away when the request ends.
                    $stable = sys_get_temp_dir() . '/audiolog_async_' . uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
                    if (!@move_uploaded_file($file['tmp_name'], $stable)) {
                        // Fallback for environments where move_uploaded_file is restricted
                        if (!@copy($file['tmp_name'], $stable)) {
                            return new JSONResponse(['error' => 'Falha ao preparar arquivo para processamento assíncrono'], Http::STATUS_INTERNAL_SERVER_ERROR);
                        }
                    }
                    $job->setSourcePath($file['name']);
                    $argument['kind'] = 'process';
                    $argument['tmpPath'] = $stable;
                    $argument['filename'] = $file['name'];
                    $argument['mimeType'] = $file['type'] ?: 'application/octet-stream';
                }

                $job = $this->jobMapper->insert($job);
                $argument['jobId'] = $job->getId();
                $this->jobList->add(ProcessAudioJob::class, $argument);

                return new JSONResponse([
                    'async' => true,
                    'taskId' => $job->getId(),
                    'status' => 'pending',
                ], Http::STATUS_ACCEPTED);
            }

            // ---- Sync path (default): same behaviour as before ----
            if (!empty($ncPath)) {
                $this->logger->info("AssistAudio: Processando arquivo NC {$ncPath} para usuario {$userId}");

                $result = $this->audioService->processNextcloudFile(
                    $userId,
                    $ncPath,
                    $prompt,
                    $outputTypes,
                    $title
                );

                return new JSONResponse($result);
            }

            $file = $this->request->getUploadedFile('audio');
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return new JSONResponse(['error' => 'Arquivo de áudio não fornecido'], Http::STATUS_BAD_REQUEST);
            }

            // Log size + types but NOT the filename (treat as PII).
            $this->logger->info('Audiolog: processing audio for user ' . $userId
                . ' (size=' . ($file['size'] ?? 0) . 'B, types=' . implode(',', $outputTypes) . ')');

            $result = $this->audioService->processAudio(
                $file['tmp_name'],
                $file['name'],
                $file['type'],
                $prompt,
                $outputTypes,
                $userId,
                $title
            );

            return new JSONResponse($result);

        } catch (\Throwable $e) {
            // Rate-limit hits surface as RuntimeException with a "Limite diário"
            // message — for those, the message is intentional UX (we built it),
            // so we surface it back to the user. Other failures get the generic
            // "internal error" wrapper that keeps stacktraces server-side.
            if (str_starts_with($e->getMessage(), 'Limite diário')) {
                $this->logger->info('Audiolog rate-limited: ' . $e->getMessage());
                return new JSONResponse(['error' => $e->getMessage()], 429);
            }
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function ncFile(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $path = $this->request->getParam('path', '');
            $prompt = $this->request->getParam('prompt', '');
            $outputTypes = $this->request->getParam('outputTypes', ['transcricao']);
            $title = $this->request->getParam('title', '');

            if (empty($path)) {
                return new JSONResponse(['error' => 'Caminho do arquivo não fornecido'], Http::STATUS_BAD_REQUEST);
            }

            // Ensure outputTypes is array
            if (!is_array($outputTypes)) {
                $outputTypes = ['transcricao'];
            }

            $this->logger->info("AssistAudio: Processando arquivo do NC {$path} para usuario {$userId}");

            $result = $this->audioService->processNextcloudFile(
                $userId,
                $path,
                $prompt,
                $outputTypes,
                $title
            );

            return new JSONResponse($result);

        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function status(int $taskId): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $job = $this->jobMapper->find($taskId);
            if (!$job) {
                return new JSONResponse(['error' => 'Task não encontrada'], Http::STATUS_NOT_FOUND);
            }
            // Don't leak other users' jobs.
            if ($job->getUserId() !== $userId) {
                return new JSONResponse(['error' => 'Acesso negado'], Http::STATUS_FORBIDDEN);
            }
            return new JSONResponse([
                'taskId' => $job->getId(),
                'status' => $job->getStatus(),
                'progress' => [
                    'current' => $job->getProgressCurrent(),
                    'total' => $job->getProgressTotal(),
                ],
                'text' => $job->getResultText(),
                'error' => $job->getError(),
                'startedAt' => $job->getStartedAt(),
                'finishedAt' => $job->getFinishedAt(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function getHistory(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $history = $this->audioService->getHistory($userId);
            return new JSONResponse(['history' => $history]);

        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function saveToNotes(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $title = $this->request->getParam('title', 'Reuniao - ' . date('Y-m-d H:i'));
            $content = $this->request->getParam('content', '');
            $format = $this->request->getParam('format', 'md'); // 'md' or 'docx'
            $download = $this->request->getParam('download', false);
            $audioPath = (string)$this->request->getParam('audioPath', '');

            if (empty($content)) {
                return new JSONResponse(['error' => 'Conteudo vazio'], Http::STATUS_BAD_REQUEST);
            }

            // Route to appropriate method based on format
            if ($format === 'docx') {
                $result = $this->audioService->saveToDocx($userId, $title, $content, (bool)$download, $audioPath);
            } else {
                $result = $this->audioService->saveToNotes($userId, $title, $content, $audioPath);
            }

            return new JSONResponse($result);

        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function getDeckBoards(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $this->logger->info("AssistAudio getDeckBoards: fetching boards for user {$userId}");
            $result = $this->audioService->getDeckBoards($userId);
            $this->logger->info("AssistAudio getDeckBoards: found " . count($result['boards'] ?? []) . " boards");
            return new JSONResponse($result);

        } catch (\Exception $e) {
            $this->logger->error("AssistAudio getDeckBoards error: " . $e->getMessage());
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function createDeckCards(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $tasks = $this->request->getParam('tasks', []);
            $boardId = $this->request->getParam('boardId');
            $stackId = $this->request->getParam('stackId');

            $this->logger->info("AssistAudio createDeckCards: boardId={$boardId}, stackId={$stackId}, tasks=" . count($tasks));

            if (empty($tasks)) {
                return new JSONResponse(['error' => 'Nenhuma tarefa fornecida'], Http::STATUS_BAD_REQUEST);
            }

            // Convert to integers if provided
            $boardIdInt = $boardId !== null && $boardId !== '' ? (int)$boardId : null;
            $stackIdInt = $stackId !== null && $stackId !== '' ? (int)$stackId : null;

            $result = $this->audioService->createDeckCards($userId, $tasks, $boardIdInt, $stackIdInt);
            return new JSONResponse($result);

        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function saveRecording(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;

            $file = $this->request->getUploadedFile('audio');
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return new JSONResponse(['error' => 'Arquivo de audio nao fornecido'], Http::STATUS_BAD_REQUEST);
            }

            $title = $this->request->getParam('title', '');
            $content = file_get_contents($file['tmp_name']);

            // Don't log the filename — user-supplied, may contain PII.
            $this->logger->info('Audiolog: saving recording for user ' . $userId
                . ' (size=' . ($file['size'] ?? 0) . 'B)');

            $result = $this->audioService->saveRecording($userId, $content, $file['name'], $title);

            return new JSONResponse($result);

        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function listRecordings(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $path = (string)$this->request->getParam('path', '');
            // Empty path → list root. Anything else must be under Audiolog/.
            if ($path !== '' && ($err = $this->validateAudiologPath($path, true))) {
                return $err;
            }
            $listing = $this->audioService->getRecordingsList($userId, $path);
            return new JSONResponse($listing);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function createRecordingFolder(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $parent = (string)$this->request->getParam('parent', '');
            $name = trim((string)$this->request->getParam('name', ''));
            if ($name === '') {
                return new JSONResponse(['error' => 'Nome obrigatório'], Http::STATUS_BAD_REQUEST);
            }
            // Block ".."/"." in the new folder name. The parent path may be
            // empty (root) or a sub-path under Audiolog/ — validate when set.
            if (str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
                return new JSONResponse(['error' => 'Nome inválido'], Http::STATUS_BAD_REQUEST);
            }
            if ($parent !== '' && ($err = $this->validateAudiologPath($parent, true))) {
                return $err;
            }
            $result = $this->audioService->createFolder($userId, $parent, $name);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function moveRecording(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $from = (string)$this->request->getParam('from', '');
            $to = (string)$this->request->getParam('to', '');
            if (empty($from)) {
                return new JSONResponse(['error' => 'Origem obrigatória'], Http::STATUS_BAD_REQUEST);
            }
            if ($err = $this->validateAudiologPath($from)) { return $err; }
            // Destination folder may be the literal "Audiolog" root.
            if ($to !== '' && ($err = $this->validateAudiologPath($to, true))) {
                return $err;
            }
            $result = $this->audioService->moveItem($userId, $from, $to);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function listFolders(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $folders = $this->audioService->listAllFolders($userId);
            return new JSONResponse(['folders' => $folders]);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function renameRecording(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $path = (string)$this->request->getParam('path', '');
            $newTitle = trim((string)$this->request->getParam('title', ''));

            if ($err = $this->validateAudiologPath($path)) { return $err; }
            if (empty($newTitle)) {
                return new JSONResponse(['error' => 'Título obrigatório'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->audioService->renameRecording($userId, $path, $newTitle);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    /**
     * Returns the WebSocket config for the live transcription panel.
     *
     * Includes the API key in the WS URL because Gemini Live's BidiGenerateContent
     * accepts auth via `?key=`. This endpoint is gated by `enable_realtime_stt`
     * and the existing user/group permission, so the key is only exposed to
     * users that can already see it in the admin settings panel anyway.
     *
     * Two providers are supported and both are safe for any allowed-group
     * user — neither leaks the API key to the browser:
     *   * web-speech: runs in the browser, no server key involved
     *   * google-stt: browser POSTs PCM chunks to /api/stt/recognize,
     *                 which proxies to Google with the server-side key
     */
    #[NoAdminRequired]
    public function realtimeConfig(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }

            $config = \OC::$server->getConfig();
            $appName = Application::APP_ID;

            $enabled = $config->getAppValue($appName, 'enable_realtime_stt', 'false') === 'true';
            if (!$enabled) {
                return new JSONResponse(['error' => 'Transcrição ao vivo desabilitada nas configurações.'], Http::STATUS_FORBIDDEN);
            }

            $provider = $config->getAppValue($appName, 'realtime_stt_provider', 'web-speech');
            $language = $config->getAppValue($appName, 'language', 'pt');

            if ($provider === 'web-speech') {
                return new JSONResponse([
                    'provider' => 'web-speech',
                    'language' => $language,
                ]);
            }

            if ($provider === 'google-stt') {
                // Just confirm SOME key is configured; the actual key stays
                // server-side and is only read inside sttRecognize().
                $sttKey = $this->crypto->decrypt($config->getAppValue($appName, 'google_stt_api_key', ''));
                $apiKey = $this->crypto->decrypt($config->getAppValue($appName, 'api_key', ''));
                if (empty($sttKey) && empty($apiKey)) {
                    return new JSONResponse(['error' => 'Nenhuma chave de API disponível para Speech-to-Text.'], Http::STATUS_FAILED_DEPENDENCY);
                }
                $googleLang = match ($language) {
                    'en' => 'en-US',
                    'es' => 'es-ES',
                    default => 'pt-BR',
                };
                return new JSONResponse([
                    'provider' => 'google-stt',
                    'language' => $language,
                    'languageCode' => $googleLang,
                    'model' => $config->getAppValue($appName, 'google_stt_model', 'latest_long'),
                ]);
            }

            // Anything else (legacy 'gemini-live' configs) — fall back to
            // web-speech, which works for everyone.
            return new JSONResponse([
                'provider' => 'web-speech',
                'language' => $language,
                'note' => 'Gemini Live não é mais suportado neste app. Usando Web Speech como fallback.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    /**
     * Server-side proxy for Google Cloud Speech-to-Text recognize calls.
     *
     * The browser POSTs base64 LINEAR16 audio + the recognition config we
     * told it to use; we forward to `speech.googleapis.com:recognize` with
     * the API key from app config, and stream the JSON response back. The
     * key NEVER touches the client, so any user in `allowed_groups` can use
     * Google STT live transcription without a leak risk.
     */
    #[NoAdminRequired]
    public function sttRecognize(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }

            $appName = Application::APP_ID;
            $config = \OC::$server->getConfig();

            $enabled = $config->getAppValue($appName, 'enable_realtime_stt', 'false') === 'true';
            if (!$enabled) {
                return new JSONResponse(['error' => 'Transcrição ao vivo desabilitada.'], Http::STATUS_FORBIDDEN);
            }

            // Prefer the dedicated Google STT key when present (works around
            // Google's restriction that blocks a Gemini-only key from STT).
            $sttKey = $this->crypto->decrypt($config->getAppValue($appName, 'google_stt_api_key', ''));
            $apiKey = $this->crypto->decrypt($config->getAppValue($appName, 'api_key', ''));
            $effectiveKey = $sttKey !== '' ? $sttKey : $apiKey;
            if (empty($effectiveKey)) {
                return new JSONResponse(['error' => 'Sem chave de API configurada para STT.'], Http::STATUS_FAILED_DEPENDENCY);
            }

            $audioB64 = (string)$this->request->getParam('audio', '');
            $cfg = $this->request->getParam('config', null);
            if ($audioB64 === '' || !is_array($cfg)) {
                return new JSONResponse(['error' => 'audio/config obrigatórios'], Http::STATUS_BAD_REQUEST);
            }
            // Cap chunk size so a single user can't tie up the server with
            // multi-MB requests on every keypress. ~1.5 MB ≈ ~45s of LINEAR16
            // 16kHz mono, well above any sane chunking cadence.
            if (strlen($audioB64) > 2 * 1024 * 1024) {
                return new JSONResponse(['error' => 'Chunk muito grande'], Http::STATUS_BAD_REQUEST);
            }

            // Sanitize the config — only allow a small known-safe subset of
            // Google STT options. The browser doesn't get to set arbitrary
            // billable parameters.
            $allowedCfg = [
                'encoding' => $cfg['encoding'] ?? 'LINEAR16',
                'sampleRateHertz' => (int)($cfg['sampleRateHertz'] ?? 16000),
                'audioChannelCount' => (int)($cfg['audioChannelCount'] ?? 1),
                'languageCode' => (string)($cfg['languageCode'] ?? 'pt-BR'),
                'enableAutomaticPunctuation' => (bool)($cfg['enableAutomaticPunctuation'] ?? true),
                'useEnhanced' => (bool)($cfg['useEnhanced'] ?? true),
                'model' => (string)($cfg['model'] ?? 'latest_long'),
            ];

            $client = $this->audioService->getHttpClient();
            $url = 'https://speech.googleapis.com/v1p1beta1/speech:recognize?key=' . urlencode($effectiveKey);
            $resp = $client->post($url, [
                'json' => ['config' => $allowedCfg, 'audio' => ['content' => $audioB64]],
                'timeout' => 30,
            ]);
            $body = $resp->getBody();
            $data = json_decode($body, true);
            if (!is_array($data)) {
                return new JSONResponse(['error' => 'Resposta inválida do STT'], Http::STATUS_BAD_GATEWAY);
            }
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }

    #[NoAdminRequired]
    public function deleteRecording(): JSONResponse {
        try {
            $check = $this->requireAccess();
            if ($check instanceof JSONResponse) { return $check; }
            $userId = $check;
            $path = (string)$this->request->getParam('path', '');
            $alsoOutputs = $this->request->getParam('alsoOutputs', true);

            if ($err = $this->validateAudiologPath($path)) { return $err; }

            $result = $this->audioService->deleteRecording($userId, $path, (bool)$alsoOutputs);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e, __FUNCTION__);
        }
    }
}
