<?php
/**
 * Assistente de Áudio - Admin Settings Template
 * @copyright 2025 Jhonatan Jaworski
 */

\OCP\Util::addScript('audiolog', 'audiolog-admin');
\OCP\Util::addStyle('audiolog', 'admin');

// Inline SVG icons
$icons = [
    'microphone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-linecap="round" width="24" height="24"><circle cx="12" cy="12" r="9.9" fill="none" stroke-width="2.7"/><rect x="6.3"  y="9.75" width="1.8" height="4.5" rx="0.9" stroke="none"/><rect x="8.7" y="6.75" width="1.8" height="10.5" rx="0.9" stroke="none"/><rect x="11.1" y="4.5" width="1.8" height="15" rx="0.9" stroke="none"/><rect x="13.5" y="7.5" width="1.8" height="9"  rx="0.9" stroke="none"/><rect x="15.9" y="9.75" width="1.8" height="4.5"  rx="0.9" stroke="none"/></svg>',
    'check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z"/></svg>',
    'eye' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z"/></svg>',
    'eye-off' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M11.83,9L15,12.16C15,12.11 15,12.05 15,12A3,3 0 0,0 12,9C11.94,9 11.89,9 11.83,9M7.53,9.8L9.08,11.35C9.03,11.56 9,11.77 9,12A3,3 0 0,0 12,15C12.22,15 12.44,14.97 12.65,14.92L14.2,16.47C13.53,16.8 12.79,17 12,17A5,5 0 0,1 7,12C7,11.21 7.2,10.47 7.53,9.8M2,4.27L4.28,6.55L4.73,7C3.08,8.3 1.78,10 1,12C2.73,16.39 7,19.5 12,19.5C13.55,19.5 15.03,19.2 16.38,18.66L16.81,19.08L19.73,22L21,20.73L3.27,3M12,7A5,5 0 0,1 17,12C17,12.64 16.87,13.26 16.64,13.82L19.57,16.75C21.07,15.5 22.27,13.86 23,12C21.27,7.61 17,4.5 12,4.5C10.6,4.5 9.26,4.75 8,5.2L10.17,7.35C10.74,7.13 11.35,7 12,7Z"/></svg>',
    'save' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M17 3H5C3.89 3 3 3.9 3 5V19C3 20.1 3.89 21 5 21H19C20.1 21 21 20.1 21 19V7L17 3M19 19H5V5H16.17L19 7.83V19M12 12C10.34 12 9 13.34 9 15S10.34 18 12 18 15 16.66 15 15 13.66 12 12 12M6 6H15V10H6V6Z"/></svg>',
    'test' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M7,2V4H8V18A4,4 0 0,0 12,22A4,4 0 0,0 16,18V4H17V2H7M11,16C10.4,16 10,15.6 10,15C10,14.4 10.4,14 11,14C11.6,14 12,14.4 12,15C12,15.6 11.6,16 11,16M13,12C12.4,12 12,11.6 12,11C12,10.4 12.4,10 13,10C13.6,10 14,10.4 14,11C14,11.6 13.6,12 13,12M14,7H10V4H14V7Z"/></svg>',
];
?>

