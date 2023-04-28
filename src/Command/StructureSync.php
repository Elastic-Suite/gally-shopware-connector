<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Synchronizer\AbstractSynchronizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronize sales channels and properties with gally.
 */
class StructureSync extends Command
{
    protected static $defaultName = 'gally:structure-sync';

    /** @var AbstractSynchronizer[] */
    private iterable $synchronizers;

    public function __construct(
        iterable $synchronizers
    ) {
        parent::__construct();
        $this->synchronizers = $synchronizers;
    }

    protected function configure(): void
    {
        $this->setDescription('Synchronize sales channels, entity fields with gally data structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("");
        foreach ($this->synchronizers as $synchronizer) {
            $time = microtime(true);
            $message = "<comment>Sync {$synchronizer->getEntityClass()}</comment>";
            $output->writeln("$message ...");
            $synchronizer->synchronizeAll();
            $time = number_format(microtime(true) - $time, 2);
            $output->writeln("\033[1A$message <info>✔</info> ($time)s");
        }
        $output->writeln("");

        return 0;
    }
}
