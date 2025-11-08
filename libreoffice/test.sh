#!/bin/bash

docker run --rm -d --name libreoffice-test -v ${PWD}/libreoffice/app:/app/ -v ${PWD}/shared-files:/shared-files -p 2080:80 -p 2081:3000 -i xakki/libreoffice:latest
BODY_FILE=$(mktemp)
trap 'rm -f "$BODY_FILE"; docker kill libreoffice-test' EXIT

URL="http://localhost:2080/doc2textMultipart"
FILE_PATH=$(realpath "example_files/161497e507c5ea.doc")
FIELD_NAME="myfile.doc"


# Выполняем curl
# -s: Тихий режим (без прогресс-бара)
# -w "%{http_code}": Вывести HTTP-код состояния в stdout
# -o "$BODY_FILE": Записать тело ответа во временный файл
HTTP_STATUS=$(curl -s -w "%{http_code}" -o "$BODY_FILE" \
                  -F "${FIELD_NAME}=@${FILE_PATH}" \
                  "$URL")
echo
# Проверяем статус-код
if [[ "$HTTP_STATUS" -ne 200 ]]; then
    # Если не 200, выводим статус и тело ответа
    echo "Ошибка: Получен HTTP-статус $HTTP_STATUS"
    echo
    cat "$BODY_FILE"
    echo
    exit 1
fi

echo "OK"
echo
# Если 200, выходим молча
exit 0