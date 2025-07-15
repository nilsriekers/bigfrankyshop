#!/bin/bash

# WordPress Backup System with Retention Policy
# Backup retention: Daily for 7 days, Weekly for 30 days, Monthly thereafter

# Configuration
BACKUP_DIR="/root/backups"
WORDPRESS_DIR="/root/wordpress-setup"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="wordpress_backup_${DATE}"

# Database credentials (from .env file)
source "${WORDPRESS_DIR}/.env"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
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

# Create backup directory
create_backup_dir() {
    log "Creating backup directory: ${BACKUP_DIR}/${BACKUP_NAME}"
    mkdir -p "${BACKUP_DIR}/${BACKUP_NAME}"
}

# Backup WordPress files
backup_wordpress_files() {
    log "Backing up WordPress files..."
    
    # Backup entire WordPress setup directory
    tar -czf "${BACKUP_DIR}/${BACKUP_NAME}/wordpress_files.tar.gz" \
        -C "${WORDPRESS_DIR}" \
        --exclude='./backups' \
        --exclude='./nginx/ssl/live' \
        --exclude='./nginx/ssl/archive' \
        .
    
    if [ $? -eq 0 ]; then
        log "WordPress files backup completed"
    else
        error "WordPress files backup failed"
        return 1
    fi
}

# Backup database
backup_database() {
    log "Backing up WordPress database..."
    
    # Export database using docker
    docker compose -f "${WORDPRESS_DIR}/docker-compose.yml" exec -T db \
        mysqldump -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" \
        > "${BACKUP_DIR}/${BACKUP_NAME}/wordpress_database.sql"
    
    if [ $? -eq 0 ]; then
        log "Database backup completed"
        # Compress database backup
        gzip "${BACKUP_DIR}/${BACKUP_NAME}/wordpress_database.sql"
    else
        error "Database backup failed"
        return 1
    fi
}

# Backup Docker volumes
backup_docker_volumes() {
    log "Backing up Docker volumes..."
    
    # Create volume backups
    docker run --rm \
        -v wordpress-setup_wordpress_data:/source:ro \
        -v "${BACKUP_DIR}/${BACKUP_NAME}:/backup" \
        alpine tar -czf /backup/wordpress_volume.tar.gz -C /source .
    
    docker run --rm \
        -v wordpress-setup_db_data:/source:ro \
        -v "${BACKUP_DIR}/${BACKUP_NAME}:/backup" \
        alpine tar -czf /backup/db_volume.tar.gz -C /source .
    
    docker run --rm \
        -v wordpress-setup_redis_data:/source:ro \
        -v "${BACKUP_DIR}/${BACKUP_NAME}:/backup" \
        alpine tar -czf /backup/redis_volume.tar.gz -C /source .
    
    if [ $? -eq 0 ]; then
        log "Docker volumes backup completed"
    else
        error "Docker volumes backup failed"
        return 1
    fi
}

# Create backup manifest
create_manifest() {
    log "Creating backup manifest..."
    
    cat > "${BACKUP_DIR}/${BACKUP_NAME}/backup_manifest.txt" << EOF
WordPress Backup Manifest
========================
Backup Date: $(date)
Backup Name: ${BACKUP_NAME}
WordPress Directory: ${WORDPRESS_DIR}
Database Name: ${DB_NAME}
Database User: ${DB_USER}

Files Included:
- wordpress_files.tar.gz (WordPress installation + configuration)
- wordpress_database.sql.gz (Complete database dump)
- wordpress_volume.tar.gz (WordPress Docker volume)
- db_volume.tar.gz (Database Docker volume)
- redis_volume.tar.gz (Redis Docker volume)

Restore Instructions:
1. Extract wordpress_files.tar.gz to your WordPress directory
2. Import wordpress_database.sql.gz to your MySQL database
3. Restore Docker volumes using docker commands
4. Update .env file with your database credentials
5. Run: docker compose up -d

EOF

    # Add file sizes
    echo "File Sizes:" >> "${BACKUP_DIR}/${BACKUP_NAME}/backup_manifest.txt"
    ls -lh "${BACKUP_DIR}/${BACKUP_NAME}/" >> "${BACKUP_DIR}/${BACKUP_NAME}/backup_manifest.txt"
}

# Apply retention policy
apply_retention_policy() {
    log "Applying backup retention policy..."
    
    cd "${BACKUP_DIR}" || return 1
    
    # Daily backups: Keep for 7 days
    find . -maxdepth 1 -name "wordpress_backup_*" -type d -mtime +7 -print0 | while read -d $'\0' backup; do
        backup_date=$(echo "$backup" | grep -o '[0-9]\{8\}')
        day_of_week=$(date -d "$backup_date" +%u)  # 1=Monday, 7=Sunday
        
        # Keep if it's a Monday (weekly backup)
        if [ "$day_of_week" != "1" ]; then
            warn "Removing daily backup older than 7 days: $backup"
            rm -rf "$backup"
        fi
    done
    
    # Weekly backups: Keep for 30 days (only Mondays)
    find . -maxdepth 1 -name "wordpress_backup_*" -type d -mtime +30 -print0 | while read -d $'\0' backup; do
        backup_date=$(echo "$backup" | grep -o '[0-9]\{8\}')
        day_of_month=$(date -d "$backup_date" +%d)
        
        # Keep if it's the 1st of the month (monthly backup)
        if [ "$day_of_month" != "01" ]; then
            warn "Removing weekly backup older than 30 days: $backup"
            rm -rf "$backup"
        fi
    done
    
    log "Retention policy applied"
}

# Compress final backup
compress_backup() {
    log "Compressing final backup..."
    
    cd "${BACKUP_DIR}" || return 1
    tar -czf "${BACKUP_NAME}.tar.gz" "${BACKUP_NAME}/"
    
    if [ $? -eq 0 ]; then
        log "Backup compressed successfully"
        rm -rf "${BACKUP_NAME}/"
    else
        error "Backup compression failed"
        return 1
    fi
}

# Main backup function
main() {
    log "Starting WordPress backup process..."
    
    # Check if WordPress is running
    if ! docker compose -f "${WORDPRESS_DIR}/docker-compose.yml" ps | grep -q "Up"; then
        error "WordPress containers are not running!"
        exit 1
    fi
    
    # Create backup directory
    create_backup_dir || exit 1
    
    # Perform backups
    backup_wordpress_files || exit 1
    backup_database || exit 1
    backup_docker_volumes || exit 1
    
    # Create manifest
    create_manifest
    
    # Compress backup
    compress_backup || exit 1
    
    # Apply retention policy
    apply_retention_policy
    
    # Show backup size
    backup_size=$(du -h "${BACKUP_DIR}/${BACKUP_NAME}.tar.gz" | cut -f1)
    log "Backup completed successfully!"
    log "Backup file: ${BACKUP_DIR}/${BACKUP_NAME}.tar.gz"
    log "Backup size: ${backup_size}"
    
    # Show disk usage
    log "Current backup directory usage:"
    du -sh "${BACKUP_DIR}"
}

# Run main function
main "$@" 