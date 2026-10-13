<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Documents;

use Nyholm\Psr7\ServerRequest;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentCatalog;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;
use Tests\Support\Higher\Documents\EnglishProgramLanguage;
use Tests\Support\Higher\Documents\ForbiddenTitleProvider;
use Tests\Support\Higher\Documents\TranscriptTypeProvider;

/**
 * Documents de l'IPES, lot I1 (T239, T252, T289-T293) : catalogue DOC-01 à 06 et 27 à 30 déclaré par les modules, numéro RG-DOC-1,
 * page publique sans notes (RG-DOC-2), duplicata (RG-DOC-4), annulation sans suppression (RG-DOC-5), version du profil (RG-DOC-6),
 * types non bloquables (RG-DOC-7), langue de la filière ou de l'école (RG-DOC-8), quitus refusé si solde non nul (RG-FIN-6).
 */
final class HigherDocumentsTest extends HigherFinanceTestCase
{
    public const NUMBER = '/^[A-Z]{3}-[A-Z]{2,}-\d{4}-\d{6}$/';

    protected function setUp(): void
    {
        $this->bootFinance(['documents.verification.detailed_status' => true, 'higher.documents.issue_place' => 'Douala'], [
            'higher.document_types.test' => [TranscriptTypeProvider::class],
            'higher.documents.language_resolvers.test' => [EnglishProgramLanguage::class],
        ]);
        $this->installDocuments();
    }

    public function testLeCatalogueReunitLesTypesDesModulesAvecSignatairesQrEtBlocage(): void
    {
        [, $json] = $this->api('GET', '/higher/documents/catalog', 'admin', self::T, self::E);
        $types = array_column($json['data'], null, 'code');
        foreach (['DOC-01', 'DOC-02', 'DOC-03', 'DOC-04', 'DOC-05', 'DOC-06', 'DOC-09', 'DOC-27', 'DOC-28', 'DOC-29', 'DOC-30'] as $code) {
            self::assertArrayHasKey($code, $types);
        }
        // RG-DOC-7 : jamais bloqués pour impayé.
        foreach (['DOC-06', 'DOC-27', 'DOC-28', 'DOC-29'] as $code) {
            self::assertFalse($types[$code]['blockable'], $code);
        }
        self::assertTrue($types['DOC-05']['blockable']);
        self::assertSame('directeur_ecole', $types['DOC-06']['signer_role']);
        self::assertSame('comptable', $types['DOC-30']['signer_role']);
        self::assertNull($types['DOC-01']['signer_role'], 'Accusé de réception : cachet électronique de l\'établissement');
        self::assertFalse($types['DOC-01']['qr']);
        self::assertTrue($types['DOC-27']['issued_by_socle'], 'Le reçu reste celui du socle');
        self::assertNull($types['DOC-27']['socle']);
        self::assertSame('CER-{ECOLE}-{AAAA}-{N6}', $types['DOC-06']['number_template']);
        self::assertStringContainsString('{{school_logo_url}}', $types['DOC-06']['socle']['templates'][0]['html']);
        self::assertSame(['fr', 'en'], array_column($types['DOC-06']['socle']['templates'], 'locale'));
    }

    public function testNumeroParEcoleConformeAuCdcEtPagePubliqueSansNotes(): void
    {
        $this->connections()->prepare('INSERT INTO number_sequences (tenant_id, establishment_id, kind, year, current_value) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::T, self::E, 'doc:hi_doc09@EC', 2026, 314]);
        [$status, $json] = $this->issueDocument('scolarite-ec', 'DOC-09', 'marie', 'EC', ['semester_average' => '11,55', 'grades_table' => 'UE1 10,87 ; UE2 7,10'], 'CF');
        self::assertSame(201, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame('REL-EC-2026-000315', $json['data']['number']);
        self::assertMatchesRegularExpression(self::NUMBER, $json['data']['number']);

        [$status, $certificate] = $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', ['birth_date' => '12/03/2007', 'birth_place' => 'Douala'], 'CF');
        self::assertSame(201, $status, json_encode($certificate, JSON_THROW_ON_ERROR));
        self::assertSame('CER-EC-2026-000001', $certificate['data']['number']);
        self::assertSame(1, $certificate['data']['profile_version']);

        $public = $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $json['data']['verification_token']));
        self::assertSame(200, $public->getStatusCode());
        $body = (string) $public->getBody();
        $verified = json_decode($body, true)['data'] ?? json_decode($body, true);
        self::assertSame('valid', $verified['state']);
        self::assertSame('REL-EC-2026-000315', $verified['number']);
        self::assertStringNotContainsString('11,55', $body, 'Jamais les notes sur la page publique (RG-DOC-2)');
        self::assertStringNotContainsString('UE1', $body);
        self::assertSame('N••• Marie', $verified['holder'], 'Nom de l\'étudiant affiché masqué');
    }

