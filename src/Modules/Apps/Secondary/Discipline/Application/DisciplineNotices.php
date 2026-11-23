<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Discipline\Domain\DisciplineDefaults as D;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Throwable;

/**
 * Notifications aux garants : NOT-03 (seuil d'heures d'absence atteint, à la création d'une proposition) et NOT-06 (convocation
 * au conseil de discipline). Une seule fois par événement et par destinataire (journal se_discipline_notices) ; une panne de
 * messagerie ne bloque jamais l'opération.
 */
final class DisciplineNotices
{
    private const TABLE = 'se_discipline_notices';

    public function __construct(
        private readonly Messenger $messenger,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function absenceThreshold(string $tenantId, string $establishmentId, string $proposalId, string $learnerId, string $className, float $hours, string $periodLabel): int
    {
        return $this->notify($tenantId, $establishmentId, D::NOT03, $proposalId, $learnerId, D::TEMPLATE_NOT03, [
            'class_name' => $className, 'hours' => rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ','), 'period_label' => $periodLabel,
        ]);
    }

    public function councilConvened(string $tenantId, string $establishmentId, string $councilId, string $learnerId, string $date, string $time, string $place): int
    {
        return $this->notify($tenantId, $establishmentId, D::NOT06, $councilId, $learnerId, D::TEMPLATE_NOT06, [
            'date' => $date, 'time' => $time, 'place' => $place, 'reference' => substr($councilId, -8),
        ]);
    }

    /** @param array<string, scalar> $variables */
    private function notify(string $tenantId, string $establishmentId, string $code, string $eventRef, string $learnerId, string $template, array $variables): int
    {
        $child = $this->people->find($tenantId, $learnerId);
        if ($child === null) {
            return 0;
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId);
        $variables += ['child_name' => $child->fullName(), 'establishment_name' => $establishment === null ? '' : $establishment->name];
        $sent = 0;
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $where = ['notice_code' => $code, 'event_ref' => $eventRef, 'recipient_person_id' => $guardian->id];
            if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
                continue;
            }
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $guardian->id, $template, $variables, ['sms']);
            } catch (Throwable $e) {
                $this->logger->warning('Notification ' . $code . ' non envoyée : ' . $e->getMessage(), ['event' => $eventRef]);

                continue;
            }
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
                'id' => (string) new Ulid(), 'template_key' => $template, 'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
            ]);
            $result->status !== DeliveryResult::BLOCKED && ++$sent;
        }

        return $sent;
    }
}
