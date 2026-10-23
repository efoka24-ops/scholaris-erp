<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use Scholaris\Core\Config\Config;

/**
 * Registre des traitements (lecture seule). Entrées par défaut ci-dessous, complétables par la clé de configuration
 * `privacy.processing_register` (liste d'entrées de même forme, identifiées par `code`).
 */
final class ProcessingRegister
{
    private const FIELDS = ['code', 'purpose', 'legal_basis', 'data_categories', 'data_subjects', 'recipients', 'retention'];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $entries = [];
        foreach ([...self::defaults(), ...(array) $this->config->get('privacy.processing_register', [])] as $entry) {
            if (!is_array($entry) || !isset($entry['code'])) {
                continue;
            }
            $clean = [];
            foreach (self::FIELDS as $field) {
                $clean[$field] = $entry[$field] ?? null;
            }
            $entries[(string) $entry['code']] = $clean;
        }

        return array_values($entries);
    }

    /** @return list<array<string, mixed>> */
    private static function defaults(): array
    {
        return [
            [
                'code' => 'scolarite',
                'purpose' => 'Gestion de la scolarité : inscriptions, présences, évaluations, bulletins',
                'legal_basis' => 'Exécution du contrat de scolarisation',
                'data_categories' => ['identité', 'coordonnées', 'parcours scolaire', 'notes'],
                'data_subjects' => ['apprenants', 'parents et tuteurs'],
                'recipients' => ['personnel habilité de l\'établissement', 'tutelle (obligation légale)'],
                'retention' => 'Durée de la scolarité puis archivage selon la réglementation',
            ],
            [
                'code' => 'facturation',
                'purpose' => 'Facturation et recouvrement des frais de scolarité',
                'legal_basis' => 'Exécution du contrat et obligation comptable',
                'data_categories' => ['identité du garant', 'montants', 'références de paiement'],
                'data_subjects' => ['garants financiers'],
                'recipients' => ['service financier', 'prestataires de paiement'],
                'retention' => 'Durée légale de conservation des pièces comptables',
            ],
            [
                'code' => 'personnel',
                'purpose' => 'Gestion du personnel et des heures enseignées',
                'legal_basis' => 'Exécution du contrat de travail',
                'data_categories' => ['identité', 'contrat', 'affectations'],
                'data_subjects' => ['personnel'],
                'recipients' => ['direction', 'ressources humaines'],
                'retention' => 'Durée du contrat puis durée légale',
            ],
            [
                'code' => 'audit',
                'purpose' => 'Traçabilité des opérations sensibles et sécurité du système',
                'legal_basis' => 'Intérêt légitime et obligation de sécurité',
                'data_categories' => ['identifiant d\'utilisateur', 'adresse IP', 'actions réalisées'],
                'data_subjects' => ['utilisateurs'],
                'recipients' => ['super administrateur du client'],
                'retention' => 'Journal chaîné conservé sans modification ; demandes et approbations anonymisées après la durée paramétrée',
            ],
        ];
    }
}