    public function testLangueDeLaFiliereSinonDeLEcoleEtIdentiteVisuelleVersionnee(): void
    {
        [, $fr] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC', [], 'CF');
        self::assertSame('fr', $fr['data']['locale'], 'Langue par défaut de l\'école EC');
        [, $en] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC', [], 'DR');
        self::assertSame('en', $en['data']['locale'], 'Filière enseignée en anglais (RG-DOC-8)');
        [, $it] = $this->issueDocument('comptable', 'DOC-06', 'yann', 'IT');
        self::assertSame('en', $it['data']['locale']);
        self::assertSame('CER-IT-2026-000001', $it['data']['number'], 'Suite propre à chaque école');
        [, $forced] = $this->issueDocument('comptable', 'DOC-06', 'yann', 'IT', [], null, 'fr');
        self::assertSame('fr', $forced['data']['locale']);

        [$put] = $this->api('PUT', '/higher/documents/schools/EC/profile', 'admin', self::T, self::E, [
            'school_code' => 'EC', 'display_name' => 'École de commerce et de gestion', 'primary_color' => '#990000',
        ]);
        self::assertSame(200, $put->getStatusCode());
        [, $profile] = $this->api('GET', '/higher/documents/schools/EC/profile', 'admin', self::T, self::E);
        self::assertSame(2, $profile['data']['version']);
        self::assertCount(2, $profile['data']['versions']);
        [, $after] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC', [], 'CF');
        self::assertSame(2, $after['data']['profile_version']);
        [, $before] = $this->api('GET', '/higher/documents/' . $fr['data']['id'], 'comptable', self::T, self::E);
        self::assertSame(1, $before['data']['profile_version'], 'Le document émis garde la version de son émission');

        [$bad] = $this->api('PUT', '/higher/documents/schools/EC/profile', 'admin', self::T, self::E, ['school_code' => 'ec', 'display_name' => 'X', 'primary_color' => 'red']);
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testDuplicataEtAnnulationSansSuppression(): void
    {
        [, $doc] = $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', [], 'CF');
        $id = $doc['data']['id'];
        [$dup, $json] = $this->api('POST', '/higher/documents/' . $id . '/duplicate', 'scolarite-ec', self::T, self::E, []);
        self::assertSame(200, $dup->getStatusCode(), (string) $dup->getBody());
        self::assertSame($doc['data']['number'], $json['data']['duplicate']['original_number']);
        self::assertSame('Duplicata — réimprimé le 01/10/2026', $json['data']['duplicate']['mention']);
        self::assertSame(1, $json['data']['duplicate_count']);

        [$noReason] = $this->api('POST', '/higher/documents/' . $id . '/cancel', 'scolarite-ec', self::T, self::E, []);
        self::assertSame(422, $noReason->getStatusCode());
        [$cancel, $cancelled] = $this->api('POST', '/higher/documents/' . $id . '/cancel', 'scolarite-ec', self::T, self::E, ['reason' => 'Erreur sur la date de naissance']);
        self::assertSame(200, $cancel->getStatusCode(), (string) $cancel->getBody());
        self::assertSame('cancelled', $cancelled['data']['status']);
        [, $still] = $this->api('GET', '/higher/documents/' . $id, 'scolarite-ec', self::T, self::E);
        self::assertSame('Erreur sur la date de naissance', $still['data']['cancel_reason'], 'Jamais supprimé, motif affiché');
        [$again] = $this->api('POST', '/higher/documents/' . $id . '/duplicate', 'scolarite-ec', self::T, self::E, []);
        self::assertSame(409, $again->getStatusCode());

        $public = json_decode((string) $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/verify/' . $doc['data']['verification_token']))->getBody(), true);
        $public = $public['data'] ?? $public;
        self::assertSame('cancelled', $public['state']);
        self::assertStringStartsWith('Erreur sur la date de naissance', $public['revoke_reason']);

        [, $first] = $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', [], 'CF');
        [, $second] = $this->issueDocument('scolarite-ec', 'DOC-06', 'marie', 'EC', [], 'CF');
        [, $replaced] = $this->api('POST', '/higher/documents/' . $first['data']['id'] . '/cancel', 'scolarite-ec', self::T, self::E, ['reason' => 'Réédition', 'replaced_by' => $second['data']['id']]);
        self::assertSame('replaced', $replaced['data']['status']);
        self::assertSame($second['data']['id'], $replaced['data']['replaced_by']);
    }

    public function testBlocagePourImpayeJamaisSurLesTypesNonBloquablesEtQuitusSurSoldeNul(): void
    {
        $this->settings->values['billing.blocks'] = ['documents' => ['enabled' => true], 'reinscription' => ['enabled' => true]];
        $this->seedFeeCatalog();
        $this->cdcGrid();
        $account = $this->openAccount();
        $this->clock->set('2026-11-20T09:00:00+01:00');

        [, $holds] = $this->api('GET', '/higher/billing/holds/marie', 'comptable', self::T, self::E);
        self::assertTrue($holds['data']['blocks']['reinscription']);
        self::assertTrue($holds['data']['blocks']['documents']);
        self::assertFalse($holds['data']['blocks']['courses'], 'Jamais les cours');
        self::assertFalse($holds['data']['blocks']['exams'], 'Jamais la composition');

        // Blocage de réinscription et de documents actifs : le certificat de scolarité et l'attestation de paiement passent.
        [$certificate, $json] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC', [], 'CF');
        self::assertSame(201, $certificate, json_encode($json, JSON_THROW_ON_ERROR));
        [$attestation] = $this->issueDocument('comptable', 'DOC-29', 'marie', 'EC', ['amount_paid' => '0'], 'CF');
        self::assertSame(201, $attestation);
        [$statement] = $this->issueDocument('comptable', 'DOC-28', 'marie', 'EC', ['balance' => '500000'], 'CF');
        self::assertSame(201, $statement);
        [$card, $refused] = $this->issueDocument('comptable', 'DOC-05', 'marie', 'EC', [], 'CF');
        self::assertSame(422, $card, 'La carte d\'étudiant est bloquable');
        self::assertStringContainsString('impayée', json_encode($refused, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // Quitus : refusé tant que le solde n'est pas nul (RG-FIN-6), puis délivré.
        [$quitus, $denied] = $this->issueDocument('comptable', 'DOC-30', 'marie', 'EC', ['purpose' => 'soutenance'], 'CF');
        self::assertSame(422, $quitus);
        self::assertStringContainsString('500000 FCFA', json_encode($denied, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        [$status] = $this->pay($account['id'], 'comptable', 500000, 'transfer', 'quitus-full-payment');
        self::assertSame(201, $status);
        [$granted, $q] = $this->issueDocument('comptable', 'DOC-30', 'marie', 'EC', ['purpose' => 'soutenance'], 'CF');
        self::assertSame(201, $granted, json_encode($q, JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^QUI-EC-2026-\d{6}$/', $q['data']['number']);

        // Le quitus exige aussi la permission higher.quitus.issue.
        [$noRight] = $this->issueDocument('scolarite-ec', 'DOC-30', 'marie', 'EC', ['purpose' => 'transfert'], 'CF');
        self::assertSame(403, $noRight);
    }

    public function testEmissionRefuseeHorsCatalogueVariableOuProfil(): void
    {
        [$receipt] = $this->issueDocument('comptable', 'DOC-27', 'marie', 'EC');
        self::assertSame(422, $receipt, 'Le reçu est émis par la caisse du socle');
        [$unknown] = $this->issueDocument('comptable', 'DOC-99', 'marie', 'EC');
        self::assertSame(422, $unknown);
        [$variable] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC', ['notes' => '12']);
        self::assertSame(422, $variable);
        [$noProfile] = $this->issueDocument('comptable', 'DOC-06', 'marie', 'EC-CF');
        self::assertSame(422, $noProfile);

        // Garde de base : un type Higher émis par la route du socle (sans école) est refusé.
        [$direct] = $this->api('POST', '/documents', 'comptable', self::T, self::E, ['type' => 'hi_doc06', 'subject_person_id' => 'marie', 'data' => new \stdClass()]);
        self::assertContains($direct->getStatusCode(), [403, 422]);
    }

    public function testPerimetreDeLEcoleEtAutreClient(): void
    {
        [$other] = $this->issueDocument('scolarite-ec', 'DOC-06', 'yann', 'IT');
        self::assertSame(403, $other, 'La scolarité de EC n\'émet pas pour IT');
        [, $it] = $this->issueDocument('comptable', 'DOC-06', 'yann', 'IT');
        [$hidden] = $this->api('GET', '/higher/documents/' . $it['data']['id'], 'scolarite-ec', self::T, self::E);
        self::assertSame(404, $hidden->getStatusCode());
        [, $list] = $this->api('GET', '/higher/documents', 'scolarite-ec', self::T, self::E);
        self::assertSame([], $list['data']);
        [$foreign] = $this->api('GET', '/higher/documents/' . $it['data']['id'], 'comptable', 'ipes-b', 'e-ipes-b');
        self::assertContains($foreign->getStatusCode(), [403, 404]);
    }

    public function testUnIntituleBtsOuHndEstRefuseParLeCatalogue(): void
    {
        $this->bootFinance([], ['higher.document_types.faulty' => [ForbiddenTitleProvider::class]]);
        $this->expectException(\LogicException::class);
        $this->service(HigherDocumentCatalog::class)->all();
    }

    private function connections(): \PDO
    {
        return $this->service(ConnectionFactory::class)->pdo();
    }
}
