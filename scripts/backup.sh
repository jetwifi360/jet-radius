#!/bin/bash
set -e

# Configuration
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="radius_backup_${TIMESTAMP}"
DB_USER="mangospot"
DB_PASS="JetWifiAdmin123"
DB_NAME="mangospot"

# Create backup directory
mkdir -p "${BACKUP_DIR}/config"
mkdir -p "${BACKUP_DIR}/db"
mkdir -p "${BACKUP_DIR}/www"

echo "Backing up FreeRADIUS configuration..."
# Copy all FreeRADIUS configuration files
# Using . to copy contents including hidden files if any, but * is usually fine.
# The previous error "cannot stat" might be due to shell expansion in sudo.
sudo cp -r /etc/freeradius/3.0/. "${BACKUP_DIR}/config/"

echo "Backing up Mangospot Web App..."
# Copy web application files
if [ -d "/var/www/html/mangospot" ]; then
    sudo cp -r /var/www/html/mangospot "${BACKUP_DIR}/www/"
else
    echo "Warning: /var/www/html/mangospot not found!"
fi

echo "Backing up MySQL database..."
# Dump the MySQL database
mysqldump -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "${BACKUP_DIR}/db/${DB_NAME}.sql"

echo "Compressing backup..."
# Create a tarball of the backup directory
# Use sudo to ensure we can read all files (some config files might have restrictive permissions)
sudo tar -czf "${BACKUP_DIR}.tar.gz" "${BACKUP_DIR}"

# Cleanup
sudo rm -rf "${BACKUP_DIR}"

echo "Backup completed: ${BACKUP_DIR}.tar.gz"
# Change ownership to current user so they can copy it
sudo chown $USER:$USER "${BACKUP_DIR}.tar.gz"
