#!/bin/bash
set -e

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
DB_USER="mangospot"
DB_PASS="JetWifiAdmin123"
DB_NAME="mangospot"

echo "Repo Root: $REPO_ROOT"

# Create directories in the repo
mkdir -p "${REPO_ROOT}/freeradius"
mkdir -p "${REPO_ROOT}/mangospot"
mkdir -p "${REPO_ROOT}/database"

echo "Backing up FreeRADIUS configuration..."
# Sync FreeRADIUS config
if [ -d "/etc/freeradius/3.0" ]; then
    sudo rsync -av --delete --exclude 'certs/*.pem' --exclude 'certs/*.key' /etc/freeradius/3.0/ "${REPO_ROOT}/freeradius/"
else
    echo "Error: /etc/freeradius/3.0 not found!"
    exit 1
fi

echo "Backing up Mangospot Web App..."
# Sync Web App
if [ -d "/var/www/html/mangospot" ]; then
    sudo rsync -av --delete --exclude '.git' /var/www/html/mangospot/ "${REPO_ROOT}/mangospot/"
else
    echo "Warning: /var/www/html/mangospot not found!"
fi

echo "Backing up MySQL database..."
# Dump Database
mysqldump -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" > "${REPO_ROOT}/database/${DB_NAME}.sql"

echo "Backup updated in ${REPO_ROOT}"
echo "You can now commit and push these changes to GitHub."
