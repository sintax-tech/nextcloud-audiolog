/**
 * Assistente de Audio (BETA) - Main JavaScript
 * @author Jhonatan Jaworski
 * @copyright 2025
 */

(function() {
    'use strict';


    // ============================================================
    // IndexedDB recording store
    // ------------------------------------------------------------
    // Persists audio chunks as they arrive from MediaRecorder so a
    // recording survives tab close, browser crash, or connection drop.
    // ============================================================
    const DB_NAME = 'audiolog';
    const DB_VERSION = 1;
    const STORE_RECORDINGS = 'recordings';
    const STORE_CHUNKS = 'chunks';
    const RECOVERY_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000; // 7d

    let dbPromise = null;
    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_RECORDINGS)) {
                    db.createObjectStore(STORE_RECORDINGS, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(STORE_CHUNKS)) {
                    const chunks = db.createObjectStore(STORE_CHUNKS, { keyPath: 'id', autoIncrement: true });
                    chunks.createIndex('recordingId', 'recordingId', { unique: false });
                }
            };
            req.onsuccess = (e) => resolve(e.target.result);
            req.onerror = (e) => reject(e.target.error);
        });
        return dbPromise;
    }
    async function dbTx(stores, mode) {
        const db = await openDb();
        return db.transaction(stores, mode);
    }
    async function dbPut(store, value) {
        const tx = await dbTx([store], 'readwrite');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).put(value);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }
    async function dbGet(store, key) {
        const tx = await dbTx([store], 'readonly');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).get(key);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }
    async function dbGetAll(store) {
        const tx = await dbTx([store], 'readonly');
        return new Promise((resolve, reject) => {
            const req = tx.objectStore(store).getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }
    async function dbGetChunksByRecording(recordingId) {
        const tx = await dbTx([STORE_CHUNKS], 'readonly');
        return new Promise((resolve, reject) => {
            const idx = tx.objectStore(STORE_CHUNKS).index('recordingId');
            const req = idx.getAll(recordingId);
            req.onsuccess = () => {
                const all = req.result || [];
                all.sort((a, b) => (a.seq || 0) - (b.seq || 0));
                resolve(all);
            };
            req.onerror = () => reject(req.error);
        });
    }
    async function dbDeleteRecording(recordingId) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction([STORE_RECORDINGS, STORE_CHUNKS], 'readwrite');
            tx.objectStore(STORE_RECORDINGS).delete(recordingId);
            const idx = tx.objectStore(STORE_CHUNKS).index('recordingId');
            const cursorReq = idx.openCursor(IDBKeyRange.only(recordingId));
            cursorReq.onsuccess = (e) => {
                const cursor = e.target.result;
                if (cursor) {
                    cursor.delete();
                    cursor.continue();
                }
            };
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }
    async function cleanupOldRecordings() {
        try {
            const all = await dbGetAll(STORE_RECORDINGS);
            const cutoff = Date.now() - RECOVERY_MAX_AGE_MS;
            for (const rec of all) {
                const ts = rec.endedAt || rec.startedAt || 0;
                if (ts < cutoff) {
                    await dbDeleteRecording(rec.id);
                }
            }
        } catch (err) {
            console.warn('cleanupOldRecordings failed:', err);
        }
    }
    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'r-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    // State
    let selectedFile = null;
    let selectedNextcloudPath = null; // Path do arquivo no Nextcloud
    let mediaRecorder = null;
    let audioChunks = [];
    let recordingStartTime = null;
    let recordingInterval = null;
    let recordingPausedAccumMs = 0; // total ms paused, subtracted from elapsed
    let recordingPauseStartedAt = null;
    let recordingBytes = 0;
    let recordingChunkSeq = 0;
    let currentRecordingId = null;
    let currentRecordingStream = null;
    let isProcessing = false;
    let wakeLock = null;

    // DOM Elements
    const elements = {};

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        // Cache DOM elements
        elements.uploadArea = document.getElementById('upload-area');
        elements.audioFile = document.getElementById('audio-file');
        elements.fileSelected = document.getElementById('file-selected');
        elements.fileName = document.getElementById('file-name');
        elements.btnSelectFile = document.getElementById('btn-select-file');
        elements.btnSelectNextcloud = document.getElementById('btn-select-nextcloud');
        elements.btnRemoveFile = document.getElementById('btn-remove-file');
        elements.btnRecord = document.getElementById('btn-record');
        elements.btnStop = document.getElementById('btn-stop');
        elements.recordingIndicator = document.getElementById('recording-indicator');
        elements.recordingTime = document.getElementById('recording-time');
        elements.btnProcess = document.getElementById('btn-process');
        elements.customPrompt = document.getElementById('custom-prompt');
        elements.processingSection = document.getElementById('processing-section');
        elements.processingStatus = document.getElementById('processing-status');
        elements.progressFill = document.getElementById('progress-fill');
        elements.resultSection = document.getElementById('result-section');
        elements.resultContent = document.getElementById('result-content');
        elements.tasksSection = document.getElementById('tasks-section');
        elements.tasksList = document.getElementById('tasks-list');
        elements.btnCopy = document.getElementById('btn-copy');
        elements.btnSaveNotes = document.getElementById('btn-save-notes');
        elements.btnSaveOffice = document.getElementById('btn-save-office');
        elements.btnDownload = document.getElementById('btn-download');
        elements.btnExportTasks = document.getElementById('btn-export-tasks');
        elements.btnNew = document.getElementById('btn-new');
        elements.audioTitle = document.getElementById('audio-title');
        
        // Audio Source Elements
        elements.micSelect = document.getElementById('mic-select');
        elements.micSelectContainer = document.getElementById('mic-select-container');
        elements.systemAudioHint = document.getElementById('system-audio-hint');
        elements.audioSourceRadios = document.querySelectorAll('input[name="audioSource"]');

        // BETA: pause + size indicator + recovery banner
        elements.btnPause = document.getElementById('btn-pause');
        elements.recordingSize = document.getElementById('recording-size');
        elements.recordingDot = document.getElementById('recording-dot');
        elements.recoveryBanner = document.getElementById('recovery-banner');
        elements.recoveryTitle = document.getElementById('recovery-title');
        elements.recoveryMeta = document.getElementById('recovery-meta');
        elements.btnRecoverResume = document.getElementById('btn-recover-resume');
        elements.btnRecoverProcess = document.getElementById('btn-recover-process');
        elements.btnRecoverDiscard = document.getElementById('btn-recover-discard');

        // Initialize audio devices
        initAudioDevices();

        // Bind events
        bindEvents();

        // BETA: cleanup old recordings + check for unfinished one
        cleanupOldRecordings();
        checkForUnfinishedRecording();

        // Sidebar navigation (Files-style)
        elements.tabBtns = document.querySelectorAll('.audiolog-nav-entry');
        elements.tabPanels = document.querySelectorAll('.audiolog-panel');
        elements.btnRecordingsRefresh = document.getElementById('btn-recordings-refresh');
        elements.recordingsList = document.getElementById('recordings-list');
        elements.tabBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                switchTab(btn.dataset.tab);
            });
        });
        if (elements.btnRecordingsRefresh) {
            elements.btnRecordingsRefresh.addEventListener('click', loadRecordings);
        }

        // Persist on tab close / hide / background
        window.addEventListener('pagehide', handlePageHide);
        window.addEventListener('beforeunload', handlePageHide);
        document.addEventListener('visibilitychange', handleRecordingVisibilityHidden);

        // Live transcription button (only shows if enabled in admin)
        initLiveButton();
    }

    function handlePageHide() {
        // If a recording is in progress: mark it interrupted in IndexedDB.
        // Critically, we do NOT call mediaRecorder.stop() here — that fires
        // onstop, which uploads to the server and *deletes* the local copy on
        // success, defeating the whole point of recovery. Chunks have already
        // been persisted every 1s by ondataavailable; we also call
        // requestData() here to force-flush whatever is still buffered
        // inside the recorder. The mic stream is released by the browser
        // automatically when the tab unloads.
        if (mediaRecorder && mediaRecorder.state !== 'inactive' && currentRecordingId) {
            const id = currentRecordingId;
            // Force-flush any pending audio in the encoder buffer so the last
            // partial second lands in IDB before the page goes away.
            try { mediaRecorder.requestData(); } catch (_) {}
            // Fire-and-forget; transactions in pagehide may or may not finish,
            // but chunks are already on disk so even partial state is fine.
            dbGet(STORE_RECORDINGS, id).then(rec => {
                if (rec && rec.status !== 'completed') {
                    rec.status = 'interrupted';
                    rec.endedAt = Date.now();
                    return dbPut(STORE_RECORDINGS, rec);
                }
            }).catch(() => {});
        }
    }

    // Same idea as pagehide, but for tab-switching/backgrounding (no unload).
    // Mobile browsers (especially Safari iOS) routinely suspend background
    // tabs; if we waited for pagehide we'd miss the chance to mark the
    // recording as interrupted before the tab is killed.
    function handleRecordingVisibilityHidden() {
        if (document.visibilityState !== 'hidden') return;
        if (mediaRecorder && mediaRecorder.state !== 'inactive' && currentRecordingId) {
            const id = currentRecordingId;
            try { mediaRecorder.requestData(); } catch (_) {}
            dbGet(STORE_RECORDINGS, id).then(rec => {
                if (rec && rec.status !== 'completed' && rec.status !== 'interrupted') {
                    rec.status = 'interrupted';
                    rec.endedAt = Date.now();
                    return dbPut(STORE_RECORDINGS, rec);
                }
            }).catch(() => {});
        }
    }

    async function checkForUnfinishedRecording() {
        try {
            const all = await dbGetAll(STORE_RECORDINGS);
            console.log('[Audiolog recovery] IDB recordings found:', all.length, all);
            // Pick the most recent recording in 'interrupted' or 'recording' state.
            const candidates = all.filter(r => r.status === 'interrupted' || r.status === 'recording');
            if (candidates.length === 0) {
                console.log('[Audiolog recovery] no interrupted/recording candidates');
                return;
            }
            candidates.sort((a, b) => (b.startedAt || 0) - (a.startedAt || 0));
            const rec = candidates[0];
            // Verify chunks really exist before showing the banner — if the
            // recording row exists but no chunks were ever flushed, recovery
            // would silently fail when the user clicks Resume/Process.
            const chunks = await dbGetChunksByRecording(rec.id);
            console.log('[Audiolog recovery] candidate', rec.id, 'has', chunks.length, 'chunks');
            if (chunks.length === 0) {
                // Drop the empty record so we don't show a useless banner.
                await dbDeleteRecording(rec.id).catch(() => {});
                return;
            }
            // Compute real bytes from the actual chunks rather than trusting
            // the row's `bytes` field (the last update may not have committed).
            const realBytes = chunks.reduce((s, c) => s + ((c.blob && c.blob.size) || 0), 0);
            const elapsedMs = (rec.endedAt || Date.now()) - rec.startedAt;
            elements.recoveryTitle.textContent = rec.title || t('audiolog', 'Gravação não finalizada');
            elements.recoveryMeta.textContent = ' — ' + formatDuration(elapsedMs / 1000) + ' · ' + formatBytes(realBytes);
            elements.recoveryBanner.style.display = 'flex';
            elements.recoveryBanner.dataset.recordingId = rec.id;
            console.log('[Audiolog recovery] banner shown for', rec.id);
        } catch (err) {
            console.warn('checkForUnfinishedRecording failed:', err);
        }
    }

    async function buildBlobFromRecording(recordingId) {
        const rec = await dbGet(STORE_RECORDINGS, recordingId);
        if (!rec) return null;
        const chunks = await dbGetChunksByRecording(recordingId);
        const blobs = chunks.map(c => c.blob).filter(Boolean);
        if (blobs.length === 0) return null;
        const blob = new Blob(blobs, { type: rec.mimeType || 'audio/webm' });
        const ext = (rec.mimeType || '').includes('webm') ? 'webm'
                 : (rec.mimeType || '').includes('ogg') ? 'ogg'
                 : (rec.mimeType || '').includes('mp4') ? 'm4a'
                 : 'webm';
        const safe = (rec.title || 'Audio').replace(/[^a-zA-Z0-9\-_À-ɏ]/g, '_');
        return new File([blob], safe + '.' + ext, { type: rec.mimeType || 'audio/webm' });
    }

    function formatBytes(n) {
        if (!n) return '0 KB';
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(2) + ' MB';
    }
    function formatDuration(seconds) {
        seconds = Math.floor(seconds || 0);
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return h > 0 ? h + ':' + m + ':' + s : m + ':' + s;
    }

    function bindEvents() {
        // File selection from computer
        elements.btnSelectFile.addEventListener('click', () => elements.audioFile.click());
        elements.audioFile.addEventListener('change', handleFileSelect);
        elements.btnRemoveFile.addEventListener('click', removeFile);

        // File selection from Nextcloud
        elements.btnSelectNextcloud.addEventListener('click', openNextcloudPicker);

        // Drag and drop
        elements.uploadArea.addEventListener('dragover', handleDragOver);
        elements.uploadArea.addEventListener('dragleave', handleDragLeave);
        elements.uploadArea.addEventListener('drop', handleDrop);

        // Recording
        // Wrapper: addEventListener passes the PointerEvent as the first arg,
        // which would land in `continueFromId` and corrupt the recovery path
        // (the event isn't a valid IDB key and isn't structured-cloneable).
        elements.btnRecord.addEventListener('click', () => startRecording());
        elements.btnStop.addEventListener('click', stopRecording);
        if (elements.btnPause) {
            elements.btnPause.addEventListener('click', togglePauseRecording);
        }

        // Recovery banner
        if (elements.btnRecoverResume) {
            elements.btnRecoverResume.addEventListener('click', recoveryResume);
        }
        if (elements.btnRecoverProcess) {
            elements.btnRecoverProcess.addEventListener('click', recoveryProcess);
        }
        if (elements.btnRecoverDiscard) {
            elements.btnRecoverDiscard.addEventListener('click', recoveryDiscard);
        }

        // Output type selection (checkboxes for multiple selection)
        document.querySelectorAll('input[name="outputType"]').forEach(checkbox => {
            checkbox.addEventListener('change', handleOutputTypeChange);
        });

        // Process
        elements.btnProcess.addEventListener('click', processAudio);

        // Result actions - use direct onclick assignment for reliability
        if (elements.btnCopy) {
            elements.btnCopy.onclick = function(e) {
                e.preventDefault();
                copyResult();
            };
        }
        if (elements.btnSaveNotes) {
            elements.btnSaveNotes.onclick = function(e) {
                e.preventDefault();
                saveToNotes();
            };
        }
        if (elements.btnSaveOffice) {
            elements.btnSaveOffice.onclick = function(e) {
                e.preventDefault();
                saveAndOpenInOffice();
            };
        }
        if (elements.btnDownload) {
            elements.btnDownload.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                showDownloadOptions(e);
            };
        }
        if (elements.btnExportTasks) {
            elements.btnExportTasks.onclick = function(e) {
                e.preventDefault();
                exportTasks();
            };
        }
        if (elements.btnNew) {
            elements.btnNew.onclick = function(e) {
                e.preventDefault();
                resetForm();
            };
        }
        const btnBackToInput = document.getElementById('btn-back-to-input');
        if (btnBackToInput) {
            btnBackToInput.addEventListener('click', (e) => {
                e.preventDefault();
                // Hide result, show input section again — keeps the file selected
                // so the user can change options and re-process without re-uploading.
                if (elements.resultSection) elements.resultSection.style.display = 'none';
                const inputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
                if (inputSection) inputSection.style.display = '';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // Audio Source Toggle
        elements.audioSourceRadios.forEach(radio => {
            radio.addEventListener('change', (e) => {
                if (e.target.value === 'mic') {
                    elements.micSelectContainer.style.display = 'block';
                    elements.systemAudioHint.style.display = 'none';
                } else {
                    elements.micSelectContainer.style.display = 'none';
                    elements.systemAudioHint.style.display = 'block';
                }
            });
        });

        // Handle visibility change for wake lock
        document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    // File handling
    function handleFileSelect(e) {
        const file = e.target.files[0];
        if (file && (file.type.startsWith('audio/') || file.type.startsWith('video/'))) {
            setSelectedFile(file);
        } else {
            showNotification(t('audiolog', 'Por favor, selecione um arquivo de audio ou video valido'), 'error');
        }
    }

    function handleDragOver(e) {
        e.preventDefault();
        elements.uploadArea.classList.add('drag-over');
    }

    function handleDragLeave(e) {
        e.preventDefault();
        elements.uploadArea.classList.remove('drag-over');
    }

    function handleDrop(e) {
        e.preventDefault();
        elements.uploadArea.classList.remove('drag-over');

        const file = e.dataTransfer.files[0];
        if (file && (file.type.startsWith('audio/') || file.type.startsWith('video/'))) {
            setSelectedFile(file);
        } else {
            showNotification(t('audiolog', 'Por favor, solte um arquivo de audio ou video valido'), 'error');
        }
    }

    function setSelectedFile(file) {
        selectedFile = file;
        selectedNextcloudPath = null; // Clear NC path when local file is selected
        elements.fileName.textContent = file.name;
        elements.uploadArea.querySelector('.upload-content').style.display = 'none';
        elements.fileSelected.style.display = 'flex';
        elements.btnProcess.disabled = false;
    }

    function setSelectedNextcloudFile(path) {
        selectedFile = null; // Clear local file
        selectedNextcloudPath = path;
        const fileName = path.split('/').pop();
        elements.fileName.textContent = fileName + ' (Nextcloud)';
        elements.uploadArea.querySelector('.upload-content').style.display = 'none';
        elements.fileSelected.style.display = 'flex';
        elements.btnProcess.disabled = false;
    }

    function removeFile() {
        selectedFile = null;
        selectedNextcloudPath = null;
        elements.audioFile.value = '';
        elements.uploadArea.querySelector('.upload-content').style.display = 'block';
        elements.fileSelected.style.display = 'none';
        elements.btnProcess.disabled = true;
    }

    // Nextcloud FilePicker
    function openNextcloudPicker() {
        // Audio and video file extensions
        const mediaExtensions = [
            // Audio
            'mp3', 'wav', 'ogg', 'webm', 'm4a', 'aac', 'flac', 'wma', 'opus', 'mpeg',
            // Video (for Talk recordings)
            'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', '3gp'
        ];

        // Callback when file is selected
        const pickerCallback = function(path) {
            if (typeof path === 'string') {
                const ext = path.split('.').pop().toLowerCase();
                if (mediaExtensions.includes(ext)) {
                    setSelectedNextcloudFile(path);
                } else {
                    showNotification(t('audiolog', 'Por favor, selecione um arquivo de áudio ou vídeo válido'), 'error');
                }
            } else if (Array.isArray(path) && path.length > 0) {
                const filePath = path[0];
                const ext = filePath.split('.').pop().toLowerCase();
                if (mediaExtensions.includes(ext)) {
                    setSelectedNextcloudFile(filePath);
                } else {
                    showNotification(t('audiolog', 'Por favor, selecione um arquivo de áudio ou vídeo válido'), 'error');
                }
            }
        };

        // Try different FilePicker APIs for compatibility
        if (typeof OC !== 'undefined' && OC.dialogs) {
            // Nextcloud FilePicker - allow all files, validation done in callback
            OC.dialogs.filepicker(
                t('audiolog', 'Selecione um arquivo de áudio ou vídeo'),
                pickerCallback,
                false,
                [],  // Empty array allows all files
                true
            );
        } else {
            showNotification(t('audiolog', 'FilePicker não disponível'), 'error');
        }
    }

    // Audio Devices
    async function initAudioDevices() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            console.warn('enumerateDevices() not supported');
            return;
        }

        try {
            // PRIVACY: We do NOT call getUserMedia at page load just to grab
            // device labels — that would flash the browser's mic indicator
            // every time the user opens the app, even if they never record.
            // Without prior permission, enumerateDevices returns devices with
            // empty `label` fields; we fall back to "Microfone 1/2/...".
            // Once the user actually records once, the browser remembers the
            // grant and labels become visible on the next list refresh.
            const devices = await navigator.mediaDevices.enumerateDevices();
            elements.micSelect.innerHTML = '';
            let hasMic = false;

            devices.forEach(function(device) {
                if (device.kind === 'audioinput') {
                    hasMic = true;
                    const option = document.createElement('option');
                    option.value = device.deviceId;
                    option.text = device.label || 'Microfone ' + (elements.micSelect.length + 1);
                    elements.micSelect.appendChild(option);
                }
            });

            if (!hasMic) {
                const option = document.createElement('option');
                option.text = t('audiolog', 'Nenhum microfone encontrado');
                elements.micSelect.appendChild(option);
            }
        } catch (err) {
            console.warn('Erro ao listar dispositivos:', err);
            // Fallback if permission denied or error
            const option = document.createElement('option');
            option.text = t('audiolog', 'Padrão');
            elements.micSelect.appendChild(option);
        }
    }

    // Wake Lock API - keeps screen on during recording
    async function requestWakeLock() {
        if ('wakeLock' in navigator) {
            try {
                wakeLock = await navigator.wakeLock.request('screen');
            } catch (err) {
                console.warn('Wake Lock nao disponivel:', err);
            }
        }
    }

    async function releaseWakeLock() {
        if (wakeLock !== null) {
            try {
                await wakeLock.release();
                wakeLock = null;
            } catch (err) {
                console.warn('Erro ao liberar Wake Lock:', err);
            }
        }
    }

    function handleVisibilityChange() {
        // Re-request wake lock when tab becomes visible again during recording
        if (document.visibilityState === 'visible' && mediaRecorder && mediaRecorder.state === 'recording') {
            requestWakeLock();
        }
    }

    // Recording
    /**
     * Acquire an audio MediaStream honoring the "Mic vs System Audio" radio
     * and the chosen mic deviceId. Returns { stream, source }.
     *
     * Used by both regular recording and live transcription so the device
     * picker is consistent across both modes.
     */
    async function acquireAudioStream(opts = {}) {
        // Caller can override source/deviceId — the live-transcription tab
        // has its own radios/select and passes them in.
        const source = opts.source
            || document.querySelector('input[name="audioSource"]:checked')?.value
            || 'mic';
        const noiseProcessing = opts.noiseProcessing !== false; // default true

        if (source === 'system') {
            const displayStream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                audio: {
                    echoCancellation: false,
                    noiseSuppression: false,
                    autoGainControl: false
                }
            });
            const audioTracks = displayStream.getAudioTracks();
            if (audioTracks.length === 0) {
                displayStream.getTracks().forEach(track => track.stop());
                throw new Error(t('audiolog',
                    'Áudio do sistema não detectado. Marque "Compartilhar áudio do sistema" ao selecionar a tela.'));
            }
            displayStream.getVideoTracks().forEach(track => track.stop());
            return { stream: new MediaStream(audioTracks), source };
        }

        const deviceId = opts.deviceId !== undefined
            ? opts.deviceId
            : (elements.micSelect ? elements.micSelect.value : '');
        const constraints = {
            audio: {
                channelCount: 1,
                echoCancellation: noiseProcessing,
                noiseSuppression: noiseProcessing,
                autoGainControl: noiseProcessing
            }
        };
        if (deviceId && deviceId !== 'default') {
            constraints.audio.deviceId = { exact: deviceId };
        }
        // Suggest 16kHz for live STT path; non-live still works at 16kHz too.
        if (opts.sampleRate) {
            constraints.audio.sampleRate = opts.sampleRate;
        }
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        return { stream, source };
    }

    async function startRecording(continueFromId = null) {
        // Defense-in-depth: only accept string UUIDs from the recovery banner.
        // If something else slips in (e.g., a click event from a direct bind),
        // ignore it and start a fresh recording.
        if (continueFromId && typeof continueFromId !== 'string') {
            continueFromId = null;
        }
        try {
            // Request wake lock to prevent screen from turning off
            await requestWakeLock();

            let stream;
            const source = document.querySelector('input[name="audioSource"]:checked').value;

            if (source === 'system') {
                // System Audio via DisplayMedia
                try {
                    const displayStream = await navigator.mediaDevices.getDisplayMedia({
                        video: true,
                        audio: {
                            echoCancellation: false,
                            noiseSuppression: false,
                            autoGainControl: false
                        }
                    });

                    const audioTracks = displayStream.getAudioTracks();
                    
                    if (audioTracks.length === 0) {
                        // User didn't share audio
                        displayStream.getTracks().forEach(track => track.stop());
                        throw new Error(t('audiolog', 'Áudio do sistema não detectado. Certifique-se de marcar "Compartilhar áudio do sistema" ao selecionar a tela.'));
                    }

                    stream = new MediaStream(audioTracks);

                    // Stop video track as we only need audio
                    displayStream.getVideoTracks().forEach(track => track.stop());

                } catch (err) {
                    if (err.name === 'NotAllowedError') {
                        throw new Error(t('audiolog', 'Compartilhamento cancelado.'));
                    }
                    throw err;
                }
            } else {
                // Microphone
                const deviceId = elements.micSelect.value;
                const constraints = {
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    }
                };

                if (deviceId && deviceId !== 'default') {
                    constraints.audio.deviceId = { exact: deviceId };
                }

                stream = await navigator.mediaDevices.getUserMedia(constraints);
            }

            // Determine best supported format
            const mimeTypes = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
                'audio/mpeg'
            ];

            let selectedMimeType = '';
            for (const mimeType of mimeTypes) {
                if (MediaRecorder.isTypeSupported(mimeType)) {
                    selectedMimeType = mimeType;
                    break;
                }
            }

            const options = selectedMimeType ? { mimeType: selectedMimeType } : {};
            mediaRecorder = new MediaRecorder(stream, options);
            audioChunks = [];
            recordingBytes = 0;
            recordingChunkSeq = 0;
            recordingPausedAccumMs = 0;
            recordingPauseStartedAt = null;

            currentRecordingStream = stream;
            const deviceLabel = source === 'system'
                ? t('audiolog', 'Áudio do sistema')
                : (elements.micSelect.options[elements.micSelect.selectedIndex]?.text || 'Microfone');

            if (continueFromId) {
                // Resume: pre-load previous chunks into the in-memory array so the
                // final Blob = previous + new. New chunks keep appending under the
                // same recordingId in IndexedDB.
                currentRecordingId = continueFromId;
                try {
                    const oldChunks = await dbGetChunksByRecording(continueFromId);
                    audioChunks = oldChunks.map(c => c.blob).filter(Boolean);
                    recordingBytes = audioChunks.reduce((s, b) => s + (b.size || 0), 0);
                    recordingChunkSeq = oldChunks.length;
                    const oldRec = await dbGet(STORE_RECORDINGS, continueFromId);
                    if (oldRec) {
                        oldRec.status = 'recording';
                        oldRec.endedAt = null;
                        oldRec.bytes = recordingBytes;
                        await dbPut(STORE_RECORDINGS, oldRec);
                    }
                    showNotification(t('audiolog', 'Continuando gravação anterior...'), 'info');
                } catch (idbErr) {
                    console.warn('IDB load for continuation failed:', idbErr);
                }
            } else {
                currentRecordingId = uuid();
                try {
                    await dbPut(STORE_RECORDINGS, {
                        id: currentRecordingId,
                        title: getTitle(),
                        mimeType: selectedMimeType || 'audio/webm',
                        deviceLabel: deviceLabel,
                        source: source,
                        startedAt: Date.now(),
                        endedAt: null,
                        status: 'recording',
                        chunkCount: 0,
                        bytes: 0
                    });
                    console.log('[Audiolog IDB] recording row created:', currentRecordingId);
                } catch (idbErr) {
                    console.error('[Audiolog IDB] failed to create recording row — recovery will NOT work:', idbErr);
                    showNotification(t('audiolog', 'Atenção: persistência local indisponível. Não será possível retomar se a página fechar.'), 'error');
                }
            }

            mediaRecorder.ondataavailable = async (e) => {
                if (e.data && e.data.size > 0) {
                    audioChunks.push(e.data);
                    recordingBytes += e.data.size;
                    const seq = recordingChunkSeq++;
                    if (currentRecordingId) {
                        try {
                            await dbPut(STORE_CHUNKS, {
                                recordingId: currentRecordingId,
                                seq: seq,
                                blob: e.data
                            });
                            const rec = await dbGet(STORE_RECORDINGS, currentRecordingId);
                            if (rec) {
                                rec.chunkCount = (rec.chunkCount || 0) + 1;
                                rec.bytes = recordingBytes;
                                await dbPut(STORE_RECORDINGS, rec);
                            }
                            // Verbose only on first few chunks to avoid spam.
                            if (seq < 3 || seq % 30 === 0) {
                                console.log('[Audiolog IDB] chunk persisted seq=' + seq + ' size=' + e.data.size + 'B');
                            }
                        } catch (idbErr) {
                            console.error('[Audiolog IDB] chunk put FAILED seq=' + seq + ':', idbErr);
                        }
                    }
                }
            };

            mediaRecorder.onstop = async () => {
                const mimeType = mediaRecorder.mimeType || 'audio/webm';
                const ext = mimeType.includes('webm') ? 'webm' :
                           mimeType.includes('ogg') ? 'ogg' :
                           mimeType.includes('mp4') ? 'm4a' : 'mp3';

                const audioBlob = new Blob(audioChunks, { type: mimeType });
                const fileName = getTitle().replace(/[^a-zA-Z0-9\-_\u00C0-\u024F]/g, '_');
                const file = new File([audioBlob], `${fileName}.${ext}`, { type: mimeType });
                setSelectedFile(file);

                stream.getTracks().forEach(track => track.stop());
                releaseWakeLock();

                const finishedId = currentRecordingId;
                if (finishedId) {
                    try {
                        const rec = await dbGet(STORE_RECORDINGS, finishedId);
                        if (rec) {
                            rec.status = 'completed';
                            rec.endedAt = Date.now();
                            rec.bytes = recordingBytes;
                            await dbPut(STORE_RECORDINGS, rec);
                        }
                    } catch (_) { /* ignore */ }
                }

                try {
                    showNotification(t('audiolog', 'Salvando gravação...'), 'info');
                    const formData = new FormData();
                    formData.append('audio', file);
                    formData.append('title', getTitle());

                    const response = await fetch(OC.generateUrl('/apps/audiolog/api/save-recording'), {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'requesttoken': OC.requestToken
                        }
                    });

                    const result = await response.json();
                    if (result && !result.error) {
                         showNotification(t('audiolog', 'Gravação salva em: ') + result.path, 'success');
                         if (finishedId) {
                             dbDeleteRecording(finishedId).catch(() => {});
                         }
                    } else {
                         console.error('Erro ao salvar gravacao:', result.error);
                         showNotification(t('audiolog', 'Falha ao enviar — gravação preservada localmente.'), 'error');
                    }
                } catch (saveErr) {
                    console.error('Erro ao enviar gravacao:', saveErr);
                    showNotification(t('audiolog', 'Falha ao enviar — gravação preservada localmente.'), 'error');
                }

                currentRecordingId = null;
                currentRecordingStream = null;
            };

            // 1s segments — worst-case data loss on crash is one 1s segment.
            // Trade-off: more IDB writes, but a user that records 3s and
            // backgrounds the tab still has 3 chunks persisted, not zero.
            mediaRecorder.start(1000);
            recordingStartTime = Date.now();

            elements.btnRecord.style.display = 'none';
            elements.recordingIndicator.style.display = 'flex';
            if (elements.btnPause) {
                elements.btnPause.querySelector('.pause-text').textContent = t('audiolog', 'Pausar');
                elements.btnPause.style.display = 'inline-flex';
            }

            recordingInterval = setInterval(updateRecordingTime, 1000);

            showNotification(t('audiolog', 'Gravação iniciada'), 'success');

        } catch (err) {
            console.error('Erro ao acessar audio:', err);
            releaseWakeLock();

            let errorMsg = err.message || t('audiolog', 'Erro ao acessar audio.');
            if (err.name === 'NotAllowedError') {
                 // Already handled or generic permission error
                 if (!errorMsg.includes('cancelado')) {
                     errorMsg = t('audiolog', 'Permissao negada. Verifique as configuracoes.');
                 }
            } else if (err.name === 'NotFoundError') {
                errorMsg = t('audiolog', 'Nenhum dispositivo de audio encontrado.');
            }
            showNotification(errorMsg, 'error');
        }
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }

        clearInterval(recordingInterval);
        elements.btnRecord.style.display = 'inline-flex';
        elements.recordingIndicator.style.display = 'none';
        elements.recordingTime.textContent = '00:00';
        if (elements.recordingSize) {
            elements.recordingSize.textContent = '· 0 KB';
        }

        showNotification(t('audiolog', 'Gravação finalizada'), 'success');
    }

    function togglePauseRecording() {
        if (!mediaRecorder) return;

        if (mediaRecorder.state === 'recording') {
            mediaRecorder.pause();
            recordingPauseStartedAt = Date.now();
            if (elements.recordingDot) {
                elements.recordingDot.classList.add('paused');
            }
            const txt = elements.btnPause.querySelector('.pause-text');
            if (txt) txt.textContent = t('audiolog', 'Continuar');
            showNotification(t('audiolog', 'Gravação pausada'), 'info');
        } else if (mediaRecorder.state === 'paused') {
            mediaRecorder.resume();
            if (recordingPauseStartedAt) {
                recordingPausedAccumMs += Date.now() - recordingPauseStartedAt;
                recordingPauseStartedAt = null;
            }
            if (elements.recordingDot) {
                elements.recordingDot.classList.remove('paused');
            }
            const txt = elements.btnPause.querySelector('.pause-text');
            if (txt) txt.textContent = t('audiolog', 'Pausar');
            showNotification(t('audiolog', 'Gravação retomada'), 'info');
        }
    }

    function updateRecordingTime() {
        const now = Date.now();
        const pausedExtra = recordingPauseStartedAt ? (now - recordingPauseStartedAt) : 0;
        const effectiveMs = (now - recordingStartTime) - recordingPausedAccumMs - pausedExtra;
        elements.recordingTime.textContent = formatDuration(effectiveMs / 1000);
        if (elements.recordingSize) {
            elements.recordingSize.textContent = '· ' + formatBytes(recordingBytes);
        }
    }

    // ============================================================
    // Recovery banner actions
    // ============================================================
    /**
     * "Continuar gravando" — carrega chunks da gravação interrompida e inicia uma
     * nova captura de microfone que CONTINUA acumulando no mesmo recordingId.
     * O Blob final = chunks antigos + novos (concat client-side).
     */
    async function recoveryResume() {
        const id = elements.recoveryBanner.dataset.recordingId;
        if (!id) return;
        const rec = await dbGet(STORE_RECORDINGS, id);
        if (rec && rec.title && elements.audioTitle) {
            elements.audioTitle.value = rec.title;
        }
        elements.recoveryBanner.style.display = 'none';
        // Switch back to "Nova" tab in case the user is somewhere else.
        switchTab('new');
        await startRecording(id);
    }

    /**
     * "Processar agora" — só pega o que já tinha sido gravado e manda para
     * processamento, sem capturar mais áudio.
     */
    async function recoveryProcess() {
        const id = elements.recoveryBanner.dataset.recordingId;
        if (!id) return;
        const file = await buildBlobFromRecording(id);
        if (!file) {
            showNotification(t('audiolog', 'Não foi possível recuperar os chunks'), 'error');
            return;
        }
        setSelectedFile(file);
        const rec = await dbGet(STORE_RECORDINGS, id);
        if (rec && rec.title && elements.audioTitle) {
            elements.audioTitle.value = rec.title;
        }
        elements.recoveryBanner.style.display = 'none';
        switchTab('new');
        // Drop from IDB now that it's loaded into the UI.
        dbDeleteRecording(id).catch(() => {});
        // Auto-trigger processing
        if (selectedFile) {
            processAudio();
        }
    }

    async function recoveryDiscard() {
        const id = elements.recoveryBanner.dataset.recordingId;
        if (id) {
            try { await dbDeleteRecording(id); } catch (_) { /* ignore */ }
        }
        elements.recoveryBanner.style.display = 'none';
        showNotification(t('audiolog', 'Gravação descartada'), 'info');
    }

    // Output type (checkbox toggle)
    function handleOutputTypeChange(e) {
        const label = e.target.closest('.output-type');
        if (e.target.checked) {
            label.classList.add('selected');
        } else {
            label.classList.remove('selected');
        }
    }

    // Get custom title or generate default
    function getTitle() {
        const customTitle = elements.audioTitle ? elements.audioTitle.value.trim() : '';
        return customTitle || `Audio_${formatDateTime()}`;
    }

    // Process audio
    async function processAudio() {
        if ((!selectedFile && !selectedNextcloudPath) || isProcessing) return;

        // Get all selected output types
        const selectedTypes = [];
        document.querySelectorAll('input[name="outputType"]:checked').forEach(cb => {
            selectedTypes.push(cb.value);
        });

        if (selectedTypes.length === 0) {
            showNotification(t('audiolog', 'Selecione pelo menos um tipo de saída'), 'error');
            return;
        }

        isProcessing = true;
        const customPrompt = elements.customPrompt.value.trim();
        const title = getTitle();

        // Show processing UI
        showProcessing();

        try {
            let response;
            updateProgress(10, t('audiolog', 'Preparando arquivo...'));

            // Simulate progress for better UX
            const progressInterval = setInterval(() => {
                const currentWidth = parseFloat(elements.progressFill.style.width) || 10;
                if (currentWidth < 85) {
                    updateProgress(currentWidth + Math.random() * 5, t('audiolog', 'Processando com IA...'));
                }
            }, 2000);

            if (selectedNextcloudPath) {
                // Process file from Nextcloud using the same /api/process endpoint with ncPath
                updateProgress(20, t('audiolog', 'Lendo arquivo do Nextcloud...'));

                response = await fetch(OC.generateUrl('/apps/audiolog/api/process'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({
                        ncPath: selectedNextcloudPath,
                        outputTypes: selectedTypes,
                        title: title,
                        prompt: customPrompt
                    })
                });
            } else {
                // Process uploaded file
                updateProgress(20, t('audiolog', 'Enviando áudio...'));

                const formData = new FormData();
                formData.append('audio', selectedFile);
                formData.append('outputTypes', JSON.stringify(selectedTypes));
                formData.append('title', title);
                formData.append('prompt', customPrompt);

                response = await fetch(OC.generateUrl('/apps/audiolog/api/process'), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'requesttoken': OC.requestToken
                    }
                });
            }

            clearInterval(progressInterval);
            updateProgress(90, t('audiolog', 'Finalizando...'));

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            updateProgress(100, t('audiolog', 'Concluido!'));

            // Show result
            setTimeout(() => {
                showResult(result);
            }, 500);

        } catch (err) {
            console.error('Erro ao processar:', err);
            showNotification(t('audiolog', 'Erro ao processar audio:') + ' ' + err.message, 'error');
            hideProcessing();
        } finally {
            isProcessing = false;
        }
    }

    function showProcessing() {
        document.querySelector('.reuniao-input-section').style.display = 'none';
        elements.processingSection.style.display = 'flex';
        elements.resultSection.style.display = 'none';
        updateProgress(5, t('audiolog', 'Iniciando...'));
    }

    function hideProcessing() {
        document.querySelector('.reuniao-input-section').style.display = 'grid';
        elements.processingSection.style.display = 'none';
    }

    function updateProgress(percent, status) {
        elements.progressFill.style.width = percent + '%';
        elements.processingStatus.textContent = status;
    }

    function showResult(result) {
        elements.processingSection.style.display = 'none';
        elements.resultSection.style.display = 'block';

        // Format and display result
        const formattedText = formatResult(result.text);
        elements.resultContent.innerHTML = formattedText;

        // Show tasks if available
        if (result.tasks && result.tasks.length > 0) {
            elements.tasksSection.style.display = 'block';
            renderTasks(result.tasks);
        } else {
            elements.tasksSection.style.display = 'none';
        }

        // Store result for actions
        elements.resultSection.dataset.result = result.text;
        elements.resultSection.dataset.tasks = JSON.stringify(result.tasks || []);
        // Track which Audiolog/* file this result came from, so saveToNotes
        // can link the generated note back into that recording's metadata.
        elements.resultSection.dataset.audioPath = result.audioPath || '';

        if (result.savedFile) {
            showNotification(t('audiolog', 'Audio salvo em:') + ' ' + result.savedFile, 'success');
        }

        // If the transcript labelled speakers, give the user a chance to
        // replace [Falante N] with real names. Triggered for both upload and
        // Nextcloud-file paths so the workflow is uniform.
        if (window.AudiologSpeakerNaming && result.text && /\[Falante\s+\d+\]/i.test(result.text)) {
            const audioPath = result.audioPath || result.sourceFile || '';
            const title = (result.title || elements.audioTitle?.value || '').trim() || 'Transcrição';
            // Defer one tick so the result is painted first; the user sees the
            // transcript briefly, then the naming form pops in.
            setTimeout(() => {
                window.AudiologSpeakerNaming.offer(result.text, audioPath, title, {
                    showNotification: showNotification,
                    t: t,
                    OC: OC
                });
            }, 600);
        }
    }

    function formatResult(text) {
        // SECURITY: escape ALL HTML metacharacters first, THEN apply our
        // markdown-to-HTML rules. The text comes from the LLM and the user
        // can steer it with a custom prompt — without escaping, a prompt
        // like "respond with <script>...</script>" would land as live HTML
        // in `innerHTML` below and execute in the user's session.
        const escaped = (text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // Convert markdown-like formatting to HTML on the already-escaped
        // string. The tags we emit here are the only ones the renderer can
        // produce, so the result is a known-safe subset.
        let html = escaped
            .replace(/^# (.+)$/gm, '<h1>$1</h1>')
            .replace(/^## (.+)$/gm, '<h2>$1</h2>')
            .replace(/^### (.+)$/gm, '<h3>$1</h3>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]]+)\]:/g, '<span class="speaker">[$1]:</span>')
            .replace(/\n/g, '<br>');

        // Handle tables
        if (html.includes('|')) {
            html = html.replace(/\|(.+)\|/g, (match) => {
                const cells = match.split('|').filter(c => c.trim());
                if (cells.every(c => c.trim() === '---' || c.trim().match(/^-+$/))) {
                    return '';
                }
                const row = cells.map(c => `<td>${c.trim()}</td>`).join('');
                return `<tr>${row}</tr>`;
            });
            html = html.replace(/(<tr>.*<\/tr>)+/g, '<table class="result-table">$&</table>');
        }

        return `<div class="formatted-result">${html}</div>`;
    }

    function renderTasks(tasks) {
        elements.tasksList.innerHTML = tasks.map((task, i) => `
            <div class="task-item">
                <input type="checkbox" id="task-${i}" checked>
                <label for="task-${i}">
                    <span class="task-title">${escapeHtml(task.title)}</span>
                    ${task.assignee ? `<span class="task-assignee"><span class="icon-user"></span> ${escapeHtml(task.assignee)}</span>` : ''}
                    ${task.dueDate ? `<span class="task-due"><span class="icon-calendar"></span> ${escapeHtml(task.dueDate)}</span>` : ''}
                </label>
            </div>
        `).join('');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Result actions
    function copyResult() {
        const text = elements.resultSection.dataset.result;
        navigator.clipboard.writeText(text).then(() => {
            showNotification(t('audiolog', 'Copiado para a area de transferencia'), 'success');
        }).catch(() => {
            showNotification(t('audiolog', 'Erro ao copiar'), 'error');
        });
    }

    async function saveToNotes() {
        const text = elements.resultSection.dataset.result;
        const title = getTitle();
        const audioPath = elements.resultSection.dataset.audioPath || '';

        try {
            const response = await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ title, content: text, audioPath })
            });

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            showNotification(result.message + ': ' + result.path, 'success');

        } catch (err) {
            showNotification(t('audiolog', 'Erro ao salvar nota:') + ' ' + err.message, 'error');
        }
    }

    async function saveAndOpenInOffice() {
        const text = elements.resultSection.dataset.result;
        const title = getTitle();

        if (!text) {
            showNotification(t('audiolog', 'Nenhum resultado para salvar'), 'error');
            return;
        }

        try {
            showNotification(t('audiolog', 'Salvando documento...'), 'info');

            const response = await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ title: title, content: text, format: 'docx', audioPath: elements.resultSection.dataset.audioPath || '' })
            });

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            // Open the file using the file ID
            if (result.fileId) {
                showNotification(t('audiolog', 'Documento salvo! Abrindo no Office...'), 'success');
                // Use the Files app with fileid parameter to trigger opening
                const fileUrl = OC.generateUrl('/f/' + result.fileId);
                window.open(fileUrl, '_blank');
            } else {
                showNotification(t('audiolog', 'Documento salvo em: ') + result.path, 'success');
            }

        } catch (err) {
            console.error('saveAndOpenInOffice error:', err);
            showNotification(t('audiolog', 'Erro ao salvar documento:') + ' ' + err.message, 'error');
        }
    }

    function showDownloadOptions(e) {

        // Remove existing menu if any
        const existingMenu = document.querySelector('.download-menu');
        if (existingMenu) {
            existingMenu.remove();
            return;
        }


        // Create menu
        const menu = document.createElement('div');
        menu.className = 'download-menu';
        menu.style.cssText = 'position:fixed;z-index:100000;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.3);min-width:180px;padding:4px 0;';

        // Position near button
        const rect = elements.btnDownload.getBoundingClientRect();
        menu.style.top = (rect.bottom + 5) + 'px';
        menu.style.left = rect.left + 'px';


        // Add options
        const mdBtn = document.createElement('button');
        mdBtn.textContent = '📄 Markdown (.md)';
        mdBtn.style.cssText = 'display:block;width:100%;padding:12px 16px;border:none;background:transparent;cursor:pointer;text-align:left;font-size:14px;color:#333;';
        mdBtn.onmouseover = () => mdBtn.style.background = '#f0f0f0';
        mdBtn.onmouseout = () => mdBtn.style.background = 'transparent';
        mdBtn.onclick = function(evt) {
            evt.preventDefault();
            evt.stopPropagation();
            downloadAsMarkdown();
            menu.remove();
        };

        const docxBtn = document.createElement('button');
        docxBtn.textContent = '📝 Word (.docx)';
        docxBtn.style.cssText = 'display:block;width:100%;padding:12px 16px;border:none;background:transparent;cursor:pointer;text-align:left;font-size:14px;color:#333;';
        docxBtn.onmouseover = () => docxBtn.style.background = '#f0f0f0';
        docxBtn.onmouseout = () => docxBtn.style.background = 'transparent';
        docxBtn.onclick = function(evt) {
            evt.preventDefault();
            evt.stopPropagation();
            downloadAsDocx();
            menu.remove();
        };

        menu.appendChild(mdBtn);
        menu.appendChild(docxBtn);
        document.body.appendChild(menu);


        // Close on outside click
        const closeHandler = (evt) => {
            if (!menu.contains(evt.target) && evt.target !== elements.btnDownload) {
                menu.remove();
                document.removeEventListener('click', closeHandler);
            }
        };
        setTimeout(() => document.addEventListener('click', closeHandler), 200);
    }

    function downloadAsMarkdown() {
        const text = elements.resultSection.dataset.result;
        const title = getTitle();
        const blob = new Blob([text], { type: 'text/markdown' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${title}.md`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    async function downloadAsDocx() {
        const text = elements.resultSection.dataset.result;
        const title = getTitle();

        try {
            // Request the server to generate DOCX and get base64 content
            const response = await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ title, content: text, format: 'docx', download: true })
            });

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            // If we have download data (base64), download it
            if (result.downloadData) {
                const byteCharacters = atob(result.downloadData);
                const byteNumbers = new Array(byteCharacters.length);
                for (let i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                const byteArray = new Uint8Array(byteNumbers);
                const blob = new Blob([byteArray], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' });

                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `${title}.docx`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } else {
                // File was saved to Nextcloud, notify user
                showNotification(result.message + ': ' + result.path, 'success');
            }

        } catch (err) {
            showNotification(t('audiolog', 'Erro ao baixar documento:') + ' ' + err.message, 'error');
        }
    }

    async function exportTasks() {
        const tasks = JSON.parse(elements.resultSection.dataset.tasks || '[]');
        const checkedTasks = [];

        document.querySelectorAll('.task-item input:checked').forEach((checkbox, i) => {
            if (tasks[i]) {
                checkedTasks.push(tasks[i]);
            }
        });

        if (checkedTasks.length === 0) {
            showNotification(t('audiolog', 'Selecione pelo menos uma tarefa'), 'error');
            return;
        }

        // Show Deck board selection dialog
        showDeckSelectionDialog(checkedTasks);
    }

    async function showDeckSelectionDialog(tasks) {
        // First fetch available boards
        try {
            showNotification(t('audiolog', 'Carregando painéis do Deck...'), 'info');

            const url = OC.generateUrl('/apps/audiolog/api/deck-boards');

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });


            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            const boards = result.boards || [];

            if (boards.length === 0) {
                // No Deck boards - save to file
                showNotification(t('audiolog', 'Nenhum painel do Deck encontrado. Salvando em arquivo...'), 'info');
                await exportTasksToLocation(tasks, null, null);
                return;
            }

            // Create and show dialog
            createDeckDialog(boards, tasks);

        } catch (err) {
            console.error('Error fetching Deck boards:', err);
            showNotification(t('audiolog', 'Erro ao carregar painéis. Salvando em arquivo...'), 'error');
            // Fallback to file
            await exportTasksToLocation(tasks, null, null);
        }
    }

    function createDeckDialog(boards, tasks) {
        // Remove existing dialog if any
        const existingDialog = document.getElementById('deck-selection-dialog');
        if (existingDialog) {
            existingDialog.remove();
        }

        // Create dialog overlay
        const overlay = document.createElement('div');
        overlay.id = 'deck-selection-dialog';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

        // Create dialog content
        const dialog = document.createElement('div');
        dialog.style.cssText = 'background:#fff;border-radius:12px;padding:24px;min-width:400px;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.3);';

        // Title
        const title = document.createElement('h3');
        title.textContent = t('audiolog', 'Exportar Tarefas para o Deck');
        title.style.cssText = 'margin:0 0 20px 0;font-size:18px;color:#333;';

        // Task count info
        const info = document.createElement('p');
        info.textContent = t('audiolog', 'Selecione o painel e a lista para exportar') + ` ${tasks.length} ` + t('audiolog', 'tarefa(s)');
        info.style.cssText = 'margin:0 0 16px 0;color:#666;font-size:14px;';

        // Board select
        const boardLabel = document.createElement('label');
        boardLabel.textContent = t('audiolog', 'Painel');
        boardLabel.style.cssText = 'display:block;margin-bottom:6px;font-weight:500;color:#333;';

        const boardSelect = document.createElement('select');
        boardSelect.id = 'deck-board-select';
        boardSelect.style.cssText = 'width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:16px;font-size:14px;';

        const defaultBoardOption = document.createElement('option');
        defaultBoardOption.value = '';
        defaultBoardOption.textContent = t('audiolog', '-- Selecione um painel --');
        boardSelect.appendChild(defaultBoardOption);

        boards.forEach(board => {
            const option = document.createElement('option');
            option.value = board.id;
            option.textContent = board.title;
            option.dataset.stacks = JSON.stringify(board.stacks || []);
            boardSelect.appendChild(option);
        });

        // Stack select
        const stackLabel = document.createElement('label');
        stackLabel.textContent = t('audiolog', 'Lista');
        stackLabel.style.cssText = 'display:block;margin-bottom:6px;font-weight:500;color:#333;';

        const stackSelect = document.createElement('select');
        stackSelect.id = 'deck-stack-select';
        stackSelect.style.cssText = 'width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:24px;font-size:14px;';
        stackSelect.disabled = true;

        const defaultStackOption = document.createElement('option');
        defaultStackOption.value = '';
        defaultStackOption.textContent = t('audiolog', '-- Selecione uma lista --');
        stackSelect.appendChild(defaultStackOption);

        // Update stacks when board changes
        boardSelect.addEventListener('change', () => {
            stackSelect.innerHTML = '';
            const defaultOpt = document.createElement('option');
            defaultOpt.value = '';
            defaultOpt.textContent = t('audiolog', '-- Selecione uma lista --');
            stackSelect.appendChild(defaultOpt);

            const selectedOption = boardSelect.options[boardSelect.selectedIndex];
            if (selectedOption && selectedOption.dataset.stacks) {
                const stacks = JSON.parse(selectedOption.dataset.stacks);
                stacks.forEach(stack => {
                    const option = document.createElement('option');
                    option.value = stack.id;
                    option.textContent = stack.title;
                    stackSelect.appendChild(option);
                });
                stackSelect.disabled = stacks.length === 0;
            } else {
                stackSelect.disabled = true;
            }
        });

        // Buttons container
        const buttons = document.createElement('div');
        buttons.style.cssText = 'display:flex;gap:12px;justify-content:flex-end;';

        // Cancel button
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = t('audiolog', 'Cancelar');
        cancelBtn.className = 'button';
        cancelBtn.style.cssText = 'padding:10px 20px;';
        cancelBtn.onclick = () => overlay.remove();

        // Export button
        const exportBtn = document.createElement('button');
        exportBtn.textContent = t('audiolog', 'Exportar');
        exportBtn.className = 'button primary';
        exportBtn.style.cssText = 'padding:10px 20px;background:#0082c9;color:#fff;border:none;';
        exportBtn.onclick = async () => {
            const boardId = boardSelect.value ? parseInt(boardSelect.value) : null;
            const stackId = stackSelect.value ? parseInt(stackSelect.value) : null;

            if (!boardId || !stackId) {
                showNotification(t('audiolog', 'Selecione um painel e uma lista'), 'error');
                return;
            }

            overlay.remove();
            await exportTasksToLocation(tasks, boardId, stackId);
        };

        // Assemble dialog
        buttons.appendChild(cancelBtn);
        buttons.appendChild(exportBtn);

        dialog.appendChild(title);
        dialog.appendChild(info);
        dialog.appendChild(boardLabel);
        dialog.appendChild(boardSelect);
        dialog.appendChild(stackLabel);
        dialog.appendChild(stackSelect);
        dialog.appendChild(buttons);

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        // Close on overlay click (but not dialog click)
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
            }
        });
    }

    async function exportTasksToLocation(tasks, boardId, stackId) {
        try {
            const response = await fetch(OC.generateUrl('/apps/audiolog/api/create-deck-cards'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ tasks: tasks, boardId: boardId, stackId: stackId })
            });

            const result = await response.json();

            if (result.error) {
                throw new Error(result.error);
            }

            showNotification(result.message, 'success');

        } catch (err) {
            showNotification(t('audiolog', 'Erro ao exportar tarefas:') + ' ' + err.message, 'error');
        }
    }

    function resetForm() {
        removeFile();
        elements.customPrompt.value = '';
        if (elements.audioTitle) {
            elements.audioTitle.value = '';
        }

        // Reset checkboxes - only transcricao checked by default
        document.querySelectorAll('input[name="outputType"]').forEach(cb => {
            cb.checked = (cb.value === 'transcricao');
        });
        document.querySelectorAll('.output-type').forEach(el => {
            el.classList.remove('selected');
            if (el.querySelector('input[value="transcricao"]')) {
                el.classList.add('selected');
            }
        });

        elements.resultSection.style.display = 'none';
        document.querySelector('.reuniao-input-section').style.display = 'grid';
    }

    // Utilities
    function formatDateTime() {
        const now = new Date();
        const year = now.getFullYear();
        const month = (now.getMonth() + 1).toString().padStart(2, '0');
        const day = now.getDate().toString().padStart(2, '0');
        const hours = now.getHours().toString().padStart(2, '0');
        const minutes = now.getMinutes().toString().padStart(2, '0');
        return `${year}-${month}-${day}_${hours}-${minutes}`;
    }

    function showNotification(message, type) {
        if (typeof OC !== 'undefined' && OC.Notification) {
            if (type === 'error') {
                OC.Notification.showTemporary(message, { type: 'error' });
            } else {
                OC.Notification.showTemporary(message);
            }
        }
    }

    // Translation helper
    function t(app, text) {
        if (typeof OC !== 'undefined' && OC.L10N && OC.L10N.translate) {
            return OC.L10N.translate(app, text);
        }
        return text;
    }

    // ============================================================
    // "Minhas Gravações" tab (Audiolog)
    // ------------------------------------------------------------
    // Built with the DOM API (createElement / textContent) rather than
    // innerHTML to avoid any XSS risk on user-controlled fields like
    // recording title or output preview.
    // ============================================================
    // Mini-Files state — current path inside Audiolog/, last received listing,
    // and a stack so back-button gestures could return up the tree later.
    let recordingsState = { path: 'Audiolog', fileId: 0, items: [], breadcrumb: [] };
    let expandedPath = null; // path of the audio currently expanded with its player
    let selectedPaths = new Set(); // multi-selection set keyed by item.path

    // Material-style icon set (paths from Material Design icons / community).
    // Inline so we don't need extra HTTP roundtrips.
    const ICONS = {
        folder: 'M10,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6H12L10,4Z',
        audio:  'M12,3V13.55C11.41,13.21 10.73,13 10,13A4,4 0 0,0 6,17A4,4 0 0,0 10,21A4,4 0 0,0 14,17V7H18V3H12Z',
        file:   'M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z',
        play:   'M8,5.14V19.14L19,12.14L8,5.14Z',
        pencil: 'M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z',
        move:   'M14,18V15H10V11H14V8L19,13M20,6H12L10,4H4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6Z',
        trash:  'M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z',
        refresh:'M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z',
        plus:   'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z',
    };

    function iconNode(name) {
        const path = ICONS[name];
        if (!path) return document.createTextNode('');
        const NS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', '18');
        svg.setAttribute('height', '18');
        svg.setAttribute('fill', 'currentColor');
        svg.setAttribute('aria-hidden', 'true');
        const p = document.createElementNS(NS, 'path');
        p.setAttribute('d', path);
        svg.appendChild(p);
        return svg;
    }

    function switchTab(tab) {
        elements.tabBtns.forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.tab === tab);
        });
        elements.tabPanels.forEach(panel => {
            panel.style.display = (panel.dataset.tabPanel === tab) ? '' : 'none';
        });
        if (tab !== 'new') {
            const livePanel = document.getElementById('live-panel');
            if (livePanel) livePanel.style.display = 'none';
        }
        // Whenever tabs change, close any overlay (naming/refine loading).
        // Otherwise they stay stuck on top of the new tab if the user didn't
        // click Pular/Salvar.
        ['speaker-naming-panel', 'live-refine-loading'].forEach((id) => {
            const o = document.getElementById(id);
            if (o) o.style.display = 'none';
        });
        if (tab === 'recordings') {
            loadRecordings(recordingsState.path || 'Audiolog');
        }
        try { history.replaceState(null, '', '#' + tab); } catch (_) { /* ignore */ }
    }

    async function loadRecordings(path) {
        if (!elements.recordingsList) return;
        const target = path || 'Audiolog';
        elements.recordingsList.replaceChildren(
            makeP('recordings-empty', t('audiolog', 'Carregando...'))
        );
        const url = OC.generateUrl('/apps/audiolog/api/recordings') + '?path=' + encodeURIComponent(target);
        try {
            const resp = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'requesttoken': OC.requestToken
                }
            });
            const ct = resp.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                const txt = (await resp.text()).slice(0, 200);
                throw new Error('HTTP ' + resp.status + ' — ' + txt.replace(/<[^>]+>/g, '').trim().slice(0, 120));
            }
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            recordingsState.path = data.path || target;
            recordingsState.fileId = data.fileId || 0;
            recordingsState.items = data.items || [];
            recordingsState.breadcrumb = data.breadcrumb || [{ name: 'Audiolog', path: 'Audiolog' }];
            selectedPaths = new Set(); // navigation resets selection
            expandedPath = null;
            renderRecordings();
        } catch (err) {
            console.error('loadRecordings failed for', url, err);
            elements.recordingsList.replaceChildren(
                makeP('recordings-empty', t('audiolog', 'Erro ao carregar: ') + err.message)
            );
        }
    }

    function renderRecordings() {
        const frag = document.createDocumentFragment();

        // Combined header: breadcrumb on the left, actions on the right.
        const header = el('div', 'audiolog-header');
        header.appendChild(buildBreadcrumb());
        header.appendChild(buildToolbar());
        frag.appendChild(header);

        // When something is selected, show a bulk-actions bar replacing the
        // breadcrumb area visually (Files-style).
        if (selectedPaths.size > 0) {
            frag.appendChild(buildBulkActionsBar());
        }

        if (!recordingsState.items.length) {
            frag.appendChild(makeP('recordings-empty', t('audiolog', 'Pasta vazia. Crie uma subpasta ou faça uma nova gravação.')));
        } else {
            const table = el('div', 'audiolog-table');
            const head = el('div', 'audiolog-row audiolog-row-head');

            // Master checkbox in the header — toggles all rows.
            const masterCell = el('div', 'audiolog-col-check');
            const masterCb = document.createElement('input');
            masterCb.type = 'checkbox';
            const allSelected = recordingsState.items.length > 0
                && recordingsState.items.every(i => selectedPaths.has(i.path));
            masterCb.checked = allSelected;
            masterCb.indeterminate = !allSelected && selectedPaths.size > 0;
            masterCb.addEventListener('change', () => {
                if (masterCb.checked) {
                    recordingsState.items.forEach(i => selectedPaths.add(i.path));
                } else {
                    selectedPaths.clear();
                }
                renderRecordings();
            });
            masterCell.appendChild(masterCb);
            head.appendChild(masterCell);

            head.appendChild(makeCell('audiolog-col-name', t('audiolog', 'Nome')));
            head.appendChild(makeCell('audiolog-col-size', t('audiolog', 'Tamanho')));
            head.appendChild(makeCell('audiolog-col-date', t('audiolog', 'Modificado')));
            head.appendChild(makeCell('audiolog-col-actions', ''));
            table.appendChild(head);

            recordingsState.items.forEach(item => {
                table.appendChild(buildRow(item));
            });
            frag.appendChild(table);
        }
        elements.recordingsList.replaceChildren(frag);
    }

    function buildBulkActionsBar() {
        const bar = el('div', 'audiolog-bulk-bar');
        const count = el('span', 'audiolog-bulk-count');
        count.textContent = selectedPaths.size + ' ' + t('audiolog', 'selecionado(s)');

        const btnMove = document.createElement('button');
        btnMove.type = 'button';
        btnMove.className = 'button';
        btnMove.appendChild(iconNode('move'));
        const lblMove = document.createElement('span');
        lblMove.textContent = ' ' + t('audiolog', 'Mover');
        btnMove.appendChild(lblMove);
        btnMove.addEventListener('click', () => bulkMove());

        const btnDelete = document.createElement('button');
        btnDelete.type = 'button';
        btnDelete.className = 'button button-delete';
        btnDelete.appendChild(iconNode('trash'));
        const lblDel = document.createElement('span');
        lblDel.textContent = ' ' + t('audiolog', 'Excluir');
        btnDelete.appendChild(lblDel);
        btnDelete.addEventListener('click', () => bulkDelete());

        const btnClear = document.createElement('button');
        btnClear.type = 'button';
        btnClear.className = 'button audiolog-icon-button';
        btnClear.title = t('audiolog', 'Limpar seleção');
        btnClear.textContent = '✕';
        btnClear.addEventListener('click', () => {
            selectedPaths.clear();
            renderRecordings();
        });

        bar.appendChild(count);
        bar.appendChild(btnMove);
        bar.appendChild(btnDelete);
        bar.appendChild(btnClear);
        return bar;
    }

    async function bulkDelete() {
        if (selectedPaths.size === 0) return;
        const list = Array.from(selectedPaths);
        if (!window.confirm(t('audiolog', 'Excluir {n} item(ns)? Essa ação não pode ser desfeita.').replace('{n}', list.length))) return;

        let ok = 0, fail = 0;
        for (const p of list) {
            try {
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/delete'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ path: p, alsoOutputs: true })
                });
                const r = await resp.json();
                if (r.error) fail++; else ok++;
            } catch (_) { fail++; }
        }
        showNotification(t('audiolog', '{ok} excluído(s), {f} falha(s)').replace('{ok}', ok).replace('{f}', fail), fail ? 'error' : 'success');
        selectedPaths.clear();
        loadRecordings(recordingsState.path);
    }

    async function bulkMove() {
        if (selectedPaths.size === 0) return;
        const dest = await pickDestinationFolder(Array.from(selectedPaths));
        if (!dest) return;
        let ok = 0, fail = 0;
        for (const from of selectedPaths) {
            try {
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/move'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ from: from, to: dest })
                });
                const r = await resp.json();
                if (r.error) fail++; else ok++;
            } catch (_) { fail++; }
        }
        showNotification(t('audiolog', '{ok} movido(s), {f} falha(s)').replace('{ok}', ok).replace('{f}', fail), fail ? 'error' : 'success');
        selectedPaths.clear();
        loadRecordings(recordingsState.path);
    }

    /**
     * Modal-style folder picker. Returns the chosen path (string) or null
     * if cancelled. Includes "Audiolog" root + a list of subfolders, with a
     * quick "+ Nova pasta" inline.
     */
    async function pickDestinationFolder(excludePaths = []) {
        const exclude = new Set(excludePaths);
        let folders = [];
        try {
            const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/folders'), {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'requesttoken': OC.requestToken }
            });
            const data = await resp.json();
            folders = (data.folders || []).filter(f => !exclude.has(f.path));
        } catch (_) {
            showNotification(t('audiolog', 'Falha ao listar pastas.'), 'error');
            return null;
        }

        return new Promise((resolve) => {
            const overlay = el('div', 'audiolog-modal-overlay');
            const card = el('div', 'audiolog-modal-card');
            const h = document.createElement('h3');
            h.textContent = t('audiolog', 'Mover para qual pasta?');
            h.style.margin = '0 0 12px';
            card.appendChild(h);

            const list = el('ul', 'audiolog-folder-list');
            folders.forEach(f => {
                const li = document.createElement('li');
                li.className = 'audiolog-folder-item';
                li.style.paddingLeft = (f.depth * 16) + 'px';
                li.appendChild(iconNode('folder'));
                const span = document.createElement('span');
                span.textContent = ' ' + f.path;
                li.appendChild(span);
                li.addEventListener('click', () => {
                    document.body.removeChild(overlay);
                    resolve(f.path);
                });
                list.appendChild(li);
            });
            card.appendChild(list);

            const actions = el('div', 'audiolog-modal-actions');
            const btnNew = document.createElement('button');
            btnNew.type = 'button';
            btnNew.className = 'button';
            btnNew.textContent = '+ ' + t('audiolog', 'Nova pasta');
            btnNew.addEventListener('click', async () => {
                const name = window.prompt(t('audiolog', 'Nome da nova pasta (em ') + recordingsState.path + '):', '');
                if (!name || !name.trim()) return;
                try {
                    const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/folder'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                        body: JSON.stringify({ parent: recordingsState.path, name: name.trim() })
                    });
                    const r = await resp.json();
                    if (r.error) throw new Error(r.error);
                    document.body.removeChild(overlay);
                    resolve(r.path);
                } catch (err) {
                    showNotification(t('audiolog', 'Erro: ') + err.message, 'error');
                }
            });
            const btnCancel = document.createElement('button');
            btnCancel.type = 'button';
            btnCancel.className = 'button';
            btnCancel.textContent = t('audiolog', 'Cancelar');
            btnCancel.addEventListener('click', () => {
                document.body.removeChild(overlay);
                resolve(null);
            });
            actions.appendChild(btnNew);
            actions.appendChild(btnCancel);
            card.appendChild(actions);

            overlay.appendChild(card);
            document.body.appendChild(overlay);
        });
    }

    function makeCell(cls, text) {
        const c = el('div', cls);
        if (text != null) c.textContent = text;
        return c;
    }

    function buildBreadcrumb() {
        const wrap = el('nav', 'audiolog-breadcrumb');
        (recordingsState.breadcrumb || []).forEach((seg, i, arr) => {
            const a = document.createElement('a');
            a.href = '#';
            a.className = 'audiolog-breadcrumb-item';
            a.textContent = seg.name;
            a.addEventListener('click', (e) => {
                e.preventDefault();
                loadRecordings(seg.path);
            });
            wrap.appendChild(a);
            if (i < arr.length - 1) {
                const sep = el('span', 'audiolog-breadcrumb-sep');
                sep.textContent = '›';
                wrap.appendChild(sep);
            }
        });
        return wrap;
    }

    function buildToolbar() {
        const bar = el('div', 'audiolog-toolbar');

        // Big "Abrir no Files" link — that's where multi-select, sharing,
        // context menu and drag-drop live. The Audiolog tab focuses on the
        // app-specific actions (play, process with AI).
        const btnFiles = document.createElement('a');
        btnFiles.className = 'button primary audiolog-btn-files';
        // Files in NC 33+ resolves the folder by fileId AND dir query string.
        // Fallback to /apps/files/ if fileId isn't known yet.
        btnFiles.href = recordingsState.fileId
            ? OC.generateUrl('/apps/files/files/' + recordingsState.fileId) + '?dir=/' + encodeURI(recordingsState.path)
            : OC.generateUrl('/apps/files/') + '?dir=/' + encodeURI(recordingsState.path);
        btnFiles.target = '_top';
        btnFiles.appendChild(iconNode('folder'));
        const flbl = document.createElement('span');
        flbl.textContent = ' ' + t('audiolog', 'Abrir no Files');
        btnFiles.appendChild(flbl);

        // "+ Nova pasta" — secondary button (still useful inline).
        const btnNew = document.createElement('button');
        btnNew.type = 'button';
        btnNew.className = 'button audiolog-btn-new';
        btnNew.appendChild(iconNode('plus'));
        const lbl = document.createElement('span');
        lbl.textContent = ' ' + t('audiolog', 'Nova pasta');
        btnNew.appendChild(lbl);
        btnNew.addEventListener('click', async () => {
            const name = window.prompt(t('audiolog', 'Nome da nova pasta:'), '');
            if (!name || !name.trim()) return;
            try {
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/folder'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ parent: recordingsState.path, name: name.trim() })
                });
                const r = await resp.json();
                if (r.error) throw new Error(r.error);
                showNotification(t('audiolog', 'Pasta criada'), 'success');
                loadRecordings(recordingsState.path);
            } catch (err) {
                showNotification(t('audiolog', 'Erro: ') + err.message, 'error');
            }
        });

        const btnRefresh = makeIconButton('refresh', () => loadRecordings(recordingsState.path), t('audiolog', 'Atualizar'));

        bar.appendChild(btnFiles);
        bar.appendChild(btnNew);
        bar.appendChild(btnRefresh);
        return bar;
    }

    /** Round ghost icon button with an SVG (no text). */
    function makeIconButton(iconName, onClick, title) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'audiolog-icon-button';
        b.appendChild(iconNode(iconName));
        if (title) {
            b.title = title;
            b.setAttribute('aria-label', title);
        }
        b.addEventListener('click', onClick);
        return b;
    }

    function buildRow(item) {
        const row = el('div', 'audiolog-row audiolog-row-' + item.type);
        row.dataset.path = item.path;

        // ----- Checkbox column -----
        const checkCell = el('div', 'audiolog-col-check');
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = selectedPaths.has(item.path);
        cb.addEventListener('click', (e) => e.stopPropagation());
        cb.addEventListener('change', () => {
            if (cb.checked) selectedPaths.add(item.path);
            else selectedPaths.delete(item.path);
            renderRecordings();
        });
        checkCell.appendChild(cb);

        // ----- Name column (icon + name, clickable) -----
        const nameCell = el('div', 'audiolog-col-name');
        const icon = el('span', 'audiolog-row-icon');
        const iconName = item.type === 'folder' ? 'folder'
                       : item.type === 'audio'  ? 'audio'
                       : 'file';
        icon.appendChild(iconNode(iconName));
        const nameLabel = el('span', 'audiolog-row-name');
        nameLabel.textContent = item.title || item.name;

        const nameLink = document.createElement('a');
        nameLink.href = '#';
        nameLink.className = 'audiolog-name-link';
        nameLink.appendChild(icon);
        nameLink.appendChild(nameLabel);
        nameLink.addEventListener('click', (e) => {
            e.preventDefault();
            if (item.type === 'folder') {
                loadRecordings(item.path);
            } else if (item.type === 'audio') {
                expandedPath = (expandedPath === item.path) ? null : item.path;
                renderRecordings();
            } else if (item.type === 'output') {
                // Open the note/document in a NEW tab so the user doesn't lose
                // the Audiolog context. Use the canonical /f/{fileId} shortcut
                // which routes the request to the right viewer (Text app for
                // .md, Office for .docx, etc.).
                const url = OC.generateUrl('/f/' + (item.fileId || ''));
                window.open(url, '_blank', 'noopener');
            }
        });
        nameCell.appendChild(nameLink);

        // ----- Size + date columns -----
        const sizeCell = el('div', 'audiolog-col-size');
        sizeCell.textContent = item.size != null ? formatBytes(item.size) : '';
        const dateCell = el('div', 'audiolog-col-date');
        const ts = (item.mtime || item.createdAt || 0) * 1000;
        dateCell.textContent = ts ? new Date(ts).toLocaleString('pt-BR') : '';

        // ----- Actions column -----
        const actionsCell = el('div', 'audiolog-col-actions');
        if (item.type === 'audio') {
            actionsCell.appendChild(makeIconButton('play',
                (e) => { e.stopPropagation(); reprocessRecording(item.path); },
                t('audiolog', 'Processar com IA')));
        }
        actionsCell.appendChild(makeIconButton('pencil',
            (e) => { e.stopPropagation(); promptRenameItem(item); },
            t('audiolog', 'Renomear')));
        actionsCell.appendChild(makeIconButton('move',
            (e) => { e.stopPropagation(); promptMoveItem(item); },
            t('audiolog', 'Mover')));
        const trash = makeIconButton('trash',
            (e) => { e.stopPropagation(); confirmDeleteItem(item); },
            t('audiolog', 'Excluir'));
        trash.classList.add('button-delete');
        actionsCell.appendChild(trash);

        row.appendChild(checkCell);
        row.appendChild(nameCell);
        row.appendChild(sizeCell);
        row.appendChild(dateCell);
        row.appendChild(actionsCell);

        // ----- Expanded panel for audio: inline player + outputs -----
        if (item.type === 'audio' && expandedPath === item.path) {
            const expand = el('div', 'audiolog-expanded');
            const player = document.createElement('audio');
            player.className = 'recording-player';
            player.controls = true;
            player.autoplay = true;
            player.src = OC.linkToRemote('webdav') + '/' + encodeURI(item.path);
            expand.appendChild(player);

            if (item.outputs && item.outputs.length) {
                const outBox = el('div', 'recording-outputs');
                item.outputs.forEach(o => {
                    if (o.type === 'note' && o.path) {
                        const a = document.createElement('a');
                        a.className = 'output-chip';
                        a.href = '#';
                        a.title = o.path;
                        a.textContent = '📄 ' + (o.format === 'docx' ? 'DOCX' : 'MD');
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            const url = OC.generateUrl('/apps/files/?fileid=' + (o.fileId || ''));
                            window.open(url, '_blank');
                        });
                        outBox.appendChild(a);
                    } else if (o.type === 'transcribed') {
                        const span = el('span', 'output-chip output-chip-info');
                        span.title = o.preview || '';
                        const types = (o.outputTypes || []).join(', ');
                        span.textContent = '🎯 ' + (types || 'processado');
                        outBox.appendChild(span);
                    }
                });
                if (outBox.childNodes.length) expand.appendChild(outBox);
            }
            // Make expansion span the full width by wrapping in another row
            const wrapper = el('div', 'audiolog-row audiolog-row-expand-wrap');
            wrapper.appendChild(expand);
            const fragRow = document.createDocumentFragment();
            fragRow.appendChild(row);
            fragRow.appendChild(wrapper);
            return fragRow;
        }

        return row;
    }

    async function promptRenameItem(item) {
        const suggested = item.title || item.name;
        const newName = window.prompt(t('audiolog', 'Novo nome:'), suggested);
        if (!newName || !newName.trim() || newName === suggested) return;
        try {
            // Audio items have a dedicated rename endpoint (cascades to meta).
            // Folders / outputs use the move endpoint with a renamed destination.
            if (item.type === 'audio') {
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/rename'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ path: item.path, title: newName.trim() })
                });
                const r = await resp.json();
                if (r.error) throw new Error(r.error);
            } else {
                showNotification(t('audiolog', 'Renomear pasta/arquivo: use a aba Files do NextCloud por enquanto.'), 'info');
                return;
            }
            showNotification(t('audiolog', 'Renomeado'), 'success');
            loadRecordings(recordingsState.path);
        } catch (err) {
            showNotification(t('audiolog', 'Erro ao renomear: ') + err.message, 'error');
        }
    }

    async function promptMoveItem(item) {
        const dest = await pickDestinationFolder([item.path]);
        if (!dest) return;
        try {
            const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/move'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ from: item.path, to: dest })
            });
            const r = await resp.json();
            if (r.error) throw new Error(r.error);
            showNotification(t('audiolog', 'Movido para ') + dest, 'success');
            loadRecordings(recordingsState.path);
        } catch (err) {
            showNotification(t('audiolog', 'Erro ao mover: ') + err.message, 'error');
        }
    }

    async function confirmDeleteItem(item) {
        const name = item.title || item.name;
        const msg = item.type === 'folder'
            ? t('audiolog', 'Excluir a pasta "{n}" e tudo dentro dela?').replace('{n}', name)
            : t('audiolog', 'Excluir "{n}"?').replace('{n}', name);
        if (!window.confirm(msg)) return;
        try {
            // Audio: dedicated endpoint cascades meta+outputs.
            // Folder/output: same endpoint accepts any path inside Audiolog/
            // — moveItem-style validation covers safety.
            const resp = await fetch(OC.generateUrl('/apps/audiolog/api/recordings/delete'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ path: item.path, alsoOutputs: true })
            });
            const result = await resp.json();
            if (result.error) throw new Error(result.error);
            showNotification(t('audiolog', 'Excluído'), 'success');
            loadRecordings(recordingsState.path);
        } catch (err) {
            showNotification(t('audiolog', 'Erro ao excluir: ') + err.message, 'error');
        }
    }

    function el(tag, className) {
        const e = document.createElement(tag);
        if (className) e.className = className;
        return e;
    }
    function makeP(className, text) {
        const p = el('p', className);
        p.textContent = text;
        return p;
    }
    function makeButton(label, className, onClick, title) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = className || 'button';
        b.textContent = label;
        if (title) b.title = title;
        b.addEventListener('click', onClick);
        return b;
    }

    function el(tag, className) {
        const e = document.createElement(tag);
        if (className) e.className = className;
        return e;
    }
    function makeP(className, text) {
        const p = el('p', className);
        p.textContent = text;
        return p;
    }
    function makeButton(label, className, onClick, title) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = className || 'button';
        b.textContent = label;
        if (title) b.title = title;
        b.addEventListener('click', onClick);
        return b;
    }

    function reprocessRecording(path) {
        switchTab('new');
        selectedFile = null;
        selectedNextcloudPath = path;
        const fileName = path.split('/').pop();
        elements.fileName.textContent = fileName + ' (Audiolog)';
        elements.uploadArea.querySelector('.upload-content').style.display = 'none';
        elements.fileSelected.style.display = 'flex';
        elements.btnProcess.disabled = false;
        const rec = (recordingsState.items || []).find(r => r.path === path);
        if (rec && rec.title && elements.audioTitle) {
            elements.audioTitle.value = rec.title;
        }
        document.querySelector('.options-card')?.scrollIntoView({ behavior: 'smooth' });
    }

    // ============================================================
    // Live transcription (Gemini Live API via WebSocket)
    // ------------------------------------------------------------
    // Captures the mic, downsamples to 16kHz PCM16 in an AudioWorklet, ships
    // chunks over a WebSocket to BidiGenerateContent, and renders streamed
    // transcription text into the #live-transcript panel.
    // ============================================================
    let liveWs = null;
    let liveAudioCtx = null;
    let liveSourceNode = null;
    let liveWorkletNode = null;
    let liveStream = null;
    let liveStartTime = 0;
    let liveTimer = null;
    let liveTranscriptBuffer = '';
    let liveMediaRecorder = null;
    let liveAudioChunks = [];
    let liveAudioBlob = null;
    let liveAudioMime = '';
    let liveSetupAcked = false;
    let livePendingChunks = [];

    /**
     * The "Transcrição ao Vivo" tab is its own screen with a device picker
     * and a start button. Wire all of it here. The sidebar entry is hidden
     * unless the admin has enabled the feature.
     */
    function initLiveButton() {
        const appContent = document.getElementById('app-content');
        if (!appContent) return;
        const enabled = appContent.dataset.realtimeEnabled === '1';
        const provider = appContent.dataset.realtimeProvider || 'web-speech';

        const navEntry = document.getElementById('nav-entry-live');
        if (navEntry) {
            const supported = ['google-stt', 'web-speech'];
            navEntry.style.display = (enabled && supported.includes(provider)) ? '' : 'none';
        }

        // Stop button inside the live screen (created by templates/main.php).
        const btnLiveStop = document.getElementById('btn-live-stop');
        if (btnLiveStop) btnLiveStop.addEventListener('click', stopLiveTranscription);

        // Wire the dedicated tab's controls.
        const btnStart = document.getElementById('btn-start-live');
        const sourceRadios = document.querySelectorAll('input[name="liveAudioSource"]');
        const micSelect = document.getElementById('live-mic-select');
        const micContainer = document.getElementById('live-mic-select-container');
        const sysHint = document.getElementById('live-system-audio-hint');

        if (sourceRadios.length) {
            sourceRadios.forEach(r => r.addEventListener('change', (e) => {
                const isSystem = e.target.value === 'system';
                if (micContainer) micContainer.style.display = isSystem ? 'none' : 'block';
                if (sysHint) sysHint.style.display = isSystem ? 'block' : 'none';
            }));
        }

        // PRIVACY: enumerate devices WITHOUT requesting mic permission.
        // Labels will be empty until the user has granted permission once
        // (via the actual recording flow); we fall back to "Microfone N".
        // This avoids the red mic indicator flashing whenever the user just
        // visits this tab without recording.
        if (micSelect && navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
            navigator.mediaDevices.enumerateDevices().then((devices) => {
                micSelect.innerHTML = '';
                let n = 0;
                devices.forEach((d) => {
                    if (d.kind !== 'audioinput') return;
                    const opt = document.createElement('option');
                    opt.value = d.deviceId;
                    opt.text = d.label || ('Microfone ' + (++n));
                    micSelect.appendChild(opt);
                });
                if (!micSelect.options.length) {
                    const opt = document.createElement('option');
                    opt.text = t('audiolog', 'Nenhum microfone encontrado');
                    micSelect.appendChild(opt);
                }
            }).catch(() => { /* ignore — Safari can throw without permission */ });
        }

        if (btnStart) {
            btnStart.addEventListener('click', () => {
                const source = document.querySelector('input[name="liveAudioSource"]:checked')?.value || 'mic';
                const deviceId = micSelect ? micSelect.value : '';
                const titleInput = document.getElementById('live-title');
                if (titleInput && elements.audioTitle) {
                    elements.audioTitle.value = titleInput.value || elements.audioTitle.value;
                }
                startLiveTranscription({ source: source, deviceId: deviceId });
            });
        }
    }

    async function startLiveTranscription(streamOpts = {}) {
        // iOS Safari suspends JavaScript (and the WebSocket) when the screen
        // turns off. Wake Lock keeps the screen on while we're streaming —
        // without it the user gets WebSocket close 1006 and loses the session.
        // Wake Lock is best-effort: if the browser denies it, we just continue.
        await requestWakeLock();

        // Prefetch the realtime config so we know which provider to use.
        // Two transport paths only: Google STT (server-side proxy) vs
        // Web Speech (browser-native). Anything else (legacy 'gemini-live'
        // configs from older versions) falls through to web-speech.
        let cfgProvider = 'web-speech';
        try {
            const peek = await fetch(OC.generateUrl('/apps/audiolog/api/realtime/config'), {
                headers: { 'Accept': 'application/json', 'requesttoken': OC.requestToken }
            });
            const peekData = await peek.json();
            if (peekData.provider) cfgProvider = peekData.provider;
            streamOpts._cfg = peekData;
        } catch (_) { /* fall through to web-speech */ }

        if (cfgProvider === 'google-stt') {
            return startLiveGoogleSTT(streamOpts);
        }
        return startLiveWebSpeech(streamOpts);
    }

    // ============================================================
    // Web Speech API — browser-native STT (Chrome/Edge).
    // ------------------------------------------------------------
    // Free, no API key. Limitations:
    //   * Chrome/Edge only (Firefox has no implementation, Safari is patchy)
    //   * Chrome routes audio through Google servers under the hood
    //   * No native speaker diarization
    //   * Sessions can stop unexpectedly — we auto-restart on silent end
    // We still spin up a parallel MediaRecorder so the post-stop refine pass
    // can do diarization on the saved audio.
    // ============================================================
    let liveSpeechRecognition = null;
    let liveSpeechWantStop = false;

    async function startLiveWebSpeech(streamOpts = {}) {
        const livePanel = document.getElementById('live-panel');
        const liveStatus = document.getElementById('live-status');
        const liveTranscript = document.getElementById('live-transcript');
        const inputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
        const liveSetupCard = document.querySelector('[data-tab-panel="live"] .live-setup-card');
        if (livePanel) livePanel.style.display = '';
        if (liveSetupCard) liveSetupCard.style.display = 'none';
        if (inputSection) inputSection.style.display = 'none';
        if (liveTranscript) liveTranscript.textContent = '';
        liveTranscriptBuffer = '';
        if (liveStatus) liveStatus.textContent = t('audiolog', 'Iniciando...');

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            showNotification(
                t('audiolog', 'Web Speech API não suportada neste navegador. Use Chrome ou Edge, ou troque o provedor no admin.'),
                'error'
            );
            if (livePanel) livePanel.style.display = 'none';
            if (liveSetupCard) liveSetupCard.style.display = '';
            return;
        }

        // We DO want a parallel MediaRecorder so the post-stop refine has
        // an audio file to feed to Gemini for diarization. Chrome can share
        // the mic between getUserMedia and SpeechRecognition in the same tab
        // — the "aborted" loop we used to see was caused by a duplicate
        // event binding firing recognition.start() twice, not by the mic
        // capture conflicting with itself.
        // If acquireAudioStream fails for any reason, we degrade gracefully
        // to text-only and let Web Speech run on its own.
        liveStream = null;
        liveMediaRecorder = null;
        liveAudioChunks = [];
        liveAudioBlob = null;
        try {
            const acquired = await acquireAudioStream({
                source: streamOpts.source,
                deviceId: streamOpts.deviceId
            });
            liveStream = acquired.stream;
            const fullMime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus'
                : 'audio/webm';
            liveAudioMime = fullMime;
            liveMediaRecorder = new MediaRecorder(liveStream, { mimeType: fullMime });
            liveMediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) liveAudioChunks.push(e.data);
            };
            liveMediaRecorder.start(2000);
        } catch (err) {
            console.warn('[live-webspeech] parallel recorder unavailable, text-only mode:', err);
            // Tear down anything that did start so the recognizer below has
            // a clean mic.
            if (liveStream) {
                try { liveStream.getTracks().forEach(t => t.stop()); } catch (_) {}
                liveStream = null;
            }
            liveMediaRecorder = null;
        }

        const recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = (streamOpts._cfg && streamOpts._cfg.language)
            ? ({ pt: 'pt-BR', en: 'en-US', es: 'es-ES' })[streamOpts._cfg.language] || 'pt-BR'
            : 'pt-BR';

        // Track which finalized result indexes are already in the buffer to
        // prevent the duplicate-words bug when the engine re-emits an existing
        // final result (or when a session restart re-feeds the same audio).
        let lastAppendedFinalIndex = -1;
        const originalStart = recognition.start.bind(recognition);
        recognition.start = function() {
            lastAppendedFinalIndex = -1;
            return originalStart();
        };

        // Auto-restart guards: if the engine aborts repeatedly without ever
        // delivering a result (e.g. mic permission revoked, OS-level capture
        // conflict), we'd otherwise spin forever. Track consecutive "aborted"
        // events and bail out once we hit a small threshold.
        let consecutiveAborts = 0;
        const ABORT_LIMIT = 3;
        let lastAbortAt = 0;

        recognition.onresult = (event) => {
            // A result means the engine is healthy — reset the abort counter.
            consecutiveAborts = 0;
            let interim = '';
            for (let i = 0; i < event.results.length; i++) {
                const result = event.results[i];
                const text = result[0].transcript;
                if (result.isFinal) {
                    if (i > lastAppendedFinalIndex) {
                        liveTranscriptBuffer += text + ' ';
                        lastAppendedFinalIndex = i;
                    }
                } else {
                    if (i > lastAppendedFinalIndex) {
                        interim += text;
                    }
                }
            }
            if (liveTranscript) {
                liveTranscript.textContent = liveTranscriptBuffer + (interim ? ('… ' + interim) : '');
                liveTranscript.scrollTop = liveTranscript.scrollHeight;
            }
        };

        recognition.onerror = (e) => {
            console.warn('SpeechRecognition error:', e.error);
            if (e.error === 'aborted') {
                const now = Date.now();
                // Tight burst (< 1.5s apart) = same failure looping.
                if (now - lastAbortAt < 1500) {
                    consecutiveAborts++;
                } else {
                    consecutiveAborts = 1;
                }
                lastAbortAt = now;
                if (consecutiveAborts >= ABORT_LIMIT) {
                    liveSpeechWantStop = true; // stop the onend restart loop
                    if (liveStatus) {
                        liveStatus.textContent = t('audiolog',
                            'Não foi possível iniciar Web Speech. Verifique a permissão de microfone, feche outras abas usando o mic e tente novamente.');
                    }
                    showNotification(
                        t('audiolog', 'Web Speech abortou repetidamente. Outra aba ou app pode estar usando o microfone.'),
                        'error'
                    );
                    try { recognition.abort(); } catch (_) {}
                    return;
                }
            }
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                liveSpeechWantStop = true;
                if (liveStatus) {
                    liveStatus.textContent = t('audiolog', 'Permissão de microfone negada.');
                }
                return;
            }
            if (liveStatus && e.error !== 'no-speech') {
                liveStatus.textContent = t('audiolog', 'Erro: ') + e.error;
            }
        };

        recognition.onend = () => {
            if (liveSpeechWantStop) return;
            // Browsers stop the engine after periods of silence — restart.
            // Single short delay; we no longer chain retries here because the
            // abort-loop guard above handles the "permanently failing" case.
            setTimeout(() => {
                if (liveSpeechWantStop) return;
                try {
                    recognition.start();
                } catch (e) {
                    console.warn('[live-webspeech] restart failed:', e.message);
                    if (liveStatus) {
                        liveStatus.textContent = t('audiolog', 'Reconhecimento parado — clique em Parar e inicie de novo.');
                    }
                }
            }, 200);
        };

        liveSpeechRecognition = recognition;
        liveSpeechWantStop = false;
        try { recognition.start(); } catch (e) {
            console.error('Failed to start SpeechRecognition:', e);
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Falha ao iniciar reconhecimento');
            return;
        }

        if (liveStatus) liveStatus.textContent = t('audiolog', 'Capturando (Web Speech)...');
        liveStartTime = Date.now();
        liveTimer = setInterval(updateLiveTime, 500);
    }

    /** Original Gemini Live path, kept under its own name now that we branch. */
    async function startLiveGeminiLive(streamOpts = {}) {
        const livePanel = document.getElementById('live-panel');
        const liveStatus = document.getElementById('live-status');
        const liveTranscript = document.getElementById('live-transcript');
        // The setup card lives inside the 'live' tab panel; hide it while the
        // session is running, restore on stop.
        const liveSetupCard = document.querySelector('[data-tab-panel="live"] .live-setup-card');
        const inputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
        if (livePanel) livePanel.style.display = '';
        if (liveSetupCard) liveSetupCard.style.display = 'none';
        if (inputSection) inputSection.style.display = 'none';
        if (liveTranscript) liveTranscript.textContent = '';
        liveTranscriptBuffer = '';
        if (liveStatus) liveStatus.textContent = t('audiolog', 'Conectando...');

        let cfg;
        try {
            const resp = await fetch(OC.generateUrl('/apps/audiolog/api/realtime/config'), {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'requesttoken': OC.requestToken }
            });
            cfg = await resp.json();
            if (cfg.error) throw new Error(cfg.error);
            if (cfg.provider !== 'gemini-live') throw new Error(t('audiolog', 'Provedor não suportado'));
        } catch (err) {
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Erro: ') + err.message;
            return;
        }

        // 1) Acquire audio stream honoring the device selector (mic / system audio).
        try {
            const acquired = await acquireAudioStream({
                sampleRate: 16000,
                source: streamOpts.source,
                deviceId: streamOpts.deviceId
            });
            liveStream = acquired.stream;
            // System audio: noise processing must stay off (echoCancellation kills tab audio).
            if (acquired.source === 'system' && liveStatus) {
                liveStatus.textContent = t('audiolog', 'Capturando áudio do sistema...');
            }
        } catch (err) {
            console.error('acquireAudioStream failed:', err);
            const restoreInputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
            if (livePanel) livePanel.style.display = 'none';
            if (restoreInputSection) restoreInputSection.style.display = '';
            const msg = err.name === 'NotAllowedError'
                ? t('audiolog', 'Permissão negada para o microfone/tela')
                : (err.message || t('audiolog', 'Falha ao acessar dispositivo de áudio'));
            showNotification(msg, 'error');
            return;
        }

        // 2) AudioContext at 16kHz so the worklet output already matches what
        //    Gemini Live wants (PCM16 mono 16kHz).
        liveAudioCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
        try {
            const workletUrl = OC.linkTo('audiolog', 'js/pcm-extractor-worklet.js');
            await liveAudioCtx.audioWorklet.addModule(workletUrl);
        } catch (err) {
            console.error('Worklet load failed', err);
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Falha ao carregar processador de áudio');
            cleanupLive();
            return;
        }
        liveSourceNode = liveAudioCtx.createMediaStreamSource(liveStream);
        liveWorkletNode = new AudioWorkletNode(liveAudioCtx, 'pcm-extractor');
        liveSourceNode.connect(liveWorkletNode);
        // Don't connect to destination — we don't want to play the mic back.

        // Parallel MediaRecorder to capture the same audio in a regular container
        // (webm/opus). On stop we can offer "refinar com identificação de falantes"
        // that re-sends the full file through the non-Live Gemini path with a
        // diarization prompt.
        liveAudioChunks = [];
        liveAudioBlob = null;
        try {
            const mimeCandidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            liveAudioMime = mimeCandidates.find(m => MediaRecorder.isTypeSupported(m)) || '';
            const recOpts = liveAudioMime ? { mimeType: liveAudioMime } : {};
            liveMediaRecorder = new MediaRecorder(liveStream, recOpts);
            liveMediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) liveAudioChunks.push(e.data);
            };
            liveMediaRecorder.start(2000);
        } catch (e) {
            console.warn('Parallel MediaRecorder failed, no diarization refine option will be offered.', e);
            liveMediaRecorder = null;
        }

        // 3) WebSocket
        liveWs = new WebSocket(cfg.wsUrl);
        liveWs.binaryType = 'arraybuffer';

        // Audio chunks captured before the server's setupComplete arrive are
        // queued here and flushed once setup finishes.
        liveSetupAcked = false;
        livePendingChunks = [];

        liveWs.onopen = () => {
            // Pin recognition language so the model doesn't drift into other
            // languages mid-stream (we saw it slipping into Mandarin without this).
            // The Live API v1beta accepts `speechConfig.languageCode` in
            // generationConfig; `inputAudioTranscription` must be an empty
            // object — `languageCodes` was removed in late 2025.
            const langTag = ({ pt: 'pt-BR', en: 'en-US', es: 'es-ES' })[cfg.language] || 'pt-BR';
            const setupMsg = {
                setup: {
                    model: cfg.model,
                    generationConfig: {
                        responseModalities: ['AUDIO'],
                        speechConfig: { languageCode: langTag }
                    },
                    inputAudioTranscription: {}
                }
            };
            console.debug('[live] setup →', setupMsg);
            liveWs.send(JSON.stringify(setupMsg));
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Aguardando setup...');
            liveStartTime = Date.now();
            liveTimer = setInterval(updateLiveTime, 500);
        };

        liveWs.onmessage = async (event) => {
            let raw;
            try {
                if (event.data instanceof Blob) {
                    raw = await event.data.text();
                } else if (event.data instanceof ArrayBuffer) {
                    raw = new TextDecoder().decode(event.data);
                } else {
                    raw = event.data;
                }
            } catch (e) {
                console.warn('Live WS: failed to read frame', e);
                return;
            }
            console.debug('[live] WS frame:', raw);
            let payload;
            try { payload = JSON.parse(raw); } catch (_) { return; }
            handleLiveMessage(payload);
        };

        liveWs.onerror = (e) => {
            console.error('Live WS error event:', e);
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Erro de conexão');
        };

        liveWs.onclose = (e) => {
            console.warn('[live] WS closed', { code: e.code, reason: e.reason, wasClean: e.wasClean });
            if (liveStatus) {
                let msg = t('audiolog', 'Conexão encerrada');
                if (e.code && e.code !== 1000) {
                    msg += ' (' + e.code + (e.reason ? ' — ' + e.reason : '') + ')';
                }
                liveStatus.textContent = msg;
            }
        };

        // 4) Pipe PCM chunks from the worklet to the WebSocket. We must wait for
        //    setupComplete from the server before sending audio — otherwise the
        //    server returns 1007 "Cannot extract voices from a non-audio request"
        //    because it hasn't finalized the audio decoder yet.
        liveWorkletNode.port.onmessage = (e) => {
            if (!liveWs || liveWs.readyState !== WebSocket.OPEN) return;
            const b64 = arrayBufferToBase64(e.data);
            if (!liveSetupAcked) {
                if (livePendingChunks.length < 200) { // cap ~50s of audio
                    livePendingChunks.push(b64);
                }
                return;
            }
            try {
                liveWs.send(JSON.stringify({
                    realtimeInput: {
                        audio: { mimeType: 'audio/pcm;rate=16000', data: b64 }
                    }
                }));
            } catch (err) {
                console.warn('Failed to send PCM chunk:', err);
            }
        };

        // 5) UI swap
        elements.btnRecord.disabled = true;
        document.getElementById('btn-record-live').disabled = true;
    }

    function handleLiveMessage(payload) {
        const liveTranscript = document.getElementById('live-transcript');
        const liveStatus = document.getElementById('live-status');
        if (!liveTranscript) return;

        // Server confirmed setup — start sending the queued PCM chunks.
        if (payload.setupComplete) {
            liveSetupAcked = true;
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Capturando...');
            if (livePendingChunks.length > 0) {
                console.debug('[live] flushing', livePendingChunks.length, 'queued PCM chunks');
                for (const b64 of livePendingChunks) {
                    try {
                        liveWs.send(JSON.stringify({
                            realtimeInput: { audio: { mimeType: 'audio/pcm;rate=16000', data: b64 } }
                        }));
                    } catch (_) { /* ignore */ }
                }
                livePendingChunks = [];
            }
            return;
        }

        const sc = payload.serverContent || {};
        // Input transcription = what the USER said. This is the channel we
        // actually care about for live transcription.
        if (sc.inputTranscription && sc.inputTranscription.text) {
            appendLiveText(sc.inputTranscription.text);
        }
        // Output transcription = what the MODEL is saying back. We ignore it
        // (we don't want the assistant chatter to land in the transcript).
        if (sc.turnComplete) {
            liveTranscriptBuffer += '\n';
            renderLiveTranscript();
        }
    }

    /**
     * Sometimes Gemini Live mis-detects language and emits a chunk of CJK
     * (Mandarin/Japanese/Korean) glyphs even when the user is speaking pt-BR.
     * If a chunk is mostly non-Latin, replace it with a clear placeholder so
     * the rendered transcript stays readable.
     */
    function appendLiveText(text) {
        const sanitized = sanitizeNonLatinHallucination(text);
        liveTranscriptBuffer += sanitized;
        renderLiveTranscript();
    }

    function sanitizeNonLatinHallucination(text) {
        if (!text) return '';
        const len = text.length;
        if (len < 4) return text;
        // Count letters that are clearly outside Latin / common punctuation.
        let nonLatin = 0;
        let letters = 0;
        for (const ch of text) {
            const code = ch.codePointAt(0);
            if (/\s|[\d.,;:!?¿¡\-—'"()\[\]]/.test(ch)) continue;
            letters++;
            // CJK Unified Ideographs, Hiragana/Katakana, Hangul, Cyrillic, Arabic, Thai
            if (
                (code >= 0x3040 && code <= 0x30FF) ||  // hiragana/katakana
                (code >= 0x3400 && code <= 0x4DBF) ||  // CJK ext A
                (code >= 0x4E00 && code <= 0x9FFF) ||  // CJK unified
                (code >= 0xAC00 && code <= 0xD7AF) ||  // hangul
                (code >= 0x0400 && code <= 0x04FF) ||  // cyrillic
                (code >= 0x0600 && code <= 0x06FF) ||  // arabic
                (code >= 0x0E00 && code <= 0x0E7F)     // thai
            ) {
                nonLatin++;
            }
        }
        if (letters > 0 && nonLatin / letters > 0.4) {
            return ' (parte não entendível) ';
        }
        return text;
    }

    function renderLiveTranscript() {
        const liveTranscript = document.getElementById('live-transcript');
        if (!liveTranscript) return;
        liveTranscript.textContent = liveTranscriptBuffer;
        liveTranscript.scrollTop = liveTranscript.scrollHeight;
    }

    function updateLiveTime() {
        const liveTime = document.getElementById('live-time');
        if (!liveTime) return;
        const elapsed = Math.floor((Date.now() - liveStartTime) / 1000);
        const m = Math.floor(elapsed / 60).toString().padStart(2, '0');
        const s = (elapsed % 60).toString().padStart(2, '0');
        liveTime.textContent = m + ':' + s;
    }

    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        const chunkSize = 0x8000;
        for (let i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
        }
        return btoa(binary);
    }

    // ============================================================
    // Google Cloud Speech-to-Text — PCM LINEAR16 path.
    // ------------------------------------------------------------
    // Pipes mic audio through an AudioWorklet at 16kHz mono PCM16, accumulates
    // ~3s windows and POSTs each as LINEAR16 to speech:recognize. PCM (raw)
    // beats fragmented webm/opus chunks because each window is a complete,
    // self-contained sample stream — webm fragments after the first lack the
    // EBML header and Google's recognizer fails to decode them reliably.
    // A parallel MediaRecorder still captures the full audio for the
    // post-stop diarization refine.
    // ============================================================
    async function startLiveGoogleSTT(streamOpts = {}) {
        const livePanel = document.getElementById('live-panel');
        const liveStatus = document.getElementById('live-status');
        const liveTranscript = document.getElementById('live-transcript');
        const inputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
        const liveSetupCard = document.querySelector('[data-tab-panel="live"] .live-setup-card');
        if (livePanel) livePanel.style.display = '';
        if (liveSetupCard) liveSetupCard.style.display = 'none';
        if (inputSection) inputSection.style.display = 'none';
        if (liveTranscript) liveTranscript.textContent = '';
        liveTranscriptBuffer = '';
        if (liveStatus) liveStatus.textContent = t('audiolog', 'Conectando...');

        const cfg = streamOpts._cfg;
        if (!cfg || cfg.provider !== 'google-stt' || !cfg.apiKey) {
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Configuração inválida');
            return;
        }

        // 1) Acquire audio stream — request 16kHz directly so the worklet's
        //    AudioContext doesn't have to resample.
        try {
            const acquired = await acquireAudioStream({
                sampleRate: 16000,
                source: streamOpts.source,
                deviceId: streamOpts.deviceId
            });
            liveStream = acquired.stream;
        } catch (err) {
            console.error('acquireAudioStream failed:', err);
            if (livePanel) livePanel.style.display = 'none';
            if (liveSetupCard) liveSetupCard.style.display = '';
            const msg = err.name === 'NotAllowedError'
                ? t('audiolog', 'Permissão negada para o microfone/tela')
                : (err.message || t('audiolog', 'Falha ao acessar dispositivo de áudio'));
            showNotification(msg, 'error');
            return;
        }

        // Server-side proxy — the API key never leaves the Nextcloud server.
        // The browser POSTs PCM chunks to /api/stt/recognize, the controller
        // forwards to speech.googleapis.com using the key from app config,
        // and pipes the JSON response back. Any allowed_groups user can use
        // this without leaking credentials.
        const apiUrl = OC.generateUrl('/apps/audiolog/api/stt/recognize');
        const langCode = cfg.languageCode || 'pt-BR';
        const model = cfg.model || 'latest_long';

        // 2) AudioContext at 16kHz so the worklet output matches LINEAR16 16kHz.
        liveAudioCtx = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
        try {
            const workletUrl = OC.linkTo('audiolog', 'js/pcm-extractor-worklet.js');
            await liveAudioCtx.audioWorklet.addModule(workletUrl);
        } catch (err) {
            console.error('Worklet load failed', err);
            if (liveStatus) liveStatus.textContent = t('audiolog', 'Falha ao carregar processador de áudio');
            cleanupLive();
            return;
        }
        liveSourceNode = liveAudioCtx.createMediaStreamSource(liveStream);
        liveWorkletNode = new AudioWorkletNode(liveAudioCtx, 'pcm-extractor');
        liveSourceNode.connect(liveWorkletNode);

        // 3) Window accumulator. 5s chunks @ 16kHz = 80000 samples.
        //    Larger windows give the recognizer more acoustic context per call,
        //    which materially improves recognition quality vs 2-3s windows
        //    (REST :recognize has no cross-call context — each window is
        //    decoded in isolation, unlike a true streaming gRPC session).
        //    Overlap of 0.6s (9600 samples) reduces word-cut on boundaries;
        //    we dedup the resulting transcripts by suffix/prefix matching.
        const TARGET_SAMPLES = 16000 * 5;
        const OVERLAP_SAMPLES = 16000 * 0.6 | 0;
        let pendingBuffers = [];
        let pendingCount = 0;
        let tailBuffer = new Int16Array(0);
        let lastChunkText = '';
        let chunkInFlight = 0;

        // Find longest overlap where suffix of `prev` equals prefix of `next`,
        // matching whole words only. Returns chars consumed from `next`.
        const findTextOverlap = (prev, next) => {
            if (!prev || !next) return 0;
            const prevTail = prev.slice(-80).toLowerCase();
            const nextHead = next.slice(0, 80).toLowerCase();
            const max = Math.min(prevTail.length, nextHead.length);
            for (let len = max; len >= 4; len--) {
                if (prevTail.slice(-len) === nextHead.slice(0, len)) {
                    // Snap to a word boundary so we don't chop mid-word.
                    const boundary = next.slice(0, len).search(/\s\S*$/);
                    return boundary > 0 ? boundary : len;
                }
            }
            return 0;
        };

        const sendChunk = async (pcmBuffer) => {
            chunkInFlight++;
            try {
                const b64 = arrayBufferToBase64(pcmBuffer);
                const body = {
                    config: {
                        encoding: 'LINEAR16',
                        sampleRateHertz: 16000,
                        audioChannelCount: 1,
                        languageCode: langCode,
                        enableAutomaticPunctuation: true,
                        useEnhanced: true,
                        model: model,
                    },
                    audio: { content: b64 }
                };
                const resp = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken,
                    },
                    body: JSON.stringify(body)
                });
                if (!resp.ok) {
                    const errTxt = await resp.text();
                    console.warn('GoogleSTT chunk error', resp.status, errTxt.slice(0, 300));
                    return;
                }
                const data = await resp.json();
                const text = (data.results || [])
                    .map(r => (r.alternatives && r.alternatives[0] && r.alternatives[0].transcript) || '')
                    .filter(Boolean)
                    .join(' ')
                    .trim();
                if (!text) return;
                const skip = findTextOverlap(lastChunkText, text);
                const toAppend = text.slice(skip).trim();
                if (toAppend) appendLiveText(toAppend + ' ');
                lastChunkText = text;
            } catch (err) {
                console.warn('GoogleSTT chunk send failed:', err);
            } finally {
                chunkInFlight--;
            }
        };

        liveWorkletNode.port.onmessage = (e) => {
            const samples = new Int16Array(e.data);
            pendingBuffers.push(samples);
            pendingCount += samples.length;
            if (pendingCount < TARGET_SAMPLES) return;

            // Flatten with the previous tail prepended for overlap.
            const total = tailBuffer.length + pendingCount;
            const merged = new Int16Array(total);
            merged.set(tailBuffer, 0);
            let offset = tailBuffer.length;
            for (const buf of pendingBuffers) {
                merged.set(buf, offset);
                offset += buf.length;
            }
            // Keep last OVERLAP_SAMPLES for the next window.
            tailBuffer = merged.slice(merged.length - OVERLAP_SAMPLES);
            pendingBuffers = [];
            pendingCount = 0;
            // Fire-and-forget; chunks are independent.
            sendChunk(merged.buffer);
        };

        // 4) Parallel full-audio recorder (for the post-stop diarization refine).
        liveAudioChunks = [];
        liveAudioBlob = null;
        try {
            const fullMime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus'
                : 'audio/webm';
            liveAudioMime = fullMime;
            liveMediaRecorder = new MediaRecorder(liveStream, { mimeType: fullMime });
            liveMediaRecorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) liveAudioChunks.push(e.data);
            };
            liveMediaRecorder.start(2000);
        } catch (_) {
            liveMediaRecorder = null;
        }

        if (liveStatus) liveStatus.textContent = t('audiolog', 'Capturando...');
        liveStartTime = Date.now();
        liveTimer = setInterval(updateLiveTime, 500);
    }

    /**
     * Flow on stop:
     *   1. Stop mic + parallel MediaRecorder
     *   2. Save the captured audio in Audios_Beta (always)
     *   3. Show a full-screen loading card while we run the diarization refine
     *   4. Show speaker-naming UI for the user to attach real names
     *   5. Save the FINAL transcript (with names) as the only note. No
     *      "rápida" / "com falantes" noise.
     */
    async function stopLiveTranscription() {
        const liveStatus = document.getElementById('live-status');
        const livePanel = document.getElementById('live-panel');
        const liveTitleInput = document.getElementById('live-title');
        const setupCard = document.querySelector('[data-tab-panel="live"] .live-setup-card');
        const loadingPanel = document.getElementById('live-refine-loading');

        if (liveStatus) liveStatus.textContent = t('audiolog', 'Encerrando...');

        // 1) Drain MediaRecorder so the last buffered chunk lands in our blob.
        await new Promise((resolve) => {
            if (!liveMediaRecorder || liveMediaRecorder.state === 'inactive') {
                resolve();
                return;
            }
            liveMediaRecorder.onstop = () => resolve();
            try { liveMediaRecorder.stop(); } catch (_) { resolve(); }
        });
        if (liveAudioChunks.length > 0) {
            liveAudioBlob = new Blob(liveAudioChunks, { type: liveAudioMime || 'audio/webm' });
        }
        cleanupLive();
        releaseWakeLock();

        // Use the title from the live tab's own input (NOT the new tab's input,
        // so the two tabs don't share state).
        const title = (liveTitleInput?.value || '').trim()
            || ('Transcrição ao vivo - ' + new Date().toLocaleString('pt-BR'));

        // Hide live transcript view, show full-screen loading.
        if (livePanel) livePanel.style.display = 'none';
        if (setupCard) setupCard.style.display = 'none';
        if (loadingPanel) loadingPanel.style.display = '';

        const cleanupAndReset = () => {
            // Restore setup, clear inputs, ready for next session.
            if (loadingPanel) loadingPanel.style.display = 'none';
            if (setupCard) setupCard.style.display = '';
            if (liveTitleInput) liveTitleInput.value = '';
        };

        // 2) Save the audio so the project folder exists.
        let savedAudioPath = '';
        if (liveAudioBlob && liveAudioBlob.size > 1024) {
            try {
                const ext = (liveAudioMime || '').includes('webm') ? 'webm'
                          : (liveAudioMime || '').includes('ogg') ? 'ogg'
                          : (liveAudioMime || '').includes('mp4') ? 'm4a'
                          : 'webm';
                const safe = title.replace(/[^a-zA-Z0-9\-_À-ɏ ]/g, '_').trim();
                const file = new File([liveAudioBlob], safe + '.' + ext, { type: liveAudioBlob.type });
                const fd = new FormData();
                fd.append('audio', file);
                fd.append('title', title);
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/save-recording'), {
                    method: 'POST', body: fd, headers: { 'requesttoken': OC.requestToken }
                });
                const r = await resp.json();
                if (r && !r.error) savedAudioPath = r.path || '';
            } catch (e) {
                console.warn('[live] Save live audio failed:', e);
            }
        }

        // No audio? Save whatever streamed text we captured and bail out.
        if (!savedAudioPath) {
            const fastText = liveTranscriptBuffer.trim();
            if (fastText.length > 0) {
                try {
                    await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                        body: JSON.stringify({ title: title, content: fastText })
                    });
                    showNotification(t('audiolog', 'Transcrição salva (sem áudio para refinar).'), 'success');
                } catch (_) {}
            }
            cleanupAndReset();
            return;
        }

        // 3) Run diarization refine.
        let refinedText = '';
        try {
            const resp = await fetch(OC.generateUrl('/apps/audiolog/api/process'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({
                    ncPath: savedAudioPath,
                    outputTypes: ['transcricao'],
                    title: title,
                    prompt: 'Identifique CLARAMENTE cada falante distinto pela voz e jeito de falar. Use [Falante 1], [Falante 2], etc. em uma nova linha antes de cada fala. Mantenha a transcrição literal, fluida, em português brasileiro.'
                })
            });
            const r = await resp.json();
            if (r.error) throw new Error(r.error);
            refinedText = r.text || '';
        } catch (err) {
            console.warn('[live] refine failed, falling back to fast transcript:', err);
            refinedText = liveTranscriptBuffer.trim();
        }

        // Hide loading before opening the next overlay.
        if (loadingPanel) loadingPanel.style.display = 'none';

        // 4) Show speaker naming UI. When the user clicks Save or Skip,
        //    AudiologSpeakerNaming saves the final note and closes.
        const namingShown = window.AudiologSpeakerNaming && window.AudiologSpeakerNaming.offer(
            refinedText, savedAudioPath, title, {
                showNotification: showNotification,
                t: t,
                OC: OC
            }
        );

        if (!namingShown) {
            // No speaker labels detected — just save the transcript as is.
            try {
                const resp = await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({ title: title, content: refinedText, audioPath: savedAudioPath })
                });
                const result = await resp.json();
                if (!result.error) {
                    showNotification(t('audiolog', 'Transcrição salva: ') + result.path, 'success');
                }
            } catch (_) {}
            cleanupAndReset();
        } else {
            // Naming UI is now visible. Reset live tab UI behind it.
            cleanupAndReset();
        }
    }


    function cleanupLive() {
        if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
        // Web Speech: tell onend we really want to stop, then abort.
        if (liveSpeechRecognition) {
            liveSpeechWantStop = true;
            try { liveSpeechRecognition.stop(); } catch (_) {}
            try { liveSpeechRecognition.abort(); } catch (_) {}
            liveSpeechRecognition = null;
        }
        try { liveWorkletNode && liveWorkletNode.disconnect(); } catch (_) {}
        try { liveSourceNode && liveSourceNode.disconnect(); } catch (_) {}
        try { liveAudioCtx && liveAudioCtx.close(); } catch (_) {}
        liveWorkletNode = null;
        liveSourceNode = null;
        liveAudioCtx = null;
        if (liveStream) {
            liveStream.getTracks().forEach(t => t.stop());
            liveStream = null;
        }
        if (liveWs) {
            try { liveWs.close(); } catch (_) {}
            liveWs = null;
        }
    }

    // NOTE: do NOT bind initLiveButton to DOMContentLoaded here — it is
    // already invoked from the main init() above (line 223). Adding this
    // listener used to register the "Iniciar" click handler twice, which
    // fired startLiveTranscription twice on a single user click and made
    // SpeechRecognition abort itself ("aborted" loop in Web Speech).

    // ============================================================
    // PWA: register service worker (best-effort)
    // ------------------------------------------------------------
    // The SW lives at /apps/audiolog/js/audiolog-sw.js, but the
    // app actually runs under /index.php/apps/audiolog/. To cover
    // that path we'd need a `Service-Worker-Allowed` header on the SW
    // response widening its scope; NextCloud doesn't emit that header for
    // app static files. So we try the wider scope first (in case the
    // server adds the header in the future) and fall back to the default
    // (the SW path itself), which still gives us an installable PWA and
    // caches the assets — just without intercepting requests under
    // /index.php/. That's acceptable for v1.
    // ============================================================
    if ('serviceWorker' in navigator && window.isSecureContext !== false) {
        window.addEventListener('load', function () {
            const swUrl = '/apps/audiolog/js/audiolog-sw.js';
            const wideScope = '/index.php/apps/audiolog/';
            navigator.serviceWorker.register(swUrl, { scope: wideScope })
                .catch(function () {
                    // Wider scope rejected (no Service-Worker-Allowed header).
                    // Fall back to default scope (= SW directory).
                    return navigator.serviceWorker.register(swUrl);
                })
                .then(function (reg) {
                    if (reg) {
                        console.log('[Audiolog] SW registered, scope:', reg.scope);
                    }
                })
                .catch(function (err) {
                    console.warn('[Audiolog] SW registration failed:', err);
                });
        });
    }

})();
