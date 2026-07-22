<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Conversion\CuratedConversionPairs;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Прокидывает {@see CuratedConversionPairs::grouped()} в Twig как функцию
 * `curated_conversion_pairs()` — нужно дропдауну «Conversions» в общем хедере
 * (templates/partials/_header.html.twig, home-09-seo-conversion-pages),
 * который рендерится на КАЖДОЙ странице через base.html.twig без контроллерного
 * контекста конкретной пары, поэтому список не проходит через render()-параметры
 * отдельных контроллеров, а читается напрямую как глобальная Twig-функция.
 */
final class ConversionExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('curated_conversion_pairs', [CuratedConversionPairs::class, 'grouped']),
        ];
    }
}
