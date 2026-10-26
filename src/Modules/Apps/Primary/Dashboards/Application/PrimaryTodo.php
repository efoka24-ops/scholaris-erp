<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Reporting\Contracts\TodoProvider;

/**
 * Source « à traiter » du primaire (`reporting.todo.primary`, T551) pour chaque établissement primaire du client où la personne a le
 * droit d'agir : conseils à valider (présidence), bulletins à émettre ou à publier (signature), corrections de compétences à
 * approuver, alertes d'absences ouvertes (tableau de bord). Des nombres seulement, jamais de nom d'élève.
 */
final class PrimaryTodo implements TodoProvider
{
    public function __construct(private readonly Store $store, private readonly Access $access)
    {
    }

    public function forUser(string $tenantId, string $userId): array
    {
        $principal = new Principal($userId, $tenantId);
        $statement = $this->store->pdo()->prepare('SELECT DISTINCT establishment_id FROM pri_level_profiles WHERE tenant_id = ? ORDER BY establishment_id');
        $statement->execute([$tenantId]);
        $items = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $establishmentId) {
            $e = (string) $establishmentId;
            $preside = $this->access->perimeter($principal, $e, ['primary.council.preside']);
            if (!$preside->isEmpty()) {
                $open = count(array_filter($this->store->select('pri_councils', $tenantId, $e, ['status' => 'open'], 'id', 1000), static fn (array $r): bool => $preside->coversClass((string) $r['class_structure_id'])));
                if ($open > 0) {
                    $items[] = ['kind' => 'primary.council', 'title' => sprintf('%d conseil(s) des maîtres à valider', $open), 'due_at' => null, 'link' => '/primaire/conseils'];
                }
            }
            if (!$this->access->perimeter($principal, $e, ['primary.report_card.sign'])->isEmpty()) {
                $pending = 0;
                foreach ($this->store->select('pri_councils', $tenantId, $e, ['status' => 'validated'], 'id', 1000) as $council) {
                    $cards = $this->store->count('pri_report_cards', $tenantId, $e, ['council_id' => (string) $council['id'], 'status' => 'published']);
                    $pending += $cards === 0 ? 1 : 0;
                }
                if ($pending > 0) {
                    $items[] = ['kind' => 'primary.report_cards', 'title' => sprintf('%d conseil(s) dont les bulletins sont à émettre ou à publier', $pending), 'due_at' => null, 'link' => '/primaire/bulletins'];
                }
            }
            if (!$this->access->perimeter($principal, $e, ['assessment.correction.approve'])->isEmpty()) {
                $corrections = $this->store->count('pri_competency_corrections', $tenantId, $e, ['status' => 'pending']);
                if ($corrections > 0) {
                    $items[] = ['kind' => 'primary.corrections', 'title' => sprintf('%d correction(s) de compétences à approuver', $corrections), 'due_at' => null, 'link' => '/primaire/competences'];
                }
            }
            if (!$this->access->perimeter($principal, $e, AbsenceAlerts::READ)->isEmpty()) {
                $alerts = $this->store->count(AbsenceAlerts::TABLE, $tenantId, $e, ['status' => 'open']);
                if ($alerts > 0) {
                    $items[] = ['kind' => 'primary.alerts', 'title' => sprintf('%d alerte(s) d\'absences répétées', $alerts), 'due_at' => null, 'link' => '/primaire/tableau-de-bord'];
                }
            }
        }

        return $items;
    }
}
