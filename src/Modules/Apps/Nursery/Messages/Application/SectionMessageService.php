<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Messages\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RestrictionService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Messages aux parents d'une ou plusieurs sections (EX-MAT-25) par la messagerie du socle (consentement et plages horaires respectés ;
 * ce n'est PAS un message de sécurité). Une personne visée par une restriction de garde active est exclue des messages de l'école
 * (MQ-19, défaut). Une éducatrice écrit aux parents de SA section ; la direction à toutes les sections. L'historique est conservé.
 */
final class SectionMessageService
{
    public const TABLE = 'nu_messages';
    public const TEMPLATE = 'nursery.msg.section_notice';

    public function __construct(
        private readonly Store $store,
        private readonly SectionService $sections,
        private readonly PersonDirectory $people,
        private readonly Messenger $messenger,
        private readonly RestrictionService $restrictions,
        private readonly EstablishmentDirectory $establishments,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data subject, body, section_ids?: list<string> (toutes les sections du périmètre si absent)
     * @return array<string, mixed>
     */
    public function send(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $targets = isset($data['section_ids']) ? array_values(array_map('strval', (array) $data['section_ids'])) : array_map(static fn (array $s): string => (string) $s['id'], $this->sections->inPerimeter($perimeter));
        if ($targets === []) {
            throw HttpException::validation(['section_ids' => ['Aucune section destinataire dans votre périmètre.']]);
        }
        foreach ($targets as $sectionId) {
            if (!$this->sections->exists($tenantId, $establishmentId, $sectionId)) {
                throw HttpException::validation(['section_ids' => ['Section introuvable dans cet établissement.']]);
            }
            $this->access->assertWritable($principal, $perimeter, $sectionId, 'section', $sectionId);
        }
        $school = $this->establishments->find($tenantId, $establishmentId)->name ?? '';
        $day = $this->clock->today();
        $recipients = [];
        $skipped = [];
        foreach ($targets as $sectionId) {
            foreach ($this->sections->childIds($tenantId, $sectionId) as $childId) {
                $excluded = $this->excludedPersons($tenantId, $establishmentId, $childId, $day);
                foreach ($this->people->guarantorsOf($tenantId, $childId) as $guardian) {
                    if (in_array($guardian->id, $excluded, true)) {
                        $skipped[$guardian->id] = true;
                    } else {
                        $recipients[$guardian->id] = true;
                    }
                }
            }
        }
        $delivered = 0;
        $blocked = 0;
        foreach (array_keys($recipients) as $personId) {
            $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE, [
                'establishment_name' => $school, 'subject' => (string) $data['subject'], 'details' => (string) $data['body'],
            ]);
            $result->status === DeliveryResult::BLOCKED ? ++$blocked : ++$delivered;
        }
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'subject' => (string) $data['subject'], 'body' => (string) $data['body'], 'section_ids' => json_encode($targets, JSON_THROW_ON_ERROR),
            'sent_by' => $principal->userId, 'recipients' => count($recipients), 'skipped' => count($skipped), 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'nursery.message.sent', 'message', $id, null, ['sections' => count($targets), 'recipients' => count($recipients), 'blocked' => $blocked]);

        return ['id' => $id, 'recipients' => count($recipients), 'delivered' => $delivered, 'blocked' => $blocked, 'skipped_by_restriction' => count($skipped)];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Perimeter $perimeter, Paging $paging): array
    {
        $rows = [];
        foreach ($this->store->select(self::TABLE, $perimeter->tenantId, $perimeter->establishmentId, [], 'created_at DESC, id', 500) as $row) {
            /** @var list<string> $sections */
            $sections = json_decode((string) $row['section_ids'], true, 8, JSON_THROW_ON_ERROR);
            if (!$perimeter->wholeEstablishment && array_diff($sections, $perimeter->sectionIds) !== []) {
                continue; // un message qui touche une section hors périmètre n'est pas listé
            }
            $rows[] = [
                'id' => (string) $row['id'], 'subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'section_ids' => $sections,
                'recipients' => (int) $row['recipients'], 'skipped' => (int) $row['skipped'], 'created_at' => (string) $row['created_at'],
            ];
        }

        return $paging->slice($rows);
    }

    /** @return list<string> personnes visées par une restriction de garde active : exclues des messages de l'école */
    private function excludedPersons(string $tenantId, string $establishmentId, string $childId, string $day): array
    {
        $ids = [];
        foreach ($this->restrictions->activeFor($tenantId, $establishmentId, $childId, $day) as $restriction) {
            if ($restriction['restricted_person_id'] !== null) {
                $ids[] = (string) $restriction['restricted_person_id'];
            }
        }

        return $ids;
    }
}
