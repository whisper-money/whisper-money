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
        return 0
    fi

    # Check if whisper-money directory exists in current directory
    if [ -d "$REPO_DIR" ] && [ -d "$REPO_DIR/.git" ]; then
        echo -e "${GREEN}✓ Repository found in ${REPO_DIR}${NC}"
        echo -e "${YELLOW}Changing to ${REPO_DIR} directory...${NC}"
        cd "$REPO_DIR"
        # Re-execute the script from the new directory with original arguments
        exec "./whispermoney" "$@"
    fi

    # Not in repo and repo directory doesn't exist - prompt to clone
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
        echo -e "  ${GREEN}whispermoney${NC}"
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

        # Make sure the script is executable
        if [ -f "whispermoney" ]; then
            chmod +x whispermoney
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

# First-time sponsor message (small and interactive)
print_sponsor_message() {
    local sponsor_url="https://github.com/sponsors/victor-falcon"

    echo ""
    echo -e "${YELLOW}☕ Psst!${NC} If Whisper Money saves you from spreadsheet hell,"
    echo -e "   consider buying the developer a coffee. No pressure!"
    echo ""
    read -p "   Open sponsor page? (y/n) " -n 1 -r
    echo ""

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${GREEN}   Opening ${sponsor_url}...${NC}"
        if [[ "$OSTYPE" == "darwin"* ]]; then
            open "$sponsor_url"
        elif command_exists xdg-open; then
            xdg-open "$sponsor_url"
        elif command_exists wslview; then
            wslview "$sponsor_url"
        else
            echo -e "${BLUE}   Visit: ${sponsor_url}${NC}"
        fi
        echo -e "${GREEN}   You're awesome. Thanks for the support! 🎉${NC}"
    fi
    echo ""
}

# ASCII Art Header
print_header() {
    echo -e "${BLUE}"
    cat <<"EOF"
██╗    ██╗██╗  ██╗██╗███████╗██████╗ ███████╗██████╗   ███╗   ███╗ ██████╗ ███╗   ██╗███████╗██╗   ██╗
██║    ██║██║  ██║██║██╔════╝██╔══██╗██╔════╝██╔══██╗  ████╗ ████║██╔═══██╗████╗  ██║██╔════╝╚██╗ ██╔╝
██║ █╗ ██║███████║██║███████╗██████╔╝█████╗  ██████╔╝  ██╔████╔██║██║   ██║██╔██╗ ██║█████╗   ╚████╔╝
██║███╗██║██╔══██║██║╚════██║██╔═══╝ ██╔══╝  ██╔══██╗  ██║╚██╔╝██║██║   ██║██║╚██╗██║██╔══╝    ╚██╔╝
╚███╔███╔╝██║  ██║██║███████║██║     ███████╗██║  ██║  ██║ ╚═╝ ██║╚██████╔╝██║ ╚████║███████╗   ██║
 ╚══╝╚══╝ ╚═╝  ╚═╝╚═╝╚══════╝╚═╝     ╚══════╝╚═╝  ╚═╝  ╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝   ╚═╝
EOF
    echo -e "${NC}"
    echo -e "${GREEN}Whisper Money - Privacy-First Personal Finance App${NC}"
    echo -e "${BLUE}Privacy-first financial management—your data, your ownership${NC}"
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

    # Get current branch
    local current_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "main")

    # Skip check if not on main branch
    if [ "$current_branch" != "main" ]; then
        return 0
    fi

    # Fetch latest changes without merging
    git fetch origin >/dev/null 2>&1 || return 0

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
            echo -e "  ${GREEN}whispermoney upgrade${NC}"
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

# Check if a port is available
is_port_available() {
    local port=$1

    # Try netstat (works on macOS and Linux)
    if command_exists netstat; then
        if netstat -an 2>/dev/null | grep -qE ":$port |:$port$|:$port\s"; then
            return 1 # Port is in use
        else
            return 0 # Port is available
        fi
    fi

    # Try ss (Linux only)
    if command_exists ss; then
        if ss -tuln 2>/dev/null | grep -q ":${port} "; then
            return 1 # Port is in use
        else
            return 0 # Port is available
        fi
    fi

    # Fallback: try to connect to the port using nc
    if command_exists nc; then
        if nc -z localhost "$port" >/dev/null 2>&1; then
            return 1 # Port is in use
        else
            return 0 # Port is available
        fi
    fi

    # If we can't check, assume it's available (optimistic)
    return 0
}

