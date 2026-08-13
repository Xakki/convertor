<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Входной контракт Controller → ConversionManager: описывает запрос на
 * конвертацию файла ДО постановки в очередь (пользователь, загруженный файл,
 * целевой формат и флаги ocr/privileged).
 *
 * Конструктор ОСТАЁТСЯ прежним (file-вход) — не ломает существующие вызовы
 * (`ConversionController`, unit-тесты `ConversionManager*Test`). Text-вход
 * (home-02-text-input) добавлен именованным конструктором {@see fromText()}:
 * он материализует полученный текст во ВРЕМЕННЫЙ файл на диске и оборачивает
 * его в обычный `UploadedFile` (test-режим), поэтому `ConversionManager`
 * (getClientOriginalExtension/getMimeType/getSize/getPathname/storeInput)
 * остаётся ВООБЩЕ не тронутым — text и file неотличимы ниже границы DTO.
 * Temp-файл живёт до вызова {@see cleanupTempFile()} — контроллер обязан
 * вызвать его в `finally` после `ConversionManager::createConversion()`.
 */
class ConversionRequestDTO
{
    public function __construct(
        public readonly User $user,
        public readonly UploadedFile $file,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $toFormat,
        public readonly bool $ocr = false,
        public readonly bool $privileged = true,
        /** @var array<string, int|string> Разрешённые параметры результата изображения. */
        public readonly array $options = [],
        /** Путь temp-файла для удаления после обработки (только text-вход, см. {@see fromText()}). */
        private readonly ?string $tempFilePath = null,
    ) {
    }

    /**
     * Text-вход (paste-режим, без файла): заворачивает `$text` во временный
     * файл с расширением `$sourceFormat` и оборачивает его `UploadedFile`
     * (test-режим Symfony — не требует реального HTTP-аплоада), чтобы
     * `ConversionManager::createConversion()` прошёл ПО ТОЙ ЖЕ цепочке, что и
     * загруженный файл (getClientOriginalExtension() отдаст `$sourceFormat`).
     *
     * `$sourceFormat` уже должен быть провалидирован вызывающей стороной
     * (контроллер: непустой + поддерживаемый текстовый источник реестра) —
     * здесь никакой доменной валидации нет, только материализация.
     */
    public static function fromText(
        User $user,
        string $text,
        string $sourceFormat,
        string $toFormat,
        bool $privileged = true,
    ): self {
        $tmpPath = tempnam(sys_get_temp_dir(), 'conv_text_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Unable to create temp file for text input');
        }

        // tempnam() создаёт файл без расширения; UploadedFile деривит формат
        // из ИМЕНИ (2-й арг конструктора), поэтому переименовываем на диске,
        // чтобы getPathname()/getClientOriginalExtension() были консистентны.
        $path = $tmpPath . '.' . $sourceFormat;
        if (! rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new \RuntimeException('Unable to materialize text input to a temp file');
        }

        file_put_contents($path, $text);

        $file = new UploadedFile($path, 'text.' . $sourceFormat, null, null, true);

        return new self($user, $file, $toFormat, false, $privileged, [], $path);
    }

    /**
     * Удаляет temp-файл, созданный {@see fromText()} (no-op для file-входа —
     * `$tempFilePath` там всегда null, чужой аплоад-tmp мы не трогаем: его
     * жизненным циклом управляет PHP/Symfony). Вызывается контроллером в
     * `finally` — ПОСЛЕ того как `ConversionManager::storeInput()` синхронно
     * прочитал файл в S3 (или бросил исключение), т.е. безопасно в обоих случаях.
     */
    public function cleanupTempFile(): void
    {
        if ($this->tempFilePath !== null && is_file($this->tempFilePath)) {
            @unlink($this->tempFilePath);
        }
    }
}
