<?php
/**
 * Assistente de Áudio - Main Template
 * @copyright 2025 Jhonatan Jaworski
 */

// Load Nextcloud dialogs for FilePicker
\OCP\Util::addScript('core', 'dist/files_fileinfo');
\OCP\Util::addScript('core', 'dist/files_client');
\OCP\Util::addScript('files', 'dist/files-main');

\OCP\Util::addScript('audiolog', 'audiolog-main');
\OCP\Util::addStyle('audiolog', 'style');

// Inline SVG icons
$icons = [
    'microphone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-linecap="round"><circle cx="12" cy="12" r="9.9" fill="none" stroke-width="2.7"/><rect x="6.3"  y="9.75" width="1.8" height="4.5" rx="0.9" stroke="none"/><rect x="8.7" y="6.75" width="1.8" height="10.5" rx="0.9" stroke="none"/><rect x="11.1" y="4.5" width="1.8" height="15" rx="0.9" stroke="none"/><rect x="13.5" y="7.5" width="1.8" height="9"  rx="0.9" stroke="none"/><rect x="15.9" y="9.75" width="1.8" height="4.5"  rx="0.9" stroke="none"/></svg>',
    'upload' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M9,16V10H5L12,3L19,10H15V16H9M5,20V18H19V20H5Z"/></svg>',
    'folder' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M10,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6H12L10,4Z"/></svg>',
    'audio' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14,3.23V5.29C16.89,6.15 19,8.83 19,12C19,15.17 16.89,17.84 14,18.7V20.77C18,19.86 21,16.28 21,12C21,7.72 18,4.14 14,3.23M16.5,12C16.5,10.23 15.5,8.71 14,7.97V16C15.5,15.29 16.5,13.76 16.5,12M3,9V15H7L12,20V4L7,9H3Z"/></svg>',
    'close' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z"/></svg>',
    'record' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,2A3,3 0 0,1 15,5V11A3,3 0 0,1 12,14A3,3 0 0,1 9,11V5A3,3 0 0,1 12,2M19,11C19,14.53 16.39,17.44 13,17.93V21H11V17.93C7.61,17.44 5,14.53 5,11H7A5,5 0 0,0 12,16A5,5 0 0,0 17,11H19Z"/></svg>',
    'stop' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M18,18H6V6H18V18Z"/></svg>',
    'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/></svg>',
    'transcription' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z"/></svg>',
    'document' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z"/></svg>',
    'chart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M22,21H2V3H4V19H6V10H10V19H12V6H16V19H18V14H22V21Z"/></svg>',
    'list' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3,4H7V8H3V4M9,5V7H21V5H9M3,10H7V14H3V10M9,11V13H21V11H9M3,16H7V20H3V16M9,17V19H21V17H9"/></svg>',
    'check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z"/></svg>',
    'play' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M8,5.14V19.14L19,12.14L8,5.14Z"/></svg>',
    'copy' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg>',
    'notes' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3M7,7H17V5H19V19H5V5H7V7M17,11H7V9H17V11M15,15H7V13H15V15Z"/></svg>',
    'download' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/></svg>',
    'export' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,1L8,5H11V14H13V5H16M18,23H6C4.89,23 4,22.1 4,21V9A2,2 0 0,1 6,7H9V9H6V21H18V9H15V7H18A2,2 0 0,1 20,9V21A2,2 0 0,1 18,23Z"/></svg>',
    'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6,2H14L20,8V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V4A2,2 0 0,1 6,2M13,3.5V9H18.5L13,3.5M7,13L8.5,18H10.5L12,15L13.5,18H15.5L17,13H15L14.1,16.5L12.6,13H11.4L9.9,16.5L9,13H7Z"/></svg>',
    'add' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/></svg>',
    'user' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/></svg>',
    'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/></svg>',
    'tasks' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3,5H9V11H3V5M5,7V9H7V7H5M11,7H21V9H11V7M11,15H21V17H11V15M5,20L1.5,16.5L2.91,15.09L5,17.17L9.59,12.59L11,14L5,20Z"/></svg>',
    'title' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5,4V7H10.5V19H13.5V7H19V4H5Z"/></svg>',
    'cloud' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19.35,10.03C18.67,6.59 15.64,4 12,4C9.11,4 6.6,5.64 5.35,8.03C2.34,8.36 0,10.9 0,14A6,6 0 0,0 6,20H19A5,5 0 0,0 24,15C24,12.36 21.95,10.22 19.35,10.03Z"/></svg>',
    'computer' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M4,6H20V16H4M20,18A2,2 0 0,0 22,16V6C22,4.89 21.1,4 20,4H4C2.89,4 2,4.89 2,6V16A2,2 0 0,0 4,18H0V20H24V18H20Z"/></svg>',
];
?>

