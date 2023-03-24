<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Indexer\CategoryIndexer;
use Gally\ShopwarePlugin\Indexer\ManufacturerIndexer;
use Gally\ShopwarePlugin\Indexer\ProductIndexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Index extends Command
{
    protected static $defaultName = 'gally:index';
    private CategoryIndexer $categoryIndexer;
    private ProductIndexer $productIndexer;
    private ManufacturerIndexer $manufacturerIndexer;

    public function __construct(
        CategoryIndexer $categoryIndexer,
        ProductIndexer $productIndexer,
        ManufacturerIndexer $manufacturerIndexer
    ) {
        parent::__construct();
        $this->categoryIndexer = $categoryIndexer;
        $this->productIndexer = $productIndexer;
        $this->manufacturerIndexer = $manufacturerIndexer;
    }

    protected function configure(): void
    {
        $this->setDescription('Index category, product and manufacturer entities.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->categoryIndexer->index();
        $this->productIndexer->index();
        $this->manufacturerIndexer->index();

        return 0;
    }
}
