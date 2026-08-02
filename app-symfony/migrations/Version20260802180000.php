<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CNV-30: пер-тир квоты (4 тира × 2 окна: daily + rolling 30-day monthly).
 */
final class Version20260802180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CNV-30: tier quotas — replace daily all/AI limits with 4×2 windows on plans/users';
    }

    public function up(Schema $schema): void
    {
        // --- plans: add tier limit columns ---
        $this->addSql(<<<'SQL'
            ALTER TABLE plans
                ADD light_daily_limit INT NOT NULL DEFAULT 0,
                ADD light_monthly_limit INT NOT NULL DEFAULT 0,
                ADD medium_daily_limit INT NOT NULL DEFAULT 0,
                ADD medium_monthly_limit INT NOT NULL DEFAULT 0,
                ADD heavy_daily_limit INT NOT NULL DEFAULT 0,
                ADD heavy_monthly_limit INT NOT NULL DEFAULT 0,
                ADD ai_daily_limit INT NOT NULL DEFAULT 0,
                ADD ai_monthly_limit INT NOT NULL DEFAULT 0
        SQL);

        // Reseed from CNV-30 price table (daily / monthly; -1 = ∞)
        $this->addSql(<<<'SQL'
            UPDATE plans SET
                light_daily_limit = 3, light_monthly_limit = 30,
                medium_daily_limit = 2, medium_monthly_limit = 15,
                heavy_daily_limit = 0, heavy_monthly_limit = 0,
                ai_daily_limit = 0, ai_monthly_limit = 0,
                max_file_size_mb = 50
            WHERE name = 'free'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE plans SET
                light_daily_limit = 100, light_monthly_limit = 1500,
                medium_daily_limit = 50, medium_monthly_limit = 800,
                heavy_daily_limit = 10, heavy_monthly_limit = 120,
                ai_daily_limit = 20, ai_monthly_limit = 200,
                max_file_size_mb = 200
            WHERE name = 'basic'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE plans SET
                light_daily_limit = -1, light_monthly_limit = -1,
                medium_daily_limit = 300, medium_monthly_limit = 6000,
                heavy_daily_limit = 60, heavy_monthly_limit = 800,
                ai_daily_limit = 80, ai_monthly_limit = 1200,
                max_file_size_mb = 500
            WHERE name = 'pro'
        SQL);

        $this->addSql('ALTER TABLE plans DROP daily_limit, DROP daily_ai_limit');

        // --- users: add tier counters + monthly window anchor ---
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD light_daily_conversions INT NOT NULL DEFAULT 0,
                ADD light_monthly_conversions INT NOT NULL DEFAULT 0,
                ADD medium_daily_conversions INT NOT NULL DEFAULT 0,
                ADD medium_monthly_conversions INT NOT NULL DEFAULT 0,
                ADD heavy_daily_conversions INT NOT NULL DEFAULT 0,
                ADD heavy_monthly_conversions INT NOT NULL DEFAULT 0,
                ADD ai_daily_conversions INT NOT NULL DEFAULT 0,
                ADD ai_monthly_conversions INT NOT NULL DEFAULT 0,
                ADD monthly_reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)'
        SQL);

        // Align monthly window start with existing daily window for legacy rows.
        $this->addSql('UPDATE users SET monthly_reset_at = quota_reset_at');

        $this->addSql('ALTER TABLE users DROP daily_conversions, DROP daily_ai_conversions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE plans
                ADD daily_limit INT NOT NULL DEFAULT 0,
                ADD daily_ai_limit INT NOT NULL DEFAULT 0
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE plans SET daily_limit = 2, daily_ai_limit = 1, max_file_size_mb = 50 WHERE name = 'free'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE plans SET daily_limit = 100, daily_ai_limit = 30, max_file_size_mb = 200 WHERE name = 'basic'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE plans SET daily_limit = -1, daily_ai_limit = 100, max_file_size_mb = 500 WHERE name = 'pro'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE plans
                DROP light_daily_limit, DROP light_monthly_limit,
                DROP medium_daily_limit, DROP medium_monthly_limit,
                DROP heavy_daily_limit, DROP heavy_monthly_limit,
                DROP ai_daily_limit, DROP ai_monthly_limit
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD daily_conversions INT NOT NULL DEFAULT 0,
                ADD daily_ai_conversions INT NOT NULL DEFAULT 0
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE users
                DROP light_daily_conversions, DROP light_monthly_conversions,
                DROP medium_daily_conversions, DROP medium_monthly_conversions,
                DROP heavy_daily_conversions, DROP heavy_monthly_conversions,
                DROP ai_daily_conversions, DROP ai_monthly_conversions,
                DROP monthly_reset_at
        SQL);
    }
}
