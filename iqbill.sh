#!/usr/bin/env bash
# ==============================================================================
# IQ Bill — Production Linux Installer, Uninstaller & Operations Manager
# Tagline : Billing RT/RW Net & Management PPPOE Mikrotik REST API
# Supports: Ubuntu, Debian, CentOS, RHEL, Rocky Linux, AlmaLinux, Fedora
# Follows : Official Docker Engine latest installation & uninstall documentation
# ==============================================================================

set -Eeuo pipefail

# ------------------------------------------------------------------------------
# Constants & Defaults
# ------------------------------------------------------------------------------
readonly APP_BRAND="IQ Bill"
readonly DEFAULT_REPO_URL="https://github.com/nasrulmufid/iqbill.git"
readonly DEFAULT_INSTALL_DIR="/opt/iqbill"
readonly DEFAULT_GOWA_IMAGE="aldinokemal2104/go-whatsapp-web-multidevice:v6.10.0"
readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

ACTION=""
INSTALL_DIR=""
REPO_URL="${IQBILL_REPO_URL:-$DEFAULT_REPO_URL}"
NON_INTERACTIVE=false
AUTO_YES=false
PURGE_VOLUMES=false
PURGE_IMAGES=false
PURGE_FILES=false
PURGE_DOCKER=false

OS_ID=""
OS_ID_LIKE=""
OS_VERSION_ID=""
OS_CODENAME=""
OS_PRETTY_NAME=""
OS_FAMILY=""
ARCH_DEB=""
PKG_MGR=""
LOG_FILE=""
CURRENT_STEP="Initialization"
SPINNER_PID=""

# Runtime values populated during install
CFG_APP_URL=""
CFG_APP_PORT="8080"
CFG_GOWA_PORT="3000"
CFG_ADMIN_EMAIL="admin@iqbill.local"
CFG_ADMIN_PASSWORD=""
CFG_COMPANY_NAME="IQ Bill"
CFG_GOWA_USER="iqbill"
CFG_ENV_CREATED=false

# ------------------------------------------------------------------------------
# Terminal Colors & Output Helpers
# ------------------------------------------------------------------------------
if [[ -t 1 ]] && [[ "${TERM:-dumb}" != "dumb" ]]; then
    readonly C_RESET=$'\033[0m'
    readonly C_BOLD=$'\033[1m'
    readonly C_DIM=$'\033[2m'
    readonly C_RED=$'\033[0;31m'
    readonly C_GREEN=$'\033[0;32m'
    readonly C_YELLOW=$'\033[0;33m'
    readonly C_BLUE=$'\033[0;34m'
    readonly C_CYAN=$'\033[0;36m'
else
    readonly C_RESET=""
    readonly C_BOLD=""
    readonly C_DIM=""
    readonly C_RED=""
    readonly C_GREEN=""
    readonly C_YELLOW=""
    readonly C_BLUE=""
    readonly C_CYAN=""
fi

init_logging() {
    local mode="${1:-setup}"
    if [[ -w "/var/log" ]]; then
        LOG_FILE="/var/log/iqbill-${mode}-${TIMESTAMP}.log"
    else
        LOG_FILE="/tmp/iqbill-${mode}-${TIMESTAMP}.log"
    fi
    touch "$LOG_FILE"
    chmod 600 "$LOG_FILE" 2>/dev/null || true
    {
        echo "=================================================================="
        echo "IQ Bill Linux Manager Log — Action: ${mode}"
        echo "Started at : $(date -Iseconds)"
        echo "Host       : $(hostname 2>/dev/null || echo unknown)"
        echo "Kernel     : $(uname -a)"
        echo "=================================================================="
    } >> "$LOG_FILE"
}

log_raw() {
    if [[ -n "$LOG_FILE" ]]; then
        printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"
    fi
}

info() {
    log_raw "[INFO] $*"
    printf '%s[INFO]%s %s\n' "$C_BLUE" "$C_RESET" "$*"
}

ok() {
    log_raw "[OK] $*"
    printf '%s[ OK ]%s %s\n' "$C_GREEN" "$C_RESET" "$*"
}

warn() {
    log_raw "[WARN] $*"
    printf '%s[WARN]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2
}

err() {
    log_raw "[ERROR] $*"
    printf '%s[FAIL]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2
}

die() {
    err "$*"
    if [[ -n "$LOG_FILE" ]]; then
        printf '%sFull diagnostic log:%s %s\n' "$C_DIM" "$C_RESET" "$LOG_FILE" >&2
    fi
    exit 1
}

step_banner() {
    local idx="$1"
    local total="$2"
    local title="$3"
    CURRENT_STEP="Step ${idx}/${total}: ${title}"
    log_raw "===== ${CURRENT_STEP} ====="

    local pct=$(( idx * 100 / total ))
    local filled=$(( pct / 5 ))
    local empty=$(( 20 - filled ))
    local bar=""
    bar="$(printf '%*s' "$filled" '' | tr ' ' '#')$(printf '%*s' "$empty" '' | tr ' ' '-')"

    printf '\n%s%s[%s/%s] %s%s  %s[%s] %3d%%%s\n' \
        "$C_BOLD" "$C_CYAN" "$idx" "$total" "$title" "$C_RESET" \
        "$C_DIM" "$bar" "$pct" "$C_RESET"
}

stop_spinner() {
    if [[ -n "$SPINNER_PID" ]] && kill -0 "$SPINNER_PID" 2>/dev/null; then
        kill "$SPINNER_PID" 2>/dev/null || true
        wait "$SPINNER_PID" 2>/dev/null || true
    fi
    SPINNER_PID=""
}

