<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Application;

use DI\Container;
use LogicException;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Catalogue des documents de l'IPES : itère les fournisseurs enregistrés sous les clés de liste `higher.document_types.*`
 * (chaque module Higher y ajoute ses types) et produit, pour chaque type émis par Documents, la définition et les modèles
 * HTML FR/EN attendus par le socle (/document-types, /document-templates) : en-tête de l'école (logo, couleur, nom, en-tête),
 * titre, corps du type, lieu et date, signataire, numéro, QR et mention de vérification.
 *
 * Refus au chargement (erreur de développement) : code répété, intitulé contenant « BTS » ou « HND » (RG-EXN-3).
 */
final class HigherDocumentCatalog
{
    public const PREFIX = 'higher.document_types.';

    /** Variables d'identité visuelle et d'identité de l'étudiant, communes à tous les modèles (remplies par l'émission). */
    public const COMMON_VARIABLES = [
        'school_name', 'school_code', 'school_header', 'school_logo_url', 'school_color', 'document_title', 'holder_matricule',
        'program_name', 'level_name', 'academic_year', 'issue_place',
    ];

    public const FORBIDDEN_TITLE = '/\b(BTS|HND)\b/i';

    /** @var array<string, HigherDocumentType>|null */
    private ?array $types = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<string, HigherDocumentType> types par code DOC-nn, triés */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $types = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if (!$provider instanceof DocumentTypeProvider) {
                    throw new LogicException('Fournisseur de types de documents invalide sous ' . $name . '.');
                }
                foreach ($provider->types() as $type) {
                    if (isset($types[$type->code])) {
                        throw new LogicException('Type de document déclaré deux fois : ' . $type->code . '.');
                    }
                    if (preg_match(self::FORBIDDEN_TITLE, $type->labelFr . ' ' . $type->labelEn) === 1) {
                        throw new LogicException('Intitulé interdit (RG-EXN-3, jamais « BTS » ni « HND ») : ' . $type->code . '.');
                    }
                    $types[$type->code] = $type;
                }
            }
        }
        ksort($types);

        return $this->types = $types;
    }

    public function find(string $code): ?HigherDocumentType
    {
        return $this->all()[$code] ?? null;
    }

    public function bySocleCode(string $socleCode): ?HigherDocumentType
    {
        foreach ($this->all() as $type) {
            if (!$type->issuedBySocle && $type->socleCode() === $socleCode) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string> variables déclarées au socle pour ce type */
    public static function variablesOf(HigherDocumentType $type): array
    {
        return array_values(array_unique([...self::COMMON_VARIABLES, ...$type->variables]));
    }

    /** Modèle HTML complet d'un type dans une langue (repli sur le français). */
    public static function html(HigherDocumentType $type, string $locale): string
    {
        $en = $locale === 'en' && isset($type->bodies['en']);
        $body = $en ? $type->bodies['en'] : ($type->bodies['fr'] ?? '');
        $verify = $en ? 'Only the signed original or the document verified online is authentic' : 'Seul l\'original signé ou vérifié en ligne fait foi';
        $number = $en ? 'Number' : 'Numéro';
        $done = $en ? 'Issued at {{issue_place}} on {{issued_on}}.' : 'Fait à {{issue_place}}, le {{issued_on}}.';

        return '<html><body>'
            . '<header style="border-bottom:3px solid {{school_color}};padding-bottom:6px">'
            . '<img src="{{school_logo_url}}" alt="" height="60">'
            . '<h1>{{establishment_name}}</h1><h2 style="color:{{school_color}}">{{school_name}}</h2><p>{{school_header}}</p></header>'
            . '<h2>{{document_title}}</h2>'
            . $body
            . '<p>' . $done . '</p>'
            . '<div>{{signature}}{{stamp}}</div>'
            . '<p>' . $number . ' : {{number}}</p>'
            . ($type->qr ? '<div class="qr">{{qr}}</div>' : '')
            . '<p><small>' . $verify . ' : {{verification_url}}</small></p>'
            . '</body></html>';
    }

    /**
     * Définition et modèles à installer au socle (POST /document-types puis /document-templates), ou null pour un document
     * produit par le socle lui-même (reçu).
     *
     * @return array{type: array<string, mixed>, templates: list<array{locale: string, html: string}>}|null
     */
    public static function soclePayload(HigherDocumentType $type): ?array
    {
        if ($type->issuedBySocle) {
            return null;
        }
        $templates = [];
        foreach (['fr', 'en'] as $locale) {
            if (isset($type->bodies[$locale])) {
                $templates[] = ['locale' => $locale, 'html' => self::html($type, $locale)];
            }
        }

        return [
            'type' => [
                'code' => $type->socleCode(), 'label' => $type->labelFr, 'category' => $type->category, 'type_letter' => $type->typeLetter,
                'number_template' => $type->numberTemplate(), 'qr_enabled' => $type->qr, 'blockable' => $type->blockable,
                'variables' => self::variablesOf($type), 'signer_role' => $type->signerRole,
            ],
            'templates' => $templates,
        ];
    }

    /** @return array<string, mixed> */
    public static function describe(HigherDocumentType $type): array
    {
        return [
            'code' => $type->code, 'prefix' => $type->prefix, 'label_fr' => $type->labelFr, 'label_en' => $type->labelEn, 'module' => $type->module,
            'signer_role' => $type->signerRole, 'qr' => $type->qr, 'blockable' => $type->blockable, 'issued_by_socle' => $type->issuedBySocle,
            'socle_code' => $type->issuedBySocle ? null : $type->socleCode(), 'number_template' => $type->issuedBySocle ? null : $type->numberTemplate(),
            'variables' => $type->issuedBySocle ? [] : self::variablesOf($type), 'socle' => self::soclePayload($type),
        ];
    }
}
