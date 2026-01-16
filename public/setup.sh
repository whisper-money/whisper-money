#!/bin/bash

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Repository URL
REPO_URL="https://github.com/whisper-money/whisper-money.git"
REPO_DIR="whisper-money"

# Check if we're in a git repository or if the repo directory exists
check_and_setup_repo() {
    # Check if we're already in the whisper-money git repo
    if [ -d ".git" ] && git remote get-url origin 2>/dev/null | grep -q "whisper-money"; then
        echo -e "${GREEN}✓ Already in Whisper Money repository${NC}"
        return 0
    fi

    # Check if whisper-money directory exists in current directory
    if [ -d "$REPO_DIR" ] && [ -d "$REPO_DIR/.git" ]; then
        echo -e "${GREEN}✓ Repository found in ${REPO_DIR}${NC}"
        echo -e "${YELLOW}Changing to ${REPO_DIR} directory...${NC}"
        cd "$REPO_DIR"
        # Re-execute the script from the new directory with original arguments
        exec "./whisper-money.sh" "$@"
    fi

    # Not in repo and repo directory doesn't exist - prompt to clone
    print_header
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Repository Setup${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${BLUE}Whisper Money repository not found in the current directory.${NC}"
    echo ""
    echo -e "${YELLOW}We need to clone the repository to continue.${NC}"
    echo ""
    echo -e "${BLUE}What we'll do:${NC}"
    echo -e "  • Clone the repository into: ${GREEN}${REPO_DIR}${NC}"
    echo -e "  • Change into that directory"
    echo -e "  • Continue with installation"
    echo ""

    # Check if git is available
    if ! command_exists git; then
        echo -e "${RED}Git is not installed. Please install Git first:${NC}"
        if [[ "$OSTYPE" == "darwin"* ]]; then
            echo -e "  ${YELLOW}brew install git${NC}"
        else
            echo -e "  ${YELLOW}sudo apt install git${NC}  # Debian/Ubuntu"
        fi
        exit 1
    fi

    read -p "Clone the repository now? (y/n) " -n 1 -r
    echo ""
    echo ""

    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Repository cloning cancelled.${NC}"
        echo -e "${BLUE}You can clone it manually and run this script from the repository directory:${NC}"
        echo -e "  ${GREEN}git clone ${REPO_URL}${NC}"
        echo -e "  ${GREEN}cd ${REPO_DIR}${NC}"
        echo -e "  ${GREEN}./whispermoney${NC}"
        exit 0
    fi

    # Clone the repository
    echo -e "${YELLOW}Cloning Whisper Money repository...${NC}"
    if git clone "$REPO_URL" "$REPO_DIR" 2>&1; then
        echo -e "${GREEN}✓ Repository cloned successfully${NC}"
        echo ""
        echo -e "${YELLOW}Changing to ${REPO_DIR} directory...${NC}"
        cd "$REPO_DIR"

        # Make sure the script is executable
        if [ -f "public/setup.sh" ]; then
            chmod +x public/setup.sh
        fi

        echo -e "${GREEN}✓ Ready to continue with installation${NC}"
        echo ""

        # Re-execute the script from the new directory
        exec "./whispermoney" "$@"
    else
        echo -e "${RED}✗ Failed to clone repository${NC}"
        echo -e "${YELLOW}Please check your internet connection and try again.${NC}"
        exit 1
    fi
}

# ASCII Art Header
print_header() {
    echo -e "${BLUE}"
    cat <<"EOF"
██╗    ██╗██╗  ██╗██╗███████╗██████╗ ███████╗██████╗     ███╗   ███╗ ██████╗ ███╗   ██╗███████╗██╗   ██╗
██║    ██║██║  ██║██║██╔════╝██╔══██╗██╔════╝██╔══██╗    ████╗ ████║██╔═══██╗████╗  ██║██╔════╝╚██╗ ██╔╝
██║ █╗ ██║███████║██║███████╗██████╔╝█████╗  ██████╔╝    ██╔████╔██║██║   ██║██╔██╗ ██║█████╗   ╚████╔╝
██║███╗██║██╔══██║██║╚════██║██╔═══╝ ██╔══╝  ██╔══██╗    ██║╚██╔╝██║██║   ██║██║╚██╗██║██╔══╝    ╚██╔╝
╚███╔███╔╝██║  ██║██║███████║██║     ███████╗██║  ██║    ██║ ╚═╝ ██║╚██████╔╝██║ ╚████║███████╗   ██║
 ╚══╝╚══╝ ╚═╝  ╚═╝╚═╝╚══════╝╚═╝     ╚══════╝╚═╝  ╚═╝    ╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝   ╚═╝

EOF
    echo -e "${NC}"
    echo -e "${GREEN}Whisper Money - Privacy-First Personal Finance App${NC}"
    echo -e "${BLUE}End-to-end encrypted financial management${NC}"
    echo ""
}

# Spinner function for long operations
spinner() {
    local pid=$1
    local message=$2
    local spin='-\|/'
    local i=0
    while kill -0 $pid 2>/dev/null; do
        i=$(((i + 1) % 4))
        printf "\r${YELLOW}${message} ${spin:$i:1}${NC}"
        sleep .1
    done
    printf "\r${GREEN}${message} ✓${NC}\n"
}

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check if project is outdated
check_project_version() {
    # Only check if we're in a git repository
    if [ ! -d ".git" ]; then
        return 0
    fi

    # Check if git is available
    if ! command_exists git; then
        return 0
    fi

    # Fetch latest changes without merging
    git fetch origin >/dev/null 2>&1 || return 0

    # Get current branch
    local current_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")

    # Compare local and remote commits
    local local_commit=$(git rev-parse HEAD 2>/dev/null)
    local remote_commit=$(git rev-parse "origin/${current_branch}" 2>/dev/null)

    if [ -z "$local_commit" ] || [ -z "$remote_commit" ]; then
        return 0
    fi

    # Check if we're behind remote
    if [ "$local_commit" != "$remote_commit" ]; then
        local behind_count=$(git rev-list --count HEAD.."origin/${current_branch}" 2>/dev/null || echo "0")

        if [ "$behind_count" -gt 0 ]; then
            echo ""
            echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
            echo -e "${YELLOW}⚠ Project Update Available${NC}"
            echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
            echo ""
            echo -e "${BLUE}Your local project is ${behind_count} commit(s) behind the remote repository.${NC}"
            echo ""
            echo -e "${YELLOW}To update, run:${NC}"
            echo -e "  ${GREEN}./whispermoney upgrade${NC}"
            echo ""
            echo -e "${BLUE}Or manually:${NC}"
            echo -e "  ${GREEN}git pull${NC}"
            echo ""
            return 1
        fi
    fi

    return 0
}

# Check dependencies
check_dependencies() {
    local missing_deps=()

    if ! command_exists docker; then
        missing_deps+=("Docker")
    fi

    if ! command_exists docker-compose && ! docker compose version >/dev/null 2>&1; then
        missing_deps+=("Docker Compose")
    fi

    if ! command_exists php; then
        missing_deps+=("PHP (>= 8.2)")
    else
        php_version=$(php -r 'echo PHP_VERSION_ID;')
        if [ "$php_version" -lt 80200 ]; then
            missing_deps+=("PHP >= 8.2 (found: $(php -r 'echo PHP_VERSION;'))")
        fi
    fi

    if ! command_exists composer; then
        missing_deps+=("Composer")
    fi

    if ! command_exists bun && ! command_exists node; then
        missing_deps+=("Bun or Node.js")
    fi

    if [ ${#missing_deps[@]} -ne 0 ]; then
        echo -e "${RED}Missing dependencies:${NC}"
        for dep in "${missing_deps[@]}"; do
            echo -e "  - ${dep}"
        done
        echo ""
        echo -e "${YELLOW}Please install the missing dependencies and try again.${NC}"
        exit 1
    fi

    echo -e "${GREEN}All dependencies are installed.${NC}"
}

# Wait for service to be healthy
wait_for_service() {
    local service=$1
    local max_attempts=60
    local attempt=1

    echo -e "${YELLOW}Waiting for ${service} to be ready...${NC}"

    while [ $attempt -le $max_attempts ]; do
        # Check container status
        local ps_output=$(docker compose ps "$service" 2>/dev/null | grep "$service" || echo "")
        local container_state=$(echo "$ps_output" | awk '{print $1}' || echo "")

        # Check if container is running
        if echo "$ps_output" | grep -qE "Up|healthy"; then
            # For PHP service, verify it's actually responding
            if [ "$service" = "php" ]; then
                # Give it a moment to fully start (especially on first attempt)
                if [ $attempt -lt 5 ]; then
                    sleep 2
                else
                    sleep 1
                fi

                # Check if we can exec into the container and PHP works
                if docker compose exec -T php php -v >/dev/null 2>&1; then
                    # Try multiple ways to check if server is running
                    # Method 1: Check for artisan serve process
                    if docker compose exec -T php sh -c "ps aux 2>/dev/null | grep '[p]hp.*artisan serve' >/dev/null 2>&1" 2>/dev/null; then
                        echo -e "${GREEN}${service} is ready!${NC}"
                        return 0
                    fi
                    # Method 2: Check if port 8000 is listening
                    if docker compose exec -T php sh -c "netstat -tuln 2>/dev/null | grep ':8000' >/dev/null 2>&1 || ss -tuln 2>/dev/null | grep ':8000' >/dev/null 2>&1" 2>/dev/null; then
                        echo -e "${GREEN}${service} is ready!${NC}"
                        return 0
                    fi
                    # Method 3: Try to connect to the server
                    if docker compose exec -T php sh -c "timeout 1 bash -c '</dev/tcp/localhost/8000' 2>/dev/null" 2>/dev/null; then
                        echo -e "${GREEN}${service} is ready!${NC}"
                        return 0
                    fi
                    # If container is up and PHP works, but server check fails, still consider it ready after enough attempts
                    # (server might be starting)
                    if [ $attempt -ge 10 ]; then
                        echo -e "${YELLOW}${service} container is running but server check inconclusive. Proceeding...${NC}"
                        echo -e "${GREEN}${service} is ready!${NC}"
                        return 0
                    fi
                fi
            else
                echo -e "${GREEN}${service} is ready!${NC}"
                return 0
            fi
        elif echo "$ps_output" | grep -qE "Exit|exited|Dead"; then
            # Container exited - show error
            echo -e "${RED}${service} container exited unexpectedly!${NC}"
            echo -e "${YELLOW}Checking logs...${NC}"
            docker compose logs --tail=30 "$service" 2>&1
            return 1
        fi

        if [ $((attempt % 10)) -eq 0 ]; then
            echo -e "${YELLOW}Still waiting... (${attempt}/${max_attempts})${NC}"
            if [ "$service" = "php" ]; then
                echo -e "${BLUE}Container status:${NC}"
                docker compose ps php 2>/dev/null | grep php || echo "  Container not found"
                echo -e "${BLUE}Recent logs:${NC}"
                docker compose logs --tail=5 php 2>&1 | tail -3
            fi
        fi

        sleep 2
        attempt=$((attempt + 1))
    done

    echo -e "${RED}${service} failed to become ready after $max_attempts attempts${NC}"
    echo -e "${YELLOW}Checking container status...${NC}"
    docker compose ps "$service"
    echo ""
    echo -e "${YELLOW}Recent logs:${NC}"
    docker compose logs --tail=30 "$service" 2>&1 | tail -20
    return 1
}

# Update /etc/hosts file
update_hosts_file() {
    local hostname="whisper.money.local"
    local ip="127.0.0.1"
    local hosts_line="${ip} ${hostname}"
    local hosts_file="/etc/hosts"

    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Hosts File Update${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${BLUE}To access your application at ${GREEN}https://${hostname}${NC}${BLUE}, we need to${NC}"
    echo -e "${BLUE}add an entry to your system's hosts file (${hosts_file}).${NC}"
    echo ""
    echo -e "${YELLOW}What we'll do:${NC}"
    echo -e "  • Add the line: ${GREEN}${hosts_line}${NC}"
    echo -e "  • This maps ${hostname} to your local machine (127.0.0.1)"
    echo ""
    echo -e "${YELLOW}Why we need sudo access:${NC}"
    echo -e "  • The hosts file is a system file that requires administrator privileges"
    echo -e "  • We'll only add the entry if it doesn't already exist (idempotent)"
    echo ""

    # Check if the line already exists
    if grep -q "${hostname}" "${hosts_file}" 2>/dev/null; then
        if grep -q "${hosts_line}" "${hosts_file}" 2>/dev/null; then
            echo -e "${GREEN}✓ The hosts entry already exists. No changes needed.${NC}"
            return 0
        else
            echo -e "${YELLOW}⚠ Found a different entry for ${hostname} in ${hosts_file}${NC}"
            echo -e "${YELLOW}  Please review it manually.${NC}"
            echo ""
        fi
    fi

    read -p "Would you like us to update the hosts file automatically? (y/n) " -n 1 -r
    echo ""
    echo ""

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Updating hosts file (sudo access required)...${NC}"

        # Use sudo to add the line if it doesn't exist
        if sudo sh -c "grep -q '${hosts_line}' ${hosts_file} || echo '${hosts_line}' >> ${hosts_file}"; then
            echo -e "${GREEN}✓ Successfully updated ${hosts_file}${NC}"
            echo -e "${GREEN}  Added: ${hosts_line}${NC}"
            return 0
        else
            echo -e "${RED}✗ Failed to update ${hosts_file}${NC}"
            echo -e "${YELLOW}  You'll need to add it manually.${NC}"
            show_manual_hosts_instructions
            return 1
        fi
    else
        echo -e "${YELLOW}Hosts file update skipped.${NC}"
        show_manual_hosts_instructions
        return 1
    fi
}

# Setup SSL certificates using mkcert for transparent trust
setup_ssl_certificates() {
    local certs_dir="docker/caddy/certs"
    local domain="whisper.money.local"
    local cert_file="${certs_dir}/${domain}.pem"
    local key_file="${certs_dir}/${domain}-key.pem"

    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}SSL Certificate Setup${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    # Check if certificates already exist
    if [ -f "$cert_file" ] && [ -f "$key_file" ]; then
        echo -e "${GREEN}✓ SSL certificates already exist${NC}"
        return 0
    fi

    # Check if mkcert is installed
    if ! command_exists mkcert; then
        echo -e "${YELLOW}mkcert is not installed. It's needed to generate trusted SSL certificates.${NC}"
        echo ""
        echo -e "${BLUE}mkcert creates locally-trusted certificates that browsers automatically trust.${NC}"
        echo ""

        if [[ "$OSTYPE" == "darwin"* ]]; then
            echo -e "${YELLOW}Install mkcert:${NC}"
            echo -e "  ${GREEN}brew install mkcert${NC}"
            echo -e "  ${GREEN}brew install nss${NC}  # For Firefox support"
            echo ""
            read -p "Would you like to install mkcert now? (y/n) " -n 1 -r
            echo ""
            echo ""

            if [[ $REPLY =~ ^[Yy]$ ]]; then
                if command_exists brew; then
                    echo -e "${YELLOW}Installing mkcert...${NC}"
                    brew install mkcert nss
                    echo -e "${GREEN}✓ mkcert installed${NC}"
                else
                    echo -e "${RED}Homebrew is not installed. Please install mkcert manually:${NC}"
                    echo -e "  ${YELLOW}brew install mkcert nss${NC}"
                    echo ""
                    echo -e "${YELLOW}Or visit: https://github.com/FiloSottile/mkcert#installation${NC}"
                    create_fallback_certificates
                    return 1
                fi
            else
                echo -e "${YELLOW}Using fallback self-signed certificates...${NC}"
                create_fallback_certificates
                return 0
            fi
        else
            echo -e "${YELLOW}Install mkcert:${NC}"
            echo -e "  ${GREEN}sudo apt install libnss3-tools${NC}  # Debian/Ubuntu"
            echo -e "  ${GREEN}wget -O /tmp/mkcert https://github.com/FiloSottile/mkcert/releases/latest/download/mkcert-v1.4.4-linux-amd64${NC}"
            echo -e "  ${GREEN}chmod +x /tmp/mkcert && sudo mv /tmp/mkcert /usr/local/bin/mkcert${NC}"
            echo ""
            echo -e "${YELLOW}Or visit: https://github.com/FiloSottile/mkcert#installation${NC}"
            echo ""
            read -p "Continue with fallback self-signed certificates? (y/n) " -n 1 -r
            echo ""
            echo ""

            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                echo -e "${YELLOW}Please install mkcert and run the setup again.${NC}"
                exit 1
            fi

            create_fallback_certificates
            return 0
        fi
    fi

    # Install local CA if not already installed
    if [ ! -f "$(mkcert -CAROOT)/rootCA.pem" ]; then
        echo -e "${YELLOW}Installing mkcert local CA (requires sudo)...${NC}"
        mkcert -install
        echo -e "${GREEN}✓ Local CA installed${NC}"
    else
        echo -e "${GREEN}✓ Local CA already installed${NC}"
    fi

    # Create certs directory
    mkdir -p "$certs_dir"

    # Generate certificate
    echo -e "${YELLOW}Generating SSL certificate for ${domain}...${NC}"
    if mkcert -cert-file "$cert_file" -key-file "$key_file" "$domain" "*.${domain}"; then
        echo -e "${GREEN}✓ SSL certificate generated successfully${NC}"
        echo -e "${GREEN}  Certificate: ${cert_file}${NC}"
        echo -e "${GREEN}  Key: ${key_file}${NC}"
        echo ""
        echo -e "${BLUE}Your browser will automatically trust this certificate!${NC}"
        return 0
    else
        echo -e "${RED}✗ Failed to generate certificate${NC}"
        create_fallback_certificates
        return 1
    fi
}

# Create fallback self-signed certificates
create_fallback_certificates() {
    local certs_dir="docker/caddy/certs"
    local domain="whisper.money.local"
    local cert_file="${certs_dir}/${domain}.pem"
    local key_file="${certs_dir}/${domain}-key.pem"

    echo ""
    echo -e "${YELLOW}Creating self-signed certificates (browser will show a warning)...${NC}"

    mkdir -p "$certs_dir"

    # Check if openssl is available
    if command_exists openssl; then
        # Create certificate with proper SAN extension
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout "$key_file" \
            -out "$cert_file" \
            -subj "/CN=${domain}/O=Development/C=US" \
            -addext "subjectAltName=DNS:${domain},DNS:*.${domain},IP:127.0.0.1" \
            2>/dev/null

        if [ -f "$cert_file" ] && [ -f "$key_file" ]; then
            # Set proper permissions
            chmod 644 "$cert_file"
            chmod 600 "$key_file"
            echo -e "${GREEN}✓ Self-signed certificates created${NC}"
            echo -e "${YELLOW}  Note: Your browser will show a security warning.${NC}"
            echo -e "${YELLOW}  Click 'Advanced' → 'Proceed to ${domain}' to continue.${NC}"
            return 0
        fi
    fi

    echo -e "${RED}✗ Could not create certificates${NC}"
    echo -e "${YELLOW}  Please install mkcert or openssl to generate certificates.${NC}"
    return 1
}

# Show manual hosts file instructions
show_manual_hosts_instructions() {
    local hostname="whisper.money.local"
    local hosts_line="127.0.0.1 ${hostname}"

    echo ""
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}Manual Hosts File Update${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${BLUE}To access your application, please add this line to ${YELLOW}/etc/hosts${NC}${BLUE}:${NC}"
    echo ""
    echo -e "  ${GREEN}${hosts_line}${NC}"
    echo ""
    echo -e "${YELLOW}Instructions:${NC}"
    echo -e "  1. Open ${YELLOW}/etc/hosts${NC} with a text editor (requires sudo):"
    echo -e "     ${YELLOW}sudo nano /etc/hosts${NC}  ${BLUE}(or use your preferred editor)${NC}"
    echo ""
    echo -e "  2. Add the line at the end of the file:"
    echo -e "     ${GREEN}${hosts_line}${NC}"
    echo ""
    echo -e "  3. Save and close the file"
    echo ""
    echo -e "${YELLOW}Note:${NC} If the line already exists, you can skip this step."
    echo ""
}

# Start Docker services
start_services() {
    echo -e "${BLUE}Starting Docker services...${NC}"
    docker compose up -d

    wait_for_service "mysql"
    wait_for_service "redis"

    # Only wait for PHP if it's supposed to be running
    if docker compose ps php 2>/dev/null | grep -q "php"; then
        wait_for_service "php"
    fi

    echo -e "${GREEN}All services are running!${NC}"
}

# Stop Docker services
stop_services() {
    echo -e "${BLUE}Stopping Docker services...${NC}"
    docker compose down
    echo -e "${GREEN}Services stopped.${NC}"
}

# Install function
install() {
    print_header

    echo -e "${BLUE}Starting Whisper Money installation...${NC}"
    echo ""

    # Check dependencies
    check_dependencies
    echo ""

    # Check if .env exists
    if [ ! -f .env ]; then
        echo -e "${YELLOW}.env file not found. Creating from .env.example...${NC}"
        cp .env.example .env
        echo -e "${GREEN}.env file created.${NC}"
    else
        echo -e "${GREEN}.env file already exists.${NC}"
    fi

    # Generate APP_KEY if not set
    if ! grep -q "APP_KEY=base64:" .env 2>/dev/null || grep -q "APP_KEY=$" .env 2>/dev/null; then
        echo -e "${YELLOW}Generating application key...${NC}"
        php artisan key:generate --no-interaction
        echo -e "${GREEN}Application key generated.${NC}"
    else
        echo -e "${GREEN}Application key already exists.${NC}"
    fi

    # Update APP_URL if needed
    if grep -q "APP_URL=https://whispermoney.test" .env 2>/dev/null || grep -q "APP_URL=$" .env 2>/dev/null; then
        echo -e "${YELLOW}Updating APP_URL to https://whisper.money.local...${NC}"
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' 's|APP_URL=.*|APP_URL=https://whisper.money.local|g' .env
        else
            sed -i 's|APP_URL=.*|APP_URL=https://whisper.money.local|g' .env
        fi
        echo -e "${GREEN}APP_URL updated.${NC}"
    else
        echo -e "${GREEN}APP_URL already configured.${NC}"
    fi

    # Update database and Redis hosts/ports for Docker
    echo -e "${YELLOW}Configuring database and Redis for Docker...${NC}"
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' 's|^DB_HOST=.*|DB_HOST=mysql|g' .env
        sed -i '' 's|^DB_PORT=.*|DB_PORT=3306|g' .env
        sed -i '' 's|^REDIS_HOST=.*|REDIS_HOST=redis|g' .env
        sed -i '' 's|^REDIS_PORT=.*|REDIS_PORT=6379|g' .env
    else
        sed -i 's|^DB_HOST=.*|DB_HOST=mysql|g' .env
        sed -i 's|^DB_PORT=.*|DB_PORT=3306|g' .env
        sed -i 's|^REDIS_HOST=.*|REDIS_HOST=redis|g' .env
        sed -i 's|^REDIS_PORT=.*|REDIS_PORT=6379|g' .env
    fi
    echo -e "${GREEN}Database and Redis configured for Docker.${NC}"

    echo ""

    # Setup SSL certificates before starting Caddy
    setup_ssl_certificates
    echo ""

    # Start Docker services (but don't start PHP yet - we'll start it after dependencies are installed)
    echo -e "${BLUE}Starting Docker services (excluding PHP for now)...${NC}"
    docker compose up -d mysql redis caddy mailhog

    wait_for_service "mysql"
    wait_for_service "redis"
    echo ""

    # Install Composer dependencies
    echo -e "${BLUE}Installing Composer dependencies...${NC}"
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo -e "${GREEN}Composer dependencies installed.${NC}"
    echo ""

    # Now start PHP service (after dependencies are installed)
    echo -e "${BLUE}Starting PHP service...${NC}"
    if ! docker compose up -d php; then
        echo -e "${RED}Failed to start PHP service!${NC}"
        echo -e "${YELLOW}Checking logs...${NC}"
        docker compose logs php 2>&1 | tail -20
        exit 1
    fi

    # Wait a moment for PHP container to start
    sleep 5

    # Check if container started successfully
    if ! docker compose ps php 2>/dev/null | grep -q "php"; then
        echo -e "${RED}PHP container failed to start!${NC}"
        echo -e "${YELLOW}Checking logs...${NC}"
        docker compose logs php 2>&1 | tail -30
        exit 1
    fi

    wait_for_service "php"
    echo ""

    # Install Bun/Node dependencies
    if command_exists bun; then
        echo -e "${BLUE}Installing Bun dependencies...${NC}"
        bun install --frozen-lockfile
        echo -e "${GREEN}Bun dependencies installed.${NC}"
    elif command_exists npm; then
        echo -e "${BLUE}Installing npm dependencies...${NC}"
        npm ci
        echo -e "${GREEN}npm dependencies installed.${NC}"
    fi
    echo ""

    # Build frontend assets with retry logic
    echo -e "${BLUE}Building frontend assets...${NC}"

    # Small delay to ensure file system is ready
    sleep 1

    local build_success=false
    local max_build_attempts=3
    local build_attempt=1

    while [ $build_attempt -le $max_build_attempts ]; do
        if command_exists bun; then
            echo -e "${YELLOW}Build attempt ${build_attempt}/${max_build_attempts}...${NC}"
            if bun run build 2>&1; then
                build_success=true
                break
            else
                if [ $build_attempt -lt $max_build_attempts ]; then
                    echo -e "${YELLOW}Build attempt ${build_attempt} failed. Retrying in 3 seconds...${NC}"
                    sleep 3
                fi
                build_attempt=$((build_attempt + 1))
            fi
        elif command_exists npm; then
            echo -e "${YELLOW}Build attempt ${build_attempt}/${max_build_attempts}...${NC}"
            if npm run build 2>&1; then
                build_success=true
                break
            else
                if [ $build_attempt -lt $max_build_attempts ]; then
                    echo -e "${YELLOW}Build attempt ${build_attempt} failed. Retrying in 3 seconds...${NC}"
                    sleep 3
                fi
                build_attempt=$((build_attempt + 1))
            fi
        else
            echo -e "${RED}No build tool (bun/npm) found. Skipping build.${NC}"
            break
        fi
    done

    if [ "$build_success" = false ]; then
        echo -e "${RED}Failed to build frontend assets after ${max_build_attempts} attempts.${NC}"
        echo ""
        echo -e "${YELLOW}Troubleshooting tips:${NC}"
        echo -e "  1. Try clearing caches and rebuilding:"
        if command_exists bun; then
            echo -e "     ${YELLOW}rm -rf node_modules/.cache .vite && bun run build${NC}"
        else
            echo -e "     ${YELLOW}rm -rf node_modules/.cache .vite && npm run build${NC}"
        fi
        echo -e "  2. Try reinstalling dependencies:"
        if command_exists bun; then
            echo -e "     ${YELLOW}rm -rf node_modules && bun install${NC}"
        else
            echo -e "     ${YELLOW}rm -rf node_modules && npm install${NC}"
        fi
        echo ""
        echo -e "${YELLOW}You can also try building manually later with:${NC}"
        if command_exists bun; then
            echo -e "  ${YELLOW}bun run build${NC}"
        else
            echo -e "  ${YELLOW}npm run build${NC}"
        fi
        echo ""
        read -p "Continue with installation anyway? (y/n) " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo -e "${RED}Installation cancelled.${NC}"
            exit 1
        fi
    else
        echo -e "${GREEN}Frontend assets built successfully.${NC}"
    fi
    echo ""

    # Run database migrations
    echo -e "${BLUE}Running database migrations...${NC}"
    docker compose exec -T php php artisan migrate --force
    echo -e "${GREEN}Database migrations completed.${NC}"
    echo ""

    # Update hosts file
    update_hosts_file

    # Generate SSL certificates using mkcert
    setup_ssl_certificates

    # Success message
    echo ""
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  ✓ Whisper Money has been installed successfully!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${BLUE}Your application is now running!${NC}"
    echo ""
    echo -e "  ${GREEN}Visit: https://whisper.money.local${NC}"
    echo ""

    # Check if mkcert certificates are being used
    if [ -f "docker/caddy/certs/whisper.money.local.pem" ]; then
        echo -e "${GREEN}✓ Using trusted SSL certificates (mkcert)${NC}"
        echo -e "${BLUE}  Your browser will automatically trust the certificate!${NC}"
    else
        echo -e "${YELLOW}⚠ Using self-signed certificates${NC}"
        echo -e "${BLUE}  Your browser may show a security warning.${NC}"
        echo -e "${BLUE}  Click 'Advanced' → 'Proceed to whisper.money.local' to continue.${NC}"
        echo ""
        echo -e "${YELLOW}To avoid the warning, install mkcert and run setup again:${NC}"
        if [[ "$OSTYPE" == "darwin"* ]]; then
            echo -e "  ${GREEN}brew install mkcert nss && mkcert -install${NC}"
        else
            echo -e "  ${GREEN}# See: https://github.com/FiloSottile/mkcert#installation${NC}"
        fi
    fi
    echo ""
    echo -e "${BLUE}Services running:${NC}"
    echo -e "  • PHP development server (port 8000)"
    echo -e "  • Caddy reverse proxy (ports 80, 443)"
    echo -e "  • MySQL database"
    echo -e "  • Redis cache"
    echo -e "  • MailHog (email testing)"
    echo ""
    echo -e "${YELLOW}To stop all services:${NC}"
    echo -e "  ${YELLOW}./whispermoney stop${NC}"
    echo ""
    echo -e "${YELLOW}To start services again:${NC}"
    echo -e "  ${YELLOW}./whispermoney start${NC}"
    echo ""
}

# Upgrade function
upgrade() {
    print_header

    echo -e "${BLUE}Upgrading Whisper Money...${NC}"
    echo ""

    # Git pull
    echo -e "${YELLOW}Pulling latest changes from Git...${NC}"
    git pull
    echo -e "${GREEN}Git pull completed.${NC}"
    echo ""

    # Composer install
    echo -e "${BLUE}Updating Composer dependencies...${NC}"
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo -e "${GREEN}Composer dependencies updated.${NC}"
    echo ""

    # Bun/Node install
    if command_exists bun; then
        echo -e "${BLUE}Updating Bun dependencies...${NC}"
        bun install --frozen-lockfile
        echo -e "${GREEN}Bun dependencies updated.${NC}"
    elif command_exists npm; then
        echo -e "${BLUE}Updating npm dependencies...${NC}"
        npm ci
        echo -e "${GREEN}npm dependencies updated.${NC}"
    fi
    echo ""

    # Run migrations
    echo -e "${BLUE}Running database migrations...${NC}"
    docker compose exec -T php php artisan migrate --force
    echo -e "${GREEN}Database migrations completed.${NC}"
    echo ""

    # Rebuild assets with retry logic
    echo -e "${BLUE}Rebuilding frontend assets...${NC}"

    # Small delay to ensure file system is ready
    sleep 1

    local build_success=false
    local max_build_attempts=3
    local build_attempt=1

    while [ $build_attempt -le $max_build_attempts ]; do
        if command_exists bun; then
            echo -e "${YELLOW}Build attempt ${build_attempt}/${max_build_attempts}...${NC}"
            if bun run build 2>&1; then
                build_success=true
                break
            else
                if [ $build_attempt -lt $max_build_attempts ]; then
                    echo -e "${YELLOW}Build attempt ${build_attempt} failed. Retrying in 3 seconds...${NC}"
                    sleep 3
                fi
                build_attempt=$((build_attempt + 1))
            fi
        elif command_exists npm; then
            echo -e "${YELLOW}Build attempt ${build_attempt}/${max_build_attempts}...${NC}"
            if npm run build 2>&1; then
                build_success=true
                break
            else
                if [ $build_attempt -lt $max_build_attempts ]; then
                    echo -e "${YELLOW}Build attempt ${build_attempt} failed. Retrying in 3 seconds...${NC}"
                    sleep 3
                fi
                build_attempt=$((build_attempt + 1))
            fi
        else
            echo -e "${RED}No build tool (bun/npm) found. Skipping build.${NC}"
            break
        fi
    done

    if [ "$build_success" = false ]; then
        echo -e "${RED}Failed to rebuild frontend assets after ${max_build_attempts} attempts.${NC}"
        echo ""
        echo -e "${YELLOW}Troubleshooting tips:${NC}"
        echo -e "  1. Try clearing caches and rebuilding:"
        if command_exists bun; then
            echo -e "     ${YELLOW}rm -rf node_modules/.cache .vite && bun run build${NC}"
        else
            echo -e "     ${YELLOW}rm -rf node_modules/.cache .vite && npm run build${NC}"
        fi
        echo -e "  2. Try reinstalling dependencies:"
        if command_exists bun; then
            echo -e "     ${YELLOW}rm -rf node_modules && bun install${NC}"
        else
            echo -e "     ${YELLOW}rm -rf node_modules && npm install${NC}"
        fi
        echo ""
        echo -e "${YELLOW}You can also try building manually later with:${NC}"
        if command_exists bun; then
            echo -e "  ${YELLOW}bun run build${NC}"
        else
            echo -e "  ${YELLOW}npm run build${NC}"
        fi
        echo ""
        read -p "Continue with upgrade anyway? (y/n) " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo -e "${RED}Upgrade cancelled.${NC}"
            exit 1
        fi
    else
        echo -e "${GREEN}Frontend assets rebuilt successfully.${NC}"
    fi
    echo ""

    # Restart services
    echo -e "${BLUE}Restarting services...${NC}"
    docker compose restart
    echo -e "${GREEN}Services restarted.${NC}"
    echo ""

    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  ✓ Whisper Money has been upgraded successfully!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
}

# Interactive menu
ask_for_action() {
    print_header

    echo -e "${BLUE}What would you like to do?${NC}"
    echo ""
    echo "  1) Install"
    echo "  2) Start Services"
    echo "  3) Stop Services"
    echo "  4) Upgrade"
    echo "  5) Exit"
    echo ""
    read -p "Enter your choice [1-5]: " choice

    case $choice in
    1)
        install
        ;;
    2)
        start_services
        ;;
    3)
        stop_services
        ;;
    4)
        upgrade
        ;;
    5)
        echo -e "${GREEN}Goodbye!${NC}"
        exit 0
        ;;
    *)
        echo -e "${RED}Invalid choice. Please try again.${NC}"
        ask_for_action
        ;;
    esac
}

# Main script logic
main() {
    # Check and setup repository first (only for install command or interactive mode)
    # This will re-execute the script if cloning was needed
    if [ "${1:-}" = "install" ] || [ -z "${1:-}" ]; then
        check_and_setup_repo "$@"
    fi

    # Check if project is outdated (skip for upgrade command)
    if [ "${1:-}" != "upgrade" ]; then
        check_project_version
    fi

    case "${1:-}" in
    install)
        install
        ;;
    start)
        start_services
        ;;
    stop)
        stop_services
        ;;
    upgrade)
        upgrade
        ;;
    "")
        ask_for_action
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}"
        echo ""
        echo "Usage: $0 [install|start|stop|upgrade]"
        echo ""
        echo "Commands:"
        echo "  install  - Install Whisper Money"
        echo "  start    - Start Docker services"
        echo "  stop     - Stop Docker services"
        echo "  upgrade  - Upgrade Whisper Money"
        echo ""
        echo "If no command is provided, an interactive menu will be shown."
        exit 1
        ;;
    esac
}

# Run main function
main "$@"
