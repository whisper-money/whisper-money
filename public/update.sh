#!/bin/bash
#
# Whisper Money - Update Script
# https://whisper.money
#
# This script updates an existing Whisper Money installation to the latest version.
#
# Usage:
#   curl -fsSL https://whisper.money/update.sh | bash
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

# Detect installation directory
detect_installation() {
    if [ -f "$CREDENTIALS_FILE" ]; then
        DETECTED_DIR=$(grep "Installation Directory:" "$CREDENTIALS_FILE" | cut -d' ' -f3)
        if [ -n "$DETECTED_DIR" ] && [ -d "$DETECTED_DIR" ]; then
            INSTALL_DIR="$DETECTED_DIR"
        fi
    fi

    if [ ! -d "$INSTALL_DIR" ]; then
        print_error "Whisper Money installation not found."
        echo ""
        echo "Expected location: $INSTALL_DIR"
        echo ""
        echo "To install Whisper Money, run:"
        echo "  curl -fsSL https://whisper.money/install.sh | bash"
        exit 1
    fi

    if [ ! -f "$INSTALL_DIR/docker-compose.production.yml" ]; then
        print_error "docker-compose.production.yml not found in $INSTALL_DIR"
        exit 1
    fi

    print_success "Installation found at $INSTALL_DIR"
}

# Check if Docker is running
check_docker_running() {
    if ! docker info &> /dev/null; then
        print_error "Docker daemon is not running."
        echo ""
        echo "Please start Docker and try again."
        exit 1
    fi
}

# Get current version
get_current_version() {
    cd "$INSTALL_DIR"
    CURRENT_IMAGE=$(docker compose -f docker-compose.production.yml images app --quiet | head -1)

    if [ -n "$CURRENT_IMAGE" ]; then
        print_info "Current image: $CURRENT_IMAGE"
    else
        print_warning "Could not determine current version"
    fi
}

# Backup APP_KEY to preserve sessions
backup_app_key() {
    if [ -f "$INSTALL_DIR/.env" ]; then
        APP_KEY=$(grep "^APP_KEY=" "$INSTALL_DIR/.env" | cut -d'=' -f2)
        if [ -n "$APP_KEY" ]; then
            print_success "APP_KEY backed up"
            export BACKUP_APP_KEY="$APP_KEY"
        fi
    fi
}

# Pull latest images
pull_latest_images() {
    print_step "⬇️  Pulling latest images..."

    cd "$INSTALL_DIR"

    if docker compose -f docker-compose.production.yml pull 2>&1 | grep -q "denied"; then
        print_error "Failed to pull Docker images (access denied or rate limited)"
        echo ""
        echo "Please try again later."
        exit 1
    fi

    print_success "Latest images pulled"
}

# Recreate containers
recreate_containers() {
    print_step "🔄 Recreating containers..."

    cd "$INSTALL_DIR"

    # Stop and remove containers, but preserve volumes
    docker compose -f docker-compose.production.yml down

    # Start with new images
    docker compose -f docker-compose.production.yml up -d --force-recreate

    print_success "Containers recreated"
}

# Wait for health check
wait_for_health() {
    local max_wait=120
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

    print_step "⏳ Waiting for services to be ready..."
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
    echo "To view logs, run:"
    echo "  cd $INSTALL_DIR && docker compose -f docker-compose.production.yml logs"
    exit 1
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
    echo -e "${GREEN}${BOLD}🎉 Whisper Money has been updated!${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "${BOLD}Access your app:${NC}    http://localhost:$port"
    echo ""
    echo "Your data has been preserved."
    echo ""
    echo "What's new: https://github.com/whisper-money/whisper-money/releases"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
}

# Main update flow
main() {
    echo ""
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}${BOLD}   Whisper Money - Update Script${NC}"
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    print_step "🔍 Detecting installation..."
    detect_installation
    check_docker_running
    get_current_version
    backup_app_key

    pull_latest_images
    recreate_containers
    wait_for_health
    show_success_message
}

# Run main function
main "$@"
