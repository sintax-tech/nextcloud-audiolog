<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCA\Audiolog\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

class PermissionService {
    private string $appName = Application::APP_ID;

    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private IUserSession $userSession
    ) {
    }

    /**
     * Check if the current user has permission to use the app
     */
    public function currentUserHasAccess(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->userHasAccess($user->getUID());
    }

    /**
     * Check if a specific user has permission to use the app
     */
    public function userHasAccess(string $userId): bool {
        $allowedGroupsJson = $this->config->getAppValue($this->appName, 'allowed_groups', '[]');
        $allowedGroups = json_decode($allowedGroupsJson, true) ?: [];

        // If no groups are configured, everyone has access
        if (empty($allowedGroups)) {
            return true;
        }

        // Check if user is in any of the allowed groups
        foreach ($allowedGroups as $groupId) {
            if ($this->groupManager->isInGroup($userId, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of allowed groups
     */
    public function getAllowedGroups(): array {
        $allowedGroupsJson = $this->config->getAppValue($this->appName, 'allowed_groups', '[]');
        return json_decode($allowedGroupsJson, true) ?: [];
    }
}
