<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Synchronizer\CatalogSynchronizer;
use Gally\ShopwarePlugin\Synchronizer\MetadataSynchronizer;
use Gally\ShopwarePlugin\Synchronizer\SourceFieldSynchronizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StructureSync extends Command
{
    protected static $defaultName = 'gally:structure:sync';
    private CatalogSynchronizer $catalogSynchronizer;
    private SourceFieldSynchronizer $sourceFieldSynchronizer;

    public function __construct(
        CatalogSynchronizer $catalogSynchronizer,
        SourceFieldSynchronizer $sourceFieldSynchronizer
    ) {
        parent::__construct();
        $this->catalogSynchronizer = $catalogSynchronizer;
        $this->sourceFieldSynchronizer = $sourceFieldSynchronizer;
    }

    protected function configure(): void
    {
        $this->setDescription('Synchronize sales channels, entity fields with gally data structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->catalogSynchronizer->synchronizeAll();
        $this->sourceFieldSynchronizer->synchronizeAll();
        return 0;
    }
}
