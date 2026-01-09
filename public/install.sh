#!/bin/bash
#
# Whisper Money - Single-Command Installation Script
# https://whisper.money
#
# This script installs Whisper Money on your local machine with Docker.
#
# Usage:
#   curl -fsSL https://whisper.money/install.sh | bash
#
# Requirements:
#   - Docker 20.10+
#   - Docker Compose v2+
#   - 2GB disk space
#   - 2GB RAM
#

set -e  # Exit on error

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'  # No Color

# Configuration
INSTALL_DIR="$HOME/whisper-money"
CREDENTIALS_FILE="$HOME/.whisper-money-credentials"
COMPOSE_URL="https://raw.githubusercontent.com/whisper-money/whisper-money/main/docker-compose.production.yml"
DEFAULT_PORT=8080
MIN_DISK_SPACE_MB=2000

# Function to print colored output
print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠️${NC}  $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC}  $1"
}

print_step() {
    echo -e "\n${BOLD}$1${NC}"
}

# Check if Docker is installed
check_docker() {
    if ! command -v docker &> /dev/null; then
        print_error "Docker is not installed."
        echo ""
        echo "To install Docker:"
        echo "  macOS:  https://docs.docker.com/desktop/install/mac-install/"
        echo "  Linux:  https://docs.docker.com/engine/install/"
        echo "  Windows: https://docs.docker.com/desktop/install/windows-install/"
        echo ""
        echo "After installing Docker, run this script again."
        exit 1
    fi

    DOCKER_VERSION=$(docker --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
    print_success "Docker detected (version $DOCKER_VERSION)"
}

# Check if Docker Compose is installed
check_docker_compose() {
    if ! docker compose version &> /dev/null; then
        print_error "Docker Compose is not installed or not available."
        echo ""
        echo "Docker Compose v2 is required (comes with Docker Desktop)."
        echo "For Linux, install with: apt-get install docker-compose-plugin"
        echo ""
        exit 1
    fi

    COMPOSE_VERSION=$(docker compose version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
    print_success "Docker Compose detected (version $COMPOSE_VERSION)"
}

# Check if Docker daemon is running
check_docker_running() {
    if ! docker info &> /dev/null; then
        print_error "Docker daemon is not running."
        echo ""
        echo "Please start Docker:"
        echo "  macOS:  Open Docker Desktop application"
        echo "  Linux:  sudo systemctl start docker"
        echo ""
        exit 1
    fi

    print_success "Docker daemon is running"
}

# Check OS compatibility
check_os() {
    OS="$(uname -s)"
    case "$OS" in
        Linux*)     OS_TYPE="Linux";;
        Darwin*)    OS_TYPE="macOS";;
        *)          OS_TYPE="UNKNOWN";;
    esac

    if [ "$OS_TYPE" = "UNKNOWN" ]; then
        print_warning "Unknown operating system: $OS"
        print_warning "Installation may not work correctly."
    else
        print_success "Operating system: $OS_TYPE"
    fi
}

# Check available disk space
check_disk_space() {
    if [ "$OS_TYPE" = "macOS" ]; then
        AVAILABLE_SPACE=$(df -m "$HOME" | tail -1 | awk '{print $4}')
    else
        AVAILABLE_SPACE=$(df -m "$HOME" | tail -1 | awk '{print $4}')
    fi

    if [ "$AVAILABLE_SPACE" -lt "$MIN_DISK_SPACE_MB" ]; then
        print_error "Insufficient disk space."
        echo ""
        echo "Required: ${MIN_DISK_SPACE_MB}MB"
        echo "Available: ${AVAILABLE_SPACE}MB"
        echo ""
        echo "Please free up space and try again."
        exit 1
    fi

    print_success "Sufficient disk space available (${AVAILABLE_SPACE}MB)"
}

# Find an available port starting from DEFAULT_PORT
find_available_port() {
    local port=$1
    local max_port=$((port + 10))

    while [ $port -le $max_port ]; do
        if ! lsof -Pi :$port -sTCP:LISTEN -t >/dev/null 2>&1 && \
           ! nc -z localhost $port >/dev/null 2>&1; then
            echo $port
            return 0
        fi
        port=$((port + 1))
    done

    print_error "No available ports found in range $1-$max_port"
    exit 1
}

