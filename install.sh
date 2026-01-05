#!/bin/bash
set -e

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
DB_USER="mangospot"
DB_PASS="JetWifiAdmin123"
DB_NAME="mangospot"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
  echo "Please run as root (sudo ./install.sh)"
  exit 1
fi

echo "Updating package lists..."
apt-get update

echo "Installing dependencies..."
# Non-interactive installation for MySQL and others
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    freeradius freeradius-mysql freeradius-utils \
    mysql-server \
    apache2 \
    php php-mysql php-gd php-curl php-mbstring php-xml php-zip \
    rsync

echo "Configuring Database..."
# Secure MySQL installation & Setup User
mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

if [ -f "${SCRIPT_DIR}/database/${DB_NAME}.sql" ]; then
    echo "Importing Database..."
    mysql "${DB_NAME}" < "${SCRIPT_DIR}/database/${DB_NAME}.sql"
else
    echo "Warning: Database dump not found at ${SCRIPT_DIR}/database/${DB_NAME}.sql"
fi

echo "Installing Mangospot Web App..."
mkdir -p /var/www/html
if [ -d "${SCRIPT_DIR}/mangospot" ]; then
    # Use rsync to copy/update
    rsync -av --delete "${SCRIPT_DIR}/mangospot/" /var/www/html/mangospot/
    
    # Set permissions
    chown -R www-data:www-data /var/www/html/mangospot
    chmod -R 755 /var/www/html/mangospot
else
    echo "Warning: Mangospot files not found at ${SCRIPT_DIR}/mangospot"
fi

echo "Installing FreeRADIUS Configuration..."
if [ -d "${SCRIPT_DIR}/freeradius" ]; then
    systemctl stop freeradius
    
    # Backup default config just in case
    if [ ! -d "/etc/freeradius/3.0.bak" ]; then
        cp -r /etc/freeradius/3.0 /etc/freeradius/3.0.bak
    fi

    # Sync config
    rsync -av "${SCRIPT_DIR}/freeradius/" /etc/freeradius/3.0/
    
    # Fix permissions
    chown -R freerad:freerad /etc/freeradius/3.0/
    chmod -R o-rwx /etc/freeradius/3.0/
    
    # Enable SQL module if needed (usually handled by the config files we just copied)
    # Ensure mods-enabled links exist if they were symlinks in the repo (rsync -a handles links)
    
    systemctl start freeradius
    systemctl enable freeradius
else
    echo "Warning: FreeRADIUS config not found at ${SCRIPT_DIR}/freeradius"
fi

echo "Installation Complete!"
echo "You can access Mangospot at http://YOUR_SERVER_IP/mangospot"
