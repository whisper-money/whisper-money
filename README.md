<p align="center">
  <img src="https://whisper.money/images/og_whisper_money.png?20260215075346" alt="Whisper Money" width="100%">
</p>
<p align="center">
<a href="https://zdoc.app/de/whisper-money/whisper-money">Deutsch</a> |
<a href="https://zdoc.app/es/whisper-money/whisper-money">Español</a> |
<a href="https://zdoc.app/fr/whisper-money/whisper-money">français</a> |
<a href="https://zdoc.app/ja/whisper-money/whisper-money">日本語</a> |
<a href="https://zdoc.app/ko/whisper-money/whisper-money">한국어</a> |
<a href="https://zdoc.app/pt/whisper-money/whisper-money">Português</a> |
<a href="https://zdoc.app/ru/whisper-money/whisper-money">Русский</a> |
<a href="https://zdoc.app/zh/whisper-money/whisper-money">中文</a>
</p>

# Whisper Money

<img src="https://github.com/whisper-money/whisper-money/actions/workflows/ci.yml/badge.svg" /> [![CC BY-NC 4.0][cc-by-nc-shield]][cc-by-nc]

**The most secure way to understand your finances.**

Whisper Money is a privacy-first personal finance application that helps you track, categorize, and understand your spending. We don't sell your data and we don't profile you for ads. The entire codebase is public, so you can check exactly where your data goes.

