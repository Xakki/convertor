<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-41 / Stage-7: обновляет seed-матрицу document-воркера до снапшота
 * workers/libreoffice/worker.py `_MATRIX` (epub-input, Calc/Impress, markup
 * rst/latex/tex/wiki, pdf→jpg, pages с libetonyek). Без epub→pdf.
 *
 * Для уже засеянных БД (registry-03 INSERT IGNORE не перезаписывает строку).
 * Свежие install'ы получают ту же матрицу из обновлённого
 * Version20260722150301::seedRows().
 */
final class Version20260803120000 extends AbstractMigration
{
    private const SEED_INSTANCE_ID = '__seed__';

    public function getDescription(): string
    {
        return 'CNV-41: worker_capabilities seed document matrix — Stage-7 LibreOffice formats';
    }

    public function up(Schema $schema): void
    {
        $payload = $this->documentSeedPayload();

        $this->addSql(
            'UPDATE worker_capabilities SET capabilities = ? WHERE worker_type = ? AND instance_id = ?',
            [
                json_encode($payload, JSON_THROW_ON_ERROR),
                'document',
                self::SEED_INSTANCE_ID,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $officeTargets  = ['docx', 'epub', 'html', 'md', 'odt', 'pdf', 'rtf', 'txt'];
        $legacyMatrix   = [
            'doc'  => $officeTargets,
            'docx' => $officeTargets,
            'odt'  => $officeTargets,
            'rtf'  => $officeTargets,
            'txt'  => $officeTargets,
            'html' => $officeTargets,
            'htm'  => $officeTargets,
            'epub' => ['md'],
            'pdf'  => ['docx', 'md', 'txt'],
            'md'   => ['docx', 'epub', 'html', 'md', 'odt', 'pdf', 'rtf', 'txt'],
        ];
        $legacyPayload = $this->payload('document', false, $legacyMatrix);

        $this->addSql(
            'UPDATE worker_capabilities SET capabilities = ? WHERE worker_type = ? AND instance_id = ?',
            [
                json_encode($legacyPayload, JSON_THROW_ON_ERROR),
                'document',
                self::SEED_INSTANCE_ID,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function documentSeedPayload(): array
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

        return $this->payload('document', false, $documentMatrix);
    }

    /**
     * @param array<string, list<string>> $matrix
     *
     * @return array<string, mixed>
     */
    private function payload(string $workerType, bool $isAi, array $matrix): array
    {
        return [
            'workerType'        => $workerType,
            'instanceId'        => self::SEED_INSTANCE_ID,
            'isAi'              => $isAi,
            'streams'           => [$workerType],
            'routingKeys'       => [$workerType],
            'matrix'            => $matrix,
            'matrix_categories' => [],
            'image'             => null,
            'version'           => 'seed',
        ];
    }
}
