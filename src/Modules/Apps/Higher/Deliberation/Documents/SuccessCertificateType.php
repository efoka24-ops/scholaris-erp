<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * DOC-11 attestation de réussite licence ou master (ARL ; après délibération finale et quitus ; direction générale ; QR ; bloquable
 * pour impayé). L'émission exige une décision annuelle enregistrée sur PV signé (garde SignedMinutesIssuanceGuard, RG-DOC-3).
 * Modèle de travail (decisions R35) : aucun texte légal inventé ; la mention officielle vient du profil de l'école.
 */
final class SuccessCertificateType implements DocumentTypeProvider
{
    public const CERTIFICATE = 'DOC-11';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::CERTIFICATE,
                'ARL',
                'Attestation de réussite',
                'Certificate of success',
                'deliberation',
                'direction_groupe',
                true,
                true,
                ['degree_level', 'final_average', 'mention', 'credits_acquired', 'decision_date'],
                [
                    'fr' => '<p>Il est attesté que <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, a été déclaré(e) admis(e) en {{degree_level}} ({{program_name}}) à la délibération du {{decision_date}}, année académique {{academic_year}}.</p><p>Moyenne finale : {{final_average}}/20 ; crédits acquis : {{credits_acquired}} ; mention : {{mention}}.</p><p>Cette attestation ne remplace pas le diplôme.</p>',
                    'en' => '<p>This certifies that <strong>{{holder_name}}</strong>, registration no. {{holder_matricule}}, was declared successful in the {{degree_level}} ({{program_name}}) at the board of examiners held on {{decision_date}}, academic year {{academic_year}}.</p><p>Final average: {{final_average}}/20; credits earned: {{credits_acquired}}; classification: {{mention}}.</p><p>This certificate does not replace the diploma.</p>',
                ],
            ),
        ];
    }
}