> 🎮 **Try the Demo:** Experience Whisper Money with our [demo account](https://whisper.money/login?demo=1) - no registration required!

> 💬 **Join our Community:** Whether you're a user looking for help or a developer wanting to contribute, we'd love to have you in our [Discord server](https://discord.gg/m8hUhx6D9D)! Share feedback, ask questions, discuss new features, or just hang out with fellow privacy enthusiasts.

## Features

- 🔐 **Privacy-first** — You own your data and we never sell it. Self-host it and point the AI at a [local model](#ai-provider) to keep it entirely on your own infrastructure
- 🏦 **Bank account management** — Track multiple accounts in one place
- 📊 **Transaction categorization** — Automatic and manual categorization
- 🤖 **Automation rules** — Set up rules to auto-categorize transactions
- 📈 **Financial insights** — Understand your spending patterns

## Tech Stack

- **Backend:** Laravel 12, PHP 8.4
- **Frontend:** React 19, Inertia.js v3, TypeScript
- **Styling:** Tailwind CSS v4
- **Database:** MySQL
- **Cache/Queue:** Redis
- **Testing:** Pest v4

## Running Locally

### Quick Start (Recommended)

The easiest way to get started is using our automated setup script:

```bash
bash <(curl -fsSL https://whisper.money/setup.sh)
```

After installation, just visit **<https://whisper.money.localhost>** in your browser.

### Manual Setup

If you prefer to set up manually:

1. **Clone the repository:**

```bash
git clone https://github.com/whisper-money/whisper-money.git
cd whisper-money
```

1. **Run the setup script:**

```bash
whispermoney install
```

### Available Commands

> **Important:** You must run `whispermoney install` before using any other command. If you skip the install step, commands like `start` will not work.

Once installed, you can use the `whispermoney` command for common tasks:

```bash
# Start all services
whispermoney start

# Stop all services
whispermoney stop

# Upgrade to latest version
whispermoney upgrade

# Interactive menu
whispermoney
```

### Development Server

For active development with hot reloading:

```bash
composer run dev
```

This will concurrently start:

- PHP development server (via [Portless](https://portless.sh) HTTPS proxy)
- Queue worker
- Log viewer (Pail)
- Vite dev server

The application will be available at **<https://dev.whisper.money.localhost>**. In git worktrees, the branch name is automatically prepended (e.g. `https://fix-ui.dev.whisper.money.localhost`).

## Running with Docker (Production Image)

For testing the production Docker image locally:

1. **Copy the production environment file:**

```bash
cp .env.production.example .env
```

1. **Start the services:**

```bash
docker compose -f docker-compose.production.yml up -d
```

The application will be available at `http://localhost:8080`.

To use a different port, set `APP_PORT`:

```bash
APP_PORT=3000 docker compose -f docker-compose.production.yml up -d
```

## Deploying to Coolify

Whisper Money can be easily deployed to [Coolify](https://coolify.io) using our Docker Compose template.

### Quick Deploy

1. In Coolify, create a new resource and select **Docker Compose**
2. Choose **Empty Compose File** as the source
3. Paste the contents from our template:
   👉 **[whisper-money.yaml](https://raw.githubusercontent.com/whisper-money/whisper-money/main/templates/coolify/whisper-money.yaml)**
4. Deploy!

The template includes:

- Whisper Money application container
- MySQL 8.0 database with health checks
- Persistent volumes for data and storage
- Auto-generated database credentials

### Required Environment Variables

| Variable         | Description                                                |
| ---------------- | ---------------------------------------------------------- |
| `RESEND_API_KEY` | Email service API key (for password resets, notifications) |

> **Note**: `APP_KEY` and `APP_URL` are auto-configured. The container generates an `APP_KEY` on first startup if not provided.

### Optional Environment Variables

| Variable                | Default | Description                                        |
| ----------------------- | ------- | -------------------------------------------------- |
| `DRIP_EMAILS_ENABLED`   | `true`  | Enable drip emails (welcome, onboarding, feedback) |
| `REGISTRATION_ENABLED`  | `true`  | Set to `false` to close public sign-ups (the `/register` routes return a 403 and every registration CTA is hidden) while keeping `/login` open |
| `SUBSCRIPTIONS_ENABLED` | `false` | Enable Stripe subscriptions                        |
| `STRIPE_KEY`            | -       | Stripe publishable key                             |
| `STRIPE_SECRET`         | -       | Stripe secret key                                  |
| `STRIPE_WEBHOOK_SECRET` | -       | Stripe webhook signing secret                      |
| `AI_PROVIDER`           | `gemini`| AI provider for every AI feature (`gemini`, `ollama`, `openai`, ...) |

## AI Provider

Whisper Money's AI features (transaction categorization and automation-rule
suggestions) run on [`laravel/ai`](https://github.com/laravel/ai) and default to
Google **Gemini**. The provider is configurable independently of the model, so
you can point the app at **any text provider `laravel/ai` supports** — `gemini`,
`openai`, `anthropic`, `azure`, `groq`, `xai`, `deepseek`, `mistral`, a
self-hosted **[Ollama](https://ollama.com)** server, or `openai-compatible` for
any endpoint speaking the OpenAI API. Ollama is the headline case because it
keeps AI processing fully local and private — data never leaves your
infrastructure — but the switch is generic.

Each provider needs its own credentials configured for `laravel/ai` (e.g.
`GEMINI_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, or `OLLAMA_URL`). An
unknown or non-text provider fails fast when the AI feature runs.

| Variable                     | Default              | Description                                                            |
| ---------------------------- | -------------------- | --------------------------------------------------------------------- |
| `AI_PROVIDER`                | `gemini`             | Provider for all AI features. Set once to switch everything.          |
| `AI_SUGGESTIONS_PROVIDER`    | `AI_PROVIDER`        | Override the provider for rule suggestions only.                      |
| `AI_CATEGORIZATION_PROVIDER` | `AI_PROVIDER`        | Override the provider for transaction categorization only.            |
| `AI_REPORTS_PROVIDER`        | `AI_PROVIDER`        | Override the provider for the stats-report summaries only.            |
| `AI_SUGGESTIONS_MODEL`       | `gemini-flash-latest`| Model used for rule suggestions.                                      |
| `AI_CATEGORIZATION_MODEL`    | `gemini-flash-latest`| Model used for transaction categorization.                            |
| `AI_REPORTS_MODEL`           | `gemini-flash-latest`| Model used for the stats-report summaries.                            |
| `AI_REPORTS_TIMEOUT`         | `30`                 | Seconds before a report is posted without its AI summary.             |
| `GEMINI_API_KEY`             | -                    | Required when the provider is `gemini`.                               |
| `OLLAMA_URL`                 | `http://localhost:11434` | Ollama server URL (used when the provider is `ollama`).           |
| `OLLAMA_API_KEY`             | -                    | Optional; only needed behind an authenticating proxy.                 |
| `OPENAI_COMPATIBLE_URL`      | -                    | Base URL of an OpenAI-compatible endpoint (required when the provider is `openai-compatible`). |
| `OPENAI_COMPATIBLE_API_KEY`  | -                    | Optional; sent as a bearer token when set.                            |

### Example: fully local AI with Ollama

```dotenv
AI_PROVIDER=ollama
OLLAMA_URL=http://ollama.example.local:11434
AI_SUGGESTIONS_MODEL=gemma3:12b
AI_CATEGORIZATION_MODEL=gemma3:12b
```

Make sure the model is pulled on the Ollama server first (`ollama pull gemma3:12b`).
Any other provider follows the same pattern: set `AI_PROVIDER`, that provider's
credentials, and the `*_MODEL` vars to one of its models. Gemini remains the
default, so existing deployments are unaffected.

### Any OpenAI-compatible endpoint

Plenty of services and local servers speak the OpenAI Chat Completions API
without being OpenAI: router/gateway services such as
[OrcaRouter](https://www.orcarouter.ai/), local runtimes like LM Studio or
vLLM, hosted inference like Together or Fireworks, a LiteLLM instance, or your
own corporate proxy. Set `AI_PROVIDER=openai-compatible` and point the app at
one of them.

- `OPENAI_COMPATIBLE_URL` is **required** — it is the base URL the app appends
  `chat/completions` to, so give it the versioned root (e.g. `.../v1`). Leaving
  it empty fails when the AI feature runs.
- `OPENAI_COMPATIBLE_API_KEY` is **optional** — when set it is sent as
  `Authorization: Bearer <key>`. Leave it empty for a local server that does not
  authenticate.
- The `*_MODEL` vars must name a model **that endpoint serves**. There is no
  default, and the Gemini defaults are meaningless to it.
- There is a **single** `openai-compatible` slot, so only one such endpoint can
  be configured at a time. To reach two of them, put a router in front.

Example with OrcaRouter:

```dotenv
AI_PROVIDER=openai-compatible
OPENAI_COMPATIBLE_URL=https://api.orcarouter.ai/v1
OPENAI_COMPATIBLE_API_KEY=sk-orca-...
AI_SUGGESTIONS_MODEL=orcarouter/auto
AI_CATEGORIZATION_MODEL=orcarouter/auto
AI_REPORTS_MODEL=orcarouter/auto
```

`orcarouter/auto` lets OrcaRouter pick the model; naming one directly
(`provider/model-name`) works too. Any other OpenAI-compatible endpoint follows
the same shape — only the URL and the model names change.

## License

This work is licensed under a
[Creative Commons Attribution-NonCommercial 4.0 International License][cc-by-nc].

[cc-by-nc]: https://creativecommons.org/licenses/by-nc/4.0/
[cc-by-nc-shield]: https://img.shields.io/badge/License-CC%20BY--NC%204.0-lightgrey.svg