<div id="audiolog-admin" class="section">
    <p class="settings-hint"><?php p($l->t('Configure o provedor de IA, controle quais grupos podem usar o app em "Controle de Acesso" e ative a transcrição ao vivo conforme necessário.')); ?></p>

    <form id="assistaudio-settings-form">
        <!-- AI Provider -->
        <div class="form-group">
            <label for="ai_provider"><?php p($l->t('Provedor de IA')); ?></label>
            <select id="ai_provider" name="ai_provider">
                <option value="gemini" <?php echo $_['ai_provider'] === 'gemini' ? 'selected' : ''; ?>>Gemini (Google)</option>
                <option value="openai" <?php echo $_['ai_provider'] === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                <option value="ollama" <?php echo $_['ai_provider'] === 'ollama' ? 'selected' : ''; ?>>Ollama (compatível OpenAI)</option>
            </select>
            <p class="hint"><?php p($l->t('Gemini processa áudio nativamente (transcrição + análise no mesmo modelo). OpenAI/Ollama transcrevem com Whisper e usam o próprio modelo de chat do provedor para resumo/ata/pauta/tarefas — você não precisa de chave Gemini para usar OpenAI ou Ollama.')); ?></p>
        </div>

        <!-- AI URL -->
        <div class="form-group">
            <label for="ai_url"><?php p($l->t('URL do Provedor')); ?></label>
            <input type="url" id="ai_url" name="ai_url"
                   value="<?php echo htmlspecialchars($_['ai_url']); ?>"
                   placeholder="http://localhost:11434">
            <p class="hint" id="url-hint"><?php p($l->t('URL base da API do provedor selecionado.')); ?></p>
        </div>

        <!-- API Key -->
        <div class="form-group" id="api-key-group">
            <label for="api_key"><?php p($l->t('Chave de API')); ?></label>
            <div class="input-with-button">
                <input type="password" id="api_key" name="api_key"
                       value="<?php echo htmlspecialchars($_['api_key']); ?>"
                       placeholder="sk-...">
                <button type="button" id="toggle-api-key" class="button">
                    <span class="btn-icon" id="eye-icon"><?php echo $icons['eye']; ?></span>
                    <span class="button-text"><?php p($l->t('Mostrar')); ?></span>
                </button>
            </div>
            <p class="hint"><?php p($l->t('Chave de API do provedor selecionado. OpenAI e Gemini exigem; Ollama local geralmente não — mas se seu Ollama estiver atrás de gateway/cloud com auth, preencha aqui.')); ?></p>
        </div>

        <!-- AI Model (transcrição) -->
        <div class="form-group">
            <label for="ai_model"><?php p($l->t('Modelo de transcrição')); ?></label>
            <input type="text" id="ai_model" name="ai_model"
                   value="<?php echo htmlspecialchars($_['ai_model']); ?>"
                   placeholder="whisper-large-v3">
            <p class="hint" id="model-hint"><?php p($l->t('Modelo usado para converter áudio em texto.')); ?></p>
            <div id="suggested-models" class="suggested-models"></div>
        </div>

        <!-- Analysis model (resumo/ata/pauta/tarefas) — só aparece quando NÃO é Gemini -->
        <div class="form-group" id="analysis-model-group">
            <label for="analysis_model"><?php p($l->t('Modelo de análise (opcional)')); ?></label>
            <input type="text" id="analysis_model" name="analysis_model"
                   value="<?php echo htmlspecialchars($_['analysis_model'] ?? ''); ?>"
                   placeholder="gpt-4o-mini">
            <p class="hint"><?php p($l->t('Modelo de chat para gerar resumo/ata/pauta/tarefas a partir do texto transcrito. Usado em OpenAI/Ollama (Whisper só transcreve). Vazio = padrão por provedor (gpt-4o-mini para OpenAI, llama3.1 para Ollama). Em Gemini, ignorado.')); ?></p>
        </div>

        <!-- Language -->
        <div class="form-group">
            <label for="language"><?php p($l->t('Idioma Padrão')); ?></label>
            <select id="language" name="language">
                <option value="pt" <?php echo $_['language'] === 'pt' ? 'selected' : ''; ?>>Português</option>
                <option value="en" <?php echo $_['language'] === 'en' ? 'selected' : ''; ?>>Inglês</option>
                <option value="es" <?php echo $_['language'] === 'es' ? 'selected' : ''; ?>>Espanhol</option>
                <option value="auto" <?php echo $_['language'] === 'auto' ? 'selected' : ''; ?>>Detectar Automaticamente</option>
            </select>
            <p class="hint"><?php p($l->t('Idioma principal dos áudios para melhor transcrição.')); ?></p>
        </div>

        <hr>
        <h3><?php p($l->t('Controle de Acesso')); ?></h3>

        <!-- Allowed Groups -->
        <div class="form-group">
            <label for="allowed_groups"><?php p($l->t('Grupos Permitidos')); ?></label>
            <select id="allowed_groups" name="allowed_groups" multiple class="multiselect">
                <?php foreach ($_['available_groups'] as $group): ?>
                    <option value="<?php echo htmlspecialchars($group['gid']); ?>"
                        <?php echo in_array($group['gid'], $_['allowed_groups']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($group['displayName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint"><?php p($l->t('Selecione os grupos que podem usar o app. Se nenhum for selecionado, todos os usuários terão acesso.')); ?></p>
        </div>

        <hr>
        <h3><?php p($l->t('Configurações Gerais')); ?></h3>

        <!-- Max File Size -->
        <div class="form-group">
            <label for="max_file_size"><?php p($l->t('Tamanho Máximo de Arquivo (MB)')); ?></label>
            <input type="number" id="max_file_size" name="max_file_size"
                   value="<?php echo htmlspecialchars($_['max_file_size']); ?>"
                   min="1" max="500" step="1">
            <p class="hint"><?php p($l->t('Tamanho máximo permitido para arquivos de áudio (em megabytes).')); ?></p>
        </div>

        <!-- Save Audio -->
        <div class="form-group checkbox-group">
            <input type="checkbox" id="save_audio" name="save_audio" class="checkbox"
                   <?php echo $_['save_audio'] === 'true' ? 'checked' : ''; ?>>
            <label for="save_audio"><?php p($l->t('Salvar arquivos de áudio automaticamente')); ?></label>
            <p class="hint"><?php p($l->t('Salva os arquivos de áudio na pasta "Audios" do usuário.')); ?></p>
        </div>

        <!-- Default Output -->
        <div class="form-group">
            <label for="default_output"><?php p($l->t('Tipo de Saída Padrão')); ?></label>
            <select id="default_output" name="default_output">
                <option value="transcricao" <?php echo $_['default_output'] === 'transcricao' ? 'selected' : ''; ?>><?php p($l->t('Transcrição')); ?></option>
                <option value="ata" <?php echo $_['default_output'] === 'ata' ? 'selected' : ''; ?>><?php p($l->t('Ata de Reunião')); ?></option>
                <option value="resumo" <?php echo $_['default_output'] === 'resumo' ? 'selected' : ''; ?>><?php p($l->t('Resumo')); ?></option>
                <option value="pauta" <?php echo $_['default_output'] === 'pauta' ? 'selected' : ''; ?>><?php p($l->t('Pauta')); ?></option>
                <option value="tarefas" <?php echo $_['default_output'] === 'tarefas' ? 'selected' : ''; ?>><?php p($l->t('Lista de Tarefas')); ?></option>
            </select>
            <p class="hint"><?php p($l->t('Tipo de processamento padrão selecionado ao abrir o app.')); ?></p>
        </div>

        <hr>
        <h3><?php p($l->t('Configurações Avançadas (BETA)')); ?></h3>
        <p class="settings-hint"><?php p($l->t('Opções específicas da versão beta. Os valores padrão funcionam bem para a maioria dos casos.')); ?></p>

        <!--
            Gemini-specific knobs. JS hides this whole block when the
            provider is OpenAI/Ollama, since none of these apply.
            (Files API is Gemini-only; Google STT only blocks Gemini-restricted
            keys; the long-audio split currently only works with Gemini's
            multimodal audio path.)
        -->
        <div class="provider-gemini-only">
            <h4 style="margin-top:0;"><?php p($l->t('Opções específicas do Gemini')); ?></h4>

            <!-- Google STT for normal transcription -->
            <div class="form-group checkbox-group">
                <input type="checkbox" id="use_google_stt_for_transcription" name="use_google_stt_for_transcription" class="checkbox"
                       <?php echo $_['use_google_stt_for_transcription'] === 'true' ? 'checked' : ''; ?>>
                <label for="use_google_stt_for_transcription"><?php p($l->t('Usar Google STT para transcrição (em vez de Gemini)')); ?></label>
                <p class="hint"><?php p($l->t('Quando ativo, transcrições do "Processar Áudio" usam Google Cloud Speech-to-Text com diarização nativa. Resumo/ata/pauta/tarefas continuam no provedor LLM acima. Custo: ~$0,024/min.')); ?></p>
            </div>

            <!-- Separate API Key for Google STT -->
            <div class="form-group">
                <label for="google_stt_api_key"><?php p($l->t('API Key Google Speech-to-Text (opcional)')); ?></label>
                <div class="input-with-button">
                    <input type="password" id="google_stt_api_key" name="google_stt_api_key"
                           autocomplete="new-password"
                           value="<?php echo htmlspecialchars($_['google_stt_api_key']); ?>"
                           placeholder="<?php p($l->t('Deixe em branco para usar a chave Gemini')); ?>">
                    <button type="button" id="toggle-stt-key" class="button">
                        <span class="btn-icon" id="eye-icon-stt"><?php echo $icons['eye']; ?></span>
                        <span class="button-text"><?php p($l->t('Mostrar')); ?></span>
                    </button>
                </div>
                <p class="hint"><?php p($l->t('O Google bloqueia uma chave restrita pra Gemini de chamar Speech-to-Text. Se você restringiu a chave Gemini, crie uma SEGUNDA chave de API só pra Speech-to-Text e cole aqui. Se ambas estiverem sem restrição, deixe em branco.')); ?></p>
            </div>

            <!-- Files API threshold -->
            <div class="form-group">
                <label for="gemini_files_api_threshold"><?php p($l->t('Limiar para usar Files API (MB)')); ?></label>
                <input type="number" id="gemini_files_api_threshold" name="gemini_files_api_threshold"
                       value="<?php echo htmlspecialchars($_['gemini_files_api_threshold']); ?>"
                       min="1" max="2000" step="1">
                <p class="hint"><?php p($l->t('Áudios maiores que esse tamanho são enviados via Files API (upload resumable), evitando o limite de 20 MB do inline_data. Recomendado: 18.')); ?></p>
            </div>

            <!-- Force Files API -->
            <div class="form-group checkbox-group">
                <input type="checkbox" id="gemini_files_api_force" name="gemini_files_api_force" class="checkbox"
                       <?php echo $_['gemini_files_api_force'] === 'true' ? 'checked' : ''; ?>>
                <label for="gemini_files_api_force"><?php p($l->t('Sempre usar Files API')); ?></label>
                <p class="hint"><?php p($l->t('Força o uso da Files API mesmo em arquivos pequenos. Útil para depuração.')); ?></p>
            </div>

            <!-- Long audio split threshold -->
            <div class="form-group">
                <label for="long_audio_split_threshold"><?php p($l->t('Áudio longo: dividir a partir de (minutos)')); ?></label>
                <input type="number" id="long_audio_split_threshold" name="long_audio_split_threshold"
                       value="<?php echo htmlspecialchars($_['long_audio_split_threshold']); ?>"
                       min="5" max="180" step="1">
                <p class="hint"><?php p($l->t('Áudios maiores que esse tempo serão divididos em partes para evitar truncamento.')); ?></p>
            </div>
        </div>

        <!-- Realtime STT toggle -->
        <div class="form-group checkbox-group">
            <input type="checkbox" id="enable_realtime_stt" name="enable_realtime_stt" class="checkbox"
                   <?php echo $_['enable_realtime_stt'] === 'true' ? 'checked' : ''; ?>>
            <label for="enable_realtime_stt"><?php p($l->t('Habilitar transcrição ao vivo (Speech-to-Text em tempo real)')); ?></label>
            <p class="hint"><?php p($l->t('Quando ativo, mostra a transcrição em tempo real durante a gravação. (Funcionalidade ativada na Fase 3.)')); ?></p>
        </div>

        <!-- Realtime STT provider -->
        <div class="form-group">
            <label for="realtime_stt_provider"><?php p($l->t('Provedor de STT em tempo real')); ?></label>
            <select id="realtime_stt_provider" name="realtime_stt_provider">
                <option value="web-speech" <?php echo $_['realtime_stt_provider'] === 'web-speech' ? 'selected' : ''; ?>><?php p($l->t('Web Speech API (Chrome/Edge, grátis, browser-only)')); ?></option>
                <option value="google-stt" <?php echo ($_['realtime_stt_provider'] === 'google-stt') ? 'selected' : ''; ?>><?php p($l->t('Google Cloud Speech-to-Text (proxy server-side, qualquer usuário)')); ?></option>
            </select>
            <p class="hint"><?php p($l->t('Ambos os provedores funcionam para qualquer usuário do grupo permitido sem expor a chave de API: Web Speech roda inteiramente no browser; Google STT é proxied pelo servidor (a chave fica em segurança no Nextcloud).')); ?></p>
        </div>

        <!-- Rate limit -->
        <div class="form-group">
            <label for="max_jobs_per_user_per_day"><?php p($l->t('Limite de processamentos por usuário/dia')); ?></label>
            <input type="number" id="max_jobs_per_user_per_day" name="max_jobs_per_user_per_day"
                   value="<?php echo htmlspecialchars($_['max_jobs_per_user_per_day']); ?>"
                   min="0" max="10000" step="1">
            <p class="hint"><?php p($l->t('Quantidade máxima de jobs (transcrição/refine/split) por usuário em 24h. 0 = ilimitado. Padrão: 50.')); ?></p>
        </div>

        <hr>
        <h3><?php p($l->t('Diagnóstico')); ?></h3>
        <div class="form-group">
            <button type="button" id="btn-healthcheck" class="button">
                <span class="button-text"><?php p($l->t('Verificar saúde do sistema')); ?></span>
            </button>
            <div id="healthcheck-results" class="healthcheck-results" style="display:none"></div>
        </div>

        <hr>

        <!-- Actions -->
        <div class="form-actions">
            <button type="button" id="test-connection" class="button">
                <span class="btn-icon"><?php echo $icons['test']; ?></span>
                <span class="button-text"><?php p($l->t('Testar Conexão')); ?></span>
            </button>
            <button type="submit" class="button primary">
                <span class="btn-icon"><?php echo $icons['save']; ?></span>
                <span class="button-text"><?php p($l->t('Salvar Configurações')); ?></span>
            </button>
        </div>

        <div id="settings-status" class="settings-status" style="display:none"></div>
    </form>
</div>
