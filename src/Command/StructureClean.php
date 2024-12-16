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

use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove all entity from gally that not exist anymore on shopware side.
 */
class StructureClean extends StructureSync
{
    protected function configure(): void
    {
        $this->setName('gally:structure:clean')
            ->setDescription('Remove all entity from gally that not exist anymore on shopware side.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Really remove the listed entity from the gally.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $isDryRun = !$input->getOption('force');

        if ($isDryRun) {
            $output->writeln("<error>Running in dry run mode, add -f to really delete entities from Gally.</error>");
            $output->writeln('');
        }

        foreach ($this->syncMethod as $entity => $method) {
            $message = "<comment>Sync $entity</comment>";
            $time = microtime(true);
            $output->writeln("$message ...");
            $this->synchonizer->{$method}(
                $this->providers[$entity]->provide(Context::createDefaultContext()),
                true,
                $isDryRun
            );
            $time = number_format(microtime(true) - $time, 2);
            $output->writeln("\033[1A$message <info>✔</info> ($time)s");
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
