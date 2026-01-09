#!/bin/bash
#
# Whisper Money - Restore Script
# https://whisper.money
#
# This script restores a Whisper Money backup.
#
# Usage:
#   curl -fsSL https://whisper.money/restore.sh | bash -s /path/to/backup.tar.gz
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

# Check if backup file is provided
check_backup_file() {
    if [ -z "$1" ]; then
        print_error "No backup file specified."
        echo ""
        echo "Usage:"
        echo "  curl -fsSL https://whisper.money/restore.sh | bash -s /path/to/backup.tar.gz"
        echo ""
        echo "Or download and run locally:"
        echo "  bash restore.sh /path/to/backup.tar.gz"
        exit 1
    fi

    BACKUP_FILE="$1"

    if [ ! -f "$BACKUP_FILE" ]; then
        print_error "Backup file not found: $BACKUP_FILE"
        exit 1
    fi

    print_success "Backup file found: $BACKUP_FILE"
}

# Validate backup archive
validate_backup() {
    print_step "🔍 Validating backup..."

    # Check if tar file is valid
    if ! tar -tzf "$BACKUP_FILE" > /dev/null 2>&1; then
        print_error "Invalid or corrupted backup file"
        exit 1
    fi

    # Check for required files
    if ! tar -tzf "$BACKUP_FILE" | grep -q "database.sql"; then
        print_error "Backup missing database.sql"
        exit 1
    fi

    if ! tar -tzf "$BACKUP_FILE" | grep -q "storage.tar.gz"; then
        print_error "Backup missing storage.tar.gz"
        exit 1
    fi

    if ! tar -tzf "$BACKUP_FILE" | grep -q ".env"; then
        print_error "Backup missing .env file"
        exit 1
    fi

    print_success "Backup is valid"
}

# Extract backup
extract_backup() {
    print_step "📦 Extracting backup..."

    TEMP_DIR=$(mktemp -d)
    tar -xzf "$BACKUP_FILE" -C "$TEMP_DIR"

    if [ -f "$TEMP_DIR/manifest.json" ]; then
        print_info "Backup manifest:"
        cat "$TEMP_DIR/manifest.json"
    fi

    print_success "Backup extracted to $TEMP_DIR"
}

# Confirm restoration
confirm_restoration() {
    echo ""
    print_warning "This will replace your current Whisper Money data!"
    echo ""
    echo "Current installation: $INSTALL_DIR"
    echo "Backup file: $BACKUP_FILE"
    echo ""
    read -p "Are you sure you want to continue? [y/N]: " confirm

    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Restoration cancelled."
        rm -rf "$TEMP_DIR"
        exit 0
    fi
}

# Check if installation exists
check_installation() {
    if [ -f "$CREDENTIALS_FILE" ]; then
        DETECTED_DIR=$(grep "Installation Directory:" "$CREDENTIALS_FILE" | cut -d' ' -f3)
        if [ -n "$DETECTED_DIR" ] && [ -d "$DETECTED_DIR" ]; then
            INSTALL_DIR="$DETECTED_DIR"
        fi
    fi

    if [ ! -d "$INSTALL_DIR" ]; then
        print_warning "No existing installation found."
        print_info "Creating new installation at $INSTALL_DIR"
        mkdir -p "$INSTALL_DIR"
    fi

    print_success "Installation directory: $INSTALL_DIR"
}

# Stop services
stop_services() {
    print_step "🛑 Stopping services..."

    cd "$INSTALL_DIR"

    if [ -f "docker-compose.production.yml" ]; then
        docker compose -f docker-compose.production.yml down || true
        print_success "Services stopped"
    else
        print_info "No running services to stop"
    fi
}

# Restore configuration
restore_configuration() {
    print_step "⚙️  Restoring configuration..."

    cp "$TEMP_DIR/.env" "$INSTALL_DIR/.env"
    print_success "Configuration restored"
}

# Download docker-compose if missing
download_docker_compose() {
    if [ ! -f "$INSTALL_DIR/docker-compose.production.yml" ]; then
        print_step "📦 Downloading docker-compose.production.yml..."

        COMPOSE_URL="https://raw.githubusercontent.com/whisper-money/whisper-money/main/docker-compose.production.yml"

        if command -v curl &> /dev/null; then
            curl -fsSL "$COMPOSE_URL" -o "$INSTALL_DIR/docker-compose.production.yml"
        elif command -v wget &> /dev/null; then
            wget -q "$COMPOSE_URL" -O "$INSTALL_DIR/docker-compose.production.yml"
        fi

        print_success "docker-compose.production.yml downloaded"
    fi
}

