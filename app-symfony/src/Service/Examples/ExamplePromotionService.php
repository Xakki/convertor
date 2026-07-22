<?php

declare(strict_types=1);

namespace App\Service\Examples;

use App\Entity\Conversion;
use App\Entity\Example;
use App\Repository\ExampleRepository;
use App\Service\Storage\S3Storage;

/**
 * Промо СУЩЕСТВУЮЩЕЙ конвертации в «живой пример» лендинга и обратное удаление
 * (карточка admin-managed-examples, подход A — не загрузка с нуля).
 *
 * `AdminExampleController` ДОЛЖЕН проверить ДО вызова {@see promote()}:
 * `conversion.status === Completed`, `outputFile !== null`, и что оба
 * S3-объекта (input, output) реально существуют (та же gate-логика, что и у
 * {@see \App\Controller\Admin\Api\DlqController::requeue()} для `input_gone`) —
 * этот сервис их не перепроверяет, только копирует/пишет.
 *
 * Ключи строятся ТЕМ ЖЕ паттерном, что и {@see \App\Service\Examples\ExampleDefinition}
 * у seed-команды: `examples/<category>/<from>-to-<to>[-N].<ext>` для результата,
 * `…-source.<from>` для исходника — но slug генерируется здесь заново (не через
 * ExampleDefinition, у которой смысл поля `sampleFile` — путь на диске, тут его
 * нет), с суффиксом `-2`, `-3`… при коллизии (открытый вопрос карточки: несколько
 * примеров на категорию РАЗРЕШЕНЫ, slug — автогенерируемый, не выбирается админом).
 *
 * Копии — В ОБА КОНЦА untracked (без строки {@see \App\Entity\FileStorage}):
 * `FileCleanupService`/`app:clean-test-data` их не видят и не удаляют.
 */
final class ExamplePromotionService
{
    public function __construct(
        private readonly S3Storage $s3,
        private readonly ExampleRepository $examples,
    ) {
    }

    public function promote(Conversion $conversion): Example
    {
        $category = $conversion->getCategory()->value;
        $from     = $conversion->getFromFormat();
        $to       = $conversion->getToFormat();

        $output = $conversion->getOutputFile();
        $input  = $conversion->getInputFile();
        if ($output === null) {
            // Не должно случиться — вызывающий гейтит Completed+outputFile до вызова.
            throw new \LogicException('promote() требует конвертацию с outputFile');
        }

        $slug           = $this->uniqueSlug($category, $from, $to);
        $filename       = $slug . '.' . $to;
        $sourceFilename = $slug . '-source.' . $from;
        $resultKey      = 'examples/' . $category . '/' . $filename;
        $sourceKey      = 'examples/' . $category . '/' . $sourceFilename;

        $resultsBucket = $this->s3->resultsBucket();

        $this->s3->copyObject($resultsBucket, $output->getStoragePath(), $resultsBucket, $resultKey, $output->getMimeType());
        $this->s3->copyObject($this->s3->inputsBucket(), $input->getStoragePath(), $resultsBucket, $sourceKey, $input->getMimeType());

        // Реальный размер копии результата (HEAD после copy) — точнее, чем
        // FileStorage.sizeBytes, если тот когда-либо разойдётся с S3.
        $stat = $this->s3->objectStat($resultsBucket, $resultKey);
        $size = $stat['size'] ?? $output->getSizeBytes();

        $example = (new Example())
            ->setCategory($category)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setFilename($filename)
            ->setMime($output->getMimeType())
            ->setSize($size)
            ->setPreviewable(PreviewableFormat::isPreviewable($output->getMimeType(), $to))
            ->setSourceFormat($from)
            ->setSourceMime($input->getMimeType())
            ->setSourceFilename($sourceFilename)
            ->setResultKey($resultKey)
            ->setSourceKey($sourceKey)
            ->setSortOrder($this->examples->nextSortOrder())
            ->setConversion($conversion);

        $this->examples->save($example, true);

        return $example;
    }

    /**
     * Удаляет строку Example и ОБА её S3-объекта (`examples/`-префикс). Удаление
     * несуществующего S3-ключа идемпотентно ({@see S3Storage::deleteObject}), так
     * что повторный вызов/частично-упавшая предыдущая попытка не бросают ошибку.
     */
    public function remove(Example $example): void
    {
        $resultsBucket = $this->s3->resultsBucket();
        $this->s3->deleteObject($resultsBucket, $example->getResultKey());
        $this->s3->deleteObject($resultsBucket, $example->getSourceKey());

        $this->examples->remove($example, true);
    }

    private function uniqueSlug(string $category, string $from, string $to): string
    {
        $base   = $from . '-to-' . $to;
        $slug   = $base;
        $suffix = 2;

        while ($this->examples->findOneByResultKey('examples/' . $category . '/' . $slug . '.' . $to) !== null) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
