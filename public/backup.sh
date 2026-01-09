#!/bin/bash
#
# Whisper Money - Backup Script
# https://whisper.money
#
# This script creates a backup of your Whisper Money database and storage.
#
# Usage:
#   curl -fsSL https://whisper.money/backup.sh | bash
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
BACKUP_DIR="$HOME/whisper-money-backups"
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
        exit 1
    fi

    if [ ! -f "$INSTALL_DIR/docker-compose.production.yml" ]; then
        print_error "docker-compose.production.yml not found in $INSTALL_DIR"
        exit 1
    fi

    print_success "Installation found at $INSTALL_DIR"
}

# Check if containers are running
check_containers_running() {
    cd "$INSTALL_DIR"

    if ! docker compose -f docker-compose.production.yml ps | grep -q "Up"; then
        print_error "Whisper Money containers are not running."
        echo ""
        echo "Please start Whisper Money before creating a backup:"
        echo "  cd $INSTALL_DIR && docker compose -f docker-compose.production.yml up -d"
        exit 1
    fi

    print_success "Containers are running"
}

# Create backup directory
create_backup_directory() {
    mkdir -p "$BACKUP_DIR"
    print_success "Backup directory: $BACKUP_DIR"
}

# Generate backup filename
generate_backup_filename() {
    local timestamp=$(date +"%Y%m%d-%H%M%S")
    echo "backup-$timestamp.tar.gz"
}

# Backup database
backup_database() {
    local backup_name=$1
    local temp_dir="$BACKUP_DIR/temp-$backup_name"

    print_step "💾 Backing up database..."

    mkdir -p "$temp_dir"

    cd "$INSTALL_DIR"

    # Get database credentials from .env
    DB_DATABASE=$(grep "^DB_DATABASE=" .env | cut -d'=' -f2)
    DB_USERNAME=$(grep "^DB_USERNAME=" .env | cut -d'=' -f2)
    DB_PASSWORD=$(grep "^DB_PASSWORD=" .env | cut -d'=' -f2)

    # Dump database
    docker compose -f docker-compose.production.yml exec -T mysql \
        mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
        > "$temp_dir/database.sql"

    if [ ! -s "$temp_dir/database.sql" ]; then
        print_error "Database backup failed (empty file)"
        rm -rf "$temp_dir"
        exit 1
    fi

    print_success "Database backed up ($(du -h "$temp_dir/database.sql" | cut -f1))"
}

# Backup storage volume
backup_storage() {
    local backup_name=$1
    local temp_dir="$BACKUP_DIR/temp-$backup_name"

    print_step "💾 Backing up storage..."

    cd "$INSTALL_DIR"

    # Create tar of storage volume
    docker compose -f docker-compose.production.yml exec -T app \
        tar -czf - -C /app/storage . > "$temp_dir/storage.tar.gz"

    if [ ! -s "$temp_dir/storage.tar.gz" ]; then
        print_error "Storage backup failed (empty file)"
        rm -rf "$temp_dir"
        exit 1
    fi

    print_success "Storage backed up ($(du -h "$temp_dir/storage.tar.gz" | cut -f1))"
}

# Backup .env file
backup_env() {
    local backup_name=$1
    local temp_dir="$BACKUP_DIR/temp-$backup_name"

    print_step "💾 Backing up configuration..."

    cd "$INSTALL_DIR"
    cp .env "$temp_dir/.env"

    print_success "Configuration backed up"
}

# Create manifest
create_manifest() {
    local backup_name=$1
    local temp_dir="$BACKUP_DIR/temp-$backup_name"

    print_step "📝 Creating manifest..."

    cd "$INSTALL_DIR"

    # Get version information
    local image_version=$(docker compose -f docker-compose.production.yml images app --format json | grep -o '"ID":"[^"]*"' | cut -d'"' -f4 | head -1)

    cat > "$temp_dir/manifest.json" << EOF
{
  "backup_date": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "backup_name": "$backup_name",
  "image_version": "$image_version",
  "files": {
    "database": "database.sql",
    "storage": "storage.tar.gz",
    "env": ".env"
  }
}
EOF

    print_success "Manifest created"
}

# Compress backup
compress_backup() {
    local backup_name=$1
    local temp_dir="$BACKUP_DIR/temp-$backup_name"
    local backup_file="$BACKUP_DIR/$backup_name"

    print_step "🗜️  Compressing backup..."

    cd "$BACKUP_DIR"
    tar -czf "$backup_file" -C "$temp_dir" .

    # Remove temp directory
    rm -rf "$temp_dir"

    print_success "Backup compressed"
}

# Show success message
show_success_message() {
    local backup_file=$1
    local backup_size=$(du -h "$backup_file" | cut -f1)

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}${BOLD}🎉 Backup created successfully!${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "${BOLD}Backup file:${NC}  $backup_file"
    echo -e "${BOLD}Size:${NC}         $backup_size"
    echo ""
    echo -e "${BOLD}To restore this backup:${NC}"
    echo "  curl -fsSL https://whisper.money/restore.sh | bash -s $backup_file"
    echo ""
    echo -e "${BOLD}Keep this backup safe!${NC}"
    echo "  - Store in a secure location"
    echo "  - Consider uploading to cloud storage"
    echo "  - Test restoration periodically"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
}

# Main backup flow
main() {
    echo ""
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}${BOLD}   Whisper Money - Backup Script${NC}"
    echo -e "${BLUE}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    print_step "🔍 Detecting installation..."
    detect_installation
    check_containers_running
    create_backup_directory

    BACKUP_NAME=$(generate_backup_filename)
    TEMP_DIR="$BACKUP_DIR/temp-$BACKUP_NAME"

    backup_database "$BACKUP_NAME"
    backup_storage "$BACKUP_NAME"
    backup_env "$BACKUP_NAME"
    create_manifest "$BACKUP_NAME"
    compress_backup "$BACKUP_NAME"

    show_success_message "$BACKUP_DIR/$BACKUP_NAME"
}

# Run main function
main "$@"
