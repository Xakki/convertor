<?php

declare(strict_types=1);

namespace App\Service\Examples;

/**
 * Эвристика «пригоден ли результат для текстового inline-превью», используемая
 * при admin-промо конвертации в пример (карточка admin-managed-examples):
 * любой `text/*`, известный текстовый MIME, либо целевой формат из
 * `{md,txt,json,csv,html}`.
 *
 * НАМЕРЕННО отдельная копия того же критерия, что и приватный
 * `ConversionController::isPreviewable()` (история/preview-эндпоинт) — тот файл
 * несёт чувствительный viewer/primitive-token фикс, трогать его ради DRY здесь
 * не стоит риска регресса. Если оба места разъедутся по значениям — синхронизировать
 * руками (список форматов маленький и стабильный).
 */
final class PreviewableFormat
{
    private const FORMATS = ['md', 'txt', 'json', 'csv', 'html'];
    private const MIMES   = ['application/json', 'text/csv', 'text/markdown', 'text/html', 'text/plain'];

    public static function isPreviewable(string $mime, string $format): bool
    {
        // Отсекаем возможные параметры (`text/plain; charset=utf-8`).
        $mime = strtolower(trim(explode(';', $mime)[0]));

        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, self::MIMES, true)) {
            return true;
        }

        return in_array(strtolower($format), self::FORMATS, true);
    }
}
