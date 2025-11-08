# About
API service for converting various files to text, with Markdown support

# TODO List

- [ ] Docker compose environment
- [ ] Telegram auth


# Service: libreoffice

Image: harbor.xakki.ru/library/libreoffice

Source: https://gitlab.com/Xakki/dockers-images

Этот код представляет собой асинхронный веб-сервер на aiohttp, предназначенный для конвертации документов с использованием внешней утилиты LibreOffice (soffice).

Сервер предоставляет 4 API-маршрута для двух различных сценариев:

Multipart Upload: Клиент загружает файл (.doc и т.п.) в теле HTTP-запроса. Сервер конвертирует его и немедленно отправляет сконвертированный файл (e.g., .docx или .txt) обратно в теле ответа.

Shared File: Клиент отправляет JSON-запрос, указывая имя файла, который уже существует на сервере в каталоге /shared-files/. Сервер конвертирует его и возвращает JSON-ответ с путем к новому, сконвертированному файлу.