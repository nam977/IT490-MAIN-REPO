#!/usr/bin/env bash
set -euo pipefail

echo "[1/9] Checking disk usage..."
df -h /

echo "[2/9] Journal disk usage"
sudo journalctl --disk-usage || true

echo "[3/9] Cleaning apt cache"
sudo apt-get clean || true

echo "[4/9] Vacuuming journal to 200M"
sudo journalctl --vacuum-size=200M || true

echo "[5/9] Truncating common large logs (syslog, kern, auth.log) if present"
for f in /var/log/syslog kern.log auth.log /var/log/apache2/error.log; do
if [ -f "$f" ]; then
echo "Truncating $f"
sudo truncate -s 0 "$f" || true
fi
done

echo "[6/9] Writing SQL dump to /tmp/testdb.sql"
cat > /tmp/testdb.sql <<'SQL'
-- MySQL dump 10.13 Distrib 8.0.43, for Linux (x86_64)
-- Host: localhost Database: testdb

-- Server version 8.0.43-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT /;
/!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS /;
/!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION /;
/!50503 SET NAMES utf8mb4 /;
/!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE /;
/!40103 SET TIME_ZONE='+00:00' /;
/!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 /;
/!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 /;
/!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' /;
/!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table request_log
DROP TABLE IF EXISTS request_log;
/*!40101 SET @saved_cs_client = @@character_set_client /;
/!50503 SET character_set_client = utf8mb4 /;
CREATE TABLE request_log (
id int NOT NULL AUTO_INCREMENT,
username varchar(50) DEFAULT NULL,
action varchar(100) NOT NULL,
timestamp timestamp NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
KEY username (username),
CONSTRAINT request_log_ibfk_1 FOREIGN KEY (username) REFERENCES users (username) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table request_log
LOCK TABLES request_log WRITE;
/*!40000 ALTER TABLE request_log DISABLE KEYS /;
/!40000 ALTER TABLE request_log ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table students
DROP TABLE IF EXISTS students;
/*!40101 SET @saved_cs_client = @@character_set_client /;
/!50503 SET character_set_client = utf8mb4 /;
CREATE TABLE students (
studentid int NOT NULL AUTO_INCREMENT,
name varchar(255) DEFAULT NULL,
year int DEFAULT NULL,
gpa float DEFAULT NULL,
PRIMARY KEY (studentid)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table students
LOCK TABLES students WRITE;
/*!40000 ALTER TABLE students DISABLE KEYS /;
INSERT INTO students VALUES (1,'steve',1,2.5);
/!40000 ALTER TABLE students ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table user_cookies
DROP TABLE IF EXISTS user_cookies;
/*!40101 SET @saved_cs_client = @@character_set_client /;
/!50503 SET character_set_client = utf8mb4 /;
CREATE TABLE user_cookies (
id int NOT NULL AUTO_INCREMENT,
session_id varchar(64) NOT NULL,
username varchar(50) NOT NULL,
auth_token varchar(64) NOT NULL,
expiration_time datetime NOT NULL,
ip_address varchar(45) DEFAULT NULL,
user_agent varchar(255) DEFAULT NULL,
created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY auth_token (auth_token),
KEY username (username),
CONSTRAINT user_cookies_ibfk_1 FOREIGN KEY (username) REFERENCES users (username) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table user_cookies
LOCK TABLES user_cookies WRITE;
/*!40000 ALTER TABLE user_cookies DISABLE KEYS /;
INSERT INTO user_cookies VALUES (1,'abcd1234efgh5678ijkl9012mnop3456','steve','token1234567890abcdef1234567890abcd','2025-10-30 20:25:56','127.0.0.1','TestClient/1.0','2025-10-01 00:25:56');
/!40000 ALTER TABLE user_cookies ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table users
DROP TABLE IF EXISTS users;
/*!40101 SET @saved_cs_client = @@character_set_client /;
/!50503 SET character_set_client = utf8mb4 /;
CREATE TABLE users (
id int NOT NULL AUTO_INCREMENT,
username varchar(50) NOT NULL,
password varchar(255) NOT NULL,
email varchar(100) NOT NULL,
created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
UNIQUE KEY username (username),
UNIQUE KEY email (email)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table users
LOCK TABLES users WRITE;
/*!40000 ALTER TABLE users DISABLE KEYS /;
INSERT INTO users VALUES (1,'steve','password','steve@example.com','2025-10-01 00:17:24');
/!40000 ALTER TABLE users ENABLE KEYS /;
UNLOCK TABLES;
/!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE /;
/!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS /;
/!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS /;
/!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT /;
/!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS /;
/!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION /;
/!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-03 21:45:22
SQL

echo "[7/9] Creating database 'testdb' and user 'testuser'@'localhost' with password 'rv9991$#'"
sudo mysql <<'MYSQL'
CREATE DATABASE IF NOT EXISTS testdb;
CREATE USER IF NOT EXISTS 'testuser'@'localhost' IDENTIFIED BY 'rv9991$#';
GRANT ALL PRIVILEGES ON testdb.* TO 'testuser'@'localhost';
FLUSH PRIVILEGES;
MYSQL

echo "[8/9] Importing schema/data into testdb"
sudo mysql testdb < /tmp/testdb.sql

echo "[9/9] Restarting database and attempting to start RabbitMQ"
sudo systemctl restart mysql || true
sudo systemctl daemon-reload || true
sudo systemctl restart rabbitmq-server || true

echo "Status (latest 80 journal lines for rabbitmq):"
sudo journalctl -u rabbitmq-server --no-pager -n 80 || true

echo "Script complete. If RabbitMQ still fails due to disk or other errors, inspect journal output above and consult logs in /var/log/rabbitmq."