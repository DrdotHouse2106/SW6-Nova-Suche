<?php declare(strict_types=1);

namespace Nova\Search\ScheduledTask;

use Nova\Search\Rewrite\RewriteService;
use Nova\Search\Service\NumberIndex;
use Nova\Search\Service\RewriteStore;
use Nova\Search\Service\SearchStatistics;
use Nova\Search\Service\SignalVerifier;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: SearchLearningTask::class)]
final class SearchLearningTaskHandler extends ScheduledTaskHandler
{
    private ?SignalVerifier $verifier = null;

    private ?RewriteStore $rewrites = null;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly RewriteService $rewriteService,
        private readonly SearchStatistics $statistics,
        private readonly NumberIndex $numberIndex,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function setVerifier(SignalVerifier $verifier): void
    {
        $this->verifier = $verifier;
    }

    public function setRewriteStore(RewriteStore $rewrites): void
    {
        $this->rewrites = $rewrites;
    }

    public function run(): void
    {
        // Nummern-Index: beim ersten Lauf und nach geänderten Einstellungen komplett neu aufbauen
        if ($this->numberIndex->needsRebuild()) {
            $this->numberIndex->rebuild();
        }

        $this->rewriteService->run();
        $this->verifier?->run();
        $this->statistics->cleanup();
        $this->rewrites?->cleanup();
    }
}
