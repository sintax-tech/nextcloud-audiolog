/**
 * Audiolog — Speaker naming module.
 *
 * Runs after a transcript with [Falante N] labels is produced (live refine OR
 * upload processing). Lets the user attach a real name to each detected
 * speaker, then saves a new note with the names substituted into the text.
 *
 * Exposed as window.AudiologSpeakerNaming = { offer, extract }.
 */
(function() {
    'use strict';

    function extract(text) {
        if (!text) return [];
        const speakerRegex = /\[Falante\s+(\d+)\]/gi;
        const seen = new Map();
        const matches = [];
        for (const m of text.matchAll(speakerRegex)) {
            matches.push({ label: 'Falante ' + m[1], index: m.index, end: m.index + m[0].length });
        }
        for (let i = 0; i < matches.length; i++) {
            const cur = matches[i];
            if (seen.has(cur.label)) continue;
            const next = matches[i + 1];
            const sliceEnd = next ? next.index : Math.min(cur.end + 400, text.length);
            const first = text.slice(cur.end, sliceEnd).trim().slice(0, 220);
            seen.set(cur.label, first);
        }
        return Array.from(seen.entries()).map(([label, first]) => ({ label, first }));
    }

    /**
     * @param {string} text - the transcript with [Falante N] markers
     * @param {string} audioPath - the Audiolog path of the source audio
     *                             (so the generated note links back to the recording)
     * @param {string} baseTitle - the title to use as a prefix for the new note
     * @param {object} deps - { showNotification, t, OC }
     * @returns {boolean} - true if the panel was shown, false if no speakers found
     */
    function offer(text, audioPath, baseTitle, deps) {
        const t = deps.t;
        const showNotification = deps.showNotification;
        const OC = deps.OC;

        const speakers = extract(text);
        if (speakers.length === 0) return false;

        const panel = document.getElementById('speaker-naming-panel');
        const list = document.getElementById('speaker-naming-list');
        const inputSection = document.querySelector('[data-tab-panel="new"] .reuniao-input-section');
        const resultSection = document.getElementById('result-section');
        if (!panel || !list) return false;

        list.replaceChildren();
        speakers.forEach((sp) => {
            const card = document.createElement('div');
            card.className = 'speaker-naming-item';
            card.dataset.label = sp.label;

            const head = document.createElement('div');
            head.className = 'speaker-naming-head';
            const tag = document.createElement('span');
            tag.className = 'speaker-naming-tag';
            tag.textContent = sp.label;
            const quote = document.createElement('span');
            quote.className = 'speaker-naming-quote';
            quote.textContent = sp.first
                ? '"' + sp.first + (sp.first.length >= 218 ? '…' : '') + '"'
                : t('audiolog', '(sem fala detectada)');
            head.appendChild(tag);
            head.appendChild(quote);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'speaker-naming-input';
            input.placeholder = t('audiolog', 'Nome real (deixe em branco para manter "') + sp.label + '")';
            input.dataset.label = sp.label;

            card.appendChild(head);
            card.appendChild(input);
            list.appendChild(card);
        });

        if (resultSection) resultSection.style.display = 'none';
        if (inputSection) inputSection.style.display = 'none';
        panel.style.display = '';

        function closeNaming() {
            panel.style.display = 'none';
            if (resultSection && resultSection.dataset && resultSection.dataset.result) {
                resultSection.style.display = 'block';
            } else if (inputSection) {
                inputSection.style.display = '';
            }
        }

        const btnSkip = document.getElementById('btn-naming-skip');
        const btnSave = document.getElementById('btn-naming-save');
        if (btnSkip) {
            // Reset state BEFORE cloning so a previous "Salvando..." or disabled
            // state doesn't leak into the next session.
            btnSkip.disabled = false;
            btnSkip.textContent = t('audiolog', 'Pular');
            const newSkip = btnSkip.cloneNode(true);
            btnSkip.parentNode.replaceChild(newSkip, btnSkip);
            newSkip.addEventListener('click', closeNaming);
        }
        if (btnSave) {
            btnSave.disabled = false;
            btnSave.textContent = t('audiolog', 'Salvar nomes');
            const newSave = btnSave.cloneNode(true);
            btnSave.parentNode.replaceChild(newSave, btnSave);
            newSave.addEventListener('click', async () => {
                newSave.disabled = true;
                newSave.textContent = t('audiolog', 'Salvando...');
                try {
                    let renamed = text;
                    let count = 0;
                    list.querySelectorAll('.speaker-naming-input').forEach((inp) => {
                        const name = (inp.value || '').trim();
                        const label = inp.dataset.label;
                        if (!name || !label) return;
                        const safeName = name.replace(/[\r\n]/g, ' ');
                        // split/join avoids building a dynamic RegExp. The label
                        // is "Falante N" so "[Falante N]" is the literal marker
                        // we substitute by "Name:".
                        renamed = renamed.split('[' + label + ']').join(safeName + ':');
                        count++;
                    });

                    if (count === 0) {
                        showNotification(
                            t('audiolog', 'Nenhum nome preenchido — mantendo rótulos originais.'),
                            'info'
                        );
                        closeNaming();
                        return;
                    }

                    const namingTitle = (baseTitle || 'Transcrição') + ' (nomeado)';
                    const resp = await fetch(OC.generateUrl('/apps/audiolog/api/save-notes'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                        body: JSON.stringify({
                            title: namingTitle,
                            content: renamed,
                            audioPath: audioPath || ''
                        })
                    });
                    const r = await resp.json();
                    if (r.error) throw new Error(r.error);
                    showNotification(
                        t('audiolog', 'Transcrição com nomes salva: ') + r.path,
                        'success'
                    );

                    if (resultSection && resultSection.dataset) {
                        resultSection.dataset.result = renamed;
                        const resultContent = document.getElementById('result-content');
                        if (resultContent) resultContent.textContent = renamed;
                    }
                    closeNaming();
                } catch (err) {
                    console.error('Speaker naming save failed:', err);
                    showNotification(
                        t('audiolog', 'Erro ao salvar: ') + err.message,
                        'error'
                    );
                    newSave.disabled = false;
                    newSave.textContent = t('audiolog', 'Salvar nomes');
                }
            });
        }
        return true;
    }

    window.AudiologSpeakerNaming = { extract: extract, offer: offer };
})();
