<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * registry-03: заливает статичный снапшот текущей conversion-матрицы в
 * worker_capabilities — по одной строке на РЕАЛЬНЫЙ worker-type (то, что
 * Python-воркеры реально регистрируют: document/image/audio/video/data/ai),
 * а НЕ на полный PHP-хардкод ConversionRegistry::workerCapabilities() — там
 * есть 'markup' (несуществующий отдельный воркер, схлопывается в 'document'
 * только при роутинге) и Stage-7 «coming-soon» пары (xls/xlsx/ods/csv→pdf,
 * ppt/pptx/odp→pdf, dwg/dxf→pdf/svg/png, pdf→jpg), которых реальные Python
 * CAPABILITIES сознательно НЕ декларируют (workers/libreoffice/worker.py
 * `_MATRIX`, комментарий над ним) — по [USER DECISION 2026-07-01] эти пары
 * должны исчезнуть (честный 400), а не мигрировать в БД.
 *
 * Данные ниже — СТАТИЧНЫЙ снимок по состоянию на 2026-07-22, сверенный
 * построчно с workers/{libreoffice,image,ffmpeg,data,ai}/worker.py
 * (CAPABILITIES/_MATRIX/SUPPORTED/AUDIO_CAPABILITIES/VIDEO_CAPABILITIES).
 * НЕ runtime-чтение ConversionRegistry или Python — миграция обязана остаться
 * валидной после того, как workerCapabilities()/buildMatrixFromHardcode()
 * удалят (registry-05).
 *
 * Seed instance_id = '__seed__' — удовлетворяет контракту registry-02
 * (`^[A-Za-z0-9._:-]+$`, непустая, ≤128), по форме неотличим от реальной
 * регистрации. Живёт в БД до explicit deregister/TTL (registry-06) — НЕ
 * вытесняется автоматически реальным register того же worker_type (другой
 * instance_id → отдельная строка по составному ключу registry-02).
 *
 * Идемпотентна: `INSERT IGNORE` на UNIQUE(worker_type, instance_id) —
 * повторный прогон (или запуск на уже засеянной БД) не дублирует и не падает.
 */
final class Version20260722150301 extends AbstractMigration
{
    // Тот же литерал задублирован (не импортирован) в
    // WorkerController::RESERVED_SEED_INSTANCE_ID, который отклоняет живой
    // register с этим instanceId 400-кой — миграция обязана оставаться
    // самодостаточной и не зависеть от кода приложения. При переименовании
    // синхронизировать оба места вручную.
    private const SEED_INSTANCE_ID = '__seed__';

    public function getDescription(): string
    {
        return 'worker_capabilities: seed статичным снапшотом реальных Python CAPABILITIES (Stage-7 document — CNV-41)';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->seedRows() as $workerType => $capabilities) {
            $this->addSql(
                <<<'SQL'
                    INSERT IGNORE INTO worker_capabilities (worker_type, instance_id, capabilities, last_seen)
                    VALUES (?, ?, ?, NOW())
                    SQL,
                [$workerType, self::SEED_INSTANCE_ID, json_encode($capabilities, JSON_THROW_ON_ERROR)],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Точечно удаляет только seed-строки (instance_id = '__seed__') — реальные
        // регистрации (другой instance_id) не трогает, гонки/дублей-guard здесь не
        // нужен (в отличие от Version20260722142906::down()), т.к. это чистое
        // DELETE по значению, а не пересоздание UNIQUE-индекса.
        $this->addSql(
            'DELETE FROM worker_capabilities WHERE instance_id = ?',
            [self::SEED_INSTANCE_ID],
        );
    }

    /**
     * @return array<string, array<string, mixed>> workerType => полный register-payload,
     *         той же формы, что и реальное тело `POST /worker/register`
     *         (см. `workers/common/ws_client.py::_build_register_body()`).
     */
    private function seedRows(): array
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
            'document' => $this->payload('document', false, $documentMatrix),
            'image'    => $this->payload('image', false, $imageMatrix),
            'audio'    => $this->payload('audio', false, $audioMatrix),
            'video'    => $this->payload('video', false, $videoMatrix),
            'data'     => $this->payload('data', false, $dataMatrix),
            'ai'       => $this->payload('ai', true, $aiMatrix, $aiCategories),
        ];
    }

    /**
     * @param array<string, list<string>> $matrix
     * @param array<string, string>       $matrixCategories
     * @return array<string, mixed>
     */
    private function payload(string $workerType, bool $isAi, array $matrix, array $matrixCategories = []): array
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
