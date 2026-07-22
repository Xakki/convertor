<?php

declare(strict_types=1);

namespace App\Service\Examples;

/**
 * Одна курируемая пара-пример (source→target) для лендинга.
 *
 * Определение — единый источник правды и для seed-команды
 * ({@see \App\Command\SeedExamplesCommand}, которая гоняет исходник через
 * РЕАЛЬНЫЙ пайплайн и копирует результат в стабильный S3-префикс), и для
 * публичного эндпоинта-витрины ({@see \App\Controller\Api\ExampleController}).
 *
 * `category` здесь — курируемая метка раздела витрины и часть S3-ключа
 * (`examples/<category>/<slug>.<to>`); она НЕ обязана совпадать с внутренним
 * routing-стримом реестра (напр. markup роутится через document-стрим) —
 * реальную маршрутизацию решает пайплайн, а не это поле.
 */
final readonly class ExampleDefinition
{
    public function __construct(
        public string $category,
        public string $from,
        public string $to,
        /** Имя файла-исходника в resources/seed-examples/. */
        public string $sampleFile,
        /** Пригоден ли результат для текстового inline-превью (md/txt/json/csv/html). */
        public bool $previewable,
    ) {
    }

    /** Стабильный slug пары — часть имени объекта и URL. */
    public function slug(): string
    {
        return $this->from . '-to-' . $this->to;
    }

    /** Имя результирующего объекта (без префикса категории). */
    public function objectName(): string
    {
        return $this->slug() . '.' . $this->to;
    }

    /** Полный S3-ключ результата в бакете результатов. */
    public function s3Key(): string
    {
        return 'examples/' . $this->category . '/' . $this->objectName();
    }

    /**
     * Имя объекта исходника в S3 (карточка admin-managed-examples): sample-файл
     * теперь дублируется в S3 рядом с результатом (не только на локальном диске),
     * чтобы {@see \App\Controller\Api\ExampleController::source()} мог отдавать И
     * seed-примеры, И admin-промо-примеры ОДНИМ кодовым путём (S3, не диск).
     */
    public function sourceObjectName(): string
    {
        return $this->slug() . '-source.' . $this->from;
    }

    /** Полный S3-ключ исходника в бакете результатов. */
    public function sourceS3Key(): string
    {
        return 'examples/' . $this->category . '/' . $this->sourceObjectName();
    }

    /** MIME результата по целевому формату (для Content-Type отдачи и JSON-витрины). */
    public function mime(): string
    {
        return self::mimeForFormat($this->to);
    }

    /**
     * MIME исходника по формату `from` (home-10: публичная отдача sample-файла
     * из resources/seed-examples/ — та же таблица форматов, что и у результата).
     */
    public function sourceMime(): string
    {
        return self::mimeForFormat($this->from);
    }

    private static function mimeForFormat(string $format): string
    {
        return match ($format) {
            'pdf'   => 'application/pdf',
            'html'  => 'text/html',
            'json'  => 'application/json',
            'csv'   => 'text/csv',
            'md'    => 'text/markdown',
            'txt'   => 'text/plain',
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'mp3'   => 'audio/mpeg',
            'wav'   => 'audio/wav',
            'ogg'   => 'audio/ogg',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            default => 'application/octet-stream',
        };
    }
}
