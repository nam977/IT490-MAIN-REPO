#!/bin/bash
#
# Combined System Status Checker
# Quickly verify that all components are running correctly
#

# Colors
echo -e "$+---------------------------------------+$"
echo -e "$+   Combined DMZ + Database Status Check+$"
echo -e "$+---------------------------------------+$"
echo ""

# Function to check service status
check_running_service() {
    local service_name=$1
    local display_name=$2
    
    echo -n "  $display_name: "
    if systemctl is-active --quiet $service_name; then
        echo -e "[+] Active Process [+]\n"
        return 0
    else
        echo -e "[X] Inactive Process [X]\n"
        return 1
    fi
}

# Function to check port
check_my_service_port() {
    local my_current_port=$1
    local my_current_service=$2
    
    echo -n "  $my_current_service (port $my_current_port): "
    if netstat -tlnp 2>/dev/null | grep -q ":$my_current_port "; then
        echo -e "[+] Listening [+]\n"
        return 0
    else
        echo -e "[X] Not Listening [X]\n"
        return 1
    fi
}

# Function to check database
check_mysql_database() {
    echo -n "  MySQL testdb: "
    if mysql -u testuser -p'rv9991$#' -h 127.0.0.1 testdb -e "SELECT 1;" &>/dev/null; then
        echo -e "[+] Accessible [+]"
        return 0
    else
        echo -e "[X] Not Accessible [X]\n"
        return 1
    fi
}

# Function to check RabbitMQ vhost
check_rabbitmq_vhost() {
    echo -n "  RabbitMQ vhost 'testHost': "
    if sudo rabbitmqctl list_vhosts 2>/dev/null | grep -q "testHost"; then
        echo -e "[+] Exists [+]\n"
        return 0
    else
        echo -e "[X] Not Found [X]\n"
        return 1
    fi
}

# Function to check directory
check_my_current_directory() {
    local dir_folder=$1
    local dir_foldername=$2
    
    echo -n "  $dir_foldername: "
    if [ -d "$dir_folder" ]; then
        echo -e "[+] Exists [+]\n"
        return 0
    else
        echo -e "[X] Missing [X]\n"
        return 1
    fi
}

# 1. Check System Services
echo -e "[-] System Services$ [-]\n"
check_running_service "rabbitmq-server" "RabbitMQ Server"
check_running_service "mysql" "MySQL Server"
check_running_service "apache2" "Apache Web Server"
check_running_service "auth-worker" "Auth Worker"
check_runningcheck_running_service_service "stock-app" "Stock Trading App"
echo ""

# 2. Check Network Ports
echo -e "[-] Network Ports [-]\n"
check_my_service_port "5672" "RabbitMQ AMQP"
check_my_service_port "15672" "RabbitMQ Management"
check_my_service_port "3306" "MySQL"
check_my_service_port "80" "Apache HTTP"
check_my_service_port "5000" "Flask Stock App"
echo ""

# 3. Check Database
echo -e "[-] Database Connectivity [-]\n"
check_mysql_database
echo ""

# 4. Check RabbitMQ Configuration
echo -e "[-] RabbitMQ Configuration [-]\n"
check_rabbitmq_vhost

if sudo rabbitmqctl list_queues -p testHost 2>/dev/null | grep -q "testQueue\|dbQueue"; then
    echo -e "  RabbitMQ Queues:"    
    sudo rabbitmqctl list_queues -p testHost 2>/dev/null | grep -E "testQueue|dbQueue" | while read line; do
        echo "    - $line"
    done
else
    echo -e "  [-] No queues found (they will be created on first use) [-]\n"
fi
echo ""

# 5. Check File Structure
echo -e "[-] File Structure [-]\n"
check_my_current_directory "/var/www/frontend" "Frontend Directory"
check_my_current_directory "/var/www/backend" "Backend Directory"
check_my_current_directory "/var/www/frontend/vendor" "Frontend Dependencies"
check_my_current_directory "/var/www/backend/vendor" "Backend Dependencies"
echo ""

# 6. Check Configuration Files
echo -e "[-] Config Files [-]\n"
echo -n "  Frontend RabbitMQ Config: "
if [ -f "/var/www/frontend/testRabbitMQ.ini" ]; then
    if grep -q "BROKER_HOST = 127.0.0.1" /var/www/frontend/testRabbitMQ.ini; then
        echo -e "[+] Configured for localhost [+]"
    else
        echo -e "  [-] Not configured for localhost [-]\n"
    fi
else
    echo -e "[X] Missing [X]\n"
fi

echo -n "  Backend RabbitMQ Config: "
if [ -f "/var/www/backend/testRabbitMQ.ini" ]; then
    if grep -q "BROKER_HOST = 127.0.0.1" /var/www/backend/testRabbitMQ.ini; then
        echo -e "[+] Configured for localhost [+]"
    else
        echo -e "  [-] Not configured for localhost [-]\n"
    fi
else
    echo -e "[X] Missing [X]\n"
fi

echo -n "  Apache Virtual Host: "
if [ -f "/etc/apache2/sites-enabled/combined-app.conf" ]; then
    echo -e "[+] Enabled [+]\n"
else
    echo -e "[X] Not Enabled [X]\n"
fi
echo ""

# 7. Check Log Files
echo -e "[-] Recent Log Entries [-]\n"
echo "  Auth Worker (last 3 lines):"
if [ -f "/var/log/auth_worker_rpc.log" ]; then
    tail -n 3 /var/log/auth_worker_rpc.log 2>/dev/null | sed 's/^/    /'
else
    echo -e "    [-] No log file yet [-]"
fi

echo ""
echo "  Apache Error (last 3 lines):"
if [ -f "/var/log/apache2/combined_error.log" ]; then
    tail -n 3 /var/log/apache2/combined_error.log 2>/dev/null | sed 's/^/    /'
else
    echo -e "    [-] No errors logged [-]"
fi
echo ""

# 8. Summary
echo -e "$+--------------------------------------------+$\n"
echo -e "$+              Quick Access URLs             +$\n"
echo -e "$+-------------------------------------------+$\n"
echo "  Frontend: http://localhost/"
echo "  Login: http://localhost/login.html"
echo "  Forum: http://localhost/forum.html"
echo "  Stock Trading: http://localhost/stock-app/"
echo "  RabbitMQ Management Dashboard: http://localhost:15672/"
echo "    (user: test, pass: test)"
echo ""

echo -e "$+--------------------------------------------+$\n"
echo -e "$+            Useful Commands                 +$\n"
echo -e "$+--------------------------------------------+$\n"
echo "  Restart worker: sudo systemctl restart auth-worker"
echo "  View worker logs: sudo journalctl -u auth-worker -f"
echo "  View app logs: sudo tail -f /var/log/auth_worker_rpc.log"
echo "  Check queues: sudo rabbitmqctl list_queues -p testHost"
echo "  Test database: mysql -u testuser -p'rv9991\$#' testdb"
echo ""

# Final status
echo -e "$+--------------------------------------------+$\n"
if systemctl is-active --quiet rabbitmq-server && \
   systemctl is-active --quiet mysql && \
   systemctl is-active --quiet apache2 && \
   systemctl is-active --quiet auth-worker && \
   systemctl is-active --quiet stock-app; then
    echo -e " [+] System Status: ALL SERVICES OPERATIONAL [+]\n"
else
    echo -e " [X] System Status: SOME SERVICES DOWN$ [X]\n"
    echo -e " [-][-] Run 'sudo systemctl start <service>' to fix$ [-][-]\n"
fi
echo -e "$+--------------------------------------------+$\n"
echo ""