# Generate a secure random password
generate_secure_password() {
    openssl rand -base64 32 | tr -d "=+/" | cut -c1-32
}

# Create installation directory
create_installation_directory() {
    if [ -d "$INSTALL_DIR" ]; then
        if [ -f "$INSTALL_DIR/docker-compose.production.yml" ] || [ -f "$INSTALL_DIR/.env" ]; then
            print_warning "Found existing installation at $INSTALL_DIR"
            echo ""
            echo "Options:"
            echo "  1) Update to latest version (preserves data)"
            echo "  2) Cancel installation"
            echo ""
            read -p "Choose [1-2]: " choice

            case $choice in
                1)
                    print_info "Updating existing installation..."
                    return 0
                    ;;
                2)
                    echo "Installation cancelled."
                    exit 0
                    ;;
                *)
                    print_error "Invalid choice."
                    exit 1
                    ;;
            esac
        fi
    fi

    mkdir -p "$INSTALL_DIR"
    print_success "Installation directory: $INSTALL_DIR"
}

# Create .env file with smart defaults
create_env_file() {
    local port=$1
    local db_password=$2
    local mysql_root_password=$3

    cat > "$INSTALL_DIR/.env" << EOF
# Whisper Money Configuration
# Generated by installation script on $(date)

# ========================================
# CORE APPLICATION (Required)
# ========================================
APP_NAME="Whisper Money"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost:$port
APP_KEY=

# ========================================
# DATABASE (Required)
# ========================================
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=whisper_money
DB_USERNAME=whisper_money
DB_PASSWORD=$db_password

MYSQL_ROOT_PASSWORD=$mysql_root_password

# ========================================
# CACHE & SESSIONS (Required)
# ========================================
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# ========================================
# EMAIL (Local logging - no external service)
# For production email, see: docs/SELF-HOSTING.md
# ========================================
MAIL_MAILER=log
MAIL_FROM_ADDRESS=hi@localhost
MAIL_FROM_NAME="Whisper Money"

# ========================================
# FEATURES (Optional)
# ========================================
DRIP_EMAILS_ENABLED=false
SUBSCRIPTIONS_ENABLED=false
HIDE_AUTH_BUTTONS=false

# Demo account disabled - create your own account
DEMO_EMAIL=
DEMO_PASSWORD=
DEMO_ENCRYPTION_KEY=

# ========================================
# STRIPE (Optional - for subscriptions)
# See docs/SELF-HOSTING.md for setup instructions
# ========================================
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

# ========================================
# LOGGING & ERROR TRACKING
# ========================================
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

# Sentry (optional - for error tracking)
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=
EOF

    print_success ".env file created with secure credentials"
}

# Download docker-compose.production.yml
download_docker_compose() {
    print_step "📦 Downloading components..."

    if command -v curl &> /dev/null; then
        curl -fsSL "$COMPOSE_URL" -o "$INSTALL_DIR/docker-compose.production.yml"
    elif command -v wget &> /dev/null; then
        wget -q "$COMPOSE_URL" -O "$INSTALL_DIR/docker-compose.production.yml"
    else
        print_error "Neither curl nor wget found. Please install curl or wget."
        exit 1
    fi

    if [ ! -f "$INSTALL_DIR/docker-compose.production.yml" ]; then
        print_error "Failed to download docker-compose.production.yml"
        exit 1
    fi

    print_success "docker-compose.production.yml"
}

# Pull Docker images
pull_images() {
    print_step "⬇️  Pulling Docker images..."
    echo "This may take a few minutes depending on your internet connection."
    echo ""

    cd "$INSTALL_DIR"

    if docker compose -f docker-compose.production.yml pull 2>&1 | grep -q "denied"; then
        print_error "Failed to pull Docker images (access denied or rate limited)"
        echo ""
        echo "This usually means:"
        echo "  - Docker Hub rate limit reached"
        echo "  - GitHub Container Registry unavailable"
        echo ""
        echo "Please try again later or check: https://status.github.com"
        exit 1
    fi

    print_success "Docker images pulled successfully"
}

# Start services
start_services() {
    print_step "🚀 Starting services..."

    cd "$INSTALL_DIR"

    # Use custom .env file location
    docker compose -f docker-compose.production.yml --env-file .env up -d

    print_success "Services started"
}

