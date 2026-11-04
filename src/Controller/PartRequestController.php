<?php declare(strict_types=1);

namespace Nova\Search\Controller;

use Nova\Search\Service\TrafficLimiter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * „Teil anfragen“ direkt aus dem Suchfenster: E-Mail-Adresse oder Telefonnummer genügt, die Anfrage
 * geht per Mail an den Shop. Gespeichert wird nichts; gegen Spam helfen ein verstecktes Feld und
 * ein Limit pro IP-Adresse (IPv6: /64-Netz) und Stunde; Anfragen von fremden Websites werden abgelehnt.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class PartRequestController extends StorefrontController
{
    private const MAX_PER_HOUR = 5;

    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly SystemConfigService $config,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/nova-search/request',
        name: 'frontend.nova-search.request',
        defaults: ['XmlHttpRequest' => true],
        methods: [Request::METHOD_POST]
    )]
    public function send(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();
        if ($this->config->get('NovaSearch.config.requestPartForm', $salesChannelId) === false) {
            return $this->error('novaSearch.requestError', 404);
        }

        // Nur aus dem eigenen Shop (Browser senden Sec-Fetch-Site; fehlt der Header, greifen die übrigen Prüfungen)
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && !\in_array($site, ['same-origin', 'none'], true)) {
            return $this->error('novaSearch.requestError', 403);
        }

        $data = self::validate($request->request->all(), $this->config->getBool('core.loginRegistration.requireDataProtectionCheckbox', $salesChannelId));
        if ($data === 'spam') {
            // Verstecktes Feld ausgefüllt: so tun, als wäre alles gut
            return new JsonResponse(['success' => true, 'message' => $this->trans('novaSearch.requestSent')]);
        }
        if (\is_string($data)) {
            return $this->error($data, 400);
        }

        if (!$this->withinLimit($request)) {
            return $this->error('novaSearch.requestTooMany', 429);
        }

        $recipient = trim($this->config->getString('NovaSearch.config.requestPartEmail', $salesChannelId))
            ?: trim($this->config->getString('core.basicInformation.email', $salesChannelId));
        if (filter_var($recipient, \FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('Nova Suche: Teileanfrage ohne Empfänger – E-Mail-Adresse in der Konfiguration fehlt');

            return $this->error('novaSearch.requestError', 500);
        }

        // Kundeneingaben nur als Template-Daten übergeben – der MailService rendert Betreff und Inhalt mit Twig
        $templateData = ['novaRequest' => $data + ['shop' => (string) $context->getSalesChannel()->getTranslation('name')]];
        $fields = [
            'term' => 'novaSearch.requestMailTerm',
            'name' => 'novaSearch.requestName',
            'email' => 'novaSearch.requestEmail',
            'phone' => 'novaSearch.requestPhone',
            'message' => 'novaSearch.requestMessage',
            'shop' => 'novaSearch.requestMailShop',
        ];

        $html = '<p>' . self::label($this->trans('novaSearch.requestMailIntro')) . '</p><table cellpadding="4">';
        $plain = self::plainLabel($this->trans('novaSearch.requestMailIntro')) . "\n\n";
        foreach ($fields as $key => $snippet) {
            if ($templateData['novaRequest'][$key] === '') {
                continue;
            }
            $label = self::label($this->trans($snippet));
            $html .= '<tr><td valign="top"><strong>' . $label . '</strong></td><td>{{ novaRequest.' . $key . '|e|nl2br }}</td></tr>';
            $plain .= self::plainLabel($this->trans($snippet)) . ': {{ novaRequest.' . $key . ' }}' . "\n";
        }
        $html .= '</table>';

        $mail = [
            'recipients' => [$recipient => null],
            'senderName' => '{{ novaRequest.shop }}',
            'salesChannelId' => $salesChannelId,
            'subject' => $this->trans('novaSearch.requestMailSubject', ['%term%' => '{{ novaRequest.term }}']),
            'contentHtml' => $html,
            'contentPlain' => $plain,
        ];
        if ($data['email'] !== '') {
            $mail['replyTo'] = $data['email'];
        }

        try {
            $this->mailService->send($mail, $context->getContext(), $templateData);
        } catch (\Throwable $e) {
            $this->logger->error('Nova Suche: Teileanfrage konnte nicht gesendet werden', ['exception' => $e]);

            return $this->error('novaSearch.requestError', 500);
        }

        return new JsonResponse(['success' => true, 'message' => $this->trans('novaSearch.requestSent')]);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array{term: string, name: string, email: string, phone: string, message: string}|string Daten, Snippet-Schlüssel des Fehlers oder „spam“
     */
    public static function validate(array $input, bool $privacyRequired): array|string
    {
        $text = static fn (string $key, int $max): string => \is_string($input[$key] ?? null)
            ? mb_substr(trim(str_replace("\0", '', $input[$key])), 0, $max)
            : '';

        // Verstecktes Feld (bis 1.14.1 „website“ – das füllte das Autofill mancher Browser aus)
        if ($text('nova_hp_field', 200) !== '') {
            return 'spam';
        }

        $data = [
            'term' => $text('term', 100),
            'name' => $text('name', 100),
            'email' => $text('email', 200),
            'phone' => $text('phone', 40),
            'message' => $text('message', 1000),
        ];

        if ($data['email'] === '' && $data['phone'] === '') {
            return 'novaSearch.requestContactMissing';
        }
        if ($data['email'] !== '' && filter_var($data['email'], \FILTER_VALIDATE_EMAIL) === false) {
            return 'novaSearch.requestEmailInvalid';
        }
        if ($data['phone'] !== '' && preg_match('/^\+?[0-9 ()\/.-]{6,30}$/', $data['phone']) !== 1) {
            return 'novaSearch.requestPhoneInvalid';
        }
        if ($data['term'] === '' && $data['message'] === '') {
            return 'novaSearch.requestMessageMissing';
        }
        if ($privacyRequired && empty($input['acceptedDataProtection'])) {
            return 'novaSearch.requestPrivacyMissing';
        }

        // Zeilenumbrüche nur in der Nachricht
        foreach (['term', 'name', 'email', 'phone'] as $key) {
            $data[$key] = (string) preg_replace('/[\r\n\t]+/', ' ', $data[$key]);
        }

        return $data;
    }

    /**
     * Beschriftung aus Textbausteinen: HTML und Twig-Klammern entschärfen.
     */
    private static function label(string $text): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($text));
    }

    private static function plainLabel(string $text): string
    {
        return str_replace(['{', '}'], '', $text);
    }

    private function withinLimit(Request $request): bool
    {
        $key = 'nova_search_request_' . sha1(TrafficLimiter::clientKey($request->getClientIp()) . '|' . date('YmdH'));
        $item = $this->cache->getItem($key);
        $count = $item->isHit() && \is_int($item->get()) ? $item->get() : 0;
        if ($count >= self::MAX_PER_HOUR) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return true;
    }

    private function error(string $snippet, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $this->trans($snippet)], $status);
    }
}
