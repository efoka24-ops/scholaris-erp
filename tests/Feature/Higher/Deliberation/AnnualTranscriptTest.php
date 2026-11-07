<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Deliberation;

use Tests\Support\Higher\Deliberation\TranscriptTestCase;

/**
 * C-103 : relevé de notes et de crédits DOC-10 (aperçu, émission d'un étudiant, émission en lot idempotente) et fin de la limite de
 * 10 UE du relevé semestriel DOC-09. Données fictives (voir TranscriptTestCase).
 */
final class AnnualTranscriptTest extends TranscriptTestCase
{
    protected function setUp(): void
    {
        $this->bootTranscripts();
    }

    public function testApercuDuReleveAnnuelAvecMgpSurQuatreEtDecisionDeLAnnee(): void
    {
        [$response, $json] = $this->api('GET', '/higher/deliberation/annual-transcripts?person_id=marie&academic_year_id=' . self::YEAR_ID, 'jury-prep', self::T, self::E);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];
        self::assertSame(['S1', 'S2', 'S9'], array_column($data['semesters'], 'period_id'));
        self::assertSame(55, $data['synthesis']['credits_earned']);
        self::assertSame('Admis avec dettes', $data['synthesis']['decision']);
        self::assertEqualsWithDelta(1.7, $data['synthesis']['gpa'], 0.2, 'MGP sur 4 pondérée par les crédits');
        self::assertSame('Assez bien', $data['semesters'][0]['units'][0]['mention'], '13,00 : mention par UE selon l\'échelle des règles');
        self::assertNull($data['semesters'][1]['units'][1]['mention'], 'UE non acquise : pas de mention');
    }

    public function testEmissionDuDoc10ViaLeSocleAvecNumeroEtRegistre(): void
    {
        [$response, $json] = $this->api('POST', '/higher/deliberation/annual-transcripts', 'jury-prep', self::T, self::E, ['person_id' => 'marie', 'academic_year_id' => self::YEAR_ID, 'locale' => 'en']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('DOC-10', $json['data']['code']);
        self::assertSame('RAN-EC-2026-000001', $json['data']['number']);
        self::assertSame('en', $json['data']['locale']);
        $content = (string) $this->pdo()->query("SELECT content FROM documents WHERE id = '" . $json['data']['document_id'] . "'")->fetchColumn();
        self::assertStringContainsString('UE22', $content, 'Toutes les UE de l\'année figurent au relevé.');
        self::assertStringContainsString('Assez bien', $content, 'Mention par UE.');
    }

    public function testSansDecisionDeFinDAnneeLEmissionEstRefuseeEn409(): void
    {
        [$response, $json] = $this->api('POST', '/higher/deliberation/annual-transcripts', 'jury-prep', self::T, self::E, ['person_id' => 'yann', 'academic_year_id' => self::YEAR_ID]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('décision de fin d\'année', (string) ($json['detail'] ?? ''));
    }

    public function testPermissionRequiseEtPerimetreEcole(): void
    {
        [$denied] = $this->api('POST', '/higher/deliberation/annual-transcripts', 'curieux', self::T, self::E, ['person_id' => 'marie', 'academic_year_id' => self::YEAR_ID]);
        [$batchDenied] = $this->api('POST', '/higher/deliberation/annual-transcripts/batch', 'curieux', self::T, self::E, ['structure_id' => 'GRP1', 'academic_year_id' => self::YEAR_ID]);
        [$preview] = $this->api('GET', '/higher/deliberation/annual-transcripts?person_id=marie&academic_year_id=' . self::YEAR_ID, 'curieux', self::T, self::E);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(403, $batchDenied->getStatusCode());
        self::assertSame(403, $preview->getStatusCode());
    }

    public function testLeLotEmetUneFoisSauteLesAutresAvecLeurRaisonEtResteIdempotent(): void
    {
        [$response, $json] = $this->api('POST', '/higher/deliberation/annual-transcripts/batch', 'jury-prep', self::T, self::E, ['structure_id' => 'GRP1', 'academic_year_id' => self::YEAR_ID]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $json['data']['total']);
        self::assertSame(['marie'], array_column($json['data']['issued'], 'person_id'));
        self::assertSame(['yann'], array_column($json['data']['skipped'], 'person_id'));
        self::assertStringContainsString('décision de fin d\'année', $json['data']['skipped'][0]['reason']);

        [, $again] = $this->api('POST', '/higher/deliberation/annual-transcripts/batch', 'jury-prep', self::T, self::E, ['structure_id' => 'GRP1', 'academic_year_id' => self::YEAR_ID]);
        self::assertSame([], $again['data']['issued'], 'Relancer le lot n\'émet rien en double.');
        self::assertSame('already_issued', array_column($again['data']['skipped'], 'reason', 'person_id')['marie']);

        // Une nouvelle décision postérieure (rectification) rend le relevé précédent obsolète : le lot en émet un nouveau.
        $this->pdo()->prepare('UPDATE hi_year_decisions SET decided_at = ? WHERE person_id = ?')->execute(['2099-01-01T09:00:00+01:00', 'marie']);
        [, $third] = $this->api('POST', '/higher/deliberation/annual-transcripts/batch', 'jury-prep', self::T, self::E, ['structure_id' => 'GRP1', 'academic_year_id' => self::YEAR_ID]);
        self::assertSame(['marie'], array_column($third['data']['issued'], 'person_id'));
    }

    public function testLeLotRefuseUnGroupeInconnu(): void
    {
        [$response] = $this->api('POST', '/higher/deliberation/annual-transcripts/batch', 'jury-prep', self::T, self::E, ['structure_id' => 'GRP-INCONNU', 'academic_year_id' => self::YEAR_ID]);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testLeReleveSemestrielAccepteAuDelaDeDixUe(): void
    {
        [$response, $json] = $this->api('POST', '/higher/deliberation/transcripts', 'jury-prep', self::T, self::E, ['person_id' => 'marie', 'period_id' => 'S9']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('DOC-09', $json['data']['code']);
        $content = (string) $this->pdo()->query("SELECT content FROM documents WHERE id = '" . $json['data']['document_id'] . "'")->fetchColumn();
        self::assertStringContainsString('U912', $content, 'La douzième UE est imprimée.');
    }
}
