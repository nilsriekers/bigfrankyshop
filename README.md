# WordPress + WooCommerce Docker Setup (Kubernetes-Ready)

Diese Installation ist so konfiguriert, dass sie später einfach nach Kubernetes migriert werden kann. Alle Daten werden in separaten Volumen gespeichert und die Konfiguration erfolgt über Environment Variables.

## 🚀 Quick Start

```bash
# Scripts ausführbar machen
chmod +x scripts/*.sh

# WordPress starten
./scripts/manage.sh start

# WooCommerce installieren
./scripts/manage.sh install-woocommerce
```

**URLs:**
- WordPress: http://localhost
- phpMyAdmin: http://localhost:8080

## 📋 Systemanforderungen

- **RAM:** 2GB (empfohlen: 4GB)
- **CPU:** 1 vCPU (empfohlen: 2 vCPU)
- **Storage:** 10GB freier Speicherplatz
- **OS:** Linux (Debian/Ubuntu bevorzugt)

## 🏗️ Architektur

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│    WordPress    │    │      MySQL      │    │      Redis      │
│   (Port 80)     │────│   (Port 3306)   │    │   (Port 6379)   │
│                 │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         │                       │                       │
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│  WordPress Vol  │    │   MySQL Vol     │    │   Redis Vol     │
│  /var/www/html  │    │  /var/lib/mysql │    │     /data       │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## 📁 Projektstruktur

```
wordpress-setup/
├── docker-compose.yml      # Docker Compose Konfiguration
├── .env                    # Environment Variables
├── uploads/                # WordPress Uploads (persistent)
├── plugins/                # WordPress Plugins (persistent)
├── themes/                 # WordPress Themes (persistent)
├── backups/                # Backup Storage
├── kubernetes/             # K8s Manifests (generiert)
└── scripts/
    ├── manage.sh          # Management Script
    ├── backup.sh          # Backup Script
    └── k8s-migrate.sh     # Kubernetes Migration
```

## 🔧 Management Commands

```bash
# WordPress starten
./scripts/manage.sh start

# WordPress stoppen
./scripts/manage.sh stop

# Status prüfen
./scripts/manage.sh status

# Logs anzeigen
./scripts/manage.sh logs
./scripts/manage.sh logs wordpress

# WooCommerce installieren
./scripts/manage.sh install-woocommerce

# WP-CLI Commands
./scripts/manage.sh wp user list
./scripts/manage.sh wp plugin list

# Backup erstellen
./scripts/manage.sh backup

# Container Shell öffnen
./scripts/manage.sh shell wordpress
./scripts/manage.sh shell mysql

# System Health Check
./scripts/manage.sh health

# WordPress & Plugins updaten
./scripts/manage.sh update
```

## 💾 Backup & Restore

### Automatisches Backup
```bash
# Komplettes Backup erstellen
./scripts/manage.sh backup
```

Das Backup enthält:
- MySQL Datenbank Export
- WordPress Core Files
- Uploads, Plugins, Themes
- Konfigurationsdateien
- Restore-Script

### Restore aus Backup
```bash
cd backups/wordpress_backup_TIMESTAMP/
./restore.sh
```

## ☸️ Kubernetes Migration

### 1. K8s Manifests generieren
```bash
./scripts/manage.sh prepare-k8s
```

### 2. Backup vor Migration
```bash
./scripts/manage.sh backup
```

### 3. Daten nach K8s übertragen
```bash
# PersistentVolumes in K8s erstellen
cd kubernetes/
./deploy.sh

# Daten von Backup übertragen
kubectl cp backups/latest/ wordpress/wordpress-pod:/var/www/html/
```

### 4. Datenbank Migration
```bash
# Datenbank in K8s importieren
kubectl exec -i mysql-pod -n wordpress -- mysql -u root -p < backups/latest/database.sql
```

## 🔒 Sicherheit

### Non-Root User erstellen
```bash
# Neuen User erstellen
adduser wpuser
usermod -aG docker wpuser
usermod -aG sudo wpuser

# Projekt ownership ändern
chown -R wpuser:wpuser /root/wordpress-setup
mv /root/wordpress-setup /home/wpuser/
```

