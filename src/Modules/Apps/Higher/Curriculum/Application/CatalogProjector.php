<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Application;

use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;

/**
 * Projection d'une maquette publiée vers le référentiel d'évaluation (D9, T220) : une matière par UE (ou matière BTS/HND), un
 * élément par EC. Idempotente par clé externe (`hi_ue:<id>`, `hi_ec:<id>`) : republier ne crée rien de nouveau. Les codes du
 * référentiel sont uniques par établissement : code de l'UE suffixé par la fin de l'identifiant de la version.
 */
final class CatalogProjector
{
    public function __construct(private readonly CurriculumCatalog $catalog)
    {
    }

    public static function unitKey(string $unitId): string
    {
        return 'hi_ue:' . $unitId;
    }

    public static function elementKey(string $elementId): string
    {
        return 'hi_ec:' . $elementId;
    }

    public static function catalogCode(string $code, string $curriculumId): string
    {
        return mb_substr($code, 0, 28) . '.' . substr($curriculumId, -10);
    }

    /**
     * @param list<array<string, mixed>> $units
     * @param list<array<string, mixed>> $elements
     * @return array{0: array<string, string>, 1: array<string, string>} [UE => matière, EC => élément]
     */
    public function project(string $tenantId, string $establishmentId, string $curriculumId, array $units, array $elements): array
    {
        $subjects = [];
        foreach ($units as $unit) {
            $subjects[(string) $unit['id']] = $this->catalog->upsertSubject($tenantId, $establishmentId, new CurriculumItem(
                self::unitKey((string) $unit['id']),
                self::catalogCode((string) $unit['code'], $curriculumId),
                (string) $unit['title'],
                'hi_' . $unit['unit_type'],
                (float) $unit['coefficient'],
                (int) $unit['credits'],
            ));
        }
        $items = [];
        foreach ($elements as $element) {
            $items[(string) $element['id']] = $this->catalog->upsertElement($tenantId, $establishmentId, self::unitKey((string) $element['unit_id']), new CurriculumItem(
                self::elementKey((string) $element['id']),
                self::catalogCode((string) $element['code'], $curriculumId),
                (string) $element['title'],
                'element',
                (float) $element['coefficient'],
                (int) $element['credits'],
            ));
        }

        return [$subjects, $items];
    }

    /**
     * Retrait d'une maquette dépubliée : matières et éléments désactivés (jamais supprimés).
     *
     * @param list<array<string, mixed>> $units
     * @param list<array<string, mixed>> $elements
     */
    public function withdraw(string $tenantId, string $establishmentId, array $units, array $elements): void
    {
        foreach ($elements as $element) {
            $this->catalog->deactivate($tenantId, $establishmentId, self::elementKey((string) $element['id']));
        }
        foreach ($units as $unit) {
            $this->catalog->deactivate($tenantId, $establishmentId, self::unitKey((string) $unit['id']));
        }
    }
}
