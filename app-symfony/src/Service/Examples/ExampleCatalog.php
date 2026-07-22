<?php

declare(strict_types=1);

namespace App\Service\Examples;

/**
 * Курируемый набор примеров конвертаций для лендинга (home-04, решение D3):
 * по одной показательной паре source→target на MVP-категорию Стадии 1.
 *
 * Набор намеренно небольшой и фиксированный (не листинг S3): и seed-команда, и
 * витрина итерируют ровно этот список, поэтому состояние детерминировано —
 * никаких «висящих» объектов и рассинхрона. Исходные сэмплы лежат в
 * resources/seed-examples/ (мелкие, собственного авторства).
 *
 * Обязательные категории (AC): document, image, audio, video, data. markup —
 * бонус; если воркер её не потянет на момент seed, объект просто не появится и
 * витрина её не покажет (эндпоинт фильтрует по факту наличия объекта).
 */
final class ExampleCatalog
{
    /**
     * @return list<ExampleDefinition>
     */
    public function all(): array
    {
        return [
            new ExampleDefinition('document', 'txt', 'pdf', 'document.txt', false),
            new ExampleDefinition('data', 'csv', 'json', 'data.csv', true),
            new ExampleDefinition('image', 'png', 'jpg', 'image.png', false),
            new ExampleDefinition('audio', 'wav', 'mp3', 'audio.wav', false),
            new ExampleDefinition('video', 'mp4', 'webm', 'video.mp4', false),
            new ExampleDefinition('markup', 'md', 'html', 'markup.md', true),
        ];
    }

    /**
     * Категории, обязательные к успешному прогону (AC home-04): их провал в
     * seed-команде — ошибка, а не «просто не показываем».
     *
     * @return list<string>
     */
    public function requiredCategories(): array
    {
        return ['document', 'data', 'image', 'audio', 'video'];
    }

    /**
     * Найти определение по (category, objectName) — для валидации запроса
     * файлового эндпоинта против whitelist (не доверяем произвольному ключу).
     */
    public function find(string $category, string $objectName): ?ExampleDefinition
    {
        foreach ($this->all() as $def) {
            if ($def->category === $category && $def->objectName() === $objectName) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Найти определение по (category, sampleFile) — та же whitelist-логика, что
     * и у {@see find()}, но для source-эндпоинта (home-10): имя файла-сэмпла из
     * запроса сверяется с каталогом, произвольный путь к файлу на диске подсунуть
     * нельзя.
     */
    public function findBySource(string $category, string $sampleFile): ?ExampleDefinition
    {
        foreach ($this->all() as $def) {
            if ($def->category === $category && $def->sampleFile === $sampleFile) {
                return $def;
            }
        }

        return null;
    }
}