run_logged() {
    local label="$1"
    shift

    log_raw ">>> ${label} :: CMD: $*"

    if [[ -t 1 ]] && [[ "${TERM:-dumb}" != "dumb" ]]; then
        (
            local frames=('|' '/' '-' '\')
            local i=0
            while true; do
                printf '\r  %s[%s]%s %s...' "$C_CYAN" "${frames[$((i % 4))]}" "$C_RESET" "$label"
                i=$((i + 1))
                sleep 0.15
            done
        ) &
        SPINNER_PID=$!

        local rc=0
        if "$@" >> "$LOG_FILE" 2>&1; then
            rc=0
        else
            rc=$?
        fi

        stop_spinner
        if [[ $rc -eq 0 ]]; then
            printf '\r  %s[OK]%s %s\n' "$C_GREEN" "$C_RESET" "$label"
            return 0
        else
            printf '\r  %s[FAIL]%s %s (exit code %d)\n' "$C_RED" "$C_RESET" "$label" "$rc" >&2
            return "$rc"
        fi
    else
        printf '  ... %s\n' "$label"
        if "$@" >> "$LOG_FILE" 2>&1; then
            ok "$label"
            return 0
        else
            local rc=$?
            err "${label} failed (exit code ${rc})"
            return "$rc"
        fi
    fi
}

# ------------------------------------------------------------------------------
# Traps (Clear Error Diagnostics & Clean Interruption)
# ------------------------------------------------------------------------------
on_error() {
    local rc=$?
    local lineno="${1:-unknown}"
    local cmd="${2:-unknown}"
    stop_spinner

    log_raw "[FATAL] Step='${CURRENT_STEP}' Line=${lineno} Exit=${rc} Cmd='${cmd}'"

    printf '\n%s======================================================================%s\n' "$C_RED" "$C_RESET" >&2
    printf '%s  INSTALLATION / OPERATION FAILED%s\n' "$C_BOLD$C_RED" "$C_RESET" >&2
    printf '%s======================================================================%s\n' "$C_RED" "$C_RESET" >&2
    printf '  Step      : %s\n' "$CURRENT_STEP" >&2
    printf '  Line      : %s\n' "$lineno" >&2
    printf '  Exit Code : %s\n' "$rc" >&2
    printf '  Command   : %s\n' "$cmd" >&2
    if [[ -n "$LOG_FILE" && -f "$LOG_FILE" ]]; then
        printf '  Log File  : %s\n' "$LOG_FILE" >&2
        printf '\n%s  Last 25 lines of log output:%s\n' "$C_YELLOW" "$C_RESET" >&2
        tail -n 25 "$LOG_FILE" | sed 's/^/    /' >&2
    fi
    printf '%s======================================================================%s\n\n' "$C_RED" "$C_RESET" >&2
    exit "$rc"
}

on_interrupt() {
    stop_spinner
    warn "Operation cancelled by user."
    if [[ -n "$LOG_FILE" ]]; then
        warn "Partial log saved at: ${LOG_FILE}"
    fi
    exit 130
}

trap 'on_error "${LINENO}" "${BASH_COMMAND}"' ERR
trap 'on_interrupt' INT TERM

# ------------------------------------------------------------------------------
# Prompts & Utilities
# ------------------------------------------------------------------------------
prompt_value() {
    local prompt_text="$1"
    local default_val="$2"
    local out_var="$3"

    if [[ "$NON_INTERACTIVE" == "true" ]]; then
        printf -v "$out_var" '%s' "$default_val"
        return 0
    fi

    local input=""
    if [[ -n "$default_val" ]]; then
        read -r -p "  ${prompt_text} [${default_val}]: " input
        input="${input:-$default_val}"
    else
        read -r -p "  ${prompt_text}: " input
    fi
    printf -v "$out_var" '%s' "$input"
}

prompt_yes_no() {
    local prompt_text="$1"
    local default_ans="${2:-n}"

    if [[ "$AUTO_YES" == "true" || "$NON_INTERACTIVE" == "true" ]]; then
        [[ "$default_ans" == "y" ]] && return 0 || return 1
    fi

    local hint="[y/N]"
    [[ "$default_ans" == "y" ]] && hint="[Y/n]"

    local ans=""
    read -r -p "  ${prompt_text} ${hint}: " ans
    ans="${ans:-$default_ans}"
    [[ "${ans,,}" == "y" || "${ans,,}" == "yes" ]]
}

generate_secret() {
    local len="${1:-24}"
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$len"
    else
        tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$len"
    fi
}

generate_app_key() {
    if command -v openssl >/dev/null 2>&1; then
        printf 'base64:%s' "$(openssl rand -base64 32)"
    else
        printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    fi
}

is_port_in_use() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tuln 2>/dev/null | awk '{print $5}' | grep -Eq "(:|\\])${port}$"
        return $?
    elif command -v netstat >/dev/null 2>&1; then
        netstat -tuln 2>/dev/null | awk '{print $4}' | grep -Eq "(:|\\])${port}$"
        return $?
    fi
    return 1
}

find_next_free_port() {
    local start_port="$1"
    local p="$start_port"
    while is_port_in_use "$p"; do
        p=$((p + 1))
        if [[ $p -gt 65000 ]]; then
            echo "$start_port"
            return 0
        fi
    done
    echo "$p"
}

detect_host_ip() {
    local ip=""
    if command -v hostname >/dev/null 2>&1; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi
    if [[ -z "$ip" ]] && command -v ip >/dev/null 2>&1; then
        ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") {print $(i+1); exit}}')"
    fi
    echo "${ip:-127.0.0.1}"
}

set_env_var() {
    local file="$1"
    local key="$2"
    local val="$3"

    # Quote value if it contains spaces
    local formatted="$val"
    if [[ "$val" =~ [[:space:]] ]]; then
        formatted="\"${val//\"/\\\"}\""
    fi

    if grep -q "^${key}=" "$file" 2>/dev/null; then
        awk -v k="$key" -v v="$formatted" '
            BEGIN { replaced = 0 }
            $0 ~ ("^" k "=") && !replaced { print k "=" v; replaced = 1; next }
            { print }
        ' "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
    else
        printf '%s=%s\n' "$key" "$formatted" >> "$file"
    fi
}

get_env_var() {
    local file="$1"
    local key="$2"
    local val=""
    if [[ -f "$file" ]]; then
        val="$(grep -E "^${key}=" "$file" | head -n 1 | cut -d'=' -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")"
    fi
    printf '%s' "$val"
}

# ------------------------------------------------------------------------------
# Step 1: Root, OS, Architecture & System Preflight Checks
# ------------------------------------------------------------------------------
require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        die "This script must be run with root privileges. Run: sudo bash $0 ${ACTION:-install}"
    fi
}

