<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\WorkerCapability;

/**
 * Deliberate literal duplicate of `Version20260722150301::seedRows()`
 * (registry-03 seed migration) as `WorkerCapability[]` entities, for unit
 * tests that need a matrix-backed {@see ConversionRegistry} without a live DB.
 *
 * WHY duplicated, not reused: migrations are one-shot SQL generators, not a
 * library API — reaching into a migration class via reflection to reuse its
 * private `seedRows()` would couple test infrastructure to migration internals
 * that are free to change shape (or be squashed) independently. The literal
 * data here is intentionally the SAME snapshot as the migration; if the seed
 * data changes, keep both in sync (registry-03's migration docblock notes the
 * data is a static snapshot, not runtime-derived, for the same reason).
 *
 * registry-05: `ConversionRegistry::workerCapabilities()`/`buildMatrixFromHardcode()`
 * (the old no-DB convenience fallback for `new ConversionRegistry()`) were
 * deleted — the registry now returns an EMPTY matrix without a repository.
 * Tests that need a non-empty, `isSupported()`-true matrix must construct
 * `ConversionRegistry` with a stub repository backed by {@see capabilities()}
 * instead of relying on the old implicit fallback.
 */
final class ConversionRegistrySeedFixture
{
    public const SEED_INSTANCE_ID = '__seed__';

    /**
     * @return WorkerCapability[]
     */
    public static function capabilities(): array
    {
        $officeTargets  = ['docx', 'epub', 'html', 'md', 'odt', 'pdf', 'rtf', 'txt'];
        $epubTargets    = ['docx', 'html', 'md', 'odt', 'rtf', 'txt'];
        $impressTargets = ['odp', 'pdf', 'pptx'];
        $documentMatrix = [
            'doc'   => $officeTargets,
            'docx'  => $officeTargets,
            'odt'   => $officeTargets,
            'rtf'   => $officeTargets,
            'txt'   => $officeTargets,
            'html'  => $officeTargets,
            'htm'   => $officeTargets,
            'epub'  => $epubTargets,
            'pdf'   => ['docx', 'jpg', 'md', 'txt'],
            'md'    => $officeTargets,
            'rst'   => $officeTargets,
            'latex' => $officeTargets,
            'tex'   => $officeTargets,
            'wiki'  => $officeTargets,
            'xls'   => $officeTargets,
            'xlsx'  => $officeTargets,
            'ods'   => $officeTargets,
            'csv'   => $officeTargets,
            'ppt'   => $impressTargets,
            'pptx'  => $impressTargets,
            'odp'   => $impressTargets,
            'pages' => $officeTargets,
        ];

        $imageMatrix = [
            'jpg'  => ['bmp', 'docx', 'gif', 'ico', 'md', 'pdf', 'png', 'tiff', 'txt', 'webp'],
            'jpeg' => ['bmp', 'docx', 'gif', 'ico', 'md', 'pdf', 'png', 'tiff', 'txt', 'webp'],
            'png'  => ['bmp', 'docx', 'gif', 'ico', 'jpg', 'md', 'pdf', 'tiff', 'txt', 'webp'],
            'gif'  => ['bmp', 'ico', 'jpg', 'pdf', 'png', 'tiff', 'webp'],
            'bmp'  => ['gif', 'ico', 'jpg', 'pdf', 'png', 'tiff', 'webp'],
            'webp' => ['bmp', 'gif', 'ico', 'jpg', 'pdf', 'png', 'tiff'],
            'tiff' => ['bmp', 'docx', 'gif', 'ico', 'jpg', 'md', 'pdf', 'png', 'txt', 'webp'],
            'tif'  => ['bmp', 'docx', 'gif', 'ico', 'jpg', 'md', 'pdf', 'png', 'txt', 'webp'],
            'ico'  => ['bmp', 'gif', 'jpg', 'pdf', 'png', 'tiff', 'webp'],
            'pdf'  => ['docx', 'md', 'txt'],
        ];

        $audioTargets = ['aac', 'flac', 'm4a', 'mp3', 'ogg', 'opus', 'wav'];
        $audioMatrix  = [
            'mp3'  => $audioTargets,
            'wav'  => $audioTargets,
            'ogg'  => $audioTargets,
            'flac' => $audioTargets,
            'aac'  => $audioTargets,
            'm4a'  => $audioTargets,
            'opus' => $audioTargets,
            'wma'  => $audioTargets,
        ];

        $videoTargets = ['avi', 'flac', 'mkv', 'mov', 'mp3', 'mp4', 'ogg', 'wav', 'webm'];
        $videoMatrix  = [
            '3gp'  => $videoTargets,
            'mp4'  => $videoTargets,
            'avi'  => $videoTargets,
            'mkv'  => $videoTargets,
            'mov'  => $videoTargets,
            'webm' => $videoTargets,
            'flv'  => $videoTargets,
            'wmv'  => $videoTargets,
        ];

        $dataMatrix = [
            'csv'  => ['json', 'toml', 'xml', 'yaml', 'yml'],
            'json' => ['csv', 'toml', 'xml', 'yaml', 'yml'],
            'xml'  => ['csv', 'json', 'toml', 'yaml', 'yml'],
            'yaml' => ['csv', 'json', 'toml', 'xml'],
            'yml'  => ['csv', 'json', 'toml', 'xml'],
            'toml' => ['csv', 'json', 'xml', 'yaml', 'yml'],
        ];

        $aiMatrix = [
            'mp3'  => ['txt', 'srt', 'vtt'],
            'wav'  => ['txt', 'srt', 'vtt'],
            'ogg'  => ['txt', 'srt', 'vtt'],
            'm4a'  => ['txt', 'srt', 'vtt'],
            'opus' => ['txt', 'srt', 'vtt'],
            'flac' => ['txt', 'srt', 'vtt'],
            'txt'  => ['mp3', 'wav', 'ogg', 'json'],
            'md'   => ['mp3', 'wav', 'ogg'],
        ];
        $aiCategories = [
            'mp3'  => 'audio',
            'wav'  => 'audio',
            'ogg'  => 'audio',
            'm4a'  => 'audio',
            'opus' => 'audio',
            'flac' => 'audio',
            'txt'  => 'document',
            'md'   => 'document',
        ];

        return [
            new WorkerCapability('document', self::SEED_INSTANCE_ID, self::payload('document', false, $documentMatrix)),
            new WorkerCapability('image', self::SEED_INSTANCE_ID, self::payload('image', false, $imageMatrix)),
            new WorkerCapability('audio', self::SEED_INSTANCE_ID, self::payload('audio', false, $audioMatrix)),
            new WorkerCapability('video', self::SEED_INSTANCE_ID, self::payload('video', false, $videoMatrix)),
            new WorkerCapability('data', self::SEED_INSTANCE_ID, self::payload('data', false, $dataMatrix)),
            new WorkerCapability('ai', self::SEED_INSTANCE_ID, self::payload('ai', true, $aiMatrix, $aiCategories)),
        ];
    }

    /**
     * @param array<string, list<string>> $matrix
     * @param array<string, string>       $matrixCategories
     *
     * @return array<string, mixed>
     */
    private static function payload(string $workerType, bool $isAi, array $matrix, array $matrixCategories = []): array
    {
        return [
            'workerType'        => $workerType,
            'instanceId'        => self::SEED_INSTANCE_ID,
            'isAi'              => $isAi,
            'streams'           => [$workerType],
            'routingKeys'       => [$workerType],
            'matrix'            => $matrix,
            'matrix_categories' => $matrixCategories,
            'image'             => null,
            'version'           => 'seed',
        ];
    }
}
