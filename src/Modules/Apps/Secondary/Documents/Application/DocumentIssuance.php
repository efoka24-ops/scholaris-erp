<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Cycles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;
use Scholaris\Modules\Apps\Secondary\Support\ModuleGate;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;

/**
 * Émission par le socle Documents (numéro unique par établissement, type et année, QR de vérification sans notes, contenu figé) :
 * contrôle du profil secondaire, du module du type (409 `module_not_enabled`) et de la capacité déclarée au profil (409
 * `profile_capability_not_offered`), choix de la langue (demandée, sinon celle de la
 * section si l'établissement n'a que la section anglophone, sinon le français : RG-DOC-5).
 */
final class DocumentIssuance implements SecondaryDocuments
{
    private const CAPABILITY_TYPE = 'https://api.scholaris.cm/errors/profile-capability-not-offered';

    public function __construct(
        private readonly DocumentTypeCatalogue $catalogue,
        private readonly EstablishmentProfiles $profiles,
        private readonly ModuleGate $modules,
        private readonly DocumentIssuer $issuer,
    ) {
    }

    public function issue(string $tenantId, string $establishmentId, string $kind, string $personId, array $values, string $issuedBy, ?string $locale = null): IssuedDocument
    {
        $type = $this->issuable($tenantId, $establishmentId, $kind);

        return $this->issuer->issue($tenantId, $establishmentId, (string) $type->socleCode, $personId, $values, $issuedBy, $this->locale($tenantId, $establishmentId, $locale));
    }

    /** Type connu, émis par ce module, avec son module et sa capacité de profil requis. */
    public function issuable(string $tenantId, string $establishmentId, string $kind): SecondaryDocumentType
    {
        $type = $this->catalogue->find($kind);
        if ($type === null || $type->issuedBySocle()) {
            throw HttpException::validation(['kind' => ['Nature de document inconnue ou émise par un autre module : ' . $kind . '.']]);
        }
        if ($type->module !== null) {
            $this->modules->assertAvailable($tenantId, $establishmentId, $type->module, true);
        }
        if ($type->requiredTeachingKind !== null
            && !$this->profiles->forEstablishment($tenantId, $establishmentId)->offersTeaching($type->requiredTeachingKind)) {
            throw new HttpException(
                409,
                'Capacité non offerte',
                'Le profil de cet établissement ne déclare pas la capacité requise pour ce document.',
                self::CAPABILITY_TYPE,
                extensions: ['code' => 'profile_capability_not_offered', 'teaching_kind' => $type->requiredTeachingKind],
            );
        }

        return $type;
    }

    public function locale(string $tenantId, string $establishmentId, ?string $requested): string
    {
        if ($requested === 'fr' || $requested === 'en') {
            return $requested;
        }
        $sections = $this->profiles->forEstablishment($tenantId, $establishmentId)->sections;

        return $sections !== [] && !in_array('fr', $sections, true) && in_array('en', array_intersect($sections, Cycles::SECTIONS), true) ? 'en' : 'fr';
    }
}
