<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Index shopware entities data to gally.
 */
class Index extends Command
{
    protected static $defaultName = 'gally:index';

    /** @var AbstractIndexer[]  */
    private iterable $indexers;

    public function __construct(
        iterable $indexers
    ) {
        parent::__construct();
        $this->indexers = $indexers;
    }

    protected function configure(): void
    {
        $this->setDescription('Index category, product and manufacturer entities.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("");
        foreach ($this->indexers as $indexer) {
            $time = microtime(true);
            $message = "<comment>Indexing {$indexer->getEntityType()}</comment>";
            $output->writeln("$message ...");
            $indexer->reindex();
            $time = number_format(microtime(true) - $time, 2);
            $output->writeln("\033[1A$message <info>✔</info> ($time)s");
        }
        $output->writeln("");

        return 0;
    }
}
