<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Nursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RegisterService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RestrictionService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/**
 * Prépare les variables des documents de la Maternelle privée à partir des données de l'application (jamais d'une saisie libre) :
 * carnet PUBLIÉ seulement, carte d'une autorisation ACTIVE dont la personne n'est visée par aucune restriction de garde, registre du
 * jour calculé depuis le journal de sortie. Les textes multi-lignes sont rendus en texte (échappés par le moteur de modèles).
 */
final class DocumentRequests
{
    /** Libellés des niveaux de départ (`nursery.dev.levels`) ; un code ajouté par l'école s'affiche tel quel. */
    private const LEVEL_LABELS = [
        'fr' => ['acquired' => 'acquis', 'in_progress' => 'en cours', 'not_yet' => 'pas encore'],
        'en' => ['acquired' => 'acquired', 'in_progress' => 'in progress', 'not_yet' => 'not yet'],
    ];

    public function __construct(
        private readonly NurseryDocuments $documents,
        private readonly ProgressBookService $books,
        private readonly AuthorizedPickupRegistry $pickups,
        private readonly RestrictionService $restrictions,
        private readonly RegisterService $register,
        private readonly ChildDirectory $children,
        private readonly SectionService $sections,
        private readonly CurrentPeriod $periods,
        private readonly UserPersonLink $links,
        private readonly Store $store,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> DOC-MAT-01 */
    public function progressBook(Principal $principal, Perimeter $perimeter, string $bookId, string $locale): array
    {
        $book = $this->books->get($perimeter, $bookId);
        $this->access->assertWritable($principal, $perimeter, (string) $book['section_id'], 'progress_book', $bookId);
        if ($book['status'] !== 'published') {
            throw HttpException::conflict('Seul un carnet publié aux parents s’imprime : le contenu est alors figé.');
        }
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        [$periodLabel, $yearLabel] = $this->periodLabels($tenantId, $establishmentId, (string) $book['period_id'], (string) $book['academic_year_id'], $locale);
        $domainLabels = $this->labels('nu_dev_domains', $tenantId, $establishmentId, $locale);
        $itemLabels = $this->labels('nu_dev_items', $tenantId, $establishmentId, $locale);
        $domains = [];
        foreach ((array) $book['domains'] as $code => $text) {
            if (trim((string) $text) !== '') {
                $domains[] = ($domainLabels[(string) $code] ?? (string) $code) . ' : ' . (string) $text;
            }
        }
        $observations = [];
        /** @var list<array<string, mixed>> $snapshot */
        $snapshot = $book['observations'];
        foreach ($snapshot as $row) {
            $level = (string) ($row['level_code'] ?? '');
            $observations[] = ($domainLabels[(string) ($row['domain_id'] ?? '')] ?? '') . ' / ' . ($itemLabels[(string) ($row['item_id'] ?? '')] ?? (string) ($row['item_id'] ?? ''))
                . ' : ' . (self::LEVEL_LABELS[$locale][$level] ?? $level);
        }
        $section = $this->sections->findRaw($tenantId, $establishmentId, (string) $book['section_id']);

        return $this->documents->issue($principal, $establishmentId, NurseryDocuments::PROGRESS_BOOK, $bookId, (string) $book['learner_id'], (string) $book['learner_id'], [
            'academic_year' => $yearLabel, 'section_label' => (string) $section['label'], 'period_label' => $periodLabel, 'synthesis' => (string) $book['synthesis'],
            'domains_text' => implode("\n", $domains), 'observations_text' => implode("\n", $observations),
        ], $locale);
    }

    /** @return array<string, mixed> DOC-MAT-03 */
    public function pickupCard(Principal $principal, Perimeter $perimeter, string $pickupId, string $locale): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $pickup = $this->pickups->find($tenantId, $establishmentId, $pickupId) ?? throw HttpException::notFound('Autorisation introuvable.');
        $section = $this->children->sectionOf($tenantId, $establishmentId, $pickup->learnerId) ?? throw HttpException::notFound('Autorisation introuvable.');
        $this->access->assertWritable($principal, $perimeter, $section, 'authorization', $pickupId);
        $today = $this->clock->today();
        if ($pickup->status !== AuthorizedPickupInfo::ACTIVE || !$pickup->isValidOn($pickup->grantType === AuthorizedPickupInfo::DAY ? (string) $pickup->validOn : $today)) {
            throw HttpException::conflict('Autorisation retirée ou expirée : aucune carte.');
        }
        if (RestrictionService::matching($this->restrictions->activeFor($tenantId, $establishmentId, $pickup->learnerId, $today), $pickup, $pickup->id, null) !== null) {
            // La personne visée par une restriction ne reçoit jamais de carte ; le refus est journalisé, sans détail exposé.
            $this->access->audit($principal, 'nursery.pickup.card_refused', 'authorization', $pickupId, null, ['reason' => 'custody_restriction']);

            throw HttpException::conflict('Aucune carte ne peut être émise pour cette personne. Voyez la direction.');
        }
        $label = (string) $this->sections->findRaw($tenantId, $establishmentId, $section)['label'];
        $grant = $pickup->grantType === AuthorizedPickupInfo::DAY
            ? ($locale === 'en' ? 'on ' : 'le ') . (string) $pickup->validOn
            : ($locale === 'en' ? 'permanent, until withdrawn' : 'permanente, jusqu’au retrait');

        return $this->documents->issue($principal, $establishmentId, NurseryDocuments::PICKUP_CARD, $pickupId, $pickup->learnerId, $pickup->learnerId, [
            'section_label' => $label, 'person_name' => $pickup->name, 'relationship' => $pickup->relationship, 'grant_label' => $grant,
        ], $locale);
    }

