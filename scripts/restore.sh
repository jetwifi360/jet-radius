#!/bin/bash
set -e

# Configuration
DB_USER="mangospot"
DB_PASS="JetWifiAdmin123"
DB_NAME="mangospot"
BACKUP_FILE=$1

if [ -z "$BACKUP_FILE" ]; then
    echo "Usage: $0 <backup_file.tar.gz>"
    exit 1
fi

echo "Installing dependencies..."
# Update package list and install FreeRADIUS and MySQL
sudo apt-get update
sudo apt-get install -y freeradius freeradius-mysql freeradius-utils mysql-server apache2 php php-mysql php-gd php-curl php-mbstring php-xml php-zip

echo "Extracting backup..."
tar -xzf "$BACKUP_FILE"
BACKUP_DIR=$(basename "$BACKUP_FILE" .tar.gz)

echo "Restoring Database..."
# Secure MySQL installation (automate or skip if already done)
# Create database and user if they don't exist
sudo mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Import the database dump
sudo mysql "${DB_NAME}" < "${BACKUP_DIR}/db/${DB_NAME}.sql"

echo "Restoring Mangospot Web App..."
if [ -d "${BACKUP_DIR}/www/mangospot" ]; then
    # Ensure apache is running and webroot exists
    sudo mkdir -p /var/www/html
    # Remove existing mangospot dir if it exists to avoid conflicts
    if [ -d "/var/www/html/mangospot" ]; then
        echo "Removing existing /var/www/html/mangospot..."
        sudo rm -rf /var/www/html/mangospot
    fi
    sudo cp -r "${BACKUP_DIR}/www/mangospot" /var/www/html/
    
    # Set permissions for web server
    sudo chown -R www-data:www-data /var/www/html/mangospot
    sudo chmod -R 755 /var/www/html/mangospot
else
    echo "No web app files found in backup."
fi

echo "Restoring FreeRADIUS configuration..."
# Stop FreeRADIUS service before modifying config
sudo systemctl stop freeradius

# Restore configuration files
sudo cp -r "${BACKUP_DIR}/config/"* /etc/freeradius/3.0/

# Fix permissions
sudo chown -R freerad:freerad /etc/freeradius/3.0/
sudo chmod -R o-rwx /etc/freeradius/3.0/

echo "Starting FreeRADIUS..."
sudo systemctl start freeradius
sudo systemctl enable freeradius

# Cleanup
rm -rf "${BACKUP_DIR}"

echo "Restore completed successfully!"
