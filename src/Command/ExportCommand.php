<?php declare(strict_types=1);

namespace Nova\Search\Command;

use Nova\Search\Service\SearchExport;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exportiert die gesammelten Suchdaten als CSV – z. B. zur Analyse in Excel oder durch Claude.
 *
 * Beispiele:
 *   bin/console nova-search:export
 *   bin/console nova-search:export --days=90 --type=terms --output=/tmp/export
 *   bin/console nova-search:export --sales-channel=<id> --min-searches=3
 */
#[AsCommand(name: 'nova-search:export', description: 'Suchdaten (Begriffe, Produkte, Claude-Umschreibungen) als CSV exportieren')]
class ExportCommand extends Command
{
    private const FILE_NAMES = [
        SearchExport::TYPE_TERMS => 'suchbegriffe.csv',
        SearchExport::TYPE_PRODUCTS => 'suchbegriffe-produkte.csv',
        SearchExport::TYPE_REWRITES => 'claude-umschreibungen.csv',
        SearchExport::TYPE_CLAUDE_USAGE => 'claude-kosten.csv',
        SearchExport::TYPE_FEEDBACK => 'rueckmeldungen.csv',
    ];

    public function __construct(private readonly SearchExport $export)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'terms, products, rewrites, claude-usage, feedback (mehrfach möglich, Standard: alle)')
            ->addOption('sales-channel', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Verkaufskanal-ID(s)')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Nur Einträge der letzten N Tage')
            ->addOption('min-searches', null, InputOption::VALUE_REQUIRED, 'Suchbegriffe erst ab N Suchen', '0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Zielordner', 'nova-search-export')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'Trennzeichen (Standard „;“ für deutsches Excel)', ';');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $types */
        $types = $input->getOption('type') ?: SearchExport::TYPES;
        $unknown = array_diff($types, SearchExport::TYPES);
        if ($unknown !== []) {
            $output->writeln(\sprintf('<error>Unbekannter Typ: %s (erlaubt: %s)</error>', implode(', ', $unknown), implode(', ', SearchExport::TYPES)));

            return Command::INVALID;
        }

        /** @var list<string> $salesChannelIds */
        $salesChannelIds = array_map('strtolower', (array) $input->getOption('sales-channel'));
        foreach ($salesChannelIds as $id) {
            if (!Uuid::isValid($id)) {
                $output->writeln(\sprintf('<error>Ungültige Verkaufskanal-ID: %s</error>', $id));

                return Command::INVALID;
            }
        }

        $days = $input->getOption('days') !== null ? max(1, (int) $input->getOption('days')) : null;
        $minSearches = max(0, (int) $input->getOption('min-searches'));
        $delimiter = (string) $input->getOption('delimiter');
        if (mb_strlen($delimiter) !== 1) {
            $output->writeln('<error>Das Trennzeichen muss genau ein Zeichen sein.</error>');

            return Command::INVALID;
        }

        $directory = rtrim((string) $input->getOption('output'), '/');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $output->writeln(\sprintf('<error>Ordner „%s“ konnte nicht angelegt werden.</error>', $directory));

            return Command::FAILURE;
        }

        foreach (array_unique($types) as $type) {
            $data = $this->export->fetch($type, $salesChannelIds, $days, $minSearches);
            $file = $directory . '/' . self::FILE_NAMES[$type];
            $this->writeCsv($file, $data['columns'], $data['rows'], $delimiter);
            $output->writeln(\sprintf('%-28s %6d Zeilen → %s', self::FILE_NAMES[$type], \count($data['rows']), $file));
        }

        $output->writeln('Hinweis: Die Dateien enthalten nur Suchbegriffe und Zähler, keine Kundendaten.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function writeCsv(string $file, array $columns, array $rows, string $delimiter): void
    {
        if (@file_put_contents($file, SearchExport::toCsv($columns, $rows, $delimiter)) === false) {
            throw new \RuntimeException(\sprintf('Datei „%s“ kann nicht geschrieben werden.', $file));
        }
    }
}
