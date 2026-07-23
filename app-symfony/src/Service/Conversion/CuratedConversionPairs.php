<?php

declare(strict_types=1);

namespace App\Service\Conversion;

/**
 * Курируемый подсписок пар конвертации для дропдауна «Conversions» в общем
 * хедере и как источник для SEO-страниц пар (home-09-seo-conversion-pages).
 *
 * Тот же принцип, что и {@see \App\Service\Examples\ExampleCatalog} для
 * home-04 (решение D3): небольшой ФИКСИРОВАННЫЙ список — 2-3 показательные
 * пары на категорию, а не полная матрица {@see ConversionRegistry} (та слишком
 * велика для dropdown). Список НЕ строится на лету из реестра — каждая пара
 * вручную сверена с DB-backed матрицей {@see ConversionRegistry} (единственный
 * источник с registry-05) и должна оставаться валидной; при изменении матрицы
 * синхронизировать вручную.
 *
 * @see \App\Twig\ConversionExtension прокидывает {@see self::grouped()} в Twig
 * @see \App\Controller\Web\ConversionPageController рендерит страницу пары
 */
final class CuratedConversionPairs
{
    /**
     * @return list<array{category: string, from: string, to: string}>
     */
    public static function all(): array
    {
        return [
            // Документы
            ['category' => 'document', 'from' => 'pdf', 'to' => 'docx'],
            ['category' => 'document', 'from' => 'docx', 'to' => 'pdf'],
            ['category' => 'document', 'from' => 'txt', 'to' => 'pdf'],
            // Изображения
            ['category' => 'image', 'from' => 'png', 'to' => 'jpg'],
            ['category' => 'image', 'from' => 'jpg', 'to' => 'png'],
            ['category' => 'image', 'from' => 'png', 'to' => 'webp'],
            // Данные
            ['category' => 'data', 'from' => 'csv', 'to' => 'json'],
            ['category' => 'data', 'from' => 'json', 'to' => 'csv'],
            // Аудио
            ['category' => 'audio', 'from' => 'mp3', 'to' => 'wav'],
            ['category' => 'audio', 'from' => 'wav', 'to' => 'mp3'],
            // Видео
            ['category' => 'video', 'from' => 'mp4', 'to' => 'webm'],
            ['category' => 'video', 'from' => 'mov', 'to' => 'mp4'],
            // Разметка
            ['category' => 'markup', 'from' => 'md', 'to' => 'html'],
            ['category' => 'markup', 'from' => 'html', 'to' => 'md'],
        ];
    }

    /**
     * Сгруппировано по категории, в порядке первого появления в {@see all()} —
     * готово для рендера dropdown-секций без доп. обработки в Twig.
     *
     * @return array<string, list<array{from: string, to: string}>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $pair) {
            $groups[$pair['category']][] = ['from' => $pair['from'], 'to' => $pair['to']];
        }

        return $groups;
    }
}
