<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Notifications\Application\Notifier;
use Scholaris\Modules\Apps\Higher\Notifications\Application\ReminderRunner;
use Scholaris\Modules\Apps\Higher\Notifications\Domain\NotificationCatalog;

/**
 * Routes /higher/notifications : catalogue NOT-01 à NOT-11 (modèles à installer dans la messagerie du socle), relances de
 * l'échéancier à la demande (même traitement que la tâche planifiée `higher:reminders`) et journal des envois.
 * Ces opérations portent sur tout l'établissement : permission sur l'établissement (pas de filtre par école).
 */
final class NotificationsController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ReminderRunner $reminders,
        private readonly Notifier $notifier,
        private readonly JsonResponder $responder,
    ) {
    }

    public function catalog(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, 'higher.access');
        $out = [];
        foreach (NotificationCatalog::ALL as $code => $entry) {
            $out[] = [
                'code' => $code, 'template_key' => $entry['key'], 'label' => $entry['label'], 'recipients' => $entry['recipients'],
                'channels' => $entry['channels'], 'wired' => $entry['wired'], 'trigger' => $entry['trigger'],
                'templates' => NotificationCatalog::messengerTemplates($code),
            ];
        }

        return $this->responder->json(['data' => $out]);
    }

    public function runReminders(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'billing.manage');
        $raw = trim((string) $request->getBody());
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body) || array_diff(array_keys($body), ['date']) !== []) {
            throw HttpException::validation(['body' => ['Seul le champ date (AAAA-MM-JJ) est accepté.']]);
        }
        $date = $body['date'] ?? null;
        if ($date !== null && (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $this->responder->json(['data' => $this->reminders->runFor($principal->tenantId, self::establishment($request), $date)]);
    }

    public function journal(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, 'higher.billing.read', 'billing.manage', 'messaging.read');
        $person = $request->getQueryParams()['person_id'] ?? null;
        if ($person !== null && (!is_string($person) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $person) !== 1)) {
            throw HttpException::validation(['person_id' => ['Identifiant attendu.']]);
        }

        return $this->responder->json(['data' => $this->notifier->journal($principal->tenantId, self::establishment($request), $person)]);
    }

    private function authorize(ServerRequestInterface $request, string ...$anyOf): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        foreach ($anyOf as $permission) {
            if ($this->permissions->can($principal, $permission, self::establishment($request))) {
                return $principal;
            }
        }

        throw HttpException::forbidden('Permission requise : ' . implode(' ou ', $anyOf) . '.');
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }
}
