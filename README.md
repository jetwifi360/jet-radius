# JetWifi Radius & Mangospot System

This repository serves as a complete backup and installer for the JetWifi FreeRADIUS and Mangospot system.

## Repository Structure

- `freeradius/`: Contains the FreeRADIUS 3.0 configuration files.
- `mangospot/`: Contains the Mangospot web application files.
- `database/`: Contains the MySQL database dump (`mangospot.sql`).
- `scripts/backup.sh`: Script to sync the current system state INTO this repository.
- `install.sh`: Script to install dependencies and deploy the system FROM this repository.

## Usage

### 1. Backing Up (On Source Machine)

To update the backup in this repository with the current state of your server:

1. Clone this repository (if not already done).
2. Run the backup script:
   ```bash
   ./scripts/backup.sh
   ```
   *Note: You may be prompted for your sudo password.*
   
3. Commit and push the changes to GitHub:
   ```bash
   git add .
   git commit -m "Backup update: $(date)"
   git push origin main
   ```

### 2. Installing / Restoring (On New Machine)

To deploy the system on a fresh Ubuntu server:

1. Clone this repository:
   ```bash
   git clone https://github.com/jetwifi360/jet-radius.git
   cd jet-radius
   ```

2. Run the installation script as root:
   ```bash
   sudo ./install.sh
   ```

The script will:
- Install all required dependencies (Apache, MySQL, FreeRADIUS, PHP, etc.).
- Configure the database and import the SQL dump.
- Deploy the web application to `/var/www/html/mangospot`.
- Deploy the FreeRADIUS configuration to `/etc/freeradius/3.0`.
- Set appropriate permissions and restart services.

**Note:** The system assumes the database credentials are:
- DB Name: `mangospot`
- User: `mangospot`
- Password: `JetWifiAdmin123`