# Start services
start_services() {
    print_step "🚀 Starting services..."

    cd "$INSTALL_DIR"
    docker compose -f docker-compose.production.yml up -d

    print_success "Services started"
}

# Wait for services to be ready
wait_for_services() {
    print_step "⏳ Waiting for services to be ready..."

    local max_wait=60
    local wait_time=0

    cd "$INSTALL_DIR"

    while [ $wait_time -lt $max_wait ]; do
        if docker compose -f docker-compose.production.yml ps | grep -q "Up"; then
            print_success "Services are running"
            sleep 5  # Extra wait for MySQL to be fully ready
            return 0
        fi

        sleep 2
        wait_time=$((wait_time + 2))
    done

    print_error "Timeout waiting for services"
    exit 1
}

# Restore database
restore_database() {
    print_step "💾 Restoring database..."

    cd "$INSTALL_DIR"

    # Get database credentials from .env
    DB_DATABASE=$(grep "^DB_DATABASE=" .env | cut -d'=' -f2)
    DB_USERNAME=$(grep "^DB_USERNAME=" .env | cut -d'=' -f2)
    DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d'=' -f2)

    # Import database
    docker compose -f docker-compose.production.yml exec -T mysql \
        mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
        < "$TEMP_DIR/database.sql"

    print_success "Database restored"
}

# Restore storage
restore_storage() {
    print_step "💾 Restoring storage..."

    cd "$INSTALL_DIR"

    # Extract storage to container
    docker compose -f docker-compose.production.yml exec -T app \
        bash -c "cd /app/storage && tar -xzf -" < "$TEMP_DIR/storage.tar.gz"

    print_success "Storage restored"
}

# Restart services
restart_services() {
    print_step "🔄 Restarting services..."

    cd "$INSTALL_DIR"
    docker compose -f docker-compose.production.yml restart

    print_success "Services restarted"
}

# Wait for health check
wait_for_health() {
    local max_wait=60
    local wait_time=0

    # Get port from .env
    local port=8080
    if [ -f "$INSTALL_DIR/.env" ]; then
        local app_url=$(grep "^APP_URL=" "$INSTALL_DIR/.env" | cut -d'=' -f2)
        if [[ $app_url =~ :([0-9]+) ]]; then
            port="${BASH_REMATCH[1]}"
        fi
    fi

    local health_url="http://localhost:$port/up"

    print_step "⏳ Waiting for app to be ready..."
    echo ""

    while [ $wait_time -lt $max_wait ]; do
        if curl -sf "$health_url" > /dev/null 2>&1; then
            echo ""
            print_success "App is ready!"
            return 0
        fi

        printf "\r  Waiting... (%ds/%ds)" $wait_time $max_wait
        sleep 2
        wait_time=$((wait_time + 2))
    done

    echo ""
    print_warning "Health check timeout, but restoration may have succeeded"
}

# Cleanup
cleanup() {
    if [ -n "$TEMP_DIR" ] && [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
}

# Show success message
show_success_message() {
    local port=8080
    if [ -f "$INSTALL_DIR/.env" ]; then
        local app_url=$(grep "^APP_URL=" "$INSTALL_DIR/.env" | cut -d'=' -f2)
        if [[ $app_url =~ :([0-9]+) ]]; then
            port="${BASH_REMATCH[1]}"
        fi
    fi

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}${BOLD}🎉 Restoration complete!${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "${BOLD}Access your app:${NC}    http://localhost:$port"
    echo ""
    echo "Your backup has been successfully restored."
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
}

# Main restoration flow
main() {
    echo ""
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}${BOLD}   Whisper Money - Restore Script${NC}"
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    # Set trap to cleanup on exit
    trap cleanup EXIT

    check_backup_file "$1"
    validate_backup
    extract_backup
    check_installation
    confirm_restoration
    stop_services
    restore_configuration
    download_docker_compose
    start_services
    wait_for_services
    restore_database
    restore_storage
    restart_services
    wait_for_health
    show_success_message
}

# Run main function
main "$@"
