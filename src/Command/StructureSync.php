<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Service\Configuration;
use Gally\ShopwarePlugin\Synchronizer\AbstractSynchronizer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronize sales channels and properties with gally.
 */
class StructureSync extends Command
{
    protected static $defaultName = 'gally:structure-sync';

    private Configuration $configuration;
    private EntityRepository $salesChannelRepository;

    /** @var AbstractSynchronizer[] */
    private iterable $synchronizers;

    public function __construct(
        Configuration $configuration,
        EntityRepository $salesChannelRepository,
        iterable $synchronizers
    ) {
        parent::__construct();
        $this->configuration = $configuration;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->synchronizers = $synchronizers;
    }

    protected function configure(): void
    {
        $this->setDescription('Synchronize sales channels, entity fields with gally data structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository->search($criteria, Context::createDefaultContext())->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configuration->isActive($salesChannel->getId())) {
                $output->writeln("\n<comment>Channel {$salesChannel->getName()}</comment>");
                foreach ($this->synchronizers as $synchronizer) {
                    $time = microtime(true);
                    $message = "  <comment>Sync {$synchronizer->getEntityClass()}</comment>";
                    $output->writeln("$message ...");
                    $synchronizer->synchronizeAll($salesChannel);
                    $time = number_format(microtime(true) - $time, 2);
                    $output->writeln("\033[1A$message <info>✔</info> ($time)s");
                }
            }
        }

        $output->writeln("");

        return 0;
    }
}
