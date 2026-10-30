<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Application;

use DI\Container;
use LogicException;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFacts;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFactsReader;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Catalogue des documents du secondaire : itère les fournisseurs enregistrés sous `secondary.document_types.*` (une seule liste de
 * documents pour les 7 types d'établissement ; la disponibilité dépend seulement des modules actifs) et les fournisseurs de faits
 * d'élève `secondary.student_facts.*`. Refus au chargement (erreur de développement) : numéro ou nature répétés, module inconnu, code
 * de type du socle hors préfixe `sec_`.
 */
final class DocumentTypeCatalogue implements StudentFactsReader
{
    public const PREFIX = 'secondary.document_types.';
    public const FACTS_PREFIX = 'secondary.student_facts.';

    /** @var array<string, SecondaryDocumentType>|null */
    private ?array $types = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<string, SecondaryDocumentType> types par nature, dans l'ordre des numéros DOC-nn */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $types = [];
        $docs = [];
        foreach ($this->entries(self::PREFIX) as $provider) {
            if (!$provider instanceof DocumentTypeProvider) {
                throw new LogicException('Fournisseur de types de documents invalide.');
            }
            foreach ($provider->types() as $type) {
                if (isset($types[$type->kind]) || isset($docs[$type->doc])) {
                    throw new LogicException('Document déclaré deux fois : ' . $type->doc . ' (' . $type->kind . ').');
                }
                if ($type->module !== null && !in_array($type->module, Modules::ALL, true)) {
                    throw new LogicException('Module inconnu pour ' . $type->doc . ' : ' . $type->module . '.');
                }
                if ($type->socleCode !== null && !str_starts_with($type->socleCode, 'sec_')) {
                    throw new LogicException('Code de type du socle sans préfixe sec_ : ' . $type->socleCode . '.');
                }
                $types[$type->kind] = $type;
                $docs[$type->doc] = true;
            }
        }
        uasort($types, static fn (SecondaryDocumentType $a, SecondaryDocumentType $b): int => strcmp($a->doc, $b->doc));

        return $this->types = $types;
    }

    public function find(string $kind): ?SecondaryDocumentType
    {
        return $this->all()[$kind] ?? null;
    }

    public function bySocleCode(string $socleCode): ?SecondaryDocumentType
    {
        foreach ($this->all() as $type) {
            if ($type->socleCode === $socleCode) {
                return $type;
            }
        }

        return null;
    }

    /** Matricule national du premier fournisseur qui le connaît (null = en attente). */
    public function nationalId(string $tenantId, string $establishmentId, string $personId): ?string
    {
        foreach ($this->entries(self::FACTS_PREFIX) as $provider) {
            if (!$provider instanceof StudentFacts) {
                throw new LogicException('Fournisseur de faits d\'élève invalide.');
            }
            $id = $provider->nationalId($tenantId, $establishmentId, $personId);
            if ($id !== null && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /** @return list<object> */
    private function entries(string $prefix): array
    {
        $names = array_values(array_filter($this->container->getKnownEntryNames(), static fn (string $name): bool => str_starts_with($name, $prefix)));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            foreach ((array) $this->container->get($name) as $entry) {
                $out[] = is_string($entry) ? $this->container->get($entry) : $entry;
            }
        }

        return $out;
    }
}