<!-- App navigation (sidebar — mesmo padrão do Files) -->
<div id="app-navigation" role="navigation" aria-label="<?php p($l->t('Audiolog')); ?>">
    <ul class="audiolog-nav-list">
        <li class="audiolog-nav-entry is-active" data-tab="new">
            <a href="#" class="audiolog-nav-link">
                <span class="audiolog-nav-icon"><?php echo $icons['record']; ?></span>
                <span class="audiolog-nav-label"><?php p($l->t('Nova Gravação')); ?></span>
            </a>
        </li>
        <li class="audiolog-nav-entry" data-tab="live" id="nav-entry-live">
            <a href="#" class="audiolog-nav-link">
                <span class="audiolog-nav-icon"><?php echo $icons['microphone']; ?></span>
                <span class="audiolog-nav-label"><?php p($l->t('Transcrição ao Vivo')); ?></span>
            </a>
        </li>
        <li class="audiolog-nav-entry" data-tab="recordings">
            <a href="#" class="audiolog-nav-link">
                <span class="audiolog-nav-icon"><?php echo $icons['list']; ?></span>
                <span class="audiolog-nav-label"><?php p($l->t('Minhas Gravações')); ?></span>
            </a>
        </li>
    </ul>
    <div class="audiolog-nav-footer">
        <span class="beta-badge">BETA</span>
    </div>
</div>

