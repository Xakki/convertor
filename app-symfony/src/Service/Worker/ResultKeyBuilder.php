<?php

declare(strict_types=1);

namespace App\Service\Worker;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Строит S3-ключ объекта-результата конвертации. Формат общий с Python-воркером:
 * {S3_PREFIX}results/{Y}/{m-d}/{id}.{ext}
 *
 * Ключ детерминирован по (conversionId, targetFormat) — это и есть гарантия
 * идемпотентности: повторный persist одного и того же результата перезапишет тот
 * же объект. Общий сервис для multipart-пути (WorkerController::result) и
 * inline-relay-пути (InternalWorkerController::result) — без дублирования.
 */
final class ResultKeyBuilder
{
    public function __construct(
        #[Autowire('%env(S3_PREFIX)%')]
        private readonly string $s3Prefix,
    ) {
    }

    /**
     * $targetFormat санитизируется до [a-z0-9]+ перед подстановкой в ключ
     * (защита от path-injection / неожиданных символов из payload воркера).
     */
    public function build(int $conversionId, string $targetFormat): string
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($targetFormat)) ?: 'bin';

        return $this->s3Prefix
            . 'results/'
            . (new \DateTimeImmutable())->format('Y/m-d')
            . '/' . $conversionId . '.' . $ext;
    }
}
