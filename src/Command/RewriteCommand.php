<?php declare(strict_types=1);

namespace Nova\Search\Command;

use Nova\Search\Rewrite\RewriteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Führt die Auswertung sofort aus, statt auf den stündlichen Scheduled Task zu warten.
 */
#[AsCommand(name: 'nova-search:rewrite', description: 'Suchbegriffe ohne Treffer mit Claude auswerten')]
class RewriteCommand extends Command
{
    public function __construct(private readonly RewriteService $rewriteService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max. Begriffe pro Verkaufskanal', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->rewriteService->run(max(1, (int) $input->getOption('limit')));

        $output->writeln(\sprintf(
            'Ausgewertet: %d · Umschreibungen gespeichert: %d · Nachgeprüft: %d · Korrekturen gelernt: %d',
            $stats['processed'],
            $stats['saved'],
            $stats['rechecked'],
            $stats['learned']
        ));

        return Command::SUCCESS;
    }
}
