# FreeRADIUS Backup & Restore

This repository contains scripts to backup and restore a FreeRADIUS server configuration, including the MySQL database.

## Contents

- `scripts/backup.sh`: Creates a compressed backup of `/etc/freeradius/3.0` and the MySQL database.
- `scripts/restore.sh`: Installs dependencies and restores the configuration and database from a backup file.

## Usage

### Backup

Run the backup script on the source machine:

```bash
./scripts/backup.sh
```

This will generate a file named `radius_backup_YYYYMMDD_HHMMSS.tar.gz`.

### Restore

Transfer the backup file and the `scripts/restore.sh` script to the target machine.

Run the restore script:

```bash
sudo ./scripts/restore.sh radius_backup_YYYYMMDD_HHMMSS.tar.gz
```

**Note:** The scripts assume the database name is `mangospot`, user is `mangospot`, and password is `JetWifiAdmin123`. You may need to edit the scripts if these credentials change.
