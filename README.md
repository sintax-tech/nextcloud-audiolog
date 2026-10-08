# Audiolog

Record, transcribe and process audio with AI models — directly inside Nextcloud.

[![Nextcloud](https://img.shields.io/badge/Nextcloud-28%E2%80%9335-blue)](https://apps.nextcloud.com/apps/audiolog)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple)](https://www.php.net/)

> [🇧🇷 Versão em português](#português)

---

## What it does

Audiolog is a self-hosted alternative to Plaud / Otter / Fathom built into Nextcloud. You record (or upload) an audio file, the app transcribes it, optionally produces a meeting summary / formal minutes / agenda / task list, and saves everything as files inside your Nextcloud — speaker diarization included.

| Stage | Providers |
|-------|-----------|
| **Live transcription** (during the recording) | Web Speech API (browser-native) · Google Cloud Speech-to-Text (server-side proxy) |
| **Post-stop transcription** | Gemini (native multimodal) · OpenAI Whisper · Ollama (Whisper-compatible) |
| **Analysis** (summary / minutes / agenda / tasks) | Gemini · OpenAI Chat Completions · Any OpenAI-compatible endpoint |

The app deliberately keeps providers isolated: pick OpenAI and the app won't ever call Google in the background. Pick Gemini and it gets to do everything in one multimodal pass.

## Features

- **Audio and video files** accepted by content, not only by name: MP3, M4A (iPhone), OPUS/OGG (WhatsApp), WAV, FLAC, AAC, WebM, MP4 and more; video is converted to audio with `ffmpeg`
- **Recording** with pause / resume / real-time size & duration indicator
- **Auto-recovery** if the tab closes or the network drops mid-recording (IndexedDB-backed)
- **Live transcription** with auto-restart on silence
- **Speaker diarization** on the post-stop refine pass — with a name editing UI to attach real names to "[Speaker 1]" labels
- **Large audio files** — Gemini's Files API resumable upload kicks in past 18 MB by default
- **Files-app integration**: everything lives under `Audiolog/<project>/`
- **Markdown / DOCX export** for Collabora / Office round-trips
- **PWA**: install as a standalone app on iOS / Android, with the browser's Wake Lock keeping the screen on during long sessions
- **Multilingual UI** (English, Portuguese, Spanish)

## Security

- API keys encrypted at rest with `OCP\Security\ICrypto` (`enc:v1:` prefix)
- Per-group access control (`Allowed Groups` setting) — empty list means everyone, otherwise restricted
- **Server-side STT proxy** for Google Cloud Speech-to-Text: the API key NEVER leaves the server, so any allowed-group user can use live transcription without leaking credentials; Nextcloud's rate limiter caps it at 30 requests per minute per user
- API keys travel in request headers (`x-goog-api-key`, `Authorization`), never in URLs, and provider error messages are masked before they reach the log, the job status or the browser
- **Deck export** goes through Deck's own services: a card is only created on a board the user can edit
- No provider origin in the page CSP: the browser only talks to Audiolog's own endpoints
- Uploads are checked against the admin's maximum size and must be audio or video by content; `ffmpeg` only reads local files with media demuxers (`-protocol_whitelist file,pipe`, `-format_whitelist`), runs with a time limit, and playlists or manifests are refused
- Path-traversal guard on every endpoint that accepts a user path
- Web Speech API runs in the browser only, no key ever leaves the server
- Generic error responses to clients; full stack traces stay in the server logs

## Requirements

- **Nextcloud 28 → 35**
- **PHP 8.1+** with `curl`, `json`, `fileinfo`, `mbstring`, `openssl`
- **`ffmpeg`** and **`ffprobe`** on the server PATH (used for audio length probing and video → audio extraction)
- A valid API key for at least one provider, **or** a working local Ollama
- **Local Ollama** (on `localhost` or the internal network): Nextcloud blocks requests to local and private addresses by default, and Audiolog uses Nextcloud's HTTP client. Add `'allow_local_remote_servers' => true,` to `config/config.php`; otherwise "Test connection" and processing report that the address was blocked.

## Installation

### From the Nextcloud app store

```bash
occ app:install audiolog
occ app:enable audiolog
```

Or via the Nextcloud admin UI → Apps → Search for "Audiolog".

### From source (development)

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/sintax-tech/nextcloud-audiolog.git audiolog
chown -R www-data:www-data audiolog
sudo -u www-data php /var/www/nextcloud/occ app:enable audiolog
```

## Configuration

**Settings → Administration → Audiolog**

1. Pick an **AI provider** (Gemini, OpenAI, Ollama)
2. Paste the API key (encrypted automatically on save)
3. Optionally set the **Analysis model** (`gpt-4o-mini`, `llama3.1`, etc) for OpenAI/Ollama
4. Enable **live transcription** if you want the live tab — pick `web-speech` (recommended) or `google-stt`
5. Restrict access via **Allowed Groups** if you don't want everyone

The healthcheck button on that page validates ffmpeg, ffprobe, PHP extensions, disk space and API reachability in one click.

## Architecture

```
templates/main.php     ← user-facing page (Files-style nav, Live, Recordings tabs)
templates/admin.php    ← admin settings form
js/audiolog-main.js    ← recording, IDB persistence, live transcription router
js/audiolog-admin.js   ← admin form, provider switching
js/audiolog-speaker-naming.js ← speaker name editing UI
js/audiolog-sw.js      ← service worker (PWA)
js/pcm-extractor-worklet.js ← AudioWorklet that extracts PCM for live STT
js/audiolog-text-utils.js ← pure text helpers (unit-tested with Jest)
lib/Controller/        ← Page, Api, Settings controllers
lib/Service/AudioService.php       ← provider-specific audio processing
lib/Service/DeckIntegration.php    ← Deck export through Deck's own services
lib/Service/MediaTools.php         ← ffmpeg/ffprobe commands (argv, local protocols only)
lib/Service/SecretRedactor.php     ← masks API keys in error messages
lib/Service/PermissionService.php  ← group ACL
lib/Service/CryptoHelper.php       ← API-key encryption wrapper
lib/BackgroundJob/ProcessAudioJob.php  ← async pipeline
lib/Db/                ← Job entity and mapper
lib/Migration/         ← database schema
```

## Development

```bash
composer install  # dev-only: PHPUnit + nextcloud/ocp into vendor/ (git-ignored, never shipped)
npm install       # dev-only: Jest into node_modules/
make test         # PHPUnit (php -d memory_limit=1G) + Jest, no Nextcloud checkout needed
make lint         # php -l on every PHP file
tools/l10n.py check   # every UI string present in en, pt_BR and es
tools/l10n.py sync    # regenerate l10n/*.js from l10n/*.json
make build        # build/appstore/audiolog-X.Y.Z.tar.gz from the committed HEAD (git archive), then check it
make sign         # sign the tarball with your app store certificate
make clean        # wipe build/
```

Releases are published as a signed tarball attached to a GitHub Release (`vX.Y.Z`); the app store downloads it from the release URL (versions up to 1.0.4 remain under `dist/`). See [`PUBLISHING.md`](PUBLISHING.md) for the full release-to-store workflow and [`CHANGELOG.md`](CHANGELOG.md) for the version history.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).

## About Sintax

Built and maintained by [Sintax](https://sintax.tech).

- Website: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contact: contato@sintax.tech
- Other Nextcloud apps by Sintax: [MCP for Nextcloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

---

## Português

**Audiolog** é um app Nextcloud para gravar, transcrever e processar áudios com modelos de IA — uma alternativa self-hosted ao Plaud Note, Otter, Fathom e similares, rodando dentro da sua própria instância Nextcloud.

Você grava (ou faz upload de) um áudio, o app transcreve, opcionalmente gera resumo executivo, ata formal de reunião, pauta ou lista de tarefas, e salva tudo na pasta `Audiolog/` do seu Nextcloud — com identificação de falantes inclusa.

### Provedores suportados

| Etapa | Provedores |
|-------|-----------|
| **Transcrição ao vivo** | Web Speech API (nativo do navegador) · Google Cloud Speech-to-Text (proxy server-side) |
| **Transcrição pós-stop** | Gemini (multimodal nativo) · OpenAI Whisper · Ollama (compatível Whisper) |
| **Análise** (resumo / ata / pauta / tarefas) | Gemini · OpenAI Chat Completions · Qualquer endpoint compatível com OpenAI |

Os provedores são isolados: se você escolher OpenAI, o app **não chama Google em momento algum**. Escolha Gemini e ele faz tudo num único modelo multimodal.

### O que está incluído

- **Arquivos de áudio e vídeo** aceitos pelo conteúdo, não só pelo nome: MP3, M4A (iPhone), OPUS/OGG (WhatsApp), WAV, FLAC, AAC, WebM, MP4 e outros; vídeo é convertido em áudio com o `ffmpeg`
- **Gravação** com pausa, retomada e indicador real de tamanho/duração
- **Recuperação automática** se a aba fechar ou a internet cair no meio da gravação (persistência via IndexedDB)
- **Transcrição em tempo real** com auto-reconexão em silêncio
- **Identificação de falantes** no refinamento pós-stop, com UI pra associar nomes reais aos rótulos `[Falante 1]`, `[Falante 2]`...
- **Áudios grandes** suportados via Files API resumable do Gemini (a partir de 18 MB por padrão, configurável)
- **Integração com Files**: tudo organizado em `Audiolog/<projeto>/`, abre normalmente pelo app Files do Nextcloud
- **Exportação Markdown / DOCX** para edição no Collabora / Office
- **PWA**: instala como app nativo no iOS/Android, Wake Lock mantém a tela ligada durante sessões longas
- **Interface multilíngue** (Inglês, Português, Espanhol)

### Segurança

- **Chaves de API criptografadas em repouso** com `OCP\Security\ICrypto`
- **Controle por grupo** (configuração "Grupos Permitidos") — lista vazia libera todos, lista preenchida restringe
- **Proxy STT server-side**: a chave do Google Cloud Speech-to-Text **nunca vai pro navegador** — qualquer usuário do grupo permitido pode usar transcrição ao vivo sem risco de vazar credenciais; o limitador do Nextcloud permite até 30 requisições por minuto por usuário
- **Chaves nos headers** (`x-goog-api-key`, `Authorization`), nunca na URL, e mensagens de erro dos provedores mascaradas antes de ir para o log, o status do job ou o navegador
- **Exportação para o Deck** pelos serviços do próprio Deck: o cartão só é criado em quadro que o usuário pode editar
- **CSP sem provedores**: o navegador só fala com os endpoints do próprio Audiolog
- **Uploads** conferidos contra o tamanho máximo do admin e pelo conteúdo (áudio ou vídeo); o `ffmpeg` só lê arquivos locais com demuxers de mídia (`-protocol_whitelist file,pipe`, `-format_whitelist`), roda com limite de tempo, e playlists ou manifestos são recusados
- **Proteção contra path traversal** em todos os endpoints que aceitam caminhos do usuário
- **Web Speech API** roda 100% no navegador, sem chave envolvida
- **Mensagens de erro genéricas** ao cliente; stack traces ficam só nos logs do servidor

### Requisitos

- **Nextcloud 28 → 35**
- **PHP 8.1+** com `curl`, `json`, `fileinfo`, `mbstring`, `openssl`
- **`ffmpeg`** e **`ffprobe`** no PATH do servidor
- Chave de API de pelo menos um provedor **ou** um Ollama local funcionando
- **Ollama local** (em `localhost` ou na rede interna): o Nextcloud bloqueia por padrão requisições a endereços locais e privados, e o Audiolog usa o cliente HTTP do Nextcloud. Adicione `'allow_local_remote_servers' => true,` ao `config/config.php`; sem isso, o "Testar conexão" e o processamento avisam que o endereço foi bloqueado.

### Instalação

```bash
occ app:install audiolog
occ app:enable audiolog
```

### Configuração

**Configurações → Administração → Audiolog**

1. Escolha o **provedor de IA** (Gemini, OpenAI, Ollama)
2. Cole a chave de API (é criptografada automaticamente ao salvar)
3. Opcionalmente, defina o **Modelo de análise** (`gpt-4o-mini`, `llama3.1`, etc) para OpenAI/Ollama
4. Habilite **transcrição ao vivo** se quiser a aba dedicada — escolha `web-speech` (recomendado) ou `google-stt`
5. Restrinja o acesso via **Grupos Permitidos** se não quiser liberar pra todo mundo

O botão de **healthcheck** valida ffmpeg, ffprobe, extensões PHP, espaço em disco e conectividade da API num único clique.

### Desenvolvimento

```bash
composer install  # só desenvolvimento: PHPUnit + nextcloud/ocp em vendor/ (fora do git e do pacote)
npm install       # só desenvolvimento: Jest em node_modules/
make test         # PHPUnit (php -d memory_limit=1G) + Jest, sem precisar de checkout do Nextcloud
make lint         # php -l em todos os arquivos PHP
tools/l10n.py check   # toda string da interface em en, pt_BR e es
tools/l10n.py sync    # regera l10n/*.js a partir de l10n/*.json
make build        # gera build/appstore/audiolog-X.Y.Z.tar.gz do HEAD commitado (git archive) e confere o pacote
make sign         # assina o tarball com o certificado da loja
make clean        # apaga build/
```

As releases são publicadas como um tarball assinado anexado a uma GitHub Release (`vX.Y.Z`); a loja baixa pela URL da release (as versões até a 1.0.4 permanecem em `dist/`). Veja [`PUBLISHING.md`](PUBLISHING.md) para o fluxo completo e [`CHANGELOG.md`](CHANGELOG.md) para o histórico de versões.

### Licença

AGPL-3.0-or-later. Veja [LICENSE](LICENSE).

## Sobre a Sintax

Desenvolvido e mantido pela [Sintax](https://sintax.tech).

- Site: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contato: contato@sintax.tech
- Outros apps da Sintax para Nextcloud: [MCP for Nextcloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)
