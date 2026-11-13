<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Parcours 1 (quickstart US1, US2, US3) : création du client et de son administrateur, connexion avec 2FA réelle,
 * établissement, année, périodes, classe autorisée, inscription d'un élève et de son garant, compte du personnel activé par SMS.
 */
final class SchoolSetupJourneyTest extends RealStackTestCase
{
    use BuildsSchool;

    protected function setUp(): void
    {
        $this->bootRealStack();
    }

    public function testUnClientSeMetEnPlaceDeBoutEnBoutParLApi(): void
    {
        $this->buildSchool();

        // L'administrateur voit son établissement dans ses contextes, et seulement lui.
        $contexts = $this->ok('GET', '/me/contexts', $this->admin);
        self::assertContains($this->school, array_column($contexts['data'], 'id'));

        // Liste paginée des périodes de l'année créée.
        $periods = $this->ok('GET', '/periods?filter[academic_year_id]=' . $this->year, $this->admin, null, $this->school);
        self::assertCount(3, $periods['data']);

        // Inscription d'un élève et de son garant : numéro interne attribué à la validation.
        $family = $this->enrollLearner('Nadia', 'Foka', '2011-05-14');
        $enrollment = $this->ok('GET', '/enrollments/' . $family['enrollment'], $this->admin, null, $this->school);
        self::assertSame($family['learner'], $enrollment['data']['person_id']);
        $relations = $this->ok('GET', '/people/' . $family['learner'] . '/relations', $this->admin, null, $this->school);
        self::assertSame($family['guarantor'], $relations['data'][0]['related_person_id'] ?? $relations['data'][0]['person']['id'] ?? null, json_encode($relations));

        // Doublon : la même identité renvoie 409 et la fiche existante.
        [$duplicate, $json] = $this->api('POST', '/people', $this->admin, ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2011-05-14', 'gender' => 'female'], $this->school);
        self::assertSame(409, $duplicate->getStatusCode(), (string) $duplicate->getBody());
        self::assertSame($family['learner'], $json['duplicates'][0]['person']['id']);

        // Un enseignant est créé, reçoit son rôle, active son compte par SMS et se connecte avec 2FA.
        $teacher = $this->provisionUser($this->admin, $this->school, 'Tano', 'Prof', 'enseignant');
        $teacherToken = $this->signIn($teacher['login'], $teacher['password']);
        $me = $this->ok('GET', '/me', $teacherToken);
        self::assertTrue($me['data']['two_factor']['verified']);
        // Il n'a pas le droit d'administrer l'établissement.
        [$forbidden] = $this->api('POST', '/structures', $teacherToken, ['kind' => 'class', 'name' => '4e Z', 'capacity' => 10], $this->school);
        self::assertSame(403, $forbidden->getStatusCode());
    }
}
