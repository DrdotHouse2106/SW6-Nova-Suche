<?php declare(strict_types=1);

namespace Nova\Search\Command;

use Nova\Search\Service\AlsoBoughtBuilder;
use Nova\Search\Service\SimilarBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nova-search:also-bought', description: 'Berechnet „Kunden kauften auch“ und „Ähnliche Artikel“ neu')]
class AlsoBoughtCommand extends Command
{
    public function __construct(
        private readonly AlsoBoughtBuilder $builder,
        private readonly SimilarBuilder $similar,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->builder->rebuild();
        $output->writeln($result['enabled']
            ? \sprintf('„Kunden kauften auch“: %d Artikel aktualisiert, %d entfernt.', $result['products'], $result['removed'])
            : \sprintf('„Kunden kauften auch“ ist ausgeschaltet – %d vorhandene entfernt.', $result['removed']));

        $similar = $this->similar->rebuild();
        $output->writeln($similar['enabled']
            ? \sprintf('„Ähnliche Artikel“: %d Artikel aktualisiert, %d entfernt.', $similar['products'], $similar['removed'])
            : \sprintf('„Ähnliche Artikel“ ist ausgeschaltet – %d vorhandene entfernt.', $similar['removed']));

        return self::SUCCESS;
    }
}
