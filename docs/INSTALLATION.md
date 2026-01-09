# Installing Whisper Money

This guide will help you install Whisper Money on your local machine for personal use.

## Quick Install (Recommended)

The easiest way to install Whisper Money is with our single-command installer:

```bash
curl -fsSL https://whisper.money/install.sh | bash
```

This command will:
- Check that Docker is installed and running
- Find an available port (default: 8080)
- Generate secure database credentials
- Download and start Whisper Money
- Complete in 60-120 seconds

After installation completes, visit `http://localhost:8080` and create your account!

## What Gets Installed

The installation creates:

- **Installation directory**: `~/whisper-money`
  - Contains Docker Compose configuration
  - Contains environment configuration (`.env`)

- **Docker containers**:
  - **App container**: Nginx, PHP, Redis, Memcached, Queue workers, SSR server
  - **MySQL container**: Database server

- **Docker volumes** (persistent data):
  - `whisper-storage`: Application files, logs, user uploads
  - `whisper-mysql`: Database files

- **Credentials file**: `~/.whisper-money-credentials`
  - Contains database passwords (keep secure!)

## Prerequisites

### Required

- **Docker Desktop 20.10+** (includes Docker Compose v2)
  - macOS: [Download Docker Desktop](https://docs.docker.com/desktop/install/mac-install/)
  - Windows: [Download Docker Desktop](https://docs.docker.com/desktop/install/windows-install/)
  - Linux: [Install Docker Engine](https://docs.docker.com/engine/install/) + [Docker Compose Plugin](https://docs.docker.com/compose/install/linux/)

### System Requirements

- **RAM**: 2GB minimum, 4GB recommended
- **Disk Space**: 2GB minimum for application + database
- **Port**: 8080 (or next available port 8081-8090)

## Step-by-Step Installation

If you prefer to understand each step, here's what the installer does:

### 1. Install Docker

First, ensure Docker is installed and running on your system.

**macOS/Windows:**
- Download and install Docker Desktop
- Launch Docker Desktop
- Wait for "Docker Desktop is running" status

**Linux:**
```bash
# Install Docker Engine (Ubuntu/Debian example)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Start Docker
sudo systemctl start docker
sudo systemctl enable docker

# Add your user to docker group (optional, avoids sudo)
sudo usermod -aG docker $USER
newgrp docker
```

### 2. Run the Installer

```bash
curl -fsSL https://whisper.money/install.sh | bash
```

The installer will:
1. ✓ Check Docker and Docker Compose
2. ✓ Verify system requirements
3. ✓ Generate secure credentials
4. ✓ Find available port
5. ✓ Create installation directory
6. ✓ Download Docker Compose configuration
7. ✓ Pull Docker images (~500MB download)
8. ✓ Start services
9. ✓ Wait for health check
10. ✓ Display success message

### 3. Create Your Account

Once installation completes:

1. Open your browser
2. Visit `http://localhost:8080` (or the port shown in the success message)
3. Click **"Sign Up"**
4. Enter your email and password
5. Create your encryption key (keep this safe!)
6. Start using Whisper Money!

## Verification

### Check Services are Running

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml ps
```

You should see:
```
NAME                        STATUS              PORTS
whisper-money-app-1         Up (healthy)        0.0.0.0:8080->80/tcp
whisper-money-mysql-1       Up (healthy)        3306/tcp
```

### Check Health Endpoint

```bash
curl http://localhost:8080/up
```

Should return HTTP 200 with empty response (this is normal).

### Access the App

Visit `http://localhost:8080` in your browser. You should see the Whisper Money landing page.

## Management Commands

### View Logs

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml logs -f
```

Press `Ctrl+C` to stop viewing logs.

### Stop Whisper Money

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml down
```

Your data is preserved in Docker volumes.

### Start Whisper Money

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml up -d
```

### Restart Services

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml restart
```

### Update to Latest Version

```bash
curl -fsSL https://whisper.money/update.sh | bash
```

### Create Backup

```bash
curl -fsSL https://whisper.money/backup.sh | bash
```

Backups are saved to `~/whisper-money-backups/`

### Restore from Backup

```bash
curl -fsSL https://whisper.money/restore.sh | bash -s ~/whisper-money-backups/backup-TIMESTAMP.tar.gz
```

## Configuration

### Changing the Port

If you need to use a different port:

1. Edit `.env` in the installation directory:
   ```bash
   nano ~/whisper-money/.env
   ```

2. Change `APP_URL`:
   ```
   APP_URL=http://localhost:9000
   ```

3. Edit `docker-compose.production.yml`:
   ```bash
   nano ~/whisper-money/docker-compose.production.yml
   ```

4. Change the port mapping under `app` service:
   ```yaml
   ports:
     - "9000:80"
   ```

5. Restart services:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml down
   docker compose -f docker-compose.production.yml up -d
   ```

### Enabling Email (for password resets)

By default, emails are logged to files. To enable real email delivery, see [Self-Hosting Guide - Email Setup](./SELF-HOSTING.md#email-setup).

### Enabling Stripe Subscriptions

To enable premium subscriptions, see [Self-Hosting Guide - Stripe Setup](./SELF-HOSTING.md#stripe-subscriptions).

## Troubleshooting

### Port Already in Use

**Problem:** Installation fails because port 8080 is in use.

**Solution:** The installer automatically tries ports 8081-8090. If all are in use, manually specify a different port (see "Changing the Port" above), or stop the service using port 8080:

```bash
# Find what's using port 8080
lsof -i :8080

# Or on Linux
sudo netstat -tulpn | grep 8080
```

### Docker Not Running

**Problem:** Error: "Cannot connect to the Docker daemon"

**Solution:**
- macOS/Windows: Open Docker Desktop application
- Linux: `sudo systemctl start docker`

### Permission Denied

**Problem:** Permission denied when running Docker commands (Linux)

**Solution:** Add your user to the docker group:
```bash
sudo usermod -aG docker $USER
newgrp docker
```

Then run the installer again.

### Services Won't Start

**Problem:** Containers exit immediately or won't start

**Solution:**
1. Check logs:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml logs
   ```

2. Check available resources (RAM, disk space):
   ```bash
   docker system df
   ```

3. Try recreating containers:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml down
   docker compose -f docker-compose.production.yml up -d
   ```

### Can't Access App in Browser

**Problem:** Browser shows "Cannot connect" or "Connection refused"

**Solution:**
1. Verify services are running:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml ps
   ```

2. Check the correct port (look at APP_URL in `.env`)

3. Try accessing `http://127.0.0.1:8080` instead of `localhost`

4. Check firewall settings (Windows/Linux)

### Database Connection Failed

**Problem:** Error about database connection in logs

**Solution:**
1. Wait 30 seconds (MySQL takes time to initialize on first start)

2. Restart services:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml restart
   ```

3. If still failing, check database logs:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml logs mysql
   ```

### Installation Times Out

**Problem:** Installation times out waiting for health check

**Solution:**
1. Check if services are running:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml ps
   ```

2. Check logs for errors:
   ```bash
   cd ~/whisper-money
   docker compose -f docker-compose.production.yml logs app
   ```

3. Try accessing the app anyway - it may be working despite timeout

### Disk Space Issues

**Problem:** Running out of disk space

**Solution:**
1. Clean up old Docker images:
   ```bash
   docker system prune -a
   ```

2. Remove old backups:
   ```bash
   rm ~/whisper-money-backups/backup-OLDDATE-*.tar.gz
   ```

## Uninstallation

To completely remove Whisper Money:

### 1. Stop and Remove Containers

```bash
cd ~/whisper-money
docker compose -f docker-compose.production.yml down -v
```

The `-v` flag removes volumes (deletes all data).

### 2. Remove Installation Directory

```bash
rm -rf ~/whisper-money
```

### 3. Remove Credentials File

```bash
rm ~/.whisper-money-credentials
```

### 4. Remove Backups (optional)

```bash
rm -rf ~/whisper-money-backups
```

### 5. Remove Docker Images (optional)

```bash
docker rmi ghcr.io/whisper-money/whisper-money:latest
docker rmi mysql:8.0
```

## Next Steps

- **[Self-Hosting Guide](./SELF-HOSTING.md)** - Configure email, Stripe, HTTPS, and more
- **[GitHub Repository](https://github.com/whisper-money/whisper-money)** - Source code and issue tracking
- **[Discord Community](https://discord.gg/whisper-money)** - Get help and share feedback

## Need Help?

- **GitHub Issues**: [Report a bug](https://github.com/whisper-money/whisper-money/issues)
- **Discord**: [Join our community](https://discord.gg/whisper-money)
- **Email**: [support@whisper.money](mailto:support@whisper.money)
