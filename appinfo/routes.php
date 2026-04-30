<?php
declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],

        // API routes
        ['name' => 'api#process', 'url' => '/api/process', 'verb' => 'POST'],
        ['name' => 'api#status', 'url' => '/api/status/{taskId}', 'verb' => 'GET'],
        ['name' => 'api#getHistory', 'url' => '/api/history', 'verb' => 'GET'],
        ['name' => 'api#saveToNotes', 'url' => '/api/save-notes', 'verb' => 'POST'],
        ['name' => 'api#getDeckBoards', 'url' => '/api/deck-boards', 'verb' => 'GET'],
        ['name' => 'api#createDeckCards', 'url' => '/api/create-deck-cards', 'verb' => 'POST'],
        ['name' => 'api#saveRecording', 'url' => '/api/save-recording', 'verb' => 'POST'],
        ['name' => 'api#listRecordings', 'url' => '/api/recordings', 'verb' => 'GET'],
        ['name' => 'api#renameRecording', 'url' => '/api/recordings/rename', 'verb' => 'POST'],
        ['name' => 'api#deleteRecording', 'url' => '/api/recordings/delete', 'verb' => 'POST'],
        ['name' => 'api#createRecordingFolder', 'url' => '/api/recordings/folder', 'verb' => 'POST'],
        ['name' => 'api#moveRecording', 'url' => '/api/recordings/move', 'verb' => 'POST'],
        ['name' => 'api#listFolders', 'url' => '/api/recordings/folders', 'verb' => 'GET'],
        ['name' => 'api#realtimeConfig', 'url' => '/api/realtime/config', 'verb' => 'GET'],
        ['name' => 'api#sttRecognize', 'url' => '/api/stt/recognize', 'verb' => 'POST'],

        // Settings routes
        ['name' => 'settings#saveSettings', 'url' => '/settings/save', 'verb' => 'POST'],
        ['name' => 'settings#getSettings', 'url' => '/settings/get', 'verb' => 'GET'],
        ['name' => 'settings#testConnection', 'url' => '/settings/test', 'verb' => 'POST'],
        ['name' => 'settings#healthcheck', 'url' => '/settings/healthcheck', 'verb' => 'GET'],
    ]
];
