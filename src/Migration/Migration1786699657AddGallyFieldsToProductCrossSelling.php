<?php

declare(strict_types=1);

namespace Gally\ShopwarePlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1786699657AddGallyFieldsToProductCrossSelling extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1786699657;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `product_cross_selling`
                ADD COLUMN `gally_recommender_type_id` BINARY(16) NULL
        ');

        $connection->executeStatement('
            ALTER TABLE `product_cross_selling`
                ADD CONSTRAINT `fk.product_cross_selling.gally_recommender_type_id` FOREIGN KEY (`gally_recommender_type_id`)
                    REFERENCES `gally_recommender_type` (`id`) ON DELETE SET NULL
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
