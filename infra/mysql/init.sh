#!/bin/sh
set -eu
# Only executed when this project's dedicated MySQL volume is empty.
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS pingofin_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON pingofin_test.* TO 'pingofin'@'%';
SQL
