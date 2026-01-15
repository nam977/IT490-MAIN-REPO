#!/bin/bash
#
# Combined DMZ + Database Deployment Script
# This script sets up both frontend and backend on a single machine
#

set -e  # Exit on error

# Color output

echo -e "[+]========================================[+]\n"
echo -e "[+] Combined DMZ + Database Setup Script   [+]\n"
echo -e "[+]========================================[+]\n"

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo -e " [+] This script must be run as root (use sudo) [+]\n" 
   exit 1
fi

# Configuration
My_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_FOLDER="$My_SCRIPT_DIR/frontend/FRONTEND"
BACKEND_FOLDER="$My_SCRIPT_DIR/database-mq/IT-490-DATABASE-MQ"
FRONTEND_LOCATION="/var/www/frontend"
BACKEND_LOCATION="/var/www/backend"
DB_USER="testuser"
DB_PASS="rv9991\$#"
DB_NAME="testdb"
RABBITMQ_USER="test"
RABBITMQ_PASS="test"
RABBITMQ_VHOST="testHost"

# Functions
print_step() {
    echo -e "\n[-] ---> $1\n"
}

print_success() {
    echo -e "[+] $1\n"
}

print_error() {
    echo -e "[X] $1\n"
}

# Step 1: Install required packages
print_step "Step 1: Installing required packages"
apt update
apt install -y rabbitmq-server mysql-server php php-cli php-mysql php-mbstring php-bcmath php-curl composer apache2 libapache2-mod-php
print_success "Packages installed"

# Step 2: Configure RabbitMQ
print_step "Step 2: Configuring RabbitMQ"
systemctl start rabbitmq-server
systemctl enable rabbitmq-server

# Wait for RabbitMQ to start
sleep 5

# Create user and vhost
rabbitmqctl add_user $RABBITMQ_USER $RABBITMQ_PASS 2>/dev/null || echo "[X] User already exists"
rabbitmqctl add_vhost $RABBITMQ_VHOST 2>/dev/null || echo "[X] Vhost already exists"
rabbitmqctl set_permissions -p $RABBITMQ_VHOST $RABBITMQ_USER ".*" ".*" ".*"

# Enable management plugin
rabbitmq-plugins enable rabbitmq_management

print_success "RabbitMQ configured"

# Step 3: Configure MySQL
print_step "Step 3: Configuring MySQL"
systemctl start mysql
systemctl enable mysql

# Create database and user
mysql -u root << EOF || echo "Database setup encountered an issue (may already exist)"
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import database schema if exists
if [ -f "$BACKEND_FOLDER/testdb.sql" ]; then
    echo "Importing database schema..."
    mysql -u $DB_USER -p"$DB_PASS" $DB_NAME < "$BACKEND_FOLDER/testdb.sql" 2>/dev/null || echo "Schema import skipped (may already exist)"
fi

print_success "MySQL configured"

# Step 4: Create deployment directories
print_step "Step 4: Creating deployment directories"
mkdir -p $FRONTEND_LOCATION
mkdir -p $BACKEND_LOCATION
print_success "Directories created"

# Step 5: Copy files
print_step "Step 5: Copying application files"

if [ -d "$FRONTEND_FOLDER" ]; then
    cp -r $FRONTEND_FOLDER/* $FRONTEND_LOCATION/
    print_success "Frontend files copied"
else
    print_error "Frontend source directory not found: $FRONTEND_FOLDER"
fi

if [ -d "$BACKEND_FOLDER" ]; then
    cp -r $BACKEND_FOLDER/* $BACKEND_LOCATION/
    print_success "Backend files copied"
else
    print_error "Backend source directory not found: $BACKEND_FOLDER"
fi

# Step 6: Install Composer dependencies
print_step "Step 6: Installing Composer dependencies"

if [ -f "$FRONTEND_LOCATION/composer.json" ]; then
    cd $FRONTEND_LOCATION
    composer install --no-interaction --prefer-dist 2>/dev/null || echo "Frontend composer install skipped"
fi

if [ -f "$BACKEND_LOCATION/composer.json" ]; then
    cd $BACKEND_LOCATION
    composer install --no-interaction --prefer-dist 2>/dev/null || echo "Backend composer install skipped"
fi

print_success "Dependencies installed"

# Step 7: Set permissions
print_step "Step 7: Setting file permissions"
chown -R www-data:www-data $FRONTEND_LOCATION
chown -R www-data:www-data $BACKEND_LOCATION
chmod -R 755 $FRONTEND_LOCATION
chmod -R 755 $BACKEND_LOCATION

# Make PHP scripts executable
find $BACKEND_LOCATION -name "*.php" -type f -exec chmod +x {} \;
print_success "Permissions set"

# Step 8: Configure Apache web server
print_step "Step 8: Configuring Apache web server"

cat > /etc/apache2/sites-available/combined-app.conf << 'APACHECONF'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/frontend

    <Directory /var/www/frontend>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Enable PHP
        DirectoryIndex index.php index.html
    </Directory>

    # Enable CORS for API endpoints
    <FilesMatch "\.(php)$">
        Header set Access-Control-Allow-Origin "*"
        Header set Access-Control-Allow-Methods "GET, POST, OPTIONS"
        Header set Access-Control-Allow-Headers "Content-Type, Authorization"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/combined_error.log
    CustomLog ${APACHE_LOG_DIR}/combined_access.log combined
</VirtualHost>
APACHECONF

# Enable required Apache modules
a2enmod rewrite
a2enmod headers
a2enmod php8.*

# Enable the site
a2dissite 000-default.conf 2>/dev/null || true
a2ensite combined-app.conf
systemctl restart apache2

print_success "Apache configured"

# Step 9: Create systemd service for backend worker
print_step "Step 9: Creating systemd service for backend worker"

cat > /etc/systemd/system/auth-worker.service << SERVICEEOF
[Unit]
Description=Authentication Worker Service
After=network.target rabbitmq-server.service mysql.service
Wants=rabbitmq-server.service mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=$BACKEND_LOCATION
ExecStart=/usr/bin/php $BACKEND_LOCATION/dbServer.php
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
SERVICEEOF

systemctl daemon-reload
systemctl enable auth-worker
systemctl start auth-worker

print_success "Backend worker service created and started"

# Step 10: Create log directory
print_step "Step 10: Setting up logging"
mkdir -p /var/log/app
touch /var/log/auth_worker_rpc.log
chown www-data:www-data /var/log/auth_worker_rpc.log
print_success "Logging configured"

# Final status check
print_step "Verifying services"

echo -n "RabbitMQ: "
if systemctl is-active --quiet rabbitmq-server; then
    print_success "Running"
else
    print_error "Not running"
fi

echo -n "MySQL: "
if systemctl is-active --quiet mysql; then
    print_success "Running"
else
    print_error "Not running"
fi

echo -n "Apache: "
if systemctl is-active --quiet apache2; then
    print_success "Running"
else
    print_error "Not running"
fi

echo -n "Auth Worker: "
if systemctl is-active --quiet auth-worker; then
    print_success "Running"
else
    print_error "Not running"
fi

# Display summary
echo -e "\n[+]========================================[+]"
echo -e "\n[+] Deployment Complete![+]                [+]"
echo -e "\n[+]========================================[+]"
echo ""
echo "Frontend URL: http://localhost/"
echo "RabbitMQ Management: http://localhost:15672/ (user: $RABBITMQ_USER, pass: $RABBITMQ_PASS)"
echo ""