<div id="app-content"
     data-realtime-enabled="<?php echo !empty($_['enable_realtime_stt']) ? '1' : '0'; ?>"
     data-realtime-provider="<?php echo htmlspecialchars($_['realtime_provider'] ?? 'web-speech'); ?>">
    <div id="app-content-wrapper">
        <!-- Recovery Banner (shown if there is an unfinished recording in IndexedDB) -->
        <div id="recovery-banner" class="recovery-banner" role="region"
             aria-labelledby="recovery-title" aria-live="polite" style="display:none">
            <div class="recovery-content">
                <span class="recovery-icon" aria-hidden="true">⚠</span>
                <div class="recovery-text">
                    <strong id="recovery-title"><?php p($l->t('Gravação não finalizada')); ?></strong>
                    <span id="recovery-meta"></span>
                </div>
            </div>
            <div class="recovery-actions">
                <button type="button" class="button primary" id="btn-recover-resume">
                    <span class="btn-icon"><?php echo $icons['record']; ?></span>
                    <?php p($l->t('Continuar gravando')); ?>
                </button>
                <button type="button" class="button" id="btn-recover-process">
                    <span class="btn-icon"><?php echo $icons['play']; ?></span>
                    <?php p($l->t('Processar como está')); ?>
                </button>
                <button type="button" class="button button-delete" id="btn-recover-discard">
                    <span class="btn-icon"><?php echo $icons['close']; ?></span>
                    <?php p($l->t('Descartar')); ?>
                </button>
            </div>
        </div>

        <!-- Speaker naming panel — overlay, fora das abas (assim não some quando troca aba). -->
        <section class="audiolog-overlay" id="speaker-naming-panel" style="display:none">
            <div class="live-card">
                <header class="naming-header">
                    <h2 style="margin:0;"><?php p($l->t('Quem é cada falante?')); ?></h2>
                    <p class="naming-subtitle">
                        <?php p($l->t('Identificamos vozes diferentes na gravação. Atribua um nome a cada uma — vamos atualizar a transcrição com os nomes reais.')); ?>
                    </p>
                </header>
                <div id="speaker-naming-list" class="speaker-naming-list"></div>
                <footer class="naming-footer">
                    <button type="button" class="button" id="btn-naming-skip"><?php p($l->t('Pular')); ?></button>
                    <button type="button" class="button primary" id="btn-naming-save"><?php p($l->t('Salvar nomes')); ?></button>
                </footer>
            </div>
        </section>

        <!-- Refine loading overlay — shows spinner while Gemini diarizes. -->
        <section class="audiolog-overlay" id="live-refine-loading" style="display:none">
            <div class="live-card live-loading-card">
                <div class="spinner"></div>
                <h3 style="margin:14px 0 6px;"><?php p($l->t('Refinando transcrição...')); ?></h3>
                <p style="color:var(--color-text-maxcontrast,#888);margin:0;">
                    <?php p($l->t('Identificando falantes pela voz. Isso leva alguns segundos.')); ?>
                </p>
            </div>
        </section>

        <!-- Main Content -->
        <main class="reuniao-main audiolog-panel" data-tab-panel="new">
            <!-- Input Section -->
            <section class="reuniao-input-section">
                <!-- Audio Input Card -->
                <div class="input-card">
                    <!-- Title Input -->
                    <div class="form-group">
                        <label for="audio-title">
                            <span class="label-icon"><?php echo $icons['title']; ?></span>
                            <?php p($l->t('Título')); ?>
                        </label>
                        <input type="text" id="audio-title" placeholder="<?php p($l->t('Ex: Reunião de planejamento, Entrevista com cliente...')); ?>">
                    </div>

                    <!-- File Upload -->
                    <div class="upload-area" id="upload-area">
                        <input type="file" id="audio-file" accept="audio/*,video/*" style="display:none">
                        <div class="upload-content">
                            <span class="upload-icon"><?php echo $icons['upload']; ?></span>
                            <p><?php p($l->t('Arraste um arquivo de áudio ou vídeo aqui ou')); ?></p>
                            <div class="upload-buttons">
                                <button type="button" class="button" id="btn-select-file">
                                    <span class="btn-icon"><?php echo $icons['computer']; ?></span>
                                    <?php p($l->t('Do Computador')); ?>
                                </button>
                                <button type="button" class="button" id="btn-select-nextcloud">
                                    <span class="btn-icon"><?php echo $icons['cloud']; ?></span>
                                    <?php p($l->t('Do Armazenamento')); ?>
                                </button>
                            </div>
                        </div>
                        <div class="file-selected" id="file-selected" style="display:none">
                            <span class="file-icon"><?php echo $icons['audio']; ?></span>
                            <span class="file-name" id="file-name"></span>
                            <button type="button" class="button-delete" id="btn-remove-file" title="<?php p($l->t('Remover')); ?>" aria-label="<?php p($l->t('Remover')); ?>">
                                <span class="btn-icon"><?php echo $icons['close']; ?></span>
                            </button>
                        </div>
                    </div>

                    <!-- Recording -->
                    <div class="recording-section">
                        <p class="divider"><span><?php p($l->t('ou')); ?></span></p>

                        <!-- Audio Source Selection -->
                        <div class="audio-source-selection">
                            <label class="source-label"><?php p($l->t('Dispositivo de Áudio')); ?></label>
                            
                            <div class="source-options">
                                <label class="source-option-label">
                                    <input type="radio" name="audioSource" value="mic" checked>
                                    <span><?php p($l->t('Microfone')); ?></span>
                                </label>
                                <label class="source-option-label">
                                    <input type="radio" name="audioSource" value="system">
                                    <span><?php p($l->t('Áudio do Sistema')); ?></span>
                                </label>
                            </div>

                            <div id="mic-select-container">
                                <select id="mic-select">
                                    <option value="default"><?php p($l->t('Carregando dispositivos...')); ?></option>
                                </select>
                            </div>
                            
                            <div id="system-audio-hint" style="display:none;">
                                <p>
                                    <strong><?php p($l->t('Gravar áudio do sistema (chamadas, reuniões online)')); ?></strong><br>
                                    <?php p($l->t('Permite capturar o áudio de uma aba ou tela compartilhada.')); ?>
                                </p>
                            </div>
                        </div>

                        <button type="button" class="button primary btn-record" id="btn-record">
                            <span class="btn-icon"><?php echo $icons['record']; ?></span>
                            <span class="record-text"><?php p($l->t('Gravar Áudio')); ?></span>
                        </button>
                        <div class="recording-indicator" id="recording-indicator" style="display:none">
                            <span class="recording-dot" id="recording-dot"></span>
                            <span class="recording-time" id="recording-time">00:00</span>
                            <span class="recording-size" id="recording-size">· 0 KB</span>
                            <button type="button" class="button" id="btn-pause" title="<?php p($l->t('Pausar')); ?>" aria-label="<?php p($l->t('Pausar')); ?>">
                                <span class="btn-icon" id="pause-icon-wrap"><?php echo $icons['stop']; ?></span>
                                <span class="pause-text"><?php p($l->t('Pausar')); ?></span>
                            </button>
                            <button type="button" class="button error" id="btn-stop">
                                <span class="btn-icon"><?php echo $icons['stop']; ?></span>
                                <?php p($l->t('Parar')); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Options Card -->
                <div class="options-card">
                    <h2>
                        <span class="section-icon"><?php echo $icons['settings']; ?></span>
                        <?php p($l->t('Opções de Processamento')); ?>
                    </h2>

                    <!-- Output Type - Multiple Selection -->
                    <div class="form-group">
                        <label><?php p($l->t('Tipos de Saída')); ?></label>
                        <p class="form-hint"><?php p($l->t('Selecione um ou mais formatos de saída')); ?></p>
                        <div class="output-types">
                            <label class="output-type selected">
                                <input type="checkbox" name="outputType" value="transcricao" checked>
                                <span class="type-icon"><?php echo $icons['transcription']; ?></span>
                                <span class="type-name"><?php p($l->t('Transcrição')); ?></span>
                            </label>
                            <label class="output-type">
                                <input type="checkbox" name="outputType" value="ata">
                                <span class="type-icon"><?php echo $icons['document']; ?></span>
                                <span class="type-name"><?php p($l->t('Ata')); ?></span>
                            </label>
                            <label class="output-type">
                                <input type="checkbox" name="outputType" value="resumo">
                                <span class="type-icon"><?php echo $icons['chart']; ?></span>
                                <span class="type-name"><?php p($l->t('Resumo')); ?></span>
                            </label>
                            <label class="output-type">
                                <input type="checkbox" name="outputType" value="pauta">
                                <span class="type-icon"><?php echo $icons['list']; ?></span>
                                <span class="type-name"><?php p($l->t('Pauta')); ?></span>
                            </label>
                            <label class="output-type">
                                <input type="checkbox" name="outputType" value="tarefas">
                                <span class="type-icon"><?php echo $icons['tasks']; ?></span>
                                <span class="type-name"><?php p($l->t('Tarefas')); ?></span>
                            </label>
                        </div>
                    </div>

                    <!-- Custom Prompt -->
                    <div class="form-group">
                        <label for="custom-prompt"><?php p($l->t('Instrução Personalizada (opcional)')); ?></label>
                        <textarea id="custom-prompt" rows="3" placeholder="<?php p($l->t('Ex: Foque nos pontos sobre orçamento, liste todos os prazos mencionados...')); ?>"></textarea>
                    </div>

                    <!-- Process Button -->
                    <button type="button" class="button primary btn-process" id="btn-process" disabled>
                        <span class="btn-icon"><?php echo $icons['play']; ?></span>
                        <span class="btn-text"><?php p($l->t('Processar Áudio')); ?></span>
                    </button>
                </div>
            </section>

            <!-- Processing Indicator -->
            <section class="processing-section" id="processing-section" style="display:none">
                <div class="processing-card">
                    <div class="spinner"></div>
                    <h3><?php p($l->t('Processando...')); ?></h3>
                    <p class="processing-status" id="processing-status"><?php p($l->t('Enviando áudio para análise')); ?></p>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill"></div>
                    </div>
                    <p class="processing-tip"><?php p($l->t('Isso pode levar alguns minutos dependendo do tamanho do áudio')); ?></p>
                </div>
            </section>

            <!-- Result Section -->
            <section class="result-section" id="result-section" style="display:none">
                <div class="result-card">
                    <div class="result-header">
                        <h2>
                            <span class="section-icon"><?php echo $icons['check']; ?></span>
                            <?php p($l->t('Resultado')); ?>
                        </h2>
                        <div class="result-actions">
                            <button type="button" class="button" id="btn-copy" title="<?php p($l->t('Copiar')); ?>" aria-label="<?php p($l->t('Copiar')); ?>">
                                <span class="btn-icon"><?php echo $icons['copy']; ?></span>
                                <span class="button-text"><?php p($l->t('Copiar')); ?></span>
                            </button>
                            <button type="button" class="button" id="btn-save-notes" title="<?php p($l->t('Salvar em Notas')); ?>" aria-label="<?php p($l->t('Salvar em Notas')); ?>">
                                <span class="btn-icon"><?php echo $icons['notes']; ?></span>
                                <span class="button-text"><?php p($l->t('Notas')); ?></span>
                            </button>
                            <button type="button" class="button" id="btn-save-office" title="<?php p($l->t('Abrir no NextCloud Office')); ?>" aria-label="<?php p($l->t('Abrir no NextCloud Office')); ?>">
                                <span class="btn-icon"><?php echo $icons['word']; ?></span>
                                <span class="button-text"><?php p($l->t('Office')); ?></span>
                            </button>
                            <button type="button" class="button" id="btn-download" title="<?php p($l->t('Baixar')); ?>">
                                <span class="btn-icon"><?php echo $icons['download']; ?></span>
                                <span class="button-text"><?php p($l->t('Baixar')); ?></span>
                            </button>
                        </div>
                    </div>
                    <div class="result-content" id="result-content"></div>

                    <!-- Tasks Section (if applicable) -->
                    <div class="tasks-section" id="tasks-section" style="display:none">
                        <h3>
                            <span class="section-icon"><?php echo $icons['tasks']; ?></span>
                            <?php p($l->t('Tarefas Identificadas')); ?>
                        </h3>
                        <div class="tasks-list" id="tasks-list"></div>
                        <button type="button" class="button" id="btn-export-tasks">
                            <span class="btn-icon"><?php echo $icons['export']; ?></span>
                            <?php p($l->t('Exportar Tarefas')); ?>
                        </button>
                    </div>
                </div>

                <!-- Footer actions -->
                <div class="result-footer-actions">
                    <button type="button" class="button" id="btn-back-to-input">
                        <?php p($l->t('← Voltar')); ?>
                    </button>
                    <button type="button" class="button primary btn-new" id="btn-new">
                        <span class="btn-icon"><?php echo $icons['add']; ?></span>
                        <?php p($l->t('Novo Processamento')); ?>
                    </button>
                </div>
            </section>
        </main>

        <!-- "Transcrição ao Vivo" tab panel — dedicated screen with device picker
             and a "Iniciar" button that starts the WebSocket session. -->
        <section class="reuniao-main audiolog-panel" data-tab-panel="live" style="display:none">
            <div class="live-setup-card">
                <h2 style="margin:0 0 6px;">
                    <span class="header-icon"><?php echo $icons['microphone']; ?></span>
                    <?php p($l->t('Transcrição ao Vivo')); ?>
                </h2>
                <p class="live-setup-subtitle">
                    <?php p($l->t('Fala sendo convertida em texto em tempo real. Ao parar, a transcrição é refinada com identificação automática de falantes.')); ?>
                </p>

                <div class="form-group">
                    <label for="live-title">
                        <span class="label-icon"><?php echo $icons['title']; ?></span>
                        <?php p($l->t('Título')); ?>
                    </label>
                    <input type="text" id="live-title" placeholder="<?php p($l->t('Ex: Reunião de planejamento, Entrevista...')); ?>">
                </div>

                <div class="audio-source-selection">
                    <label class="source-label"><?php p($l->t('Dispositivo de Áudio')); ?></label>
                    <div class="source-options">
                        <label class="source-option-label">
                            <input type="radio" name="liveAudioSource" value="mic" checked>
                            <span><?php p($l->t('Microfone')); ?></span>
                        </label>
                        <label class="source-option-label">
                            <input type="radio" name="liveAudioSource" value="system">
                            <span><?php p($l->t('Áudio do Sistema (reuniões online)')); ?></span>
                        </label>
                    </div>

                    <div id="live-mic-select-container">
                        <select id="live-mic-select">
                            <option value="default"><?php p($l->t('Carregando dispositivos...')); ?></option>
                        </select>
                    </div>

                    <div id="live-system-audio-hint" style="display:none">
                        <p>
                            <strong><?php p($l->t('Capturar áudio de uma aba ou tela')); ?></strong><br>
                            <?php p($l->t('Ao iniciar, o navegador pedirá pra escolher a aba/janela. Marque "Compartilhar áudio do sistema".')); ?>
                        </p>
                    </div>
                </div>

                <button type="button" class="button primary btn-start-live" id="btn-start-live">
                    <span class="btn-icon"><?php echo $icons['record']; ?></span>
                    <?php p($l->t('Iniciar transcrição ao vivo')); ?>
                </button>
            </div>

            <!-- Live transcription panel (active when capturing) — sibling of
                 the setup card so toggling them is just display swap. -->
            <section class="live-section" id="live-panel" style="display:none">
                <div class="live-card">
                    <header class="live-header">
                        <div class="live-header-left">
                            <span class="live-dot"></span>
                            <strong><?php p($l->t('Rascunho ao vivo')); ?></strong>
                        </div>
                        <div class="live-header-right">
                            <span class="live-time" id="live-time">00:00</span>
                            <span class="live-status" id="live-status"><?php p($l->t('Conectando...')); ?></span>
                            <button type="button" class="button error" id="btn-live-stop">
                                <span class="btn-icon"><?php echo $icons['stop']; ?></span>
                                <?php p($l->t('Parar e salvar')); ?>
                            </button>
                        </div>
                    </header>
                    <div class="live-transcript" id="live-transcript" aria-live="polite"></div>
                    <p class="live-hint"><?php p($l->t('A transcrição é salva automaticamente como nota ao parar.')); ?></p>
                </div>
            </section>
        </section>

        <!-- "Minhas Gravações" tab panel. Toolbar (Files button, refresh,
             bulk actions) is rendered by JS so we don't ship duplicates. -->
        <section class="reuniao-main audiolog-panel" data-tab-panel="recordings" style="display:none">
            <div class="recordings-section">
                <div id="recordings-list" class="recordings-list">
                    <p class="recordings-empty"><?php p($l->t('Carregando...')); ?></p>
                </div>
            </div>
        </section>
    </div>
</div>
