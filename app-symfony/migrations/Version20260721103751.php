<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * admin-managed-examples: таблица `examples` — админ-управляемые «живые
 * примеры» лендинга (заменяет захардкоженный App\Service\Examples\ExampleCatalog
 * как источник данных для публичного ExampleController; каталог остаётся только
 * seed-источником). `conversion_id` — необязательная ссылка на конвертацию-
 * источник промо, ON DELETE SET NULL (см. класс-докблок App\Entity\Example):
 * `app:clean-test-data` безусловно вайпает ВСЕ conversions, а Example-строка
 * (untracked S3-копия) должна пережить этот вайп.
 *
 * Автоген `doctrine:migrations:diff` заодно поймал НЕСВЯЗАННЫЙ пред-существующий
 * дрифт схемы (переименования индексов в hash-имена Doctrine, COMMENT-разметку
 * datetime_immutable по всей БД) — сюда намеренно НЕ включён, та же политика, что
 * Version20260718115536/Version20260719141857.
 */
final class Version20260721103751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create examples table (admin-managed-examples)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE examples (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(20) NOT NULL, from_format VARCHAR(20) NOT NULL, to_format VARCHAR(20) NOT NULL, filename VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size BIGINT NOT NULL, previewable TINYINT NOT NULL, source_format VARCHAR(20) NOT NULL, source_mime VARCHAR(127) NOT NULL, source_filename VARCHAR(255) NOT NULL, result_key VARCHAR(500) NOT NULL, source_key VARCHAR(500) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, conversion_id INT DEFAULT NULL, INDEX IDX_7BD0AD454C1FF126 (conversion_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE examples ADD CONSTRAINT FK_7BD0AD454C1FF126 FOREIGN KEY (conversion_id) REFERENCES conversions (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE examples DROP FOREIGN KEY FK_7BD0AD454C1FF126');
        $this->addSql('DROP TABLE examples');
    }
}
