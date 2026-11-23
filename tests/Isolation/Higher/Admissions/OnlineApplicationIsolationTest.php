<?php

declare(strict_types=1);

namespace Tests\Isolation\Higher\Admissions;

use Nyholm\Psr7\ServerRequest;
use Tests\Support\Higher\Admissions\EntryTestCase;

/** Isolation de la candidature en ligne : un candidat d'un autre client ou d'un autre établissement ne voit rien, les liens ne se mélangent pas. */
final class OnlineApplicationIsolationTest extends EntryTestCase
{
    private const CAND_B = 'cand-b';

    protected function setUp(): void
    {
        $this->bootEntry();
        $this->people->add(self::TB, 'p-b', 'Brice', 'Autre', '+237690000009');
        $this->links->link(self::TB, self::CAND_B, 'p-b');
        $this->assign(self::TB, self::CAND_B, self::EB, 'higher.application.submit', 'establishment');
        $this->assign(self::TB, self::ADMIN_B, self::EB, 'higher.application.manage', 'establishment');
    }

    private function as(string $user, string $tenant, string $establishment, string $path): int
    {
        return $this->kernel->handle(new ServerRequest('GET', '/api/v1' . $path, ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment]))->getStatusCode();
    }

    public function testUnCandidatDUnAutreClientNeVoitNiLeDossierNiLeFichier(): void
    {
        $campaign = $this->openCampaign();
        $application = $this->ok('POST', '/higher/admissions/applications', ['campaign_id' => $campaign, 'diploma' => 'bac'], self::CAND);
        $id = (string) $application['id'];
        $document = (string) $application['documents'][0]['id'];

        foreach ([[self::TB, self::EB], [self::T, self::E]] as [$tenant, $establishment]) {
            $status = $this->as(self::CAND_B, $tenant, $establishment, '/higher/admissions/applications/' . $id . '/follow-up');
            self::assertContains($status, [403, 404], 'Suivi lu par un candidat étranger : ' . $tenant);
            self::assertContains($this->as(self::CAND_B, $tenant, $establishment, '/higher/admissions/applications/' . $id . '/documents/' . $document . '/file'), [403, 404]);
        }
        self::assertSame(200, $this->as(self::CAND_B, self::TB, self::EB, '/higher/admissions/my-applications'));
        $mine = $this->ok('GET', '/higher/admissions/my-applications', null, self::CAND_B, self::TB, self::EB);
        self::assertSame([], $mine, 'Aucun dossier du client A dans la liste du candidat B.');
    }

    public function testLeLienPublicNeMontreQueLesCampagnesDeSonEtablissement(): void
    {
        $this->openCampaign();
        $slugA = (string) $this->ok('PUT', '/higher/admissions/public-link', ['enabled' => true], self::SCOL)['slug'];
        $slugB = (string) $this->ok('PUT', '/higher/admissions/public-link', ['enabled' => true], self::ADMIN_B, self::TB, self::EB)['slug'];

        self::assertNotSame($slugA, $slugB);
        $a = json_decode((string) $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/admissions/' . $slugA))->getBody(), true);
        $b = json_decode((string) $this->kernel->handle(new ServerRequest('GET', '/api/v1/public/admissions/' . $slugB))->getBody(), true);
        self::assertSame('IPES A', $a['data']['institute']['name']);
        self::assertCount(1, $a['data']['campaigns']);
        self::assertSame('IPES B', $b['data']['institute']['name']);
        self::assertSame([], $b['data']['campaigns'], 'Aucune campagne du client A sur le lien du client B.');
    }

    public function testLaGestionDuLienDemandeLeDroitEtResteDansLeClient(): void
    {
        self::assertSame(403, $this->call('GET', '/higher/admissions/public-link', null, self::CAND)[0]);
        self::assertSame(403, $this->call('PUT', '/higher/admissions/public-link', [], self::CAND_B, self::TB, self::EB)[0]);
        self::assertContains($this->call('PUT', '/higher/admissions/public-link', [], self::ADMIN_B, self::T, self::E)[0], [403, 404], "Un administrateur de B n'agit pas sur A.");
    }
}
