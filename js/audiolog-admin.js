/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Assistente de Audio - Admin Settings JavaScript
 * @author Jhonatan Jaworski
 * @copyright 2026 Sintax (Jhonatan Jaworski)
 */

(function() {
    'use strict';

    // Provider default URLs.
    // Note: Anthropic Claude is intentionally NOT in this list — Anthropic's API
    // doesn't offer a speech-to-text product, only text. Listing it here would
    // be a dead option that fails at runtime.
    const providerUrls = {
        'ollama': 'http://localhost:11434',
        'openai': 'https://api.openai.com/v1',
        'gemini': 'https://generativelanguage.googleapis.com'
    };

    // Provider models (atualizado 2026-04 conforme https://ai.google.dev/gemini-api/docs/models)
    const providerModels = {
        'ollama': ['whisper-large-v3', 'whisper-medium', 'whisper-small'],
        'openai': ['whisper-1', 'gpt-4o-audio-preview', 'gpt-4o-transcribe'],
        'gemini': ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite', 'gemini-3-flash-preview', 'gemini-3.1-pro-preview']
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Provider URL hints (translated once the page's l10n is loaded).
        const urlHints = {
            'ollama': t('audiolog', 'URL do servidor Ollama (padrão: {url})', { url: providerUrls.ollama }),
            'openai': t('audiolog', 'URL da API OpenAI (padrão: {url})', { url: providerUrls.openai }),
            'gemini': t('audiolog', 'URL da API Google AI (padrão: {url})', { url: providerUrls.gemini })
        };

        // Model hints
        const modelHints = {
            'ollama': t('audiolog', 'Modelos Whisper para transcrição local'),
            'openai': t('audiolog', 'whisper-1 para transcrição, gpt-4o para análise avançada'),
            'gemini': t('audiolog', 'Gemini 2.5+ suporta áudio nativo. Recomendado: gemini-2.5-flash (econômico) ou gemini-2.5-pro (qualidade).')
        };
        const textShow = t('audiolog', 'Mostrar');
        const textHide = t('audiolog', 'Ocultar');

        const form = document.getElementById('assistaudio-settings-form');
        const providerSelect = document.getElementById('ai_provider');
        const urlInput = document.getElementById('ai_url');
        const apiKeyInput = document.getElementById('api_key');
        const apiKeyGroup = document.getElementById('api-key-group');
        const toggleApiKey = document.getElementById('toggle-api-key');
        const modelInput = document.getElementById('ai_model');
        const urlHint = document.getElementById('url-hint');
        const modelHint = document.getElementById('model-hint');
        const suggestedModels = document.getElementById('suggested-models');
        const testButton = document.getElementById('test-connection');
        const statusDiv = document.getElementById('settings-status');

        // Handle provider change
        // Track the *previous* provider so we can detect a real user-driven
        // change (vs the synthetic dispatch on page load) and only then
        // overwrite the URL with the new provider's default.
        let lastProvider = providerSelect.value;
        const analysisModelGroup = document.getElementById('analysis-model-group');
        providerSelect.addEventListener('change', function() {
            const provider = this.value;

            // Update URL placeholder and hint.
            urlInput.placeholder = providerUrls[provider] || '';
            urlHint.textContent = urlHints[provider] || '';

            // If the URL still matches the OLD provider's default (or is
            // empty), replace it with the new provider's default. This makes
            // the field "follow" the select instead of staying stuck on a
            // URL that no longer matches the provider — but we never wipe a
            // truly custom URL the admin typed in.
            const prevDefault = providerUrls[lastProvider] || '';
            const cur = (urlInput.value || '').trim();
            if (cur === '' || cur === prevDefault) {
                urlInput.value = providerUrls[provider] || '';
            }
            lastProvider = provider;

            // Update model hint
            modelHint.textContent = modelHints[provider] || '';

            // API key field stays visible for ALL providers — Ollama is
            // optional locally but required when behind a gateway/cloud
            // proxy with auth. The label/hint already explains this.
            apiKeyGroup.style.display = 'block';

            // Hide "modelo de análise" when Gemini (it's not used —
            // Gemini does audio + analysis natively).
            if (analysisModelGroup) {
                analysisModelGroup.style.display = (provider === 'gemini') ? 'none' : 'block';
            }

            // Hide the Gemini-only knobs (Files API threshold, force-Files-API,
            // long-audio split, Google STT toggle) when the admin picks
            // OpenAI or Ollama. These features either require Gemini's
            // multimodal API or only matter if Gemini is the active provider.
            document.querySelectorAll('.provider-gemini-only').forEach((el) => {
                el.style.display = (provider === 'gemini') ? '' : 'none';
            });

            // Update suggested models
            updateSuggestedModels(provider);
        });

        // Initialize on load — pass a flag so we don't blow away the saved URL.
        providerSelect.dispatchEvent(new Event('change'));

        // Toggle API key visibility
        toggleApiKey.addEventListener('click', function() {
            if (apiKeyInput.type === 'password') {
                apiKeyInput.type = 'text';
                this.textContent = textHide;
            } else {
                apiKeyInput.type = 'password';
                this.textContent = textShow;
            }
        });
        toggleApiKey.textContent = textShow;

        // Same pattern for the Google STT key toggle (when present).
        const toggleStt = document.getElementById('toggle-stt-key');
        const sttKeyInput = document.getElementById('google_stt_api_key');
        if (toggleStt && sttKeyInput) {
            const sttBtnText = toggleStt.querySelector('.button-text');
            toggleStt.addEventListener('click', () => {
                if (sttKeyInput.type === 'password') {
                    sttKeyInput.type = 'text';
                    if (sttBtnText) sttBtnText.textContent = textHide;
                } else {
                    sttKeyInput.type = 'password';
                    if (sttBtnText) sttBtnText.textContent = textShow;
                }
            });
        }

        // Update suggested models
        function updateSuggestedModels(provider) {
            const models = providerModels[provider] || [];
            suggestedModels.innerHTML = models.map(model =>
                `<span class="model-chip" data-model="${model}">${model}</span>`
            ).join('');

            // Add click handlers
            suggestedModels.querySelectorAll('.model-chip').forEach(chip => {
                chip.addEventListener('click', function() {
                    modelInput.value = this.dataset.model;
                });
            });
        }

        // Test connection
        testButton.addEventListener('click', async function() {
            testButton.disabled = true;
            testButton.querySelector('.button-text').textContent = t('audiolog', 'Testando...');

            try {
                const response = await fetch(OC.generateUrl('/apps/audiolog/settings/test'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    }
                });

                const result = await response.json();

                showStatus(result.message, result.status === 'success' ? 'success' : 'error');

            } catch (err) {
                showStatus(t('audiolog', 'Erro ao testar conexão:') + ' ' + err.message, 'error');
            } finally {
                testButton.disabled = false;
                testButton.querySelector('.button-text').textContent = t('audiolog', 'Testar Conexão');
            }
        });

        // Save settings
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"]');
            const submitBtnText = submitBtn.querySelector('.button-text');
            submitBtn.disabled = true;
            submitBtnText.textContent = t('audiolog', 'Salvando...');

            // Get selected groups from multiselect
            const allowedGroupsSelect = document.getElementById('allowed_groups');
            const allowedGroups = Array.from(allowedGroupsSelect.selectedOptions).map(opt => opt.value);

            // Helpers: tolerate fields that may have been removed from the
            // template (e.g. Gemini-only knobs hidden when a different
            // provider is active). Without these guards, getElementById('x')
            // returning null trips a TypeError on `.value`/`.checked` and the
            // submit silently freezes on "Saving...".
            const val = (id, fallback = '') => document.getElementById(id)?.value ?? fallback;
            const checked = (id) => document.getElementById(id)?.checked ? 'true' : 'false';

            const formData = {
                ai_provider: providerSelect.value,
                ai_url: urlInput.value,
                api_key: apiKeyInput.value,
                ai_model: modelInput.value,
                language: val('language', 'pt'),
                max_file_size: val('max_file_size', '100'),
                save_audio: checked('save_audio'),
                default_output: val('default_output', 'transcricao'),
                allowed_groups: allowedGroups,
                gemini_files_api_threshold: val('gemini_files_api_threshold', '18'),
                gemini_files_api_force: checked('gemini_files_api_force'),
                long_audio_split_threshold: val('long_audio_split_threshold', '25'),
                enable_realtime_stt: checked('enable_realtime_stt'),
                realtime_stt_provider: val('realtime_stt_provider', 'web-speech'),
                use_google_stt_for_transcription: checked('use_google_stt_for_transcription'),
                google_stt_api_key: val('google_stt_api_key', ''),
                analysis_model: val('analysis_model', '')
            };

            try {
                const response = await fetch(OC.generateUrl('/apps/audiolog/settings/save'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.status === 'success') {
                    showStatus(t('audiolog', 'Configurações salvas com sucesso!'), 'success');
                } else {
                    showStatus(t('audiolog', 'Erro ao salvar:') + ' ' + (result.message || t('audiolog', 'Erro desconhecido')), 'error');
                }

            } catch (err) {
                showStatus(t('audiolog', 'Erro ao salvar configurações:') + ' ' + err.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtnText.textContent = t('audiolog', 'Salvar Configurações');
            }
        });

        // Healthcheck
        const btnHealth = document.getElementById('btn-healthcheck');
        const healthResults = document.getElementById('healthcheck-results');
        if (btnHealth && healthResults) {
            btnHealth.addEventListener('click', async () => {
                btnHealth.disabled = true;
                btnHealth.querySelector('.button-text').textContent = t('audiolog', 'Verificando...');
                healthResults.style.display = 'block';
                healthResults.replaceChildren();
                try {
                    const resp = await fetch(OC.generateUrl('/apps/audiolog/settings/healthcheck'), {
                        method: 'GET',
                        headers: { 'Accept': 'application/json', 'requesttoken': OC.requestToken }
                    });
                    const data = await resp.json();
                    const ul = document.createElement('ul');
                    ul.className = 'healthcheck-list';
                    (data.checks || []).forEach(c => {
                        const li = document.createElement('li');
                        li.className = 'healthcheck-item ' + (c.ok ? 'ok' : 'fail');
                        const icon = document.createElement('span');
                        icon.className = 'healthcheck-icon';
                        icon.textContent = c.ok ? '✓' : '✗';
                        const name = document.createElement('strong');
                        name.textContent = c.name;
                        const detail = document.createElement('span');
                        detail.className = 'healthcheck-detail';
                        detail.textContent = ' — ' + (c.detail || '');
                        li.appendChild(icon);
                        li.appendChild(name);
                        li.appendChild(detail);
                        ul.appendChild(li);
                    });
                    healthResults.appendChild(ul);
                } catch (err) {
                    const p = document.createElement('p');
                    p.className = 'healthcheck-error';
                    p.textContent = t('audiolog', 'Erro:') + ' ' + err.message;
                    healthResults.appendChild(p);
                } finally {
                    btnHealth.disabled = false;
                    btnHealth.querySelector('.button-text').textContent = t('audiolog', 'Verificar saúde do sistema');
                }
            });
        }

        function showStatus(message, type) {
            statusDiv.textContent = message;
            statusDiv.className = 'settings-status ' + type;
            statusDiv.style.display = 'block';

            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }
    });
})();
