<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkOverlaySource;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSource;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Sources de notes déclarées sous `higher.deliberation.mark_sources.*`. Les feuilles de toutes les sources principales qui
 * connaissent le groupe sont fusionnées (personnes en union, unités par matière, EC par élément, entrées concaténées, `unlocked` en
 * union) ; les sources complémentaires (MarkOverlaySource, ex. la soutenance) s'ajoutent ensuite, sans jamais répondre seules ni
 * ajouter de personne. Avec une seule source principale le résultat est celui de cette source.
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class MarkSources
{
    /** @var list<MarkSource>|null */
    private ?array $sources = null;

    public function __construct(private readonly Container $container)
    {
    }

    public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
    {
        $base = null;
        $overlays = [];
        foreach ($this->sources() as $source) {
            $sheet = $source->sheet($tenantId, $establishmentId, $structureId, $periodId, $session, $academicYearId);
            if ($sheet === null) {
                continue;
            }
            if ($source instanceof MarkOverlaySource) {
                $overlays[] = $sheet;
            } else {
                $base = $base === null ? $sheet : self::merge($base, $sheet, true);
            }
        }
        if ($base === null) {
            return null;
        }
        foreach ($overlays as $overlay) {
            $base = self::merge($base, $overlay, false);
        }

        return $base;
    }

    /** @throws HttpException 409 si aucune source ne fournit les notes */
    public function require(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): MarkSheet
    {
        return $this->sheet($tenantId, $establishmentId, $structureId, $periodId, $session, $academicYearId)
            ?? throw HttpException::conflict('Aucune source de notes n\'est disponible pour ce groupe et cette période : la simulation et la liste du rattrapage exigent les notes verrouillées des EC.');
    }

    /** Ajoute les entrées de `$extra` à `$base` (unités par `subject_id`, EC par `element_id`) ; `$addPeople` : accepte les personnes absentes de la base. */
    private static function merge(MarkSheet $base, MarkSheet $extra, bool $addPeople): MarkSheet
    {
        $units = $base->units;
        foreach ($extra->units as $person => $personUnits) {
            if (!isset($units[$person])) {
                if ($addPeople) {
                    $units[$person] = $personUnits;
                }
                continue;
            }
            foreach ($personUnits as $unit) {
                $units[$person] = self::mergeUnit($units[$person], $unit);
            }
        }

        return new MarkSheet($units, array_values(array_unique([...$base->unlocked, ...$extra->unlocked])));
    }

    /**
     * @param list<Unit> $units
     * @param Unit $unit
     * @return list<Unit>
     */
    private static function mergeUnit(array $units, array $unit): array
    {
        foreach ($units as $i => $existing) {
            if ($existing['subject_id'] !== $unit['subject_id']) {
                continue;
            }
            foreach ($unit['groups'] as $group) {
                $found = false;
                foreach ($existing['groups'] as $j => $current) {
                    if ($current['element_id'] === $group['element_id'] && $group['element_id'] !== null) {
                        $existing['groups'][$j]['entries'] = [...$current['entries'], ...$group['entries']];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $existing['groups'][] = $group;
                }
            }
            $units[$i] = $existing;

            return $units;
        }
        $units[] = $unit;

        return $units;
    }

    /** @return list<MarkSource> */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, MarkSource::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $source = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($source instanceof MarkSource) {
                    $found[spl_object_id($source)] = $source;
                }
            }
        }

        return $this->sources = array_values($found);
    }
}
