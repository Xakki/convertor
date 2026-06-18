#!/bin/bash
set -o nounset
set -o errexit

if [[ ! ${MARIADB_DATABASE+x} ]];  then
  echo "Must run only in MariadDb docker"
  exit
fi


mysqlQuery() {
    mariadb -uroot -p$MARIADB_ROOT_PASSWORD -e "$1"
}


mysqlQuery "CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}\` COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\`.* TO \`${MARIADB_USER}\`@\`%\`;"

#mysqlQuery "FLUSH PRIVILEGES; SET SESSION sql_mode = ''; SET GLOBAL sql_mode = '';"

BACKUP_FILE=dump.sql

echo
date
#mysqlQuery "DROP DATABASE ${MARIADB_DATABASE};"
# Сам дам дропает таблицу в начале


if ! [ -e $BACKUP_FILE ]; then

  if ! [ -e $BACKUP_FILE.gz ]; then
      echo "Error: cant get $BACKUP_FILE.gz"
      exit
  fi

  echo "Unpacking $BACKUP_FILE.gz ..."
  gunzip -k $BACKUP_FILE.gz
  date
fi

echo "Restore $BACKUP_FILE ..."

mariadb -uroot -p${MARIADB_ROOT_PASSWORD}  --default-character-set=utf8 $MARIADB_DATABASE < $BACKUP_FILE

rm $BACKUP_FILE

date

echo "Done"
echo
