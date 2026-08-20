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

namespace Gally\ShopwarePlugin\Command;

use Gally\Sdk\Service\StructureSynchonizer;
use Gally\ShopwarePlugin\Indexer\Provider\ProviderInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronize sales channels and properties with gally.
 */
class StructureSync extends Command
{
    /** @var ProviderInterface[] */
    protected array $providers;
    protected array $syncMethod = [
        'catalog' => 'syncAllLocalizedCatalogs',
        'sourceField' => 'syncAllSourceFields',
        'sourceFieldOption' => 'syncAllSourceFieldOptions',
    ];

    public function __construct(
        protected StructureSynchonizer $synchonizer,
        \IteratorAggregate $providers,
    ) {
        parent::__construct();
        $this->providers = iterator_to_array($providers);
    }

    protected function configure(): void
    {
        $this->setName('gally:structure:sync')
            ->setDescription('Synchronize sales channels, entity fields with gally data structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');

        foreach ($this->syncMethod as $entity => $method) {
            $message = "<comment>Sync $entity</comment>";
            $time = microtime(true);
            $output->writeln("$message ...");
            $this->synchonizer->{$method}($this->providers[$entity]->provide(Context::createDefaultContext()));
            $time = number_format(microtime(true) - $time, 2);
            $output->writeln("\033[1A$message <info>✔</info> ($time)s");
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
