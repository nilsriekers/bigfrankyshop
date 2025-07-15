#!/bin/bash

# WordPress Restore System
# Restores WordPress from backup created by backup-system.sh

# Configuration
BACKUP_DIR="/root/backups"
WORDPRESS_DIR="/root/wordpress-setup"
RESTORE_DIR="/tmp/wordpress_restore"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
}

warn() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

info() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')] INFO:${NC} $1"
}

# Usage function
usage() {
    echo "Usage: $0 <backup_file.tar.gz> [options]"
    echo ""
    echo "Options:"
    echo "  --files-only     Restore only WordPress files"
    echo "  --db-only        Restore only database"
    echo "  --volumes-only   Restore only Docker volumes"
    echo "  --dry-run        Show what would be done without actually doing it"
    echo ""
    echo "Examples:"
    echo "  $0 wordpress_backup_20241214_120000.tar.gz"
    echo "  $0 wordpress_backup_20241214_120000.tar.gz --files-only"
    echo "  $0 wordpress_backup_20241214_120000.tar.gz --dry-run"
}

# List available backups
list_backups() {
    echo -e "${BLUE}Available backups:${NC}"
    ls -la "${BACKUP_DIR}"/wordpress_backup_*.tar.gz 2>/dev/null | while read -r line; do
        echo "  $line"
    done
    echo ""
}

# Extract backup
extract_backup() {
    local backup_file="$1"
    
    log "Extracting backup: $backup_file"
    
    # Create restore directory
    mkdir -p "$RESTORE_DIR"
    cd "$RESTORE_DIR" || return 1
    
    # Extract backup
    tar -xzf "$backup_file"
    
    if [ $? -eq 0 ]; then
        log "Backup extracted successfully"
        return 0
    else
        error "Failed to extract backup"
        return 1
    fi
}

# Show backup manifest
show_manifest() {
    if [ -f "$RESTORE_DIR"/*/backup_manifest.txt ]; then
        info "Backup Manifest:"
        cat "$RESTORE_DIR"/*/backup_manifest.txt
        echo ""
    fi
}

# Stop WordPress containers
stop_wordpress() {
    log "Stopping WordPress containers..."
    
    if [ "$DRY_RUN" = "true" ]; then
        info "[DRY RUN] Would stop WordPress containers"
        return 0
    fi
    
    cd "$WORDPRESS_DIR" || return 1
    docker compose down
    
    if [ $? -eq 0 ]; then
        log "WordPress containers stopped"
    else
        warn "Failed to stop WordPress containers (they might not be running)"
    fi
}

# Restore WordPress files
restore_files() {
    log "Restoring WordPress files..."
    
    local backup_dir=$(find "$RESTORE_DIR" -name "wordpress_backup_*" -type d | head -1)
    
    if [ ! -f "$backup_dir/wordpress_files.tar.gz" ]; then
        error "WordPress files backup not found"
        return 1
    fi
    
    if [ "$DRY_RUN" = "true" ]; then
        info "[DRY RUN] Would restore WordPress files from $backup_dir/wordpress_files.tar.gz"
        return 0
    fi
    
    # Backup current WordPress directory
    if [ -d "$WORDPRESS_DIR" ]; then
        warn "Backing up current WordPress directory to ${WORDPRESS_DIR}.backup.$(date +%Y%m%d_%H%M%S)"
        mv "$WORDPRESS_DIR" "${WORDPRESS_DIR}.backup.$(date +%Y%m%d_%H%M%S)"
    fi
    
    # Create WordPress directory
    mkdir -p "$WORDPRESS_DIR"
    cd "$WORDPRESS_DIR" || return 1
    
    # Extract WordPress files
    tar -xzf "$backup_dir/wordpress_files.tar.gz"
    
    if [ $? -eq 0 ]; then
        log "WordPress files restored successfully"
    else
        error "Failed to restore WordPress files"
        return 1
    fi
}

# Restore database
restore_database() {
    log "Restoring WordPress database..."
    
    local backup_dir=$(find "$RESTORE_DIR" -name "wordpress_backup_*" -type d | head -1)
    
    if [ ! -f "$backup_dir/wordpress_database.sql.gz" ]; then
        error "Database backup not found"
        return 1
    fi
    
    if [ "$DRY_RUN" = "true" ]; then
        info "[DRY RUN] Would restore database from $backup_dir/wordpress_database.sql.gz"
        return 0
    fi
    
    # Start only database container
    cd "$WORDPRESS_DIR" || return 1
    docker compose up -d db
    
    # Wait for database to be ready
    log "Waiting for database to be ready..."
    sleep 10
    
    # Load database credentials
    source "${WORDPRESS_DIR}/.env"
    
    # Drop and recreate database
    docker compose exec -T db mysql -u"${DB_USER}" -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME};"
    
    # Import database
    gunzip -c "$backup_dir/wordpress_database.sql.gz" | docker compose exec -T db mysql -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}"
    
    if [ $? -eq 0 ]; then
        log "Database restored successfully"
    else
        error "Failed to restore database"
        return 1
    fi
}

