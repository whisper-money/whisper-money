# Self-Hosting Guide

This guide covers advanced configuration and management of your self-hosted Whisper Money installation.

## Table of Contents

- [Configuration](#configuration)
  - [Email Setup](#email-setup)
  - [Stripe Subscriptions](#stripe-subscriptions)
  - [Error Tracking (Sentry)](#error-tracking-sentry)
- [Management](#management)
  - [Starting & Stopping](#starting--stopping)
  - [Viewing Logs](#viewing-logs)
  - [Database Access](#database-access)
  - [File Access](#file-access)
- [Maintenance](#maintenance)
  - [Backups](#backups)
  - [Updates](#updates)
  - [Monitoring](#monitoring)
- [Security](#security)
  - [HTTPS Setup](#https-setup)
  - [Firewall Configuration](#firewall-configuration)
  - [Credential Rotation](#credential-rotation)
- [Performance](#performance)
  - [Queue Workers](#queue-workers)
  - [Caching](#caching)
- [Advanced](#advanced)
  - [Custom Domain](#custom-domain)
  - [Reverse Proxy](#reverse-proxy)
  - [Resource Limits](#resource-limits)

---

## Configuration

All configuration is done via environment variables in the `.env` file:

```bash
nano ~/whisper-money/.env
```

After making changes, restart services:

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart
```

### Email Setup

By default, Whisper Money logs emails to files instead of sending them. To enable real email delivery, you'll need an email service.

#### Option 1: Resend (Recommended)

[Resend](https://resend.com) offers 3,000 free emails per month and excellent deliverability.

1. **Sign up** at [resend.com](https://resend.com)

2. **Create an API key** in your Resend dashboard

3. **Verify your domain** (or use Resend's testing domain for personal use)

4. **Update your `.env`:**
   ```bash
   MAIL_MAILER=resend
   MAIL_FROM_ADDRESS=hi@yourdomain.com
   MAIL_FROM_NAME="Whisper Money"
   RESEND_KEY=re_your_api_key_here
   ```

5. **Restart services:**
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml restart
   ```

#### Option 2: SMTP (Gmail, Mailgun, etc.)

For generic SMTP servers:

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Whisper Money"
```

**Gmail Note:** You'll need to create an [App Password](https://support.google.com/accounts/answer/185833) (not your regular password).

#### Testing Email Configuration

After configuration, test email delivery:

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan tinker
```

Then in the Tinker console:
```php
Mail::raw('Test email', function ($message) {
    $message->to('your-email@example.com')->subject('Test');
});
```

Check your inbox for the test email.

### Stripe Subscriptions

Enable premium subscriptions powered by Stripe.

#### 1. Create Stripe Account

Sign up at [stripe.com](https://stripe.com)

#### 2. Create Products

In your Stripe Dashboard:
1. Go to **Products** → **Add Product**
2. Create your subscription tiers (e.g., "Pro - Monthly", "Pro - Yearly")
3. Note the **Price IDs** (e.g., `price_1ABC...`)

#### 3. Get API Keys

In Stripe Dashboard → **Developers** → **API keys**:
- Copy your **Publishable key** (starts with `pk_`)
- Copy your **Secret key** (starts with `sk_`)

#### 4. Configure Webhook

1. Go to **Developers** → **Webhooks** → **Add endpoint**
2. Set endpoint URL: `https://yourdomain.com/stripe/webhook`
3. Select events to listen for (Whisper Money handles all events automatically)
4. Copy the **Webhook signing secret** (starts with `whsec_`)

#### 5. Update Configuration

Edit `.env`:

```bash
SUBSCRIPTIONS_ENABLED=true
STRIPE_KEY=pk_live_your_publishable_key
STRIPE_SECRET=sk_live_your_secret_key
STRIPE_WEBHOOK_SECRET=whsec_your_webhook_secret
```

**For testing**, use Stripe test mode keys (start with `pk_test_` and `sk_test_`).

#### 6. Restart Services

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart
```

#### 7. Test Subscription Flow

1. Visit your app
2. Go to Settings → Subscription
3. Choose a plan
4. Use Stripe test card: `4242 4242 4242 4242`
5. Verify subscription activates

### Error Tracking (Sentry)

Monitor errors and performance with [Sentry](https://sentry.io).

#### 1. Create Sentry Account

Sign up at [sentry.io](https://sentry.io) (free tier available)

#### 2. Create Project

1. Create new project
2. Choose **Laravel** platform
3. Copy your **DSN** (looks like `https://abc123@o123.ingest.sentry.io/456`)

#### 3. Configure Whisper Money

Edit `.env`:

```bash
SENTRY_LARAVEL_DSN=https://your-sentry-dsn
SENTRY_TRACES_SAMPLE_RATE=0.1
```

`SENTRY_TRACES_SAMPLE_RATE` controls performance monitoring (0.1 = 10% of requests).

#### 4. Restart Services

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart
```

#### 5. Test Error Tracking

Trigger a test error:
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan sentry:test
```

Check your Sentry dashboard for the test error.

---

## Management

### Starting & Stopping

**Start services:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml up -d
```

**Stop services:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml down
```

**Stop and remove volumes (⚠️ deletes all data):**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml down -v
```

**Restart services:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart
```

**Restart specific service:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart app
```

### Viewing Logs

**All services:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml logs -f
```

**Specific service:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml logs -f app
```

**Last 100 lines:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml logs --tail=100 app
```

**Application logs (Laravel):**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app tail -f storage/logs/laravel.log
```

### Database Access

**MySQL command line:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec mysql mysql -u whisper_money -p whisper_money
```

Enter the password from `~/.whisper-money-credentials`.

**Run SQL query:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec mysql mysql -u whisper_money -p whisper_money -e "SELECT COUNT(*) FROM users;"
```

**Database dump:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec mysql mysqldump -u whisper_money -p whisper_money > backup.sql
```

### File Access

**Access app container shell:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app bash
```

**Copy file from container:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml cp app:/app/storage/logs/laravel.log ./laravel.log
```

**Copy file to container:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml cp ./custom-config.php app:/app/config/custom-config.php
```

---

## Maintenance

### Backups

#### Automated Backups

Create a backup script and schedule it with cron:

```bash
# Edit crontab
crontab -e

# Add daily backup at 2 AM
0 2 * * * curl -fsSL https://whisper.money/backup.sh | bash

# Or with local script
0 2 * * * bash ~/whisper-money/backup.sh
```

#### Manual Backup

```bash
curl -fsSL https://whisper.money/backup.sh | bash
```

Backups are saved to `~/whisper-money-backups/backup-TIMESTAMP.tar.gz`

#### Backup to Cloud Storage

**Sync backups to S3:**
```bash
# Install AWS CLI
pip install awscli

# Configure AWS credentials
aws configure

# Sync backups
aws s3 sync ~/whisper-money-backups/ s3://your-bucket/whisper-money-backups/
```

**Or use rclone for any cloud provider:**
```bash
# Install rclone
curl https://rclone.org/install.sh | bash

# Configure cloud storage
rclone config

# Sync backups
rclone sync ~/whisper-money-backups/ remote:whisper-money-backups/
```

#### Backup Retention

Clean up old backups automatically:

```bash
# Keep last 30 days of backups
find ~/whisper-money-backups -name "backup-*.tar.gz" -mtime +30 -delete
```

Add to crontab for automatic cleanup:
```bash
0 3 * * * find ~/whisper-money-backups -name "backup-*.tar.gz" -mtime +30 -delete
```

### Updates

#### Update to Latest Version

```bash
curl -fsSL https://whisper.money/update.sh | bash
```

The update process:
1. Detects your installation
2. Backs up your APP_KEY (preserves sessions)
3. Pulls latest Docker images
4. Recreates containers
5. Waits for health check
6. Displays success message

Your data is preserved during updates.

#### Check Current Version

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan --version
```

#### Rollback to Previous Version

If an update causes issues, rollback:

1. **Find previous image:**
   ```bash
   docker images ghcr.io/whisper-money/whisper-money
   ```

2. **Edit docker-compose.production.yml:**
   ```bash
   nano ~/whisper-money/docker-compose.production.yml
   ```

3. **Change image tag:**
   ```yaml
   services:
     app:
       image: ghcr.io/whisper-money/whisper-money:PREVIOUS_SHA
   ```

4. **Recreate containers:**
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml up -d --force-recreate
   ```

### Monitoring

#### Health Check Endpoint

Whisper Money includes a `/up` endpoint for monitoring:

```bash
curl http://localhost:8080/up
```

Returns HTTP 200 if app is healthy.

#### Resource Usage

**Check Docker resource usage:**
```bash
docker stats
```

**Check disk usage:**
```bash
docker system df
```

**Check volume sizes:**
```bash
docker system df -v
```

#### Application Metrics

**Queue status:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan queue:work --once
```

**Cache statistics:**
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
```

---

## Security

### HTTPS Setup

Running Whisper Money over HTTPS is highly recommended for production use.

#### Option 1: Caddy (Easiest)

Caddy automatically handles HTTPS certificates.

1. **Install Caddy:**
   ```bash
   # macOS
   brew install caddy

   # Linux
   sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
   sudo apt update
   sudo apt install caddy
   ```

2. **Create Caddyfile:**
   ```bash
   sudo nano /etc/caddy/Caddyfile
   ```

3. **Configure reverse proxy:**
   ```
   yourdomain.com {
       reverse_proxy localhost:8080
   }
   ```

4. **Restart Caddy:**
   ```bash
   sudo systemctl restart caddy
   ```

Caddy automatically obtains and renews Let's Encrypt certificates!

#### Option 2: Nginx + Certbot

1. **Install Nginx:**
   ```bash
   sudo apt install nginx
   ```

2. **Create Nginx config:**
   ```bash
   sudo nano /etc/nginx/sites-available/whisper-money
   ```

3. **Add configuration:**
   ```nginx
   server {
       listen 80;
       server_name yourdomain.com;

       location / {
           proxy_pass http://localhost:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```

4. **Enable site:**
   ```bash
   sudo ln -s /etc/nginx/sites-available/whisper-money /etc/nginx/sites-enabled/
   sudo nginx -t
   sudo systemctl reload nginx
   ```

5. **Install Certbot:**
   ```bash
   sudo apt install certbot python3-certbot-nginx
   ```

6. **Obtain certificate:**
   ```bash
   sudo certbot --nginx -d yourdomain.com
   ```

Certbot automatically configures HTTPS and sets up renewal.

### Firewall Configuration

#### UFW (Linux)

```bash
# Enable firewall
sudo ufw enable

# Allow SSH
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Block direct access to app port
sudo ufw deny 8080/tcp

# Check status
sudo ufw status
```

#### macOS Firewall

1. System Preferences → Security & Privacy → Firewall
2. Turn on Firewall
3. Click Firewall Options
4. Allow incoming connections for Docker

### Credential Rotation

#### Change Database Password

1. **Generate new password:**
   ```bash
   openssl rand -base64 32
   ```

2. **Update in database:**
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml exec mysql mysql -u root -p
   ```

   ```sql
   ALTER USER 'whisper_money'@'%' IDENTIFIED BY 'new_password_here';
   FLUSH PRIVILEGES;
   ```

3. **Update .env:**
   ```bash
   nano ~/whisper-money/.env
   ```
   Change `DB_PASSWORD=new_password_here`

4. **Restart app:**
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml restart app
   ```

#### Rotate APP_KEY

⚠️ **Warning:** This will invalidate all user sessions and encrypted data!

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan key:generate
```

---

## Performance

### Queue Workers

Whisper Money uses queue workers to process background jobs (emails, notifications, etc.).

#### Check Queue Status

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan queue:monitor
```

#### Adjust Worker Count

Edit `docker/supervisor/supervisord.conf` to change worker count:

```ini
[program:queue-worker]
numprocs=4  ; Change from 2 to 4 for more throughput
```

Then rebuild and restart:
```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml up -d --build
```

### Caching

Whisper Money uses Redis for caching by default.

#### Clear Cache

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan cache:clear
```

#### Clear All Caches

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan optimize:clear
```

#### Rebuild Cache

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml exec app php artisan optimize
```

---

## Advanced

### Custom Domain

To use a custom domain:

1. **Point DNS** to your server's IP address
2. **Update APP_URL** in `.env`:
   ```bash
   APP_URL=https://yourdomain.com
   ```
3. **Setup HTTPS** (see Security section above)
4. **Restart services**

### Reverse Proxy

If running behind a reverse proxy, ensure these headers are set:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

### Resource Limits

Limit Docker container resources:

Edit `docker-compose.production.yml`:

```yaml
services:
  app:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          cpus: '1'
          memory: 1G
```

Restart services after changes.

---

## Need Help?

- **Documentation**: [Installation Guide](./INSTALLATION.md)
- **GitHub**: [Report Issues](https://github.com/whisper-money/whisper-money/issues)
- **Discord**: [Community Support](https://discord.gg/whisper-money)
- **Email**: [support@whisper.money](mailto:support@whisper.money)
