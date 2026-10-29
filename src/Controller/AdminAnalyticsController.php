<?php declare(strict_types=1);

namespace Nova\Search\Controller;

use Nova\Search\Rewrite\ClaudeBudget;
use Nova\Search\Service\SearchAnalytics;
use Nova\Search\Service\RewriteStore;
use Nova\Search\Service\SearchExport;
use Nova\Search\Service\StatsImport;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für den Bereich „Erweiterungen → Nova Suche“: Auswertung, CSV-Export und gespeicherte Umschreibungen.
 * Nur für angemeldete Benutzer der Administration (keine Integrationen) mit Berechtigung für die
 * Einstellungen (Lesen: system_config:read, Ändern: system_config:update).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminAnalyticsController
{
    private ?RewriteStore $rewrites = null;

    public function __construct(
        private readonly SearchAnalytics $analytics,
        private readonly SearchExport $export,
        private readonly ClaudeBudget $budget,
        private readonly StatsImport $import,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function setRewriteStore(RewriteStore $rewrites): void
    {
        $this->rewrites = $rewrites;
    }

    #[Route(path: '/api/_action/nova-search/analytics', name: 'api.action.nova_search.analytics', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function analytics(Request $request, Context $context): JsonResponse
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        try {
            $data = $this->analytics->overview($this->salesChannelId($request), $request->query->getInt('days', 30));
            $data['claude'] = [
                'month' => $this->budget->month(),
                'spentUsd' => round($this->budget->spentMicro() / 1_000_000, 4),
                'limitUsd' => round($this->budget->limitMicro() / 1_000_000, 2),
            ];
            $data['rewrites'] = $this->rewrites?->listRewrites($this->salesChannelId($request)) ?? [];

            return new JsonResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('Nova Suche: Auswertung fehlgeschlagen', ['exception' => $e]);

            return new JsonResponse(['message' => 'Auswertung fehlgeschlagen. Details stehen im Shopware-Log.'], 500);
        }
    }

    #[Route(path: '/api/_action/nova-search/export', name: 'api.action.nova_search.export', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function export(Request $request, Context $context): Response
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        $type = $request->query->getString('type');
        if (!\in_array($type, SearchExport::TYPES, true)) {
            return new JsonResponse(['message' => 'Unbekannter Export-Typ.'], 400);
        }

        $days = $request->query->getInt('days');
        $salesChannelId = $this->salesChannelId($request);
        $data = $this->export->fetch($type, $salesChannelId !== null ? [$salesChannelId] : [], $days > 0 ? $days : null);

        $response = new Response(SearchExport::toCsv($data['columns'], $data['rows']));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="nova-suche-%s-%s.csv"', $type, date('Y-m-d')));

        return $response;
    }

    /**
     * Statistik-Dateien eines bisherigen Suchdienstes übernehmen.
     * Body: { salesChannelId, files: [{ name, text }] }
     */
    #[Route(path: '/api/_action/nova-search/import-stats', name: 'api.action.nova_search.import_stats', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function importStats(Request $request, Context $context): JsonResponse
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        $salesChannelId = $request->request->getString('salesChannelId');
        $files = $request->request->all()['files'] ?? null;
        if (!\is_array($files) || $files === [] || \count($files) > 20) {
            return new JsonResponse(['message' => 'Bitte 1 bis 20 CSV-Dateien wählen.'], 400);
        }

        $results = [];
        try {
            foreach ($files as $file) {
                $name = \is_array($file) && \is_string($file['name'] ?? null) ? $file['name'] : '';
                $text = \is_array($file) && \is_string($file['text'] ?? null) ? $file['text'] : '';
                if (\strlen($text) > 10 * 1024 * 1024) {
                    throw new \InvalidArgumentException(\sprintf('„%s“ ist zu groß (max. 10 MB).', $name));
                }
                $results[] = ['name' => $name] + $this->import->import($text, $salesChannelId, $name);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'results' => $results], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Nova Suche: Import fehlgeschlagen', ['exception' => $e]);

            return new JsonResponse(['message' => 'Import fehlgeschlagen. Details stehen im Shopware-Log.', 'results' => $results], 500);
        }

        return new JsonResponse(['results' => $results]);
    }

    /** Gespeicherte Umschreibung abschalten (wird danach weder angewendet noch neu gelernt) */
    #[Route(path: '/api/_action/nova-search/rewrite/disable', name: 'api.action.nova_search.rewrite.disable', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function disableRewrite(Request $request, Context $context): JsonResponse
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        $salesChannelId = $request->request->getString('salesChannelId');
        $term = $request->request->getString('term');
        if (preg_match('/^[0-9a-f]{32}$/', $salesChannelId) !== 1 || $term === '' || $this->rewrites === null) {
            return new JsonResponse(['message' => 'Ungültige Angaben.'], 400);
        }

        try {
            $this->rewrites->disable($salesChannelId, $term);
        } catch (\Throwable $e) {
            $this->logger->error('Nova Suche: Umschreibung konnte nicht deaktiviert werden', ['exception' => $e]);

            return new JsonResponse(['message' => 'Interner Fehler. Details stehen im Shopware-Log.'], 500);
        }

        return new JsonResponse([]);
    }

    private function salesChannelId(Request $request): ?string
    {
        $id = $request->query->getString('salesChannelId');

        return preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $id : null;
    }

    private function isAdminUser(Context $context): bool
    {
        $source = $context->getSource();

        return $source instanceof AdminApiSource && $source->getUserId() !== null;
    }
}
