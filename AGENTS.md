
# libreoffice test

1. Собрать docker `docker build -t -t libreoffice-test libreoffice/`
2. Используя образ libreoffice-test выполнить тест

- `composer test:phpstan` - Ошибки, которые выдает Phpstan необходимо исправить (игнорировать разрешается только в крайнем случае, когда не получилось поправить с двух попыток или это не усложняет код и делает его плохо читаемым).
- `composer test:cs-fix` - Автоматическое исправление Code style.
- `composer test:cs-check` - Все что автоматический не исправилось, нужно поправить по результатам этой команды.

# Список файлов которые следует игнорировать
* laravel/app/Services/HealthCheck.php
