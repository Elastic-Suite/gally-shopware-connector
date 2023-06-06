<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Command;

use Gally\ShopwarePlugin\Indexer\AbstractIndexer;
use Gally\ShopwarePlugin\Service\Configuration;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Index shopware entities data to gally.
 */
class Index extends Command
{
    protected static $defaultName = 'gally:index';

    private Configuration $configuration;
    protected EntityRepository $salesChannelRepository;

    /** @var AbstractIndexer[]  */
    private iterable $indexers;

    public function __construct(
        Configuration $configuration,
        EntityRepository $salesChannelRepository,
        iterable $indexers
    ) {
        parent::__construct();
        $this->configuration = $configuration;
        $this->salesChannelRepository = $salesChannelRepository;
        $this->indexers = $indexers;
    }

    protected function configure(): void
    {
        $this->setDescription('Index category, product and manufacturer entities.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $criteria = new Criteria();
        $criteria->addAssociations(['language', 'languages', 'languages.locale', 'currency', 'domains']);

        /** @var SalesChannelCollection $salesChannels */
        $salesChannels = $this->salesChannelRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($this->configuration->isActive($salesChannel->getId())) {
                $output->writeln("\n<comment>Channel {$salesChannel->getName()}</comment>");
                foreach ($this->indexers as $indexer) {
                    $time = microtime(true);
                    $message = "  <comment>Indexing {$indexer->getEntityType()}</comment>";
                    $output->writeln("$message ...");
                    $indexer->reindex($salesChannel);
                    $time = number_format(microtime(true) - $time, 2);
                    $output->writeln("\033[1A$message <info>✔</info> ($time)s");
                }
            }
        }
        $output->writeln("");

        $output->writeln("");

        return 0;
    }
}