check_os_and_preflight() {
    [[ "$(uname -s)" == "Linux" ]] || die "Unsupported operating system: $(uname -s). Linux is required."

    local raw_arch
    raw_arch="$(uname -m)"
    case "$raw_arch" in
        x86_64|amd64)  ARCH_DEB="amd64" ;;
        aarch64|arm64) ARCH_DEB="arm64" ;;
        *) die "Unsupported CPU architecture: ${raw_arch}. Required: x86_64 (amd64) or aarch64 (arm64)." ;;
    esac

    [[ -r /etc/os-release ]] || die "Cannot read /etc/os-release to detect Linux distribution."
    # shellcheck disable=SC1091
    source /etc/os-release

    OS_ID="${ID,,}"
    OS_ID_LIKE="${ID_LIKE,,}"
    OS_VERSION_ID="${VERSION_ID:-unknown}"
    OS_PRETTY_NAME="${PRETTY_NAME:-$OS_ID $OS_VERSION_ID}"

    case "$OS_ID" in
        ubuntu)
            OS_FAMILY="ubuntu"
            OS_CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
            PKG_MGR="apt"
            ;;
        debian)
            OS_FAMILY="debian"
            OS_CODENAME="${VERSION_CODENAME:-}"
            PKG_MGR="apt"
            ;;
        centos|rocky|almalinux|ol)
            OS_FAMILY="centos"
            OS_CODENAME="$OS_VERSION_ID"
            PKG_MGR="$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)"
            ;;
        rhel)
            OS_FAMILY="rhel"
            OS_CODENAME="$OS_VERSION_ID"
            PKG_MGR="$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)"
            ;;
        fedora)
            OS_FAMILY="fedora"
            OS_CODENAME="$OS_VERSION_ID"
            PKG_MGR="dnf"
            ;;
        *)
            if [[ "$OS_ID_LIKE" == *"ubuntu"* && -n "${UBUNTU_CODENAME:-}" ]]; then
                OS_FAMILY="ubuntu"
                OS_CODENAME="$UBUNTU_CODENAME"
                PKG_MGR="apt"
            elif [[ "$OS_ID_LIKE" == *"debian"* && -n "${VERSION_CODENAME:-}" ]]; then
                OS_FAMILY="debian"
                OS_CODENAME="$VERSION_CODENAME"
                PKG_MGR="apt"
            elif [[ "$OS_ID_LIKE" == *"rhel"* || "$OS_ID_LIKE" == *"centos"* ]]; then
                OS_FAMILY="centos"
                OS_CODENAME="$OS_VERSION_ID"
                PKG_MGR="$(command -v dnf >/dev/null 2>&1 && echo dnf || echo yum)"
            else
                die "Unsupported Linux distribution '${OS_PRETTY_NAME}'. Supported: Ubuntu, Debian, CentOS, RHEL, Rocky, AlmaLinux, Fedora."
            fi
            ;;
    esac

    if [[ "$PKG_MGR" == "apt" && -z "$OS_CODENAME" ]]; then
        die "Could not determine distribution codename from /etc/os-release."
    fi

    ok "Detected OS       : ${OS_PRETTY_NAME} (family: ${OS_FAMILY}, codename: ${OS_CODENAME})"
    ok "Architecture      : ${raw_arch} (${ARCH_DEB}) | Package manager: ${PKG_MGR}"

    # Check systemd
    if ! command -v systemctl >/dev/null 2>&1; then
        warn "systemd (systemctl) was not detected. Service auto-start may require manual handling."
    fi

    # Check disk space (require >= 2GB free on /var/lib or /)
    local check_path="/"
    [[ -d /var/lib ]] && check_path="/var/lib"
    local avail_kb
    avail_kb="$(df -Pk "$check_path" | awk 'NR==2 {print $4}')"
    local avail_gb=$(( avail_kb / 1024 / 1024 ))
    if (( avail_kb < 2097152 )); then
        die "Insufficient disk space (${avail_gb} GB free on ${check_path}). At least 2 GB is required (5 GB+ recommended)."
    elif (( avail_kb < 5242880 )); then
        warn "Low disk space detected: ${avail_gb} GB free on ${check_path} (5 GB+ recommended for Docker images & MySQL)."
    else
        ok "Available disk    : ${avail_gb} GB free on ${check_path}"
    fi

    # Check RAM
    local total_ram_mb
    total_ram_mb="$(awk '/MemTotal:/ {printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)"
    if (( total_ram_mb > 0 && total_ram_mb < 1536 )); then
        warn "Total RAM is ${total_ram_mb} MB. At least 2 GB RAM (or swap) is recommended for MySQL 8 + PHP + Redis + Go-WhatsApp."
    elif (( total_ram_mb > 0 )); then
        ok "Total system RAM  : ${total_ram_mb} MB"
    fi
}

