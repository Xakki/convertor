### AI dev-server — verify truncated webm/opus partial decode in a real av/ffmpeg image

**Критичность:** Medium

**TAGS:**
- verification
- ai-worker

**Описание:**
Dev-сервер AI-воркера (`workers/ai/devserver`) стримит аудио по WS как `webm/opus`
(браузерный `MediaRecorder`) и на сервере **аккумулирует** все бинарные фреймы,
прогоняя растущий буфер через faster-whisper (`process_file`) для `partial`/`final`.

**Проблема:**
Путь декода webm/opus был протестирован backend-dev только с **замоканной** моделью —
`av`/faster-whisper отсутствуют в его окружении и в тест-образе. `final` по полному
буферу безопасен (валидный файл). Риск — **`partial` по обрезанному mid-stream буферу**:
не гарантировано, что `av.open()`/ffmpeg корректно декодируют неполный webm-контейнер
(возможны исключения или пустой результат на «хвосте» без полного кластера).

**Что проверить:**
- В образе с установленным `av` (PyAV): синтезировать короткий webm/opus, обрезать его
  в нескольких точках, прогнать `av.open()` → decode и `StreamingWhisper.process_file`
  над обрезанным буфером. Убедиться, что partial либо даёт корректный текст, либо
  деградирует мягко (без 500/краша WS).
- Если обрезанный буфер падает — добавить на сервере fallback: ловить ошибку декода
  partial и просто пропускать тик (ждать следующего фрейма), либо декодировать только
  по границам кластеров; `final` оставить как есть.

**Влияние:**
Без проверки live-partial в аудио-вкладке может молча не работать (только финал) на
реальном раннере с GPU. Не блокер MVP dev-сервера, но нужно закрыть до полагания на
live-транскрипт.

**Decisions:**
- On undecodable partial = **emit an error frame (noisy), keep socket open** (not silent skip-tick). Plus the in-image verification task: synthesize+truncate webm/opus, run av.open() + StreamingWhisper.process_file.

**Status:** ready (todo).