### Passwörter ändern
Bearbeite die `.env` Datei:
```bash
nano .env
```

### Firewall konfigurieren
```bash
# UFW installieren und konfigurieren
apt install ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

## 🔧 Konfiguration

### Environment Variables (.env)
```env
# Database
DB_ROOT_PASSWORD=WP_Root_Pass_2024!
DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=WP_User_Pass_2024!

# Redis
REDIS_PASSWORD=Redis_Pass_2024!

# Kubernetes
K8S_NAMESPACE=wordpress
K8S_STORAGE_CLASS=standard
```

### WordPress Optimierung
```bash
# WordPress Memory Limit erhöhen
./scripts/manage.sh wp config set WP_MEMORY_LIMIT 512M

# WordPress Cache aktivieren
./scripts/manage.sh wp plugin install w3-total-cache --activate
```

## 📊 Monitoring

### Performance Monitoring
```bash
# Container Resources
docker stats

# Disk Usage
./scripts/manage.sh status

# WordPress Health
./scripts/manage.sh health
```

### Log Monitoring
```bash
# Alle Logs
./scripts/manage.sh logs

# Nur WordPress
./scripts/manage.sh logs wordpress

# Nur Database
./scripts/manage.sh logs db
```

## 🐛 Troubleshooting

### WordPress nicht erreichbar
```bash
# Container Status prüfen
./scripts/manage.sh status

# Logs prüfen
./scripts/manage.sh logs wordpress

# Health Check
./scripts/manage.sh health

# Container neu starten
./scripts/manage.sh restart
```

### Datenbank Probleme
```bash
# MySQL Logs
./scripts/manage.sh logs db

# MySQL Shell
./scripts/manage.sh mysql

# Database Health
docker exec wordpress_db mysqladmin -u root -p ping
```

### Performance Probleme
```bash
# Resource Usage
docker stats

# Disk Space
df -h

# Memory Usage
free -h

# WordPress Debug aktivieren
./scripts/manage.sh wp config set WP_DEBUG true
```

## 🚀 Produktions-Deployment

### SSL/HTTPS Setup
```bash
# Certbot installieren
apt install certbot python3-certbot-apache

# SSL Zertifikat erstellen
certbot --apache -d deine-domain.de
```

### Reverse Proxy (Nginx)
```bash
# Nginx installieren
apt install nginx

# Konfiguration in /etc/nginx/sites-available/wordpress
server {
    listen 80;
    server_name deine-domain.de;
    
    location / {
        proxy_pass http://localhost:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

## 📝 Wartung

### Regelmäßige Tasks
```bash
# Wöchentliches Backup (Crontab)
0 2 * * 0 /home/wpuser/wordpress-setup/scripts/backup.sh

# Monatliche Updates
0 3 1 * * /home/wpuser/wordpress-setup/scripts/manage.sh update

# Log Rotation
0 4 * * * docker system prune -f
```

### Plugin Updates
```bash
# Alle Plugins updaten
./scripts/manage.sh wp plugin update --all

# Spezifische Plugins
./scripts/manage.sh wp plugin update woocommerce
```

## 📞 Support

Bei Problemen:
1. Logs prüfen: `./scripts/manage.sh logs`
2. Health Check: `./scripts/manage.sh health`
3. Container neu starten: `./scripts/manage.sh restart`
4. Backup erstellen: `./scripts/manage.sh backup`

## 🔄 Kubernetes Migration Checklist

- [ ] Backup erstellt
- [ ] K8s Manifests generiert
- [ ] Kubernetes Cluster bereit
- [ ] PersistentVolumes konfiguriert
- [ ] Secrets erstellt
- [ ] Services deployed
- [ ] Daten migriert
- [ ] DNS konfiguriert
- [ ] SSL Zertifikate übertragen
- [ ] Backup in K8s verfügbar

Deine WordPress + WooCommerce Installation ist jetzt Kubernetes-ready! 🎉 