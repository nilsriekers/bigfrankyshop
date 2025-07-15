# WordPress Backup & Restore System

## Übersicht

Dieses System erstellt automatische Backups Ihrer WordPress-Installation mit intelligenter Retention-Policy und bietet einfache Restore-Funktionen.

## Backup-Inhalt

Jedes Backup enthält:
- **WordPress-Dateien**: Komplette Installation, Themes, Plugins, Uploads
- **Datenbank**: Vollständiger MySQL-Dump
- **Docker-Volumes**: Alle persistenten Daten
- **Konfiguration**: .env, docker-compose.yml, nginx-Konfiguration
- **SSL-Zertifikate**: Ausgenommen (werden automatisch erneuert)

## Retention-Policy

- **Täglich**: 7 Tage aufbewahren
- **Wöchentlich**: 30 Tage aufbewahren (jeden Montag)
- **Monatlich**: Unbegrenzt (jeden 1. des Monats)

## Automatisches Backup

**Cron-Job**: Täglich um 3:00 Uhr morgens
```bash
0 3 * * * /root/wordpress-setup/backup-system.sh >> /var/log/wordpress-backup.log 2>&1
```

**Log-Datei**: `/var/log/wordpress-backup.log`

## Manuelles Backup

```bash
cd /root/wordpress-setup
./backup-system.sh
```

## Backup-Speicherort

- **Verzeichnis**: `/root/backups/`
- **Format**: `wordpress_backup_YYYYMMDD_HHMMSS.tar.gz`
- **Beispiel**: `wordpress_backup_20241214_030000.tar.gz`

## Restore-Funktionen

### Verfügbare Backups anzeigen
```bash
./restore-backup.sh
```

### Komplettes Restore
```bash
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz
```

### Nur Dateien wiederherstellen
```bash
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz --files-only
```

### Nur Datenbank wiederherstellen
```bash
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz --db-only
```

### Nur Docker-Volumes wiederherstellen
```bash
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz --volumes-only
```

### Dry-Run (Test ohne Änderungen)
```bash
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz --dry-run
```

## Backup auf anderen Server übertragen

### 1. Backup herunterladen
```bash
scp root@185.144.70.101:/root/backups/wordpress_backup_20241214_030000.tar.gz ./
```

### 2. Auf neuen Server hochladen
```bash
scp wordpress_backup_20241214_030000.tar.gz root@NEW_SERVER_IP:/root/backups/
```

### 3. Auf neuem Server wiederherstellen
```bash
cd /root/wordpress-setup
./restore-backup.sh wordpress_backup_20241214_030000.tar.gz
```

## Migration auf neuen Server

### Vorbereitung neuer Server
1. Docker und Docker Compose installieren
2. Backup-Scripts kopieren:
   ```bash
   scp backup-system.sh restore-backup.sh root@NEW_SERVER:/root/wordpress-setup/
   chmod +x /root/wordpress-setup/*.sh
   ```

### Domain-Migration
1. DNS-Einstellungen auf neue IP ändern
2. Backup auf neuen Server übertragen
3. `.env` Datei anpassen (falls nötig)
4. SSL-Zertifikat neu erstellen:
   ```bash
   docker compose exec certbot certbot certonly --webroot -w /var/www/certbot -d shop.bigfranky.de --email shop@bigfranky.de --agree-tos --no-eff-email --force-renewal
   ```

## Wichtige Befehle

### Backup-Status prüfen
```bash
ls -la /root/backups/
du -sh /root/backups/
```

### Log-Datei prüfen
```bash
tail -f /var/log/wordpress-backup.log
```

### Cron-Job prüfen
```bash
crontab -l
systemctl status cron
```

### Letzte Backups anzeigen
```bash
ls -lt /root/backups/ | head -10
```

## Disaster Recovery

### Schneller Restore bei Problemen
```bash
cd /root/wordpress-setup
./restore-backup.sh $(ls -t /root/backups/wordpress_backup_*.tar.gz | head -1)
```

### Backup-Validierung
```bash
# Backup-Größe prüfen
ls -lh /root/backups/wordpress_backup_*.tar.gz

# Backup-Inhalt prüfen
tar -tzf /root/backups/wordpress_backup_20241214_030000.tar.gz | head -20
```

## Monitoring

### Backup-Größe überwachen
```bash
# Alert wenn Backup < 100MB (könnte fehlerhaft sein)
backup_size=$(stat -c%s /root/backups/wordpress_backup_$(date +%Y%m%d)_*.tar.gz 2>/dev/null | head -1)
if [ "$backup_size" -lt 104857600 ]; then
    echo "WARNING: Backup seems too small!"
fi
```

### Festplatz-Überwachung
```bash
# Alert wenn /root zu voll wird
df -h /root | awk 'NR==2 {if (substr($5,1,length($5)-1) > 80) print "WARNING: Disk space low!"}'
```

## Troubleshooting

### Backup schlägt fehl
1. Docker-Container prüfen: `docker compose ps`
2. Berechtigungen prüfen: `ls -la /root/backups/`
3. Festplatz prüfen: `df -h`
4. Log-Datei prüfen: `tail -20 /var/log/wordpress-backup.log`

### Restore schlägt fehl
1. Backup-Datei validieren: `tar -tzf backup_file.tar.gz > /dev/null`
2. Docker prüfen: `docker --version`
3. Berechtigungen prüfen: `chmod +x restore-backup.sh`

### Performance-Optimierung
```bash
# Backup komprimieren (falls Platz knapp)
gzip -9 /root/backups/wordpress_backup_*.tar.gz

# Alte Backups manuell löschen
find /root/backups -name "wordpress_backup_*.tar.gz" -mtime +60 -delete
```

## Sicherheits-Hinweise

- **Backups verschlüsseln** für externe Speicherung
- **Zugriff beschränken**: `chmod 700 /root/backups/`
- **Regelmäßig testen**: Restore-Tests durchführen
- **Monitoring einrichten**: Backup-Erfolg überwachen

## Support

Bei Problemen:
1. Log-Dateien prüfen
2. Docker-Status prüfen
3. Berechtigungen validieren
4. Festplatz kontrollieren

**Automatische Backups**: ✅ Täglich um 3:00 Uhr
**Retention**: ✅ 7 Tage / 30 Tage / Unbegrenzt
**Restore**: ✅ Flexible Optionen
**Migration**: ✅ Server-zu-Server möglich 