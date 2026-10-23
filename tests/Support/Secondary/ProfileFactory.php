<?php

declare(strict_types=1);

namespace Tests\Support\Secondary;

use PDO;
use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Setup\Infrastructure\PdoProfileStore;

/**
 * Fabrique de test des 7 types d'établissement secondaire (T950) : établissement du socle (`type = secondary`,
 * `establishment_subtype = <code>`), puis, à la demande, ouverture du profil (copie du profil de type, propriété publique ou
 * privée). Données fictives. `EXPECTED` reprend les valeurs de départ amorcées (« à confirmer ») : un test qui échoue ici signale
 * un changement du profil de type, pas une règle officielle.
 */
final class ProfileFactory
{
    public const CODES = ['leg', 'lycee_technique', 'ces', 'cetic', 'college_prive', 'lycee_prive', 'autre'];

    /** @var array<string, array{cycles: list<string>, teaching_kinds: list<string>, ownership: ?string, modules: list<string>, head_title: ?string}> */
    public const EXPECTED = [
        'leg' => ['cycles' => ['first', 'second'], 'teaching_kinds' => ['general'], 'ownership' => 'public', 'modules' => ['second_cycle', 'orientation', 'official_exams', 'fees_public_catalog'], 'head_title' => 'proviseur'],
        'lycee_technique' => ['cycles' => ['first', 'second'], 'teaching_kinds' => ['technical'], 'ownership' => 'public', 'modules' => ['second_cycle', 'orientation', 'official_exams', 'workshops', 'internships', 'fees_public_catalog'], 'head_title' => 'proviseur'],
        'ces' => ['cycles' => ['first'], 'teaching_kinds' => ['general'], 'ownership' => 'public', 'modules' => ['official_exams', 'fees_public_catalog'], 'head_title' => 'principal'],
        'cetic' => ['cycles' => ['first'], 'teaching_kinds' => ['technical'], 'ownership' => 'public', 'modules' => ['official_exams', 'workshops', 'internships', 'fees_public_catalog'], 'head_title' => 'principal'],
        'college_prive' => ['cycles' => ['first'], 'teaching_kinds' => ['general'], 'ownership' => null, 'modules' => ['official_exams', 'fees_private', 'authorizations', 'payroll_simple'], 'head_title' => 'principal'],
        'lycee_prive' => ['cycles' => ['first', 'second'], 'teaching_kinds' => ['general'], 'ownership' => null, 'modules' => ['second_cycle', 'orientation', 'official_exams', 'fees_private', 'authorizations', 'payroll_simple'], 'head_title' => 'proviseur'],
        'autre' => ['cycles' => [], 'teaching_kinds' => [], 'ownership' => null, 'modules' => [], 'head_title' => null],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ProfileResolver $resolver,
        private readonly PdoProfileStore $store,
    ) {
    }

    /** Établissement du socle d'un type donné (profil non ouvert). */
    public function establishment(string $tenantId, string $code, ?string $id = null): string
    {
        $id ??= 'e-' . str_replace('_', '-', $code) . '-' . substr(md5($tenantId), 0, 6);
        $now = '2026-10-01T08:00:00+01:00';
        $this->pdo->prepare(
            'INSERT INTO establishments (id, tenant_id, name, type, branding, modules_enabled, establishment_subtype, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $tenantId, 'Établissement fictif ' . $code . ' ' . $id, 'secondary', '{}', '[]', $code, $now, $now]);

        return $id;
    }

    /**
     * Établissement ouvert : profil de type copié tel quel ; propriété = celle du profil, sinon `$ownership`, sinon la première
     * proposée (privé laïc pour un établissement privé, public pour « autre »).
     */
    public function opened(string $tenantId, string $code, ?string $ownership = null, ?string $id = null): string
    {
        $establishmentId = $this->establishment($tenantId, $code, $id);
        $profile = $this->resolver->forEstablishment($tenantId, $establishmentId);
        $this->store->open($profile, $ownership ?? $profile->ownership ?? $profile->ownershipOptions[0], 'Ouverture de test (données fictives)', 'fabrique-test');

        return $establishmentId;
    }

    /**
     * Un établissement de chacun des 7 types.
     *
     * @return array<string, string> code => identifiant
     */
    public function all(string $tenantId, bool $open = false): array
    {
        $ids = [];
        foreach (self::CODES as $code) {
            $ids[$code] = $open ? $this->opened($tenantId, $code) : $this->establishment($tenantId, $code);
        }

        return $ids;
    }
}