# Find an available port starting from a base port
find_available_port() {
    local base_port=$1
    local max_attempts=100
    local port=$base_port
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if is_port_available "$port"; then
            echo "$port"
            return 0
        fi
        port=$((port + 1))
        attempt=$((attempt + 1))
    done

    echo "$base_port"
    return 1
}

# Check and configure ports for Docker services
check_and_configure_ports() {
    echo -e "${BLUE}Checking port availability...${NC}"

    if ! is_port_available 8000; then
        echo -e "${RED}Port 8000 is already in use. PHP requires this port.${NC}"
        echo -e "${YELLOW}Please stop the service using port 8000 and try again.${NC}"
        exit 1
    fi

    # Check optional ports and find alternatives if needed
    local db_port=3307
    local redis_port=6380
    local mailhog_port=1025
    local mailhog_dashboard_port=8025

    # Read existing ports from .env if they exist
    if [ -f .env ]; then
        if grep -q "^FORWARD_DB_PORT=" .env 2>/dev/null; then
            db_port=$(grep "^FORWARD_DB_PORT=" .env | cut -d '=' -f2 | tr -d ' ')
        fi
        if grep -q "^FORWARD_REDIS_PORT=" .env 2>/dev/null; then
            redis_port=$(grep "^FORWARD_REDIS_PORT=" .env | cut -d '=' -f2 | tr -d ' ')
        fi
        if grep -q "^FORWARD_MAILHOG_PORT=" .env 2>/dev/null; then
            mailhog_port=$(grep "^FORWARD_MAILHOG_PORT=" .env | cut -d '=' -f2 | tr -d ' ')
        fi
        if grep -q "^FORWARD_MAILHOG_DASHBOARD_PORT=" .env 2>/dev/null; then
            mailhog_dashboard_port=$(grep "^FORWARD_MAILHOG_DASHBOARD_PORT=" .env | cut -d '=' -f2 | tr -d ' ')
        fi
    fi

    # Check MySQL port
    local original_db_port=$db_port
    if ! is_port_available "$db_port"; then
        echo -e "${YELLOW}Port ${db_port} is in use. Finding alternative port for MySQL...${NC}"
        db_port=$(find_available_port "$db_port")
        if [ "$db_port" != "$original_db_port" ]; then
            echo -e "${GREEN}Using port ${db_port} for MySQL (instead of ${original_db_port})${NC}"
        fi
    fi

    # Check Redis port
    local original_redis_port=$redis_port
    if ! is_port_available "$redis_port"; then
        echo -e "${YELLOW}Port ${redis_port} is in use. Finding alternative port for Redis...${NC}"
        redis_port=$(find_available_port "$redis_port")
        if [ "$redis_port" != "$original_redis_port" ]; then
            echo -e "${GREEN}Using port ${redis_port} for Redis (instead of ${original_redis_port})${NC}"
        fi
    fi

    # Check MailHog SMTP port
    local original_mailhog_port=$mailhog_port
    if ! is_port_available "$mailhog_port"; then
        echo -e "${YELLOW}Port ${mailhog_port} is in use. Finding alternative port for MailHog SMTP...${NC}"
        mailhog_port=$(find_available_port "$mailhog_port")
        if [ "$mailhog_port" != "$original_mailhog_port" ]; then
            echo -e "${GREEN}Using port ${mailhog_port} for MailHog SMTP (instead of ${original_mailhog_port})${NC}"
        fi
    fi

    # Check MailHog Dashboard port
    local original_mailhog_dashboard_port=$mailhog_dashboard_port
    if ! is_port_available "$mailhog_dashboard_port"; then
        echo -e "${YELLOW}Port ${mailhog_dashboard_port} is in use. Finding alternative port for MailHog Dashboard...${NC}"
        mailhog_dashboard_port=$(find_available_port "$mailhog_dashboard_port")
        if [ "$mailhog_dashboard_port" != "$original_mailhog_dashboard_port" ]; then
            echo -e "${GREEN}Using port ${mailhog_dashboard_port} for MailHog Dashboard (instead of ${original_mailhog_dashboard_port})${NC}"
        fi
    fi

    # Update .env file with the ports
    if [ -f .env ]; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            # Update or add FORWARD_DB_PORT
            if grep -q "^FORWARD_DB_PORT=" .env; then
                sed -i '' "s|^FORWARD_DB_PORT=.*|FORWARD_DB_PORT=${db_port}|g" .env
            else
                echo "FORWARD_DB_PORT=${db_port}" >>.env
            fi

            # Update or add FORWARD_REDIS_PORT
            if grep -q "^FORWARD_REDIS_PORT=" .env; then
                sed -i '' "s|^FORWARD_REDIS_PORT=.*|FORWARD_REDIS_PORT=${redis_port}|g" .env
            else
                echo "FORWARD_REDIS_PORT=${redis_port}" >>.env
            fi

            # Update or add FORWARD_MAILHOG_PORT
            if grep -q "^FORWARD_MAILHOG_PORT=" .env; then
                sed -i '' "s|^FORWARD_MAILHOG_PORT=.*|FORWARD_MAILHOG_PORT=${mailhog_port}|g" .env
            else
                echo "FORWARD_MAILHOG_PORT=${mailhog_port}" >>.env
            fi

            # Update or add FORWARD_MAILHOG_DASHBOARD_PORT
            if grep -q "^FORWARD_MAILHOG_DASHBOARD_PORT=" .env; then
                sed -i '' "s|^FORWARD_MAILHOG_DASHBOARD_PORT=.*|FORWARD_MAILHOG_DASHBOARD_PORT=${mailhog_dashboard_port}|g" .env
            else
                echo "FORWARD_MAILHOG_DASHBOARD_PORT=${mailhog_dashboard_port}" >>.env
            fi
        else
            # Update or add FORWARD_DB_PORT
            if grep -q "^FORWARD_DB_PORT=" .env; then
                sed -i "s|^FORWARD_DB_PORT=.*|FORWARD_DB_PORT=${db_port}|g" .env
            else
                echo "FORWARD_DB_PORT=${db_port}" >>.env
            fi

            # Update or add FORWARD_REDIS_PORT
            if grep -q "^FORWARD_REDIS_PORT=" .env; then
                sed -i "s|^FORWARD_REDIS_PORT=.*|FORWARD_REDIS_PORT=${redis_port}|g" .env
            else
                echo "FORWARD_REDIS_PORT=${redis_port}" >>.env
            fi

            # Update or add FORWARD_MAILHOG_PORT
            if grep -q "^FORWARD_MAILHOG_PORT=" .env; then
                sed -i "s|^FORWARD_MAILHOG_PORT=.*|FORWARD_MAILHOG_PORT=${mailhog_port}|g" .env
            else
                echo "FORWARD_MAILHOG_PORT=${mailhog_port}" >>.env
            fi

            # Update or add FORWARD_MAILHOG_DASHBOARD_PORT
            if grep -q "^FORWARD_MAILHOG_DASHBOARD_PORT=" .env; then
                sed -i "s|^FORWARD_MAILHOG_DASHBOARD_PORT=.*|FORWARD_MAILHOG_DASHBOARD_PORT=${mailhog_dashboard_port}|g" .env
            else
                echo "FORWARD_MAILHOG_DASHBOARD_PORT=${mailhog_dashboard_port}" >>.env
            fi
        fi
    fi

    echo -e "${GREEN}Port configuration complete.${NC}"
    echo -e "${BLUE}  MySQL: ${db_port}${NC}"
    echo -e "${BLUE}  Redis: ${redis_port}${NC}"
    echo -e "${BLUE}  MailHog SMTP: ${mailhog_port}${NC}"
    echo -e "${BLUE}  MailHog Dashboard: ${mailhog_dashboard_port}${NC}"
    echo ""
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

# Setup portless for HTTPS reverse proxy
setup_portless() {
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}HTTPS Setup (Portless)${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    if ! command_exists npx; then
        echo -e "${RED}npx is not available. Portless requires Node.js.${NC}"
        echo -e "${YELLOW}Skipping HTTPS setup. The app will be available at http://localhost:8000${NC}"
        return 1
    fi

    # Trust the portless CA (one-time setup, auto-elevates with sudo)
    echo -e "${YELLOW}Setting up portless HTTPS certificates...${NC}"
    if npx portless trust 2>/dev/null; then
        echo -e "${GREEN}✓ Portless CA trusted${NC}"
    else
        echo -e "${YELLOW}⚠ Could not trust portless CA automatically.${NC}"
        echo -e "${YELLOW}  You can run 'npx portless trust' manually later.${NC}"
    fi

    # Start the portless proxy if not already running
    echo -e "${YELLOW}Starting portless proxy...${NC}"
    if npx portless proxy start 2>/dev/null; then
        echo -e "${GREEN}✓ Portless proxy running${NC}"
    else
        echo -e "${YELLOW}⚠ Could not start portless proxy automatically.${NC}"
        echo -e "${YELLOW}  It may already be running, or you can start it with 'npx portless proxy start'.${NC}"
    fi

    # Register the fixed alias for whisper.money
    echo -e "${YELLOW}Registering HTTPS route...${NC}"
    if npx portless alias whisper.money 8000 --force 2>/dev/null; then
        echo -e "${GREEN}✓ https://whisper.money.localhost → localhost:8000${NC}"
    else
        echo -e "${YELLOW}⚠ Could not register portless alias.${NC}"
        echo -e "${YELLOW}  You can run 'npx portless alias whisper.money 8000' manually.${NC}"
    fi

    echo ""
}

# Start Docker services
start_services() {
    # Check and configure ports before starting Docker services
    if [ -f .env ]; then
        check_and_configure_ports
    fi

    echo -e "${BLUE}Starting Docker services...${NC}"
    docker compose --profile full up -d

    wait_for_service "mysql"
    wait_for_service "redis"

    # Only wait for PHP if it's supposed to be running
    if docker compose ps php 2>/dev/null | grep -q "php"; then
        wait_for_service "php"
    fi

    # Register portless alias for HTTPS access
    if command_exists npx; then
        npx portless proxy start 2>/dev/null || true
        npx portless alias whisper.money 8000 --force 2>/dev/null || true
        echo -e "${GREEN}All services are running!${NC}"
        echo -e "  ${GREEN}Visit: https://whisper.money.localhost${NC}"
    else
        echo -e "${GREEN}All services are running!${NC}"
        echo -e "  ${GREEN}Visit: http://localhost:8000${NC}"
    fi
}

# Stop Docker services
stop_services() {
    # Remove portless alias
    if command_exists npx; then
        npx portless alias --remove whisper.money 2>/dev/null || true
    fi

    echo -e "${BLUE}Stopping Docker services...${NC}"
    docker compose down
    echo -e "${GREEN}Services stopped.${NC}"
}

# Show logs
show_logs() {
    local service="${1:-php}"

    echo -e "${BLUE}Showing logs for ${service} (Ctrl+C to exit)...${NC}"
    echo ""
    docker compose logs -f "$service"
}

# Install function
install() {
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

    # Update APP_URL if needed
    if grep -q "APP_URL=https://whispermoney.test" .env 2>/dev/null || grep -q "APP_URL=$" .env 2>/dev/null; then
        echo -e "${YELLOW}Updating APP_URL to https://whisper.money.localhost...${NC}"
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' 's|APP_URL=.*|APP_URL=https://whisper.money.localhost|g' .env
        else
            sed -i 's|APP_URL=.*|APP_URL=https://whisper.money.localhost|g' .env
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

        # Add MYSQL_EXTRA_OPTIONS if it doesn't exist
        if ! grep -q "^MYSQL_EXTRA_OPTIONS=" .env 2>/dev/null; then
            echo "MYSQL_EXTRA_OPTIONS=" >>.env
        fi
    else
        sed -i 's|^DB_HOST=.*|DB_HOST=mysql|g' .env
        sed -i 's|^DB_PORT=.*|DB_PORT=3306|g' .env
        sed -i 's|^REDIS_HOST=.*|REDIS_HOST=redis|g' .env
        sed -i 's|^REDIS_PORT=.*|REDIS_PORT=6379|g' .env

        # Add MYSQL_EXTRA_OPTIONS if it doesn't exist
        if ! grep -q "^MYSQL_EXTRA_OPTIONS=" .env 2>/dev/null; then
            echo "MYSQL_EXTRA_OPTIONS=" >>.env
        fi
    fi
    echo -e "${GREEN}Database and Redis configured for Docker.${NC}"

    echo ""

    # Install Composer dependencies BEFORE starting Docker services
    # (Docker Compose mounts files from vendor/laravel/sail that need to exist)
    echo -e "${BLUE}Installing Composer dependencies...${NC}"
    composer install --no-interaction --prefer-dist --optimize-autoloader
    echo -e "${GREEN}Composer dependencies installed.${NC}"
    echo ""

    # Check and configure ports before starting Docker services
    check_and_configure_ports

    # Start Docker services (but don't start PHP yet - we'll start it after APP_KEY is generated)
    echo -e "${BLUE}Starting Docker services (excluding PHP for now)...${NC}"
    docker compose up -d mysql redis mailhog

    wait_for_service "mysql"
    wait_for_service "redis"
    echo ""

    # Generate APP_KEY if not set (after Composer dependencies are installed)
    local current_key=$(grep "^APP_KEY=" .env 2>/dev/null | cut -d '=' -f2-)
    if [ -z "$current_key" ]; then
        echo -e "${YELLOW}Generating application key...${NC}"
        if php artisan key:generate --no-interaction 2>/dev/null; then
            # Verify the key was actually written
            local new_key=$(grep "^APP_KEY=" .env 2>/dev/null | cut -d '=' -f2-)
            if [ -n "$new_key" ]; then
                echo -e "${GREEN}Application key generated.${NC}"
            else
                echo -e "${RED}✗ key:generate ran but APP_KEY is still empty. Generating manually...${NC}"
                local generated_key="base64:$(openssl rand -base64 32)"
                if [[ "$OSTYPE" == "darwin"* ]]; then
                    sed -i '' "s|^APP_KEY=.*|APP_KEY=${generated_key}|g" .env
                else
                    sed -i "s|^APP_KEY=.*|APP_KEY=${generated_key}|g" .env
                fi
                echo -e "${GREEN}Application key generated (manual fallback).${NC}"
            fi
        else
            echo -e "${YELLOW}php artisan key:generate failed. Generating key manually...${NC}"
            local generated_key="base64:$(openssl rand -base64 32)"
            if [[ "$OSTYPE" == "darwin"* ]]; then
                sed -i '' "s|^APP_KEY=.*|APP_KEY=${generated_key}|g" .env
            else
                sed -i "s|^APP_KEY=.*|APP_KEY=${generated_key}|g" .env
            fi
            echo -e "${GREEN}Application key generated (manual fallback).${NC}"
        fi
    else
        echo -e "${GREEN}Application key already exists.${NC}"
    fi
    echo ""

    # Now start PHP service (after dependencies are installed)
    echo -e "${BLUE}Starting PHP service...${NC}"
    if ! docker compose --profile full up -d php; then
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
        bun install
        echo -e "${GREEN}Bun dependencies installed.${NC}"
    elif command_exists npm; then
        echo -e "${BLUE}Installing npm dependencies...${NC}"
        npm install
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
    if docker compose exec -T php php artisan migrate --force; then
        echo -e "${GREEN}Database migrations completed.${NC}"
    else
        echo -e "${RED}✗ Database migrations failed.${NC}"
        echo -e "${YELLOW}Try running manually: docker compose exec php php artisan migrate --force${NC}"
    fi
    echo ""

    # Setup portless for HTTPS access
    setup_portless
    echo ""

    # Success message
    echo ""
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  ✓ Whisper Money has been installed successfully!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
    echo -e "${BLUE}Your application is now running!${NC}"
    echo ""
    echo -e "  ${GREEN}Visit: https://whisper.money.localhost${NC}"
    echo ""
    echo -e "${GREEN}✓ Using portless for HTTPS (auto-trusted certificates)${NC}"
    echo ""
    echo -e "${BLUE}Services running:${NC}"
    echo -e "  • PHP development server (port 8000)"
    echo -e "  • Portless HTTPS proxy (port 443)"
    echo -e "  • MySQL database"
    echo -e "  • Redis cache"
    echo -e "  • MailHog (email testing)"
    echo ""
    echo -e "${YELLOW}To stop all services:${NC}"
    echo -e "  ${YELLOW}whispermoney stop${NC}"
    echo ""
    echo -e "${YELLOW}To start services again:${NC}"
    echo -e "  ${YELLOW}whispermoney start${NC}"
    echo ""

    # Show sponsor message on first install
    print_sponsor_message
}

# Upgrade function
upgrade() {
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
    docker compose --profile full restart
    echo -e "${GREEN}Services restarted.${NC}"
    echo ""

    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  ✓ Whisper Money has been upgraded successfully!${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
}

# Interactive menu
ask_for_action() {
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
    print_header

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
    logs)
        show_logs "${2:-php}"
        ;;
    "")
        ask_for_action
        ;;
    *)
        echo -e "${RED}Unknown command: $1${NC}"
        echo ""
        echo "Usage: $0 [install|start|stop|upgrade|logs]"
        echo ""
        echo "Commands:"
        echo "  install         - Install Whisper Money"
        echo "  start           - Start Docker services"
        echo "  stop            - Stop Docker services"
        echo "  upgrade         - Upgrade Whisper Money"
        echo "  logs [service]  - Show logs (default: php)"
        echo ""
        echo "Examples:"
        echo "  whispermoney logs php    - Show PHP logs"
        echo "  whispermoney logs mysql  - Show MySQL logs"
        echo ""
        echo "If no command is provided, an interactive menu will be shown."
        exit 1
        ;;
    esac
}

# Run main function
main "$@"
