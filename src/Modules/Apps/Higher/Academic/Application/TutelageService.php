<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;

/**
 * Registre des tutelles (EX-ACA-04, RG-ORG-8) : une filière LMD est rattachée à une université de tutelle PAR ANNÉE académique
 * (une ligne par filière et par année). Changer de tutelle = enregistrer la tutelle de l'année concernée ; l'université d'une
 * année déjà enregistrée ne se modifie pas, les années passées restent intactes (R38). Convention numérisée, contacts,
 * référence du programme approuvé (RG-MAQ-3).
 */
final class TutelageService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::TUTELAGE]);
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $v['program_id'])
            ?? throw HttpException::validation(['program_id' => ['Filière introuvable dans cet établissement.']]);
        $this->access->assertWritable($principal, $perimeter, StructureService::scopeOf('program', $program), 'higher_tutelage', (string) $program['id']);
        if (Regime::tryFrom((string) $program['regime'])?->requiresTutelage() !== true) {
            throw HttpException::validation(['program_id' => ['Seule une filière LMD est placée sous la tutelle d\'une université (RG-ORG-8).']]);
        }
        self::assertConventionDates($v);
        if ($this->store->count('hi_tutelages', $tenantId, $establishmentId, ['program_id' => $program['id'], 'academic_year_id' => $v['academic_year_id']]) > 0) {
            throw HttpException::conflict('Cette filière a déjà une université de tutelle pour cette année académique (une seule par année, RG-ORG-8).');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert('hi_tutelages', $tenantId, $establishmentId, [
            'id' => $id, 'school_id' => $program['school_id'], 'department_id' => $program['department_id'], 'program_id' => $program['id'],
            'academic_year_id' => $v['academic_year_id'], 'university_name' => $v['university_name'],
            'convention_reference' => $v['convention_reference'] ?? null, 'convention_document_id' => $v['convention_document_id'] ?? null,
            'convention_starts_on' => $v['convention_starts_on'] ?? null, 'convention_ends_on' => $v['convention_ends_on'] ?? null,
            'approved_programme_ref' => $v['approved_programme_ref'] ?? null,
            'contacts' => json_encode($v['contacts'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $row = $this->store->findOrFail('hi_tutelages', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.tutelage.created', 'higher_tutelage', $id, null, $row);

        return $row;
    }

    /**
     * Convention, contacts et programme approuvé modifiables ; université et année figées (RG-ORG-8).
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, [Permissions::TUTELAGE]);
        $row = $this->store->findOrFail('hi_tutelages', $tenantId, $establishmentId, $id, 'Tutelle introuvable.');
        $this->access->assertWritable($principal, $perimeter, AuthorizationService::scope($row), 'higher_tutelage', $id);
        if (isset($v['university_name']) && $v['university_name'] !== $row['university_name']) {
            throw new HttpException(
                409,
                'Tutelle de l\'année figée',
                'L\'université de tutelle d\'une année ne se modifie pas (RG-ORG-8) : enregistrez la tutelle de l\'année concernée par le changement.',
                'https://api.scholaris.cm/errors/higher-tutelage-year-locked',
            );
        }
        unset($v['university_name']);
        self::assertConventionDates($v + ['convention_starts_on' => $row['convention_starts_on'], 'convention_ends_on' => $row['convention_ends_on']]);
        if (array_key_exists('contacts', $v)) {
            $v['contacts'] = json_encode($v['contacts'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        /** @var array<string, scalar|null> $changes */
        $changes = array_filter($v, static fn (mixed $value): bool => is_scalar($value) || $value === null);
        if ($changes === []) {
            return $row;
        }
        $this->store->update('hi_tutelages', $tenantId, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->store->findOrFail('hi_tutelages', $tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'higher.tutelage.updated', 'higher_tutelage', $id, $row, $after);

        return $after;
    }

    /** @param array<string, mixed> $v */
    private static function assertConventionDates(array $v): void
    {
        $from = $v['convention_starts_on'] ?? null;
        $to = $v['convention_ends_on'] ?? null;
        if (is_string($from) && is_string($to) && $to < $from) {
            throw HttpException::validation(['convention_ends_on' => ['La fin de la convention ne peut pas précéder son début.']]);
        }
    }

    /**
     * Contacts de la tutelle : liste d'objets {name, role, email, phone}.
     *
     * @return list<array<string, ?string>>
     */
    public static function contacts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new \InvalidArgumentException('Liste de 20 contacts au plus attendue.');
        }
        $clean = [];
        foreach ($value as $contact) {
            if (!is_array($contact) || !isset($contact['name']) || !is_string($contact['name']) || trim($contact['name']) === '') {
                throw new \InvalidArgumentException('Chaque contact a un nom.');
            }
            $item = ['name' => mb_substr(trim($contact['name']), 0, 120)];
            foreach (['role' => 80, 'email' => 160, 'phone' => 30] as $field => $max) {
                $item[$field] = isset($contact[$field]) && is_string($contact[$field]) && trim($contact[$field]) !== '' ? mb_substr(trim($contact[$field]), 0, $max) : null;
            }
            if ($item['email'] !== null && filter_var($item['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Adresse électronique de contact invalide.');
            }
            $clean[] = $item;
        }

        return $clean;
    }
}
