<?php

declare(strict_types=1);

namespace Gally\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1786693692CreateGallyRecommenderType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1786693692;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `gally_recommender_type` (
              `id` BINARY(16) NOT NULL,
              `code` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              `label` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq.gally_recommender_type.code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
