<?php

/*
 * This file is part of the zenstruck/class-metadata package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Survos\Installer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class ListMetadataCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('survos:installer')
            ->setDescription('commands for the Survos installer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\file_exists($autoloadFile = $this->requireComposer()->getConfig()->get('vendor-dir').'/autoload.php')) {
            throw new \RuntimeException(\sprintf('Please run "composer install" before running this command: "%s" not found.', $autoloadFile));
        }

        require $autoloadFile;


        // mimic SymfonyStyle - causes autoload conflicts if used directly
        $style = clone Table::getStyleDefinition('symfony-style-guide');
        $style->setCellHeaderFormat('<info>%s</info>');

        $table = new Table($output);
        $table->setStyle($style);
        $table->setHeaders(['Class', 'Alias', 'Metadata']);

        $table->render();

        return 0;
    }
}
