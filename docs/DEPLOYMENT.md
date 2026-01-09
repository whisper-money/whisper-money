# Deployment Options

This guide covers various ways to run and deploy Whisper Money.

## Table of Contents

- [Quick Install (Self-Hosting)](#quick-install-self-hosting)
- [Running Locally (Development)](#running-locally-development)
- [Running with Docker (Production)](#running-with-docker-production)
- [Deploying to Coolify](#deploying-to-coolify)

---

## Quick Install (Self-Hosting)

The easiest way to run Whisper Money locally for personal use:

```bash
curl -fsSL https://whisper.money/install.sh | bash
```

This installs Whisper Money on port 8080 with:
- MySQL database with secure credentials
- Local email logging (no external services needed)
- All features enabled except Stripe

After installation, visit `http://localhost:8080` and create your account.

**Requirements:** Docker Desktop 20.10+ (includes Docker Compose v2)

**Full Documentation:** [Installation Guide](./INSTALLATION.md) | [Self-Hosting Guide](./SELF-HOSTING.md)

---

## Running Locally (Development)

For developing and contributing to Whisper Money.

### Prerequisites

- **Docker & Docker Compose** - For MySQL database
- **Composer** - PHP dependency management
- **Node.js / Bun** - Frontend dependencies and build tools

### Setup

1. **Clone the repository:**

```bash
git clone https://github.com/whisper-money/whisper-money.git
cd whisper_money
```

2. **Copy the environment file:**

```bash
cp .env.example .env
```

3. **Start the Docker services (MySQL only):**

```bash
docker compose up -d
```

4. **Install dependencies and setup the application:**

```bash
composer setup
```

This command runs:
- `composer install` - Install PHP dependencies
- `php artisan key:generate` - Generate application key
- `php artisan migrate --seed` - Create database tables and seed data
- `bun install` - Install JavaScript dependencies
- `bun run build` - Build frontend assets

5. **Start the development server:**

```bash
composer run dev
```

This will concurrently start:
- **PHP development server** (`php artisan serve`)
- **Queue worker** (`php artisan queue:work`)
- **Log viewer** (`php artisan pail`)
- **Vite dev server** (`bun run dev`)

The application will be available at `https://whispermoney.test`.

### Development Commands

**Run all services:**
```bash
composer run dev
```

**Run with SSR (Server-Side Rendering):**
```bash
composer run dev:ssr
```

**Run tests:**
```bash
php artisan test
```

**Run specific test file:**
```bash
php artisan test tests/Feature/ExampleTest.php
```

**Code formatting:**
```bash
# PHP
vendor/bin/pint --dirty

# JavaScript
bun run format
bun run lint
```

**Build frontend:**
```bash
# Development build
bun run build

# Production build with SSR
bun run build:ssr
```

### Directory Structure

```
whisper_money/
├── app/                    # Laravel application
│   ├── Console/           # Artisan commands
│   ├── Http/              # Controllers, Middleware
│   └── Models/            # Eloquent models
├── resources/
│   ├── js/                # React frontend
│   │   ├── components/    # Reusable components
│   │   ├── pages/         # Inertia pages
│   │   ├── services/      # IndexedDB services
│   │   └── routes/        # Wayfinder generated routes
│   └── css/               # Tailwind CSS
├── tests/
│   ├── Feature/           # Feature tests
│   └── Unit/              # Unit tests
└── docker/                # Docker configuration
```

---

## Running with Docker (Production)

Run the production Docker image locally for testing.

### Prerequisites

- Docker Desktop 20.10+
- Docker Compose v2

### Setup

1. **Copy the production environment file:**

```bash
cp .env.production.example .env
```

2. **Configure environment variables:**

Edit `.env` and set:
- `APP_URL` - Your application URL (default: `http://localhost:8080`)
- `DB_PASSWORD` - Database password (change from default)
- `MAIL_MAILER` - Email driver (`log` for testing, `resend` for production)

3. **Start the services:**

```bash
docker compose -f docker-compose.production.yml up -d
```

The application will be available at `http://localhost:8080`.

### Custom Port

To use a different port:

```bash
APP_PORT=3000 docker compose -f docker-compose.production.yml up -d
```

Or set `APP_PORT=3000` in your `.env` file.

### Management Commands

**View logs:**
```bash
docker compose -f docker-compose.production.yml logs -f
```

**Stop services:**
```bash
docker compose -f docker-compose.production.yml down
```

**Start services:**
```bash
docker compose -f docker-compose.production.yml up -d
```

**Restart services:**
```bash
docker compose -f docker-compose.production.yml restart
```

**Update to latest image:**
```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d --force-recreate
```

**Access application shell:**
```bash
docker compose -f docker-compose.production.yml exec app bash
```

**Access MySQL:**
```bash
docker compose -f docker-compose.production.yml exec mysql mysql -u whisper_money -p
```

### What's Included

The production Docker image includes:
- **Nginx** - Web server
- **PHP 8.4-FPM** - Application runtime
- **Redis** - Cache and sessions
- **Memcached** - Additional caching layer
- **Queue workers** - Background job processing
- **Inertia SSR** - Server-side rendering (Bun)

The `docker-compose.production.yml` adds:
- **MySQL 8.0** - Database server

### Volumes

Data is persisted in Docker volumes:
- `whisper-storage` - Application files, logs, uploads
- `whisper-mysql` - Database files

To completely remove all data:
```bash
docker compose -f docker-compose.production.yml down -v
```

---

## Deploying to Coolify

[Coolify](https://coolify.io) is an open-source, self-hostable Heroku/Netlify alternative.

### Quick Deploy

1. In Coolify, create a new resource and select **Docker Compose**
2. Choose **Empty Compose File** as the source
3. Paste the contents from our template:
   👉 **[whisper-money.yaml](https://raw.githubusercontent.com/whisper-money/whisper-money/main/templates/coolify/whisper-money.yaml)**
4. Configure environment variables (see below)
5. Deploy!

### What's Included

The Coolify template includes:
- Whisper Money application container
- MySQL 8.0 database with health checks
- Persistent volumes for data and storage
- Auto-generated database credentials
- Automatic HTTPS (via Coolify's proxy)

### Required Environment Variables

| Variable         | Description                                                |
| ---------------- | ---------------------------------------------------------- |
| `RESEND_API_KEY` | Email service API key (for password resets, notifications) |

> **Note**: `APP_KEY` and `APP_URL` are auto-configured. The container generates an `APP_KEY` on first startup if not provided.

### Optional Environment Variables

| Variable                | Default | Description                                        |
| ----------------------- | ------- | -------------------------------------------------- |
| `DRIP_EMAILS_ENABLED`   | `true`  | Enable drip emails (welcome, onboarding, feedback) |
| `HIDE_AUTH_BUTTONS`     | `false` | Hide login/register buttons on landing page        |
| `SUBSCRIPTIONS_ENABLED` | `false` | Enable Stripe subscriptions                        |
| `STRIPE_KEY`            | -       | Stripe publishable key                             |
| `STRIPE_SECRET`         | -       | Stripe secret key                                  |
| `STRIPE_WEBHOOK_SECRET` | -       | Stripe webhook signing secret                      |

### Email Setup (Resend)

1. Sign up at [resend.com](https://resend.com) (3,000 free emails/month)
2. Create an API key
3. Verify your domain (or use Resend's testing domain)
4. Add `RESEND_API_KEY` to your Coolify environment variables

### Stripe Setup (Optional)

To enable subscriptions:

1. Create account at [stripe.com](https://stripe.com)
2. Create subscription products and pricing
3. Get your API keys from Stripe Dashboard → Developers → API keys
4. Set up webhook endpoint: `https://yourdomain.com/stripe/webhook`
5. Add environment variables to Coolify:
   - `SUBSCRIPTIONS_ENABLED=true`
   - `STRIPE_KEY=pk_live_...`
   - `STRIPE_SECRET=sk_live_...`
   - `STRIPE_WEBHOOK_SECRET=whsec_...`

### Custom Domain

1. In Coolify, go to your application settings
2. Add your custom domain
3. Coolify automatically configures HTTPS with Let's Encrypt

### Monitoring

Coolify provides built-in monitoring:
- **Logs**: View real-time application and container logs
- **Metrics**: CPU, memory, and network usage
- **Health checks**: Automatic container restart on failure

### Backups

Set up automated backups in Coolify:
1. Go to your MySQL service settings
2. Enable automatic backups
3. Configure backup schedule and retention
4. Backups are stored in Coolify's backup storage

### Updating

Coolify automatically pulls new images when you redeploy. To update:
1. Click **Redeploy** in your application dashboard
2. Coolify pulls the latest `:latest` tag
3. Containers are recreated with zero downtime

To pin to a specific version, change the image tag in your compose file:
```yaml
image: ghcr.io/whisper-money/whisper-money:v0.1.3
```

---

## Other Deployment Options

### Deploy to DigitalOcean

Use our one-click installer on a DigitalOcean Droplet:

1. Create a Ubuntu 22.04 Droplet
2. SSH into your server
3. Run the installer:
   ```bash
   curl -fsSL https://whisper.money/install.sh | bash
   ```

See [Installation Guide](./INSTALLATION.md) for details.

### Deploy to Railway

1. Fork the repository
2. Connect Railway to your GitHub account
3. Create new project from your forked repo
4. Add MySQL plugin
5. Set environment variables
6. Deploy!

### Deploy to Render

1. Fork the repository
2. Create new Web Service in Render
3. Connect to your forked repo
4. Add PostgreSQL database (or use external MySQL)
5. Set environment variables
6. Deploy!

### Deploy to Kubernetes

For advanced users, a Helm chart is coming soon. Meanwhile, you can create your own Kubernetes manifests based on the Docker Compose file.

---

## Need Help?

- **Installation Issues**: [Installation Guide](./INSTALLATION.md)
- **Self-Hosting Questions**: [Self-Hosting Guide](./SELF-HOSTING.md)
- **GitHub Issues**: [Report a bug](https://github.com/whisper-money/whisper-money/issues)
- **Discord**: [Join our community](https://discord.gg/whisper-money)
