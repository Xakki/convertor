# Субагент: git-commit

## Назначение
Делает осмысленный git-коммит после завершения любой задачи разработки.
Запускать через `Agent(subagent_type="general-purpose", prompt=...)` с передачей контекста задачи.

## Промпт для вызова субагента

```
Ты делаешь git commit для проекта Convertor Service.

Рабочая директория: /home/xakki/www/xakki/convertor

Задача, которую только что завершили: {{TASK_DESCRIPTION}}

Шаги:
1. Запусти `git status` и `git diff --stat`
2. Проанализируй изменения
3. Сформируй commit message по правилам ниже
4. Добавь нужные файлы через `git add <specific files>` (не git add -A)
5. Сделай коммит

Правила хорошего commit message:
- Первая строка: тип(область): краткое описание (max 72 символа)
- Пустая строка
- Тело: что изменилось и ПОЧЕМУ (не что делает код)
- Подпись Co-Authored-By в конце

Типы:
  feat     — новая функциональность
  fix      — исправление бага
  refactor — рефакторинг без изменения поведения
  test     — добавление/изменение тестов
  chore    — обновление зависимостей, конфигов, не касается кода
  docs     — изменения документации
  build    — изменения сборки/Docker/Makefile
  ci       — CI/CD конфигурация

Области (в скобках): backend, workers, frontend, docker, auth, payment, queue, ai

Примеры хороших сообщений:
  feat(workers): add multi-provider AI worker with OpenAI/Gemini/Claude fallback
  fix(backend): correct Telegram auth hash verification order
  build(docker): add healthchecks and resource limits for all worker containers
  chore(env): add JWT keys, bot token and AI API keys

Примеры плохих сообщений (НЕ ДЕЛАТЬ):
  "update files"
  "fix bug"
  "wip"
  "changes"

ВАЖНО:
- Никогда не добавляй .env в коммит (там секреты)
- Убедись что .env в .gitignore
- Не коммить файлы с ключами/паролями
- Если нечего коммитить — сообщи об этом
```

## Когда вызывать
- После завершения каждой логически завершённой задачи (не после каждого файла)
- После завершения работы субагента над своей областью
- Перед сменой контекста задачи

## Пример вызова из Claude Code

```python
Agent(
    description="Git commit after workers refactor",
    subagent_type="general-purpose",
    prompt="""
    Ты делаешь git commit для проекта Convertor Service.
    Рабочая директория: /home/xakki/www/xakki/convertor
    
    Задача: Добавлена поддержка OpenAI/Gemini/Claude в AI воркер,
    обновлён workers/ai/worker.py и workers/requirements.txt
    
    [вставь правила из subagent-git-commit.md]
    """
)
```