# Restore Docker volumes
restore_volumes() {
    log "Restoring Docker volumes..."
    
    local backup_dir=$(find "$RESTORE_DIR" -name "wordpress_backup_*" -type d | head -1)
    
    if [ "$DRY_RUN" = "true" ]; then
        info "[DRY RUN] Would restore Docker volumes"
        return 0
    fi
    
    # Remove existing volumes
    docker volume rm wordpress-setup_wordpress_data wordpress-setup_db_data wordpress-setup_redis_data 2>/dev/null
    
    # Restore WordPress volume
    if [ -f "$backup_dir/wordpress_volume.tar.gz" ]; then
        docker volume create wordpress-setup_wordpress_data
        docker run --rm \
            -v wordpress-setup_wordpress_data:/target \
            -v "$backup_dir:/backup" \
            alpine tar -xzf /backup/wordpress_volume.tar.gz -C /target
    fi
    
    # Restore database volume
    if [ -f "$backup_dir/db_volume.tar.gz" ]; then
        docker volume create wordpress-setup_db_data
        docker run --rm \
            -v wordpress-setup_db_data:/target \
            -v "$backup_dir:/backup" \
            alpine tar -xzf /backup/db_volume.tar.gz -C /target
    fi
    
    # Restore Redis volume
    if [ -f "$backup_dir/redis_volume.tar.gz" ]; then
        docker volume create wordpress-setup_redis_data
        docker run --rm \
            -v wordpress-setup_redis_data:/target \
            -v "$backup_dir:/backup" \
            alpine tar -xzf /backup/redis_volume.tar.gz -C /target
    fi
    
    log "Docker volumes restored successfully"
}

# Start WordPress containers
start_wordpress() {
    log "Starting WordPress containers..."
    
    if [ "$DRY_RUN" = "true" ]; then
        info "[DRY RUN] Would start WordPress containers"
        return 0
    fi
    
    cd "$WORDPRESS_DIR" || return 1
    docker compose up -d
    
    if [ $? -eq 0 ]; then
        log "WordPress containers started successfully"
        log "WordPress should be available at: https://shop.bigfranky.de"
    else
        error "Failed to start WordPress containers"
        return 1
    fi
}

# Cleanup
cleanup() {
    log "Cleaning up temporary files..."
    rm -rf "$RESTORE_DIR"
}

# Main restore function
main() {
    local backup_file="$1"
    
    # Parse arguments
    RESTORE_FILES=true
    RESTORE_DB=true
    RESTORE_VOLUMES=true
    DRY_RUN=false
    
    while [[ $# -gt 1 ]]; do
        case $2 in
            --files-only)
                RESTORE_FILES=true
                RESTORE_DB=false
                RESTORE_VOLUMES=false
                shift
                ;;
            --db-only)
                RESTORE_FILES=false
                RESTORE_DB=true
                RESTORE_VOLUMES=false
                shift
                ;;
            --volumes-only)
                RESTORE_FILES=false
                RESTORE_DB=false
                RESTORE_VOLUMES=true
                shift
                ;;
            --dry-run)
                DRY_RUN=true
                shift
                ;;
            *)
                error "Unknown option: $2"
                usage
                exit 1
                ;;
        esac
    done
    
    # Check if backup file is provided
    if [ -z "$backup_file" ]; then
        error "No backup file specified"
        echo ""
        list_backups
        usage
        exit 1
    fi
    
    # Check if backup file exists
    if [ ! -f "$backup_file" ] && [ ! -f "${BACKUP_DIR}/$backup_file" ]; then
        error "Backup file not found: $backup_file"
        echo ""
        list_backups
        exit 1
    fi
    
    # Use full path if relative path is provided
    if [ ! -f "$backup_file" ]; then
        backup_file="${BACKUP_DIR}/$backup_file"
    fi
    
    log "Starting WordPress restore process..."
    log "Backup file: $backup_file"
    
    if [ "$DRY_RUN" = "true" ]; then
        warn "DRY RUN MODE - No actual changes will be made"
    fi
    
    # Extract backup
    extract_backup "$backup_file" || exit 1
    
    # Show manifest
    show_manifest
    
    # Confirm restore
    if [ "$DRY_RUN" != "true" ]; then
        read -p "Are you sure you want to proceed with the restore? This will overwrite existing data. (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log "Restore cancelled by user"
            cleanup
            exit 0
        fi
    fi
    
    # Stop WordPress
    stop_wordpress
    
    # Restore components
    if [ "$RESTORE_FILES" = "true" ]; then
        restore_files || exit 1
    fi
    
    if [ "$RESTORE_VOLUMES" = "true" ]; then
        restore_volumes || exit 1
    fi
    
    if [ "$RESTORE_DB" = "true" ]; then
        restore_database || exit 1
    fi
    
    # Start WordPress
    start_wordpress
    
    # Cleanup
    cleanup
    
    if [ "$DRY_RUN" = "true" ]; then
        log "Dry run completed - no changes were made"
    else
        log "WordPress restore completed successfully!"
        log "You may need to:"
        log "  1. Update DNS settings if domain changed"
        log "  2. Update SSL certificates if needed"
        log "  3. Clear any caches"
    fi
}

# Run main function
main "$@" 