# Wait for health check
wait_for_health() {
    local port=$1
    local max_wait=120
    local wait_time=0
    local health_url="http://localhost:$port/up"

    print_step "⏳ Waiting for services to be ready..."
    echo "This usually takes 30-60 seconds..."
    echo ""

    while [ $wait_time -lt $max_wait ]; do
        if curl -sf "$health_url" > /dev/null 2>&1; then
            echo ""
            print_success "Services are ready! (${wait_time}s)"
            return 0
        fi

        printf "\r  Waiting... (%ds/%ds)" $wait_time $max_wait
        sleep 2
        wait_time=$((wait_time + 2))
    done

    echo ""
    print_error "Timeout waiting for services to be ready"
    echo ""
    echo "The installation may have failed. To view logs, run:"
    echo "  cd $INSTALL_DIR && docker compose -f docker-compose.production.yml logs"
    echo ""
    echo "For troubleshooting, visit: https://whisper.money/docs/INSTALLATION.md"
    exit 1
}

# Save credentials to file
save_credentials() {
    local port=$1
    local db_password=$2
    local mysql_root_password=$3

    cat > "$CREDENTIALS_FILE" << EOF
# Whisper Money Installation Credentials
# Installation Date: $(date)
# Installation Directory: $INSTALL_DIR

APP_URL=http://localhost:$port
DB_PASSWORD=$db_password
MYSQL_ROOT_PASSWORD=$mysql_root_password

# IMPORTANT: Keep this file secure!
# These credentials are used by Docker Compose.
# Do not share or commit to version control.
EOF

    chmod 600 "$CREDENTIALS_FILE"
    print_success "Credentials saved to $CREDENTIALS_FILE"
}

# Show success message
show_success_message() {
    local port=$1

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}${BOLD}🎉 Whisper Money is now running!${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "${BOLD}Access your app:${NC}    http://localhost:$port"
    echo -e "${BOLD}Create account:${NC}     http://localhost:$port/register"
    echo ""
    echo -e "${BOLD}Installation:${NC}       $INSTALL_DIR"
    echo -e "${BOLD}Credentials:${NC}        $CREDENTIALS_FILE"
    echo ""
    echo -e "${BOLD}Management commands:${NC}"
    echo "  Stop:    cd ~/whisper-money && docker compose -f docker-compose.production.yml down"
    echo "  Start:   cd ~/whisper-money && docker compose -f docker-compose.production.yml up -d"
    echo "  Logs:    cd ~/whisper-money && docker compose -f docker-compose.production.yml logs -f"
    echo "  Update:  curl -fsSL https://whisper.money/update.sh | bash"
    echo ""
    echo -e "${BOLD}Next steps:${NC}"
    echo "  1. Visit http://localhost:$port"
    echo "  2. Click \"Sign Up\" to create your account"
    echo "  3. Start tracking your finances!"
    echo ""
    echo "Documentation: https://whisper.money/docs"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
}

# Main installation flow
main() {
    echo ""
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}${BOLD}   Whisper Money - Installation Script${NC}"
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    print_step "🔍 Checking requirements..."
    check_os
    check_docker
    check_docker_compose
    check_docker_running
    check_disk_space

    print_step "🔐 Generating secure credentials..."
    DB_PASSWORD=$(generate_secure_password)
    MYSQL_ROOT_PASSWORD=$(generate_secure_password)
    print_success "Database passwords generated"

    print_step "🔍 Finding available port..."
    PORT=$(find_available_port $DEFAULT_PORT)
    if [ "$PORT" -eq "$DEFAULT_PORT" ]; then
        print_success "Port $PORT is available"
    else
        print_warning "Port $DEFAULT_PORT is in use, using port $PORT instead"
    fi

    create_installation_directory
    create_env_file "$PORT" "$DB_PASSWORD" "$MYSQL_ROOT_PASSWORD"
    download_docker_compose
    pull_images
    start_services
    wait_for_health "$PORT"
    save_credentials "$PORT" "$DB_PASSWORD" "$MYSQL_ROOT_PASSWORD"
    show_success_message "$PORT"
}

# Run main function
main "$@"