    /** @return array<string, mixed> DOC-MAT-04 : registre du jour de tout l'établissement, au nom de la personne qui l'édite */
    public function exitRegister(Principal $principal, Perimeter $perimeter, string $day, string $locale): array
    {
        $this->access->assertWhole($principal, $perimeter, 'register');
        $personId = $this->links->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::forbidden('Le registre est édité au nom d’une personne : rattachez ce compte à une fiche personne.');
        $register = $this->register->forDay($perimeter, $day);
        $en = $locale === 'en';
        $statusLabels = $en
            ? ['left' => 'left', 'still_at_school' => 'still at school', 'absent' => 'absent', 'expected' => 'not arrived']
            : ['left' => 'sorti', 'still_at_school' => 'encore à l’école', 'absent' => 'absent', 'expected' => 'non arrivé'];
        $rows = [];
        $labels = [];
        foreach ($register['sections'] as $section) {
            $labels[] = (string) $section['label'];
            $rows[] = '— ' . (string) $section['label'];
            foreach ($section['children'] as $child) {
                $line = (string) $child['name'] . ' : ' . ($statusLabels[(string) $child['status']] ?? (string) $child['status']);
                if ($child['arrived_at'] !== null) {
                    $line .= ($en ? ', arrived ' : ', arrivé ') . substr((string) $child['arrived_at'], 11, 5);
                }
                if ($child['left_at'] !== null) {
                    $line .= ($en ? ', left ' : ', sorti ') . substr((string) $child['left_at'], 11, 5) . ($en ? ' with ' : ' avec ') . (string) $child['left_to'];
                }
                if ((int) $child['refusals'] > 0) {
                    $line .= ($en ? ', refusals: ' : ', refus : ') . (int) $child['refusals'];
                }
                if ($child['alerts'] !== []) {
                    $line .= ($en ? ', alerts: ' : ', alertes : ') . implode(', ', (array) $child['alerts']);
                }
                $rows[] = $line;
            }
        }
        $t = $register['totals'];
        $totals = $en
            ? sprintf('Children: %d. Present: %d. Absent: %d. Left: %d. Still at school: %d. Refusals: %d. Alerts: %d.', $t['children'], $t['present'], $t['absent'], $t['left'], $t['still_at_school'], $t['refusals'], $t['alerts'])
            : sprintf('Enfants : %d. Présents : %d. Absents : %d. Sortis : %d. Encore à l’école : %d. Refus : %d. Alertes : %d.', $t['children'], $t['present'], $t['absent'], $t['left'], $t['still_at_school'], $t['refusals'], $t['alerts']);

        return $this->documents->issue($principal, $perimeter->establishmentId, NurseryDocuments::EXIT_REGISTER, $day, null, $personId, [
            'register_date' => $day, 'sections_text' => implode(', ', $labels), 'totals_text' => $totals, 'rows_text' => implode("\n", $rows),
        ], $locale);
    }

    /** @return array{0: string, 1: string} libellés de la période et de l'année (identifiants si la période n'est plus en cours) */
    private function periodLabels(string $tenantId, string $establishmentId, string $periodId, string $yearId, string $locale): array
    {
        foreach ($this->periods->allAt($tenantId, $establishmentId, $this->clock->today()) as $period) {
            if ($period->id === $periodId) {
                return [($locale === 'en' ? 'Term ' : 'Trimestre ') . $period->ordinal, $period->academicYearLabel ?? $yearId];
            }
        }

        return [$periodId, $yearId];
    }

    /** @return array<string, string> identifiant (et code) => libellé dans la langue */
    private function labels(string $table, string $tenantId, string $establishmentId, string $locale): array
    {
        $labels = [];
        foreach ($this->store->select($table, $tenantId, $establishmentId, [], 'id', 5000) as $row) {
            $label = (string) ($locale === 'en' ? $row['label_en'] : $row['label_fr']);
            $labels[(string) $row['id']] = $label;
            $labels[(string) $row['code']] = $label;
        }

        return $labels;
    }
}
