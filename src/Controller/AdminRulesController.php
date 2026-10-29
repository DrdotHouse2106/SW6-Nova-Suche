<?php declare(strict_types=1);

namespace Nova\Search\Controller;

use Nova\Search\Rules\RuleStore;
use Nova\Search\Service\SearchExport;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API für Synonyme und Weiterleitungen (Erweiterungen → Nova Suche).
 * Lesen braucht system_config:read, Ändern system_config:update (wie die Plugin-Einstellungen).
 * {type} ist „synonym“, „redirect“ oder „hint“ (Hinweise haben keinen CSV-Import/-Export).
 */
#[Route(defaults: ['_routeScope' => ['api']], requirements: ['type' => 'synonym|redirect|hint'])]
class AdminRulesController
{
    public function __construct(
        private readonly RuleStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/_action/nova-search/rules/{type}', name: 'api.action.nova_search.rules.list', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function list(string $type, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, function () use ($type, $request): array {
            $search = $request->query->getString('search');

            return ['items' => match ($type) {
                'redirect' => $this->store->listRedirects($search),
                'hint' => $this->store->listHints($search),
                default => $this->store->listSynonyms($search),
            }];
        });
    }

    #[Route(path: '/api/_action/nova-search/rules/{type}', name: 'api.action.nova_search.rules.save', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function save(string $type, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, function () use ($type, $request): array {
            $body = $request->request->all();
            $id = \is_string($body['id'] ?? null) ? $body['id'] : null;
            $salesChannelId = \is_string($body['salesChannelId'] ?? null) ? $body['salesChannelId'] : null;
            $active = !\array_key_exists('active', $body) || (bool) $body['active'];

            if ($type === 'hint') {
                $words = \is_array($body['words'] ?? null) ? array_map('strval', array_filter($body['words'], 'is_scalar')) : [];
                $url = \is_string($body['url'] ?? null) ? $body['url'] : null;
                $id = $this->store->saveHint($id, $salesChannelId, array_values($words), (string) ($body['text'] ?? ''), $url, $active);
            } elseif ($type === 'redirect') {
                $id = $this->store->saveRedirect($id, $salesChannelId, (string) ($body['term'] ?? ''), (string) ($body['target'] ?? ''), $active);
            } else {
                $terms = \is_array($body['terms'] ?? null) ? array_map('strval', array_filter($body['terms'], 'is_scalar')) : [];
                $id = $this->store->saveSynonym($id, $salesChannelId, array_values($terms), $active);
            }

            return ['id' => $id];
        });
    }

    #[Route(path: '/api/_action/nova-search/rules/{type}/{id}', name: 'api.action.nova_search.rules.delete', defaults: ['_acl' => ['system_config:update']], requirements: ['id' => '[0-9a-f]{32}'], methods: ['DELETE'])]
    public function delete(string $type, string $id, Context $context): JsonResponse
    {
        return $this->handle($context, function () use ($type, $id): array {
            $this->store->delete($type, $id);

            return [];
        });
    }

    #[Route(path: '/api/_action/nova-search/rules/{type}/import', name: 'api.action.nova_search.rules.import', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function import(string $type, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, function () use ($type, $request): array {
            if ($type === 'hint') {
                throw new \InvalidArgumentException('Für Hinweise gibt es keinen CSV-Import.');
            }
            $body = $request->request->all();
            $text = \is_string($body['text'] ?? null) ? $body['text'] : '';
            if (\strlen($text) > 2 * 1024 * 1024) {
                throw new \InvalidArgumentException('Die Datei ist zu groß (max. 2 MB).');
            }
            $salesChannelId = \is_string($body['salesChannelId'] ?? null) && $body['salesChannelId'] !== '' ? $body['salesChannelId'] : null;

            return $this->store->import($type, $text, $salesChannelId);
        });
    }

    #[Route(path: '/api/_action/nova-search/rules/{type}/export', name: 'api.action.nova_search.rules.export', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function export(string $type, Context $context): Response
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        if ($type === 'hint') {
            return new JsonResponse(['message' => 'Für Hinweise gibt es keinen CSV-Export.'], 400);
        }

        if ($type === 'redirect') {
            $rows = array_map(static fn (array $r): array => ['begriff' => $r['term'], 'ziel' => $r['target'], 'aktiv' => $r['active'] ? 1 : 0, 'verkaufskanal_id' => $r['salesChannelId'] ?? ''], $this->store->listRedirects());
            $columns = ['begriff', 'ziel', 'aktiv', 'verkaufskanal_id'];
        } else {
            $rows = array_map(static fn (array $r): array => ['begriffe' => implode(', ', $r['terms']), 'aktiv' => $r['active'] ? 1 : 0, 'verkaufskanal_id' => $r['salesChannelId'] ?? ''], $this->store->listSynonyms());
            $columns = ['begriffe', 'aktiv', 'verkaufskanal_id'];
        }

        $response = new Response(SearchExport::toCsv($columns, $rows));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="nova-suche-%s-%s.csv"', $type === 'redirect' ? 'weiterleitungen' : 'synonyme', date('Y-m-d')));

        return $response;
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function handle(Context $context, callable $action): JsonResponse
    {
        if (!$this->isAdminUser($context)) {
            return new JsonResponse(['message' => 'Nur für Benutzer der Administration.'], 403);
        }

        try {
            return new JsonResponse($action());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Nova Suche: Regeln konnten nicht gespeichert werden', ['exception' => $e]);

            return new JsonResponse(['message' => 'Interner Fehler. Details stehen im Shopware-Log.'], 500);
        }
    }

    private function isAdminUser(Context $context): bool
    {
        $source = $context->getSource();

        return $source instanceof AdminApiSource && $source->getUserId() !== null;
    }
}
