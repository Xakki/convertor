#!/bin/bash
set -o nounset
set -o errexit

srcLib="https://raw.githubusercontent.com/Xakki/kvm.scripts/master/src/bashlibs.sh"

if ! [[ -f "bashlibs.sh" ]]; then
    wget -nv --cache=off "$srcLib"
    chmod 0744 bashlibs.sh
fi

. bashlibs.sh
echo "Load bashlibs.sh version $BLV"

set -a; source ../.env; set +a


if ! [[ "$ENV" = "dev" ]] ; then
  echo "Не доступно! Только для dev!";
  exit 0
fi

mysqlQuery() {
    docker exec -i uni-mariadb bash -c "exec mysql -uroot -p$ROOT_DB_PASS -e \"$1\""
	#mysql -e "$1"
}


PROD_BACKUP_DIR="/home/wephost/backup"

if myAskYN "Use DB: unidoski - Y, email_service - N"; then
  MAIN_DB="unidoski"
  MAIN_USER="unidoski"
  FILE_NAME="backup"
  PROD_PASS=$UNIDOSKI_PROD_DB_PASS
else
  MAIN_DB="email_service"
  MAIN_USER="emailservice"
  FILE_NAME="backup_email"
  PROD_PASS=$EMAILSERVICE_DB_PASS

  if myAskYN "Run create DB (phpwall, email_service) - Y"; then
    mysqlQuery "CREATE DATABASE IF NOT EXISTS phpwall; CREATE USER IF NOT EXISTS 'phpwall'@'%' IDENTIFIED BY '${PHPWALL_DB_PASS}';GRANT ALL PRIVILEGES ON phpwall.* TO 'phpwall'@'%';"
    mysqlQuery "CREATE DATABASE IF NOT EXISTS ${MAIN_DB}; CREATE USER IF NOT EXISTS '${MAIN_USER}'@'%' IDENTIFIED BY '${PROD_PASS}';GRANT ALL PRIVILEGES ON ${MAIN_DB}.* TO '${MAIN_USER}'@'%';"
    mysqlQuery "FLUSH PRIVILEGES;"
  fi
  ##ALTER USER 'emailservice'@'%' IDENTIFIED BY 'New-Password-Here';
fi

if myAskYN "Создать бэкап на PROD?"; then
	echo "Создаем дамп БД"
	date
	ssh -p10522 root@unidoski.ru "cd $PROD_BACKUP_DIR && mysqldump -h127.0.0.1 -u$MAIN_USER -p$PROD_PASS $MAIN_DB > $FILE_NAME.sql && gzip -v7 $FILE_NAME.sql"
  date
  echo
fi

if myAskYN "Взять бэкап с PROD?"; then
  date
  echo "Копируем БД с PROD"
  scp -r -P 10522 "root@unidoski.ru:$PROD_BACKUP_DIR/$FILE_NAME.sql.gz" ./backup
  if ! [ -e "./backup/$FILE_NAME.sql.gz" ]; then
      echo "Error: cant copy $FILE_NAME.sql.gz from PROD"
      exit
  fi
  ssh -p10522 root@unidoski.ru "rm $PROD_BACKUP_DIR/$FILE_NAME.sql.gz"
fi
#  if [ -f ~/.my.cnf ]; then
#    mysql --protocol=TCP -h127.0.0.1 $MAIN_DB < $FILE_NAME.sql
#  else
#    echo "Введите локальный рутовый пароль MySQL!"
#    read rootpasswd
#    mysql --protocol=TCP -h127.0.0.1 -uroot -p${rootpasswd} $MAIN_DB < $FILE_NAME.sql
#  fi
  
if myAskYN "Накатить бэкап из $FILE_NAME.sql.gz?"; then
  date
  echo "Распаковываем"
  gunzip -k "backup/$FILE_NAME.sql.gz"
  
  echo "Применяем бэкап"
  mysqlQuery "CREATE DATABASE IF NOT EXISTS ${MAIN_DB}; GRANT ALL PRIVILEGES ON ${MAIN_DB}.* TO '${MAIN_USER}'@'%'; FLUSH PRIVILEGES;"
  docker exec -i uni-mariadb sh -c "exec mysql -uroot -p${ROOT_DB_PASS} $MAIN_DB < /backup/$FILE_NAME.sql"
  rm "backup/$FILE_NAME.sql"
fi

echo "Done"