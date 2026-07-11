<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\ConversionToggle;
use App\Repository\ConversionToggleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Чтение и переключение персистентных флагов конвертаций (пара from→to).
 *
 * Флаг читается на каждый сабмит, поэтому кешируется. Кешируем ЛИШЬ множество
 * отключённых пар («from>to»): по умолчанию всё включено (отсутствие ряда =
 * включено), а отключённых пар мало → множество компактно, а per-submit проверка
 * = один cache-hit + in-memory lookup.
 *
 * Кеш — тот же Symfony `cache.app`, что использует {@see ConversionRegistry}
 * (не заводим отдельное подключение). Инвалидация зеркалит
 * {@see ConversionRegistry::invalidateMatrix()}: сброс per-request memo + delete
 * cross-request. Memo обязателен к сбросу: WebTestCase переиспользует один
 * контейнер между запросами, иначе устаревший memo переживёт delete().
 */
class ConversionToggleService
{
    private const CACHE_KEY = 'conv.toggle.disabled';

    /**
     * Per-request memo отключённых пар («from>to»).
     *
     * @var list<string>|null
     */
    private ?array $disabled = null;

    public function __construct(
        private readonly ConversionToggleRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Включена ли конвертация. Отсутствие ряда = включена. Проверка
     * flag-agnostic: пара блокируется независимо от ocr-флага (та же пара
     * from→to), что и есть смысл гранулярности «по паре».
     */
    public function isEnabled(string $fromFormat, string $toFormat): bool
    {
        return ! in_array($fromFormat . '>' . $toFormat, $this->disabledSet(), true);
    }

    /**
     * Переключает пару: upsert ряда + инвалидация кеша (сброс memo + delete).
     */
    public function setEnabled(string $fromFormat, string $toFormat, bool $enabled): void
    {
        $toggle = $this->repository->findPair($fromFormat, $toFormat);
        if ($toggle === null) {
            $toggle = new ConversionToggle($fromFormat, $toFormat, $enabled);
            $this->em->persist($toggle);
        } else {
            $toggle->setEnabled($enabled);
        }
        $this->em->flush();

        $this->invalidate();
    }

    /**
     * Сброс кеша (per-request memo + cross-request). Зеркалит
     * {@see ConversionRegistry::invalidateMatrix()}.
     */
    public function invalidate(): void
    {
        $this->disabled = null;
        $this->cache?->delete(self::CACHE_KEY);
    }

    /**
     * Множество отключённых пар («from>to»): из memo, иначе из кеша, иначе из БД.
     *
     * @return list<string>
     */
    private function disabledSet(): array
    {
        if ($this->disabled !== null) {
            return $this->disabled;
        }

        if ($this->cache === null) {
            return $this->disabled = $this->repository->disabledPairKeys();
        }

        /** @var list<string> $set */
        $set = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(3600); // страховка; основная инвалидация — delete()

            return $this->repository->disabledPairKeys();
        });

        return $this->disabled = $set;
    }
}