# ------------------------------------------------------------------------------
# Step 2: Install Base Utilities
# ------------------------------------------------------------------------------
install_base_prerequisites() {
    if [[ "$PKG_MGR" == "apt" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        run_logged "Updating APT package index" apt-get update -y
        run_logged "Installing base prerequisites (ca-certificates, curl, gnupg, git, openssl, iproute2)" \
            apt-get install -y --no-install-recommends ca-certificates curl gnupg git openssl iproute2
    else
        run_logged "Installing base prerequisites (ca-certificates, curl, git, openssl, iproute)" \
            "$PKG_MGR" install -y ca-certificates curl git openssl iproute
    fi

    # Verify outbound HTTPS access to Docker registry
    run_logged "Checking connectivity to download.docker.com" \
        curl -fsSL --connect-timeout 10 --max-time 20 -I "https://download.docker.com/linux/"
}

# ------------------------------------------------------------------------------
# Step 3: Official Latest Docker Engine + Buildx + Compose Plugin Installation
# ------------------------------------------------------------------------------
docker_stack_ready() {
    command -v docker >/dev/null 2>&1 \
        && docker compose version >/dev/null 2>&1 \
        && docker buildx version >/dev/null 2>&1
}

install_docker_ubuntu_debian() {
    export DEBIAN_FRONTEND=noninteractive
    local repo_distro="$OS_FAMILY" # ubuntu or debian

    # 1. Remove unofficial / conflicting packages per official Docker docs
    local conflicting_pkgs=(docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc)
    local to_remove=()
    local pkg
    for pkg in "${conflicting_pkgs[@]}"; do
        if dpkg-query -W -f='${Status}\n' "$pkg" 2>/dev/null | grep -q "install ok installed"; then
            to_remove+=("$pkg")
        fi
    done

    if (( ${#to_remove[@]} > 0 )); then
        run_logged "Removing conflicting unofficial packages (${to_remove[*]})" \
            apt-get remove -y "${to_remove[@]}"
    else
        info "No conflicting unofficial Docker packages found."
    fi

    # 2. Set up Docker's official APT GPG key (/etc/apt/keyrings/docker.asc)
    run_logged "Creating /etc/apt/keyrings directory" install -m 0755 -d /etc/apt/keyrings
    run_logged "Downloading official Docker GPG key (${repo_distro})" \
        curl -fsSL "https://download.docker.com/linux/${repo_distro}/gpg" -o /etc/apt/keyrings/docker.asc
    run_logged "Setting read permissions on /etc/apt/keyrings/docker.asc" chmod a+r /etc/apt/keyrings/docker.asc

    # 3. Add Docker's official APT repository
    rm -f /etc/apt/sources.list.d/docker.sources 2>/dev/null || true
    cat > /etc/apt/sources.list.d/docker.list <<EOF
deb [arch=${ARCH_DEB} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${repo_distro} ${OS_CODENAME} stable
EOF
    ok "Configured official Docker APT repository (${repo_distro} / ${OS_CODENAME})"

    # 4. Update APT index & install latest Docker Engine + plugins
    run_logged "Refreshing APT package index with Docker repository" apt-get update -y
    run_logged "Installing docker-ce, docker-ce-cli, containerd.io, docker-buildx-plugin, docker-compose-plugin" \
        apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

install_docker_rhel_centos_fedora() {
    local repo_url=""
    case "$OS_FAMILY" in
        fedora) repo_url="https://download.docker.com/linux/fedora/docker-ce.repo" ;;
        rhel)   repo_url="https://download.docker.com/linux/rhel/docker-ce.repo" ;;
        *)      repo_url="https://download.docker.com/linux/centos/docker-ce.repo" ;;
    esac

    # 1. Remove older/conflicting packages per official Docker docs
    local conflicting_pkgs=(
        docker docker-client docker-client-latest docker-common
        docker-latest docker-latest-logrotate docker-logrotate
        docker-engine podman runc
    )
    run_logged "Removing conflicting legacy Docker/Podman packages (if installed)" \
        "$PKG_MGR" remove -y "${conflicting_pkgs[@]}" || true

    # 2. Install dnf-plugins-core & add official Docker repository
    run_logged "Installing dnf-plugins-core" "$PKG_MGR" install -y dnf-plugins-core

    # Support both DNF5 (Fedora 41+) and DNF4/YUM syntax
    if "$PKG_MGR" config-manager --help 2>&1 | grep -q "addrepo"; then
        run_logged "Adding official Docker repository (${repo_url})" \
            "$PKG_MGR" config-manager addrepo --overwrite --from-repofile="$repo_url"
    else
        run_logged "Adding official Docker repository (${repo_url})" \
            "$PKG_MGR" config-manager --add-repo "$repo_url"
    fi

    # 3. Install latest Docker Engine + plugins
    run_logged "Installing docker-ce, docker-ce-cli, containerd.io, docker-buildx-plugin, docker-compose-plugin" \
        "$PKG_MGR" install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

ensure_docker_installed() {
    if docker_stack_ready; then
        ok "Docker Engine, Buildx plugin, and Compose v2 plugin are already installed."
    else
        info "Installing latest Docker Engine and official plugins for ${OS_FAMILY}..."
        if [[ "$PKG_MGR" == "apt" ]]; then
            install_docker_ubuntu_debian
        else
            install_docker_rhel_centos_fedora
        fi
    fi

    if command -v systemctl >/dev/null 2>&1; then
        run_logged "Enabling and starting docker.service & containerd.service" \
            systemctl enable --now containerd.service docker.service
    fi

    if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
        if id -nG "$SUDO_USER" 2>/dev/null | grep -qw docker; then
            info "User '${SUDO_USER}' is already in the 'docker' group."
        else
            run_logged "Adding user '${SUDO_USER}' to the 'docker' group" usermod -aG docker "$SUDO_USER"
        fi
    fi
}

verify_docker_installation() {
    run_logged "Checking Docker daemon responsiveness (docker info)" docker info

    local docker_ver compose_ver buildx_ver
    docker_ver="$(docker version --format '{{.Server.Version}}' 2>/dev/null || docker --version)"
    compose_ver="$(docker compose version --short 2>/dev/null || docker compose version)"
    buildx_ver="$(docker buildx version 2>/dev/null | head -n 1)"

    ok "Docker Engine  : v${docker_ver}"
    ok "Docker Compose : ${compose_ver}"
    ok "Docker Buildx  : ${buildx_ver}"
}

# ------------------------------------------------------------------------------
# Step 5: Prepare IQ Bill Source Directory
# ------------------------------------------------------------------------------
prepare_application_source() {
    if [[ -z "$INSTALL_DIR" ]]; then
        if [[ -f "${SCRIPT_DIR}/docker-compose.yml" && -f "${SCRIPT_DIR}/artisan" ]]; then
            INSTALL_DIR="$SCRIPT_DIR"
            ok "Using existing IQ Bill repository at: ${INSTALL_DIR}"
        else
            prompt_value "Installation directory" "$DEFAULT_INSTALL_DIR" INSTALL_DIR
        fi
    fi

    if [[ -f "${INSTALL_DIR}/docker-compose.yml" && -f "${INSTALL_DIR}/artisan" ]]; then
        ok "Verified IQ Bill project files in ${INSTALL_DIR}"
        return 0
    fi

    if [[ -d "$INSTALL_DIR" && -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]]; then
        die "Directory '${INSTALL_DIR}' exists and is not an IQ Bill project directory. Choose an empty directory."
    fi

    prompt_value "IQ Bill Git repository URL" "$REPO_URL" REPO_URL
    mkdir -p "$(dirname "$INSTALL_DIR")"
    run_logged "Cloning IQ Bill repository into ${INSTALL_DIR}" git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"

    [[ -f "${INSTALL_DIR}/docker-compose.yml" ]] || die "Cloned repository is missing docker-compose.yml."
}

# ------------------------------------------------------------------------------
# Step 6: Configure Production .env (with Port Conflict & HTTPS Auto-Detection)
# ------------------------------------------------------------------------------
configure_environment() {
    local env_file="${INSTALL_DIR}/.env"
    local template_file="${INSTALL_DIR}/.env.production.example"

    [[ -f "$template_file" ]] || die "Template ${template_file} not found in ${INSTALL_DIR}."

    if [[ -f "$env_file" ]]; then
        warn "An existing .env file was found at ${env_file}."
        if ! prompt_yes_no "Overwrite existing .env and regenerate production secrets? (Backup will be saved)" "n"; then
            info "Preserving existing .env file."
            CFG_APP_URL="$(get_env_var "$env_file" "APP_URL")"
            CFG_APP_PORT="$(get_env_var "$env_file" "APP_PORT")"
            CFG_APP_PORT="${CFG_APP_PORT:-8080}"
            CFG_GOWA_PORT="$(get_env_var "$env_file" "GOWA_HOST_PORT")"
            CFG_GOWA_PORT="${CFG_GOWA_PORT:-3000}"
            CFG_ADMIN_EMAIL="$(get_env_var "$env_file" "ADMIN_EMAIL")"
            CFG_ADMIN_EMAIL="${CFG_ADMIN_EMAIL:-admin@iqbill.local}"
            CFG_GOWA_USER="$(get_env_var "$env_file" "GOWA_BASIC_AUTH_USER")"
            CFG_GOWA_USER="${CFG_GOWA_USER:-iqbill}"

            # Check for port conflicts on existing .env when containers aren't already holding them
            if is_port_in_use "$CFG_GOWA_PORT" && ! docker ps --format '{{.Names}}' | grep -qx 'iqbill_gowa'; then
                local free_gowa
                free_gowa="$(find_next_free_port 3010)"
                warn "Host port ${CFG_GOWA_PORT} is already in use by another process. Updating GOWA_HOST_PORT to ${free_gowa}."
                set_env_var "$env_file" "GOWA_HOST_PORT" "$free_gowa"
                CFG_GOWA_PORT="$free_gowa"
            fi
            return 0
        fi
        cp "$env_file" "${env_file}.bak.${TIMESTAMP}"
        chmod 600 "${env_file}.bak.${TIMESTAMP}"
        ok "Backed up previous .env to ${env_file}.bak.${TIMESTAMP}"
    fi

    local host_ip
    host_ip="$(detect_host_ip)"

    local suggested_app_port="8080"
    if is_port_in_use "$suggested_app_port"; then
        suggested_app_port="$(find_next_free_port 8081)"
        warn "Port 8080 is already in use on this host. Suggested free HTTP port: ${suggested_app_port}"
    fi

    local suggested_gowa_port="3000"
    if is_port_in_use "$suggested_gowa_port"; then
        suggested_gowa_port="$(find_next_free_port 3010)"
        warn "Port 3000 is already in use on this host. Suggested free Go-WhatsApp port: ${suggested_gowa_port}"
    fi

    prompt_value "ISP / Company Name" "IQ Bill" CFG_COMPANY_NAME
    prompt_value "Host HTTP port for IQ Bill web server" "$suggested_app_port" CFG_APP_PORT
    while is_port_in_use "$CFG_APP_PORT"; do
        warn "Port ${CFG_APP_PORT} is currently in use on this server."
        if [[ "$NON_INTERACTIVE" == "true" ]]; then
            CFG_APP_PORT="$(find_next_free_port "$((CFG_APP_PORT + 1))")"
            warn "Auto-selected free HTTP port: ${CFG_APP_PORT}"
            break
        fi
        local alt_port
        alt_port="$(find_next_free_port "$((CFG_APP_PORT + 1))")"
        prompt_value "Choose a free HTTP port" "$alt_port" CFG_APP_PORT
    done

    local default_url="http://${host_ip}:${CFG_APP_PORT}"
    prompt_value "Public Application URL (use https://domain if behind TLS proxy)" "$default_url" CFG_APP_URL

    prompt_value "Host loopback port for Go-WhatsApp (127.0.0.1)" "$suggested_gowa_port" CFG_GOWA_PORT
    while is_port_in_use "$CFG_GOWA_PORT"; do
        warn "Port ${CFG_GOWA_PORT} is currently in use on this server."
        if [[ "$NON_INTERACTIVE" == "true" ]]; then
            CFG_GOWA_PORT="$(find_next_free_port "$((CFG_GOWA_PORT + 1))")"
            warn "Auto-selected free Go-WhatsApp port: ${CFG_GOWA_PORT}"
            break
        fi
        local alt_gowa
        alt_gowa="$(find_next_free_port "$((CFG_GOWA_PORT + 1))")"
        prompt_value "Choose a free Go-WhatsApp loopback port" "$alt_gowa" CFG_GOWA_PORT
    done

    prompt_value "Initial Super Admin email" "admin@iqbill.local" CFG_ADMIN_EMAIL

    local secure_cookie="false"
    local trusted_proxies=""
    if [[ "$CFG_APP_URL" == https://* ]]; then
        secure_cookie="true"
        trusted_proxies="*"
        info "HTTPS APP_URL detected -> enabling SESSION_SECURE_COOKIE=true and TRUSTED_PROXIES=*"
    else
        secure_cookie="false"
        info "HTTP APP_URL detected -> setting SESSION_SECURE_COOKIE=false (avoids 419 over HTTP; enable true after adding HTTPS)"
    fi

    local app_key db_pass db_root_pass redis_pass gowa_pass
    app_key="$(generate_app_key)"
    db_pass="$(generate_secret 24)"
    db_root_pass="$(generate_secret 28)"
    redis_pass="$(generate_secret 24)"
    gowa_pass="$(generate_secret 24)"
    CFG_ADMIN_PASSWORD="$(generate_secret 16)"

    cp "$template_file" "$env_file"
    set_env_var "$env_file" "APP_KEY" "$app_key"
    set_env_var "$env_file" "APP_URL" "$CFG_APP_URL"
    set_env_var "$env_file" "APP_PORT" "$CFG_APP_PORT"
    set_env_var "$env_file" "SESSION_SECURE_COOKIE" "$secure_cookie"
    set_env_var "$env_file" "TRUSTED_PROXIES" "$trusted_proxies"
    set_env_var "$env_file" "DB_PASSWORD" "$db_pass"
    set_env_var "$env_file" "DB_ROOT_PASSWORD" "$db_root_pass"
    set_env_var "$env_file" "REDIS_PASSWORD" "$redis_pass"
    set_env_var "$env_file" "ADMIN_EMAIL" "$CFG_ADMIN_EMAIL"
    set_env_var "$env_file" "ADMIN_PASSWORD" "$CFG_ADMIN_PASSWORD"
    set_env_var "$env_file" "ADMIN_REQUIRE_PASSWORD_CHANGE" "true"
    set_env_var "$env_file" "ISP_COMPANY_NAME" "$CFG_COMPANY_NAME"
    set_env_var "$env_file" "GOWA_IMAGE" "$DEFAULT_GOWA_IMAGE"
    set_env_var "$env_file" "GOWA_BASIC_AUTH_USER" "$CFG_GOWA_USER"
    set_env_var "$env_file" "GOWA_BASIC_AUTH_PASSWORD" "$gowa_pass"
    set_env_var "$env_file" "GOWA_HOST_BIND" "127.0.0.1"
    set_env_var "$env_file" "GOWA_HOST_PORT" "$CFG_GOWA_PORT"

    chmod 600 "$env_file"
    CFG_ENV_CREATED=true
    ok "Generated production ${env_file} (chmod 600) with strong random secrets."
}

# ------------------------------------------------------------------------------
# Step 7-9: Validate, Build, Launch & Wait for Healthy Containers
# ------------------------------------------------------------------------------
validate_and_deploy_stack() {
    cd "$INSTALL_DIR"
    run_logged "Validating Docker Compose configuration" docker compose config --quiet
}

build_and_start_stack() {
    cd "$INSTALL_DIR"
    run_logged "Pulling base runtime images (MySQL, Redis, Go-WhatsApp)" docker compose pull db redis gowa
    run_logged "Building IQ Bill production images (app & web)" docker compose build
    run_logged "Starting IQ Bill production containers" docker compose up -d --remove-orphans
}

wait_for_stack_health() {
    cd "$INSTALL_DIR"
    local timeout_sec=180
    local elapsed=0
    local health_containers=(iqbill_db iqbill_redis iqbill_app iqbill_web iqbill_queue iqbill_scheduler)

    info "Waiting for containers to become healthy (timeout: ${timeout_sec}s)..."

    while (( elapsed < timeout_sec )); do
        local all_ready=true
        local summary=""
        local cname

        for cname in "${health_containers[@]}"; do
            local state health
            state="$(docker inspect -f '{{.State.Status}}' "$cname" 2>/dev/null || echo missing)"
            health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cname" 2>/dev/null || echo none)"

            if [[ "$state" == "exited" || "$state" == "dead" ]]; then
                printf '\n'
                err "Container '${cname}' exited unexpectedly (status: ${state})."
                docker logs --tail 40 "$cname" >> "$LOG_FILE" 2>&1 || true
                die "Container '${cname}' failed to start. Check logs via: docker compose -f ${INSTALL_DIR}/docker-compose.yml logs ${cname#iqbill_}"
            fi

            if [[ "$state" != "running" || ( "$health" != "healthy" && "$health" != "none" ) ]]; then
                all_ready=false
            fi
            summary+="${cname#iqbill_}:${health} "
        done

        local gowa_state
        gowa_state="$(docker inspect -f '{{.State.Status}}' iqbill_gowa 2>/dev/null || echo missing)"
        if [[ "$gowa_state" != "running" ]]; then
            all_ready=false
        fi
        summary+="gowa:${gowa_state}"

        if [[ -t 1 ]]; then
            printf '\r  [%3ds/%ds] %s   ' "$elapsed" "$timeout_sec" "$summary"
        fi

        if [[ "$all_ready" == "true" ]]; then
            [[ -t 1 ]] && printf '\n'
            ok "All 7 IQ Bill production containers are healthy and running!"
            break
        fi

        sleep 3
        elapsed=$((elapsed + 3))
    done

    if (( elapsed >= timeout_sec )); then
        [[ -t 1 ]] && printf '\n'
        docker compose ps >> "$LOG_FILE" 2>&1 || true
        die "Timed out waiting for containers to report healthy after ${timeout_sec}s."
    fi

    # Verify HTTP health endpoint
    if curl -fsS --max-time 10 "http://127.0.0.1:${CFG_APP_PORT}/up" >/dev/null 2>&1; then
        ok "HTTP health check passed: http://127.0.0.1:${CFG_APP_PORT}/up"
    else
        warn "Containers are running, but http://127.0.0.1:${CFG_APP_PORT}/up did not respond immediately. Verify with: docker compose logs web app"
    fi
}

print_install_summary() {
    printf '\n%s======================================================================%s\n' "$C_GREEN" "$C_RESET"
    printf '%s  IQ BILL DEPLOYMENT COMPLETE%s\n' "$C_BOLD$C_GREEN" "$C_RESET"
    printf '%s======================================================================%s\n' "$C_GREEN" "$C_RESET"
    printf '  Application URL       : %s%s%s\n' "$C_BOLD" "$CFG_APP_URL" "$C_RESET"
    printf '  Install Directory     : %s\n' "$INSTALL_DIR"
    printf '  Super Admin Email     : %s\n' "$CFG_ADMIN_EMAIL"
    if [[ "$CFG_ENV_CREATED" == "true" && -n "$CFG_ADMIN_PASSWORD" ]]; then
        printf '  Initial Temp Password : %s%s%s\n' "$C_BOLD$C_YELLOW" "$CFG_ADMIN_PASSWORD" "$C_RESET"
        printf '  %sSecurity Notice       : Mandatory password change will be enforced on first login.%s\n' "$C_YELLOW" "$C_RESET"
    else
        printf '  Initial Password      : (preserved from existing %s/.env)\n' "$INSTALL_DIR"
    fi
    printf '  Go-WhatsApp Internal  : http://gowa:3000 (auto-synced to IQ Bill)\n'
    printf '  Go-WhatsApp Host Bind : 127.0.0.1:%s (user: %s)\n' "$CFG_GOWA_PORT" "$CFG_GOWA_USER"
    printf '  Installation Log      : %s\n' "$LOG_FILE"
    printf '%s======================================================================%s\n' "$C_GREEN" "$C_RESET"
    printf '\n  Next steps:\n'
    printf '    1. Open %s and sign in as Super Admin.\n' "$CFG_APP_URL"
    printf '    2. Complete the one-time mandatory Super Admin password change.\n'
    printf '    3. Go to WhatsApp -> Go-WhatsApp Device and scan the QR code.\n'
    printf '    4. Configure MikroTik routers & Sumopod payment settings.\n\n'
}

do_install() {
    init_logging "install"
    require_root

    printf '\n%s%s — Production Linux Installer%s\n' "$C_BOLD$C_CYAN" "$APP_BRAND" "$C_RESET"
    printf '%sBilling RT/RW Net & Management PPPOE Mikrotik REST API%s\n' "$C_DIM" "$C_RESET"

    step_banner 1 9 "Checking OS Version, Architecture & System Preflight"
    check_os_and_preflight

    step_banner 2 9 "Installing Base System Prerequisites"
    install_base_prerequisites

    step_banner 3 9 "Installing Docker Engine, Buildx & Compose v2 Plugin"
    ensure_docker_installed

    step_banner 4 9 "Verifying Docker Engine & Official Plugins"
    verify_docker_installation

    step_banner 5 9 "Preparing IQ Bill Application Source"
    prepare_application_source

    step_banner 6 9 "Configuring Production Environment (.env) & Checking Ports"
    configure_environment

    step_banner 7 9 "Validating Docker Compose Stack Configuration"
    validate_and_deploy_stack

    step_banner 8 9 "Building & Launching Production Containers"
    build_and_start_stack

    step_banner 9 9 "Verifying Container Health & Finalizing Setup"
    wait_for_stack_health
    print_install_summary
}

# ------------------------------------------------------------------------------
# Uninstaller Pipeline
# ------------------------------------------------------------------------------
purge_docker_engine() {
    check_os_and_preflight

    if command -v systemctl >/dev/null 2>&1; then
        run_logged "Stopping docker.service and containerd.service" \
            systemctl stop docker.service docker.socket containerd.service || true
    fi

    if [[ "$PKG_MGR" == "apt" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        run_logged "Purging Docker Engine & official plugins via APT" \
            apt-get purge -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-ce-rootless-extras || true
        run_logged "Autoremoving unused APT packages" apt-get autoremove -y || true
        rm -rf /var/lib/docker /var/lib/containerd /etc/apt/sources.list.d/docker.list /etc/apt/sources.list.d/docker.sources /etc/apt/keyrings/docker.asc
    else
        run_logged "Removing Docker Engine & official plugins via ${PKG_MGR}" \
            "$PKG_MGR" remove -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin docker-ce-rootless-extras || true
        rm -rf /var/lib/docker /var/lib/containerd /etc/yum.repos.d/docker-ce.repo
    fi
    ok "Docker Engine and its data directories (/var/lib/docker, /var/lib/containerd) have been removed."
}

do_uninstall() {
    init_logging "uninstall"
    require_root

    printf '\n%s%s — Production Linux Uninstaller%s\n' "$C_BOLD$C_RED" "$APP_BRAND" "$C_RESET"

    step_banner 1 5 "Locating Installation & Confirming Uninstall Options"
    if [[ -z "$INSTALL_DIR" ]]; then
        if [[ -f "${SCRIPT_DIR}/docker-compose.yml" && -f "${SCRIPT_DIR}/artisan" ]]; then
            INSTALL_DIR="$SCRIPT_DIR"
        else
            prompt_value "IQ Bill installation directory to uninstall" "$DEFAULT_INSTALL_DIR" INSTALL_DIR
        fi
    fi
    info "Target directory: ${INSTALL_DIR}"

    if [[ "$AUTO_YES" != "true" ]]; then
        if prompt_yes_no "Delete persistent Docker volumes (MySQL database, Redis, storage, WhatsApp session)?" "n"; then
            PURGE_VOLUMES=true
        fi
        if prompt_yes_no "Remove IQ Bill Docker images?" "y"; then
            PURGE_IMAGES=true
        fi
        if [[ -d "$INSTALL_DIR" ]] && prompt_yes_no "Delete application directory (${INSTALL_DIR})?" "n"; then
            PURGE_FILES=true
        fi
        if prompt_yes_no "Also uninstall Docker Engine & plugins from this server? (Affects OTHER Docker apps!)" "n"; then
            PURGE_DOCKER=true
        fi

        printf '\n%s  Uninstall Plan:%s\n' "$C_BOLD" "$C_RESET"
        printf '    - Stop & remove IQ Bill containers/networks : YES\n'
        printf '    - Purge Docker volumes (DB & WA sessions)   : %s\n' "$PURGE_VOLUMES"
        printf '    - Remove IQ Bill Docker images              : %s\n' "$PURGE_IMAGES"
        printf '    - Delete directory %-24s : %s\n' "(${INSTALL_DIR})" "$PURGE_FILES"
        printf '    - Purge Docker Engine from host             : %s\n\n' "$PURGE_DOCKER"

        local confirm_text=""
        read -r -p "  Type 'UNINSTALL' to proceed (anything else cancels): " confirm_text
        if [[ "$confirm_text" != "UNINSTALL" ]]; then
            warn "Uninstall aborted by user."
            exit 0
        fi
    fi

    step_banner 2 5 "Pre-Uninstall Database Backup Check"
    if [[ "$PURGE_VOLUMES" == "true" ]] && command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx 'iqbill_db'; then
        if prompt_yes_no "Create a SQL backup of MySQL before wiping volumes?" "y"; then
            local backup_file="/root/iqbill-backup-${TIMESTAMP}.sql"
            local db_user db_pass db_name
            db_user="$(get_env_var "${INSTALL_DIR}/.env" "DB_USERNAME")"
            db_pass="$(get_env_var "${INSTALL_DIR}/.env" "DB_PASSWORD")"
            db_name="$(get_env_var "${INSTALL_DIR}/.env" "DB_DATABASE")"
            db_user="${db_user:-iqbill}"
            db_name="${db_name:-iqbill}"
            if [[ -n "$db_pass" ]]; then
                if docker exec iqbill_db mysqldump -u"$db_user" -p"$db_pass" "$db_name" > "$backup_file" 2>> "$LOG_FILE"; then
                    chmod 600 "$backup_file"
                    ok "Database backup saved to ${backup_file}"
                else
                    warn "Automatic mysqldump failed (see ${LOG_FILE}); continuing uninstall."
                    rm -f "$backup_file"
                fi
            else
                warn "DB_PASSWORD not found in ${INSTALL_DIR}/.env; skipping automatic SQL backup."
            fi
        fi
    else
        info "Skipping database backup."
    fi

    step_banner 3 5 "Stopping & Removing IQ Bill Containers, Networks & Volumes"
    if command -v docker >/dev/null 2>&1 && [[ -f "${INSTALL_DIR}/docker-compose.yml" ]]; then
        cd "$INSTALL_DIR"
        local down_args=(down --remove-orphans)
        [[ "$PURGE_VOLUMES" == "true" ]] && down_args+=(-v)
        [[ "$PURGE_IMAGES" == "true" ]] && down_args+=(--rmi local)

        run_logged "Running docker compose ${down_args[*]}" docker compose "${down_args[@]}" || true

        if [[ "$PURGE_IMAGES" == "true" ]]; then
            local gowa_img
            gowa_img="$(get_env_var "${INSTALL_DIR}/.env" "GOWA_IMAGE")"
            gowa_img="${gowa_img:-$DEFAULT_GOWA_IMAGE}"
            run_logged "Removing Go-WhatsApp image (${gowa_img})" docker rmi -f "$gowa_img" || true
        fi
    elif command -v docker >/dev/null 2>&1; then
        info "docker-compose.yml not found in ${INSTALL_DIR}; cleaning up named iqbill_* containers if present."
        local c
        for c in iqbill_web iqbill_app iqbill_queue iqbill_scheduler iqbill_db iqbill_redis iqbill_gowa; do
            if docker ps -a --format '{{.Names}}' | grep -qx "$c"; then
                run_logged "Removing container ${c}" docker rm -f "$c" || true
            fi
        done
    else
        info "Docker is not installed; skipping container teardown."
    fi

    step_banner 4 5 "Cleaning Up Application Directory"
    if [[ "$PURGE_FILES" == "true" && -d "$INSTALL_DIR" ]]; then
        local resolved_dir
        resolved_dir="$(cd "$INSTALL_DIR" && pwd -P)"
        if [[ "$resolved_dir" == "/" || "$resolved_dir" == "/root" || "$resolved_dir" == "/home" || "$resolved_dir" == "/opt" ]]; then
            warn "Refusing to delete unsafe directory path: ${resolved_dir}"
        else
            cd /
            run_logged "Removing application directory ${resolved_dir}" rm -rf "$resolved_dir"
        fi
    else
        info "Application files at ${INSTALL_DIR} were kept."
    fi

    step_banner 5 5 "Optional Docker Engine Removal"
    if [[ "$PURGE_DOCKER" == "true" ]]; then
        purge_docker_engine
    else
        info "Docker Engine and plugins were kept intact."
    fi

    printf '\n%s======================================================================%s\n' "$C_GREEN" "$C_RESET"
    printf '%s  IQ BILL UNINSTALL COMPLETED%s\n' "$C_BOLD$C_GREEN" "$C_RESET"
    printf '%s======================================================================%s\n' "$C_GREEN" "$C_RESET"
    printf '  Uninstall Log : %s\n' "$LOG_FILE"
    printf '%s======================================================================%s\n\n' "$C_GREEN" "$C_RESET"
}

# ------------------------------------------------------------------------------
# Status & Diagnostics
# ------------------------------------------------------------------------------
do_status() {
    if [[ -z "$INSTALL_DIR" ]]; then
        if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
            INSTALL_DIR="$SCRIPT_DIR"
        else
            INSTALL_DIR="$DEFAULT_INSTALL_DIR"
        fi
    fi

    printf '\n%s%s — System & Container Status%s\n\n' "$C_BOLD$C_CYAN" "$APP_BRAND" "$C_RESET"

    if command -v docker >/dev/null 2>&1; then
        ok "Docker Engine  : $(docker --version)"
        if docker compose version >/dev/null 2>&1; then
            ok "Docker Compose : $(docker compose version)"
        else
            err "Docker Compose plugin is missing."
        fi
    else
        err "Docker Engine is not installed."
        exit 1
    fi

    if [[ -f "${INSTALL_DIR}/docker-compose.yml" ]]; then
        printf '\n'
        (cd "$INSTALL_DIR" && docker compose ps)
    else
        warn "No docker-compose.yml found at ${INSTALL_DIR}."
    fi
    printf '\n'
}

# ------------------------------------------------------------------------------
# Usage & Interactive Menu
# ------------------------------------------------------------------------------
show_help() {
    cat <<EOF
${APP_BRAND} — Production Linux Installer & Uninstaller

Usage:
  sudo bash $0                         Open interactive menu
  sudo bash $0 install [options]       Install Docker (if needed) and deploy IQ Bill
  sudo bash $0 uninstall [options]     Stop and uninstall IQ Bill
  sudo bash $0 status [--dir PATH]     Show Docker and IQ Bill container status

Options:
  --dir PATH             Installation directory (default: current repo or /opt/iqbill)
  --repo URL             Git repository URL when installing into a fresh directory
  --non-interactive      Use defaults and auto-generated secrets without prompting
  --yes, -y              Skip interactive confirmation prompts (for automation)
  --purge-volumes        [uninstall] Remove Docker volumes (database, storage, GOWA)
  --purge-images         [uninstall] Remove IQ Bill and GOWA Docker images
  --purge-files          [uninstall] Delete the IQ Bill installation directory
  --purge-docker         [uninstall] Completely uninstall Docker Engine & plugins
  -h, --help             Show this help message
EOF
}

parse_args() {
    if (( $# > 0 )); then
        case "$1" in
            install|uninstall|status)
                ACTION="$1"
                shift
                ;;
            -h|--help)
                show_help
                exit 0
                ;;
        esac
    fi

    while (( $# > 0 )); do
        case "$1" in
            --dir)
                [[ $# -ge 2 ]] || die "Option --dir requires a path argument."
                INSTALL_DIR="$2"
                shift 2
                ;;
            --repo)
                [[ $# -ge 2 ]] || die "Option --repo requires a URL argument."
                REPO_URL="$2"
                shift 2
                ;;
            --non-interactive)
                NON_INTERACTIVE=true
                AUTO_YES=true
                shift
                ;;
            --yes|-y)
                AUTO_YES=true
                shift
                ;;
            --purge-volumes)
                PURGE_VOLUMES=true
                shift
                ;;
            --purge-images)
                PURGE_IMAGES=true
                shift
                ;;
            --purge-files)
                PURGE_FILES=true
                shift
                ;;
            --purge-docker)
                PURGE_DOCKER=true
                shift
                ;;
            -h|--help)
                show_help
                exit 0
                ;;
            *)
                die "Unknown argument: $1 (run with --help for usage)"
                ;;
        esac
    done
}

interactive_menu() {
    printf '\n%s======================================================================%s\n' "$C_CYAN" "$C_RESET"
    printf '%s  IQ Bill — Linux Deployment Manager%s\n' "$C_BOLD$C_CYAN" "$C_RESET"
    printf '%s  Billing RT/RW Net & Management PPPOE Mikrotik REST API%s\n' "$C_DIM" "$C_RESET"
    printf '%s======================================================================%s\n' "$C_CYAN" "$C_RESET"
    printf '  1) Install IQ Bill (Check OS -> Docker Engine & Plugins -> Deploy)\n'
    printf '  2) Uninstall IQ Bill (Safe / Complete Cleanup)\n'
    printf '  3) Check System & Container Status\n'
    printf '  4) Exit\n\n'

    local choice=""
    read -r -p "  Select an option [1-4]: " choice
    case "$choice" in
        1) do_install ;;
        2) do_uninstall ;;
        3) do_status ;;
        4) info "Goodbye."; exit 0 ;;
        *) die "Invalid menu selection: ${choice}" ;;
    esac
}

main() {
    parse_args "$@"

    case "$ACTION" in
        install)   do_install ;;
        uninstall) do_uninstall ;;
        status)    do_status ;;
        "")        interactive_menu ;;
    esac
}

main "$@"
