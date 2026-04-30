<?php
declare(strict_types=1);

namespace OCA\Audiolog\Settings;

use OCA\Audiolog\AppInfo\Application;
use OCA\Audiolog\Service\CryptoHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

    public function __construct(
        private IConfig $config,
        private IL10N $l,
        private IGroupManager $groupManager,
        private CryptoHelper $crypto,
        private string $appName = Application::APP_ID
    ) {
    }

    public function getForm(): TemplateResponse {
        // Get all available groups
        $groups = $this->groupManager->search('');
        $availableGroups = [];
        foreach ($groups as $group) {
            $availableGroups[] = [
                'gid' => $group->getGID(),
                'displayName' => $group->getDisplayName()
            ];
        }

        // Get allowed groups (stored as JSON array)
        $allowedGroupsJson = $this->config->getAppValue($this->appName, 'allowed_groups', '[]');
        $allowedGroups = json_decode($allowedGroupsJson, true) ?: [];

        $parameters = [
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
            'use_google_stt_for_transcription' => $this->config->getAppValue($this->appName, 'use_google_stt_for_transcription', 'false'),
            'google_stt_api_key' => $this->crypto->decrypt($this->config->getAppValue($this->appName, 'google_stt_api_key', '')),
            'max_jobs_per_user_per_day' => $this->config->getAppValue($this->appName, 'max_jobs_per_user_per_day', '50'),
            'available_groups' => $availableGroups,
            'allowed_groups' => $allowedGroups,
        ];

        return new TemplateResponse($this->appName, 'admin', $parameters);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
