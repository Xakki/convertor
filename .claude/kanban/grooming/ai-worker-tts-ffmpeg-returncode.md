### AI-воркер TTS — проверять ffmpeg.returncode в espeak-пути

**Критичность:** Low

**TAGS:**
- tech-debt

**Описание:**
`workers/ai/providers/tts.py` (espeak-путь, ~строки 38-41) делает `await ffmpeg.wait()`
без проверки `ffmpeg.returncode`. Упавший transcode всплывает только generic-ошибкой
`convert.py` («conversion produced no output»), а не описательной ошибкой ffmpeg.
Синхронный путь `_pyttsx3_sync` при этом использует `check=True` — поведение
несогласованное.

**Рекомендация:**
Проверять `returncode` после `wait()` в espeak-пути и бросать описательную ошибку с
stderr ffmpeg; привести к единому стилю с pyttsx3-путём.

**Контекст:** nit из ревью [[ai-worker-refactor-core]]. Не блокер, тесты зелёные.
