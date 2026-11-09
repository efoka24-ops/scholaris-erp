<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Setup\Application\StructureService;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Jeu « classes » : une classe par ligne (libellé, code du niveau de l'établissement, code de série ou spécialité ouverte,
 * capacité). Création par le paramétrage (StructureService::createClass : profil, cycle offert, série au 2nd cycle ou en
 * technique, structure du socle). Une classe de même libellé déjà présente pour l'année est un doublon ignoré.
 *
 * @phpstan-import-type Line from KindRows
 */
final class ClassRows implements KindRows
{
    public function __construct(
        private readonly Store $store,
        private readonly ClassProfiles $classes,
        private readonly StructureService $structure,
    ) {
    }

    public function permissions(): array
    {
        return StructureService::CLASS_WRITE;
    }

    public function atomic(): bool
    {
        return true;
    }

    public function plan(Principal $principal, string $establishmentId, string $academicYearId, ?string $entryDate, array $rows): array
    {
        $tenantId = $principal->tenantId;
        $levels = [];
        foreach ($this->store->select('se_levels', $tenantId, $establishmentId, ['active' => 1]) as $level) {
            $levels[Cells::fold((string) $level['code'])] = (string) $level['id'];
        }
        $streams = [];
        foreach ($this->store->select('se_streams', $tenantId, $establishmentId, ['active' => 1]) as $stream) {
            $streams[Cells::fold((string) $stream['code'])] = (string) $stream['id'];
        }
        $existing = [];
        foreach ($this->classes->forYear($tenantId, $establishmentId, $academicYearId) as $class) {
            $existing[Cells::fold($class->label)] = $class->id;
        }
        $lines = [];
        foreach ($rows as $number => $row) {
            $messages = [];
            $label = $row['classe'];
            if (Cells::isExample($label)) {
                $messages[] = 'Ligne d\'exemple du modèle : supprimez-la.';
            }
            if ($label === '' || mb_strlen($label) > 120) {
                $messages[] = 'Libellé de la classe obligatoire (120 caractères au plus).';
            }
            $levelId = $levels[Cells::fold($row['niveau'])] ?? null;
            if ($levelId === null) {
                $messages[] = 'Niveau « ' . $row['niveau'] . ' » inconnu ou désactivé : chargez ou créez les niveaux dans le paramétrage.';
            }
            $streamId = null;
            if ($row['serie'] !== '') {
                $streamId = $streams[Cells::fold($row['serie'])] ?? null;
                if ($streamId === null) {
                    $messages[] = 'Série ou spécialité « ' . $row['serie'] . ' » non ouverte dans l\'établissement.';
                }
            }
            $capacity = null;
            if ($row['capacite'] !== '') {
                $capacity = Cells::amount($row['capacite']);
                if ($capacity === null || $capacity < 1) {
                    $messages[] = 'Capacité : entier positif attendu.';
                }
            }
            $status = $messages === [] ? 'valid' : 'error';
            if ($status === 'valid' && isset($existing[Cells::fold($label)])) {
                $status = 'duplicate';
                $messages[] = 'Classe déjà présente pour cette année : ligne ignorée.';
            }
            $lines[] = [
                'line' => (int) $number, 'status' => $status, 'key' => 'C:' . Cells::fold($label), 'label' => $label, 'messages' => $messages,
                'plan' => $status === 'valid' ? array_filter([
                    'label' => $label, 'level_id' => $levelId, 'academic_year_id' => $academicYearId, 'stream_id' => $streamId, 'capacity' => $capacity,
                ], static fn (mixed $v): bool => $v !== null) : null,
            ];
        }

        return $lines;
    }

    public function apply(Principal $principal, string $establishmentId, string $academicYearId, array $plan): string
    {
        return (string) $this->structure->createClass($principal, $establishmentId, $plan)['id'];
    }
}
