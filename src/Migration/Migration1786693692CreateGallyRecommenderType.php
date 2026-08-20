<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Gally to newer versions in the future.
 *
 * @package   Gally
 * @author    Gally Team <elasticsuite@smile.fr>
 * @copyright 2022-present Smile
 * @license   Open Software License v. 3.0 (OSL-3.0)
 */

declare(strict_types=1);

namespace Gally\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Pure FK target for product_cross_selling.gally_recommender_type_id: only holds the code of a
 * recommender type configured directly in Gally, no label. Rows are created on demand, never
 * managed by hand (see AdminController::resolveRecommenderType()).
 */
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
              `created_at` DATETIME(3) NOT NULL,
              `updated_at` DATETIME(3) NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq.gally_recommender_type.code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->addColumn($connection, 'product_cross_selling', 'gally_recommender_type_id', 'BINARY(16)');

        try {
            $connection->executeStatement('
                ALTER TABLE `product_cross_selling`
                    ADD CONSTRAINT `fk.product_cross_selling.gally_recommender_type_id` FOREIGN KEY (`gally_recommender_type_id`)
                        REFERENCES `gally_recommender_type` (`id`) ON DELETE SET NULL
            ');
        } catch (DBALException) {
            // Constraint already exists: this migration is being re-run (e.g. after a failed
            // earlier attempt), nothing left to do.
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        try {
            $connection->executeStatement('
                ALTER TABLE `product_cross_selling`
                    DROP FOREIGN KEY `fk.product_cross_selling.gally_recommender_type_id`
            ');
        } catch (DBALException) {
            // Constraint already gone (or never created): nothing left to drop.
        }
        $this->dropColumnIfExists($connection, 'product_cross_selling', 'gally_recommender_type_id');
        $this->dropTableIfExists($connection, 'gally_recommender_type');
    }
}
