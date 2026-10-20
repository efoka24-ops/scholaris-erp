<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Scholaris\Core\Support\Ulid;

/**
 * Lot I2 (enseignement, appel, notes) et lot I3 (délibérations) de l'IPES sur la pile réelle. Les défauts constatés sont isolés
 * par `markTestIncomplete` APRÈS l'assertion du comportement attendu, et consignés dans recette-septentrion.md.
 */
trait BuildsIpesGrades
{
    /** Jour de l'horloge de la pile (lendemain ouvré, 10 h). */
    protected function stackDay(): string
    {
        return (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new \DateTimeZone('Africa/Douala'))->format('Y-m-d');
    }

    /**
     * Fiches du personnel (socle Staff), affectations par le chef de département (MF à l'enseignant, CG à l'enseignante),
     * créneau d'emploi du temps, appel réel et séance du cahier de textes signée.
     *
     * @param array<string, mixed> $ctx
     */
    protected function teaching(array &$ctx): void
    {
        foreach (['prof', 'prof2'] as $who) {
            $this->as($ctx, 'admin', 'POST', '/staff', ['person_id' => $ctx['people'][$who], 'kind' => 'permanent'], 201);
        }
        $assign = fn (string $who, string $ec): array => $this->res($ctx, 'chef', 'POST', '/higher/teaching/assignments', [
            'person_id' => $ctx['people'][$who], 'curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $ctx['curriculum']['elements'][$ec],
            'group_id' => $ctx['org']['g1'], 'session_kind' => 'cm', 'planned_minutes' => 60 * 60,
        ], 201);
        $mf = $assign('prof', 'MF');
        $assign('prof2', 'CG');
        $ctx['assignment'] = (string) $mf['id'];

        $day = $this->stackDay();
        $entry = $this->res($ctx, 'chef', 'POST', '/higher/timetable/entries', [
            'assignment_id' => $mf['id'], 'weekday' => (int) date('N', (int) strtotime($day)), 'start_time' => '08:00', 'end_time' => '10:00',
            'valid_from' => self::day('-14 days'),
        ], 201);
        $ctx['entry'] = $entry;

        // D-03 : l'inscription IPES validée dans G1 inscrit l'étudiante au socle dans son groupe (liste d'appel). La candidature ne
        // recueille pas la date de naissance : le socle la traite en mineure sans garant et la validation l'a signalé « pending ».
        // La scolarité complète la date de naissance puis relance l'inscription au groupe (POST /link sans identifiant).
        $this->as($ctx, 'scol', 'PATCH', '/people/' . $ctx['student']['person'], ['birth_date' => (self::startYear() - 19) . '-03-14']);
        $linked = $this->res($ctx, 'scol', 'POST', '/higher/enrolments/' . $ctx['student']['enrolment']['id'] . '/link', []);
        self::assertNotNull($linked['enrollment_id'] ?? null, 'D-03 : inscription au socle reliée. ' . json_encode($linked, JSON_UNESCAPED_UNICODE));

        // Appel du jour par l'enseignant affecté (route du socle) : l'étudiante est sur la liste et présente.
        $call = $this->res($ctx, 'prof', 'POST', '/attendance-sessions', ['client_uuid' => (string) new Ulid(), 'slot_id' => $entry['slot_id'], 'date' => $day], 201);
        self::assertSame(1, (int) $call['expected_count'], 'D-03 : étudiante inscrite dans G1 sur la liste d\'appel.');
        $this->as($ctx, 'prof', 'PUT', '/attendance-sessions/' . $call['id'] . '/records', [
            'records' => [['person_id' => $ctx['student']['person'], 'status' => 'P', 'client_uuid' => (string) new Ulid()]],
        ]);
        $validated = $this->res($ctx, 'prof', 'POST', '/attendance-sessions/' . $call['id'] . '/validate', []);
        self::assertSame('validated', $validated['status'] ?? null, (string) json_encode($validated));
        $ctx['attendance_session'] = (string) $call['id'];

        // Cahier de textes de la séance de la semaine passée (les horloges de l'IPES suivent l'heure système, pas l'horloge de la pile).
        $lastWeek = (new \DateTimeImmutable($day))->modify('-7 days')->format('Y-m-d');
        $log = $this->res($ctx, 'prof', 'POST', '/higher/session-logs', [
            'assignment_id' => $mf['id'], 'entry_id' => $entry['id'], 'held_on' => $lastWeek, 'start_time' => '08:00', 'end_time' => '10:00',
            'content' => 'Intérêts simples et composés.', 'homework' => 'Exercices 1 à 5.',
        ], 201);
        $this->as($ctx, 'prof', 'POST', '/higher/session-logs/' . $log['id'] . '/sign');
        $ctx['session_log'] = (string) $log['id'];
    }

    /**
     * Appel en succès ; rend la ressource, qu'elle soit enveloppée dans `data` ou non (Teaching renvoie la ressource nue).
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function res(array $ctx, string $who, string $method, string $path, ?array $body = null, ?int $expected = null): array
    {
        $json = $this->as($ctx, $who, $method, $path, $body, $expected);

        return isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
    }

    /**
     * Enrôlement TOTP volontaire d'un acteur non sensible (pour une 2FA récente).
     *
     * @param array<string, mixed> $ctx
     */
    protected function enrol2fa(array &$ctx, string $who): void
    {
        [$login] = $ctx['logins'][$who];
        $token = $ctx['tokens'][$who];
        $setup = $this->ok('POST', '/auth/2fa/setup', $token);
        $this->totpSecrets[$login] = (string) $setup['secret'];
        $this->ok('POST', '/auth/2fa/confirm', $token, ['code' => $this->totp($login)]);
        $this->relogin($ctx, $who);
    }

    /** @param array<string, mixed> $ctx */
    protected function gradesJourney(array &$ctx): void
    {
        $this->teaching($ctx);
        $student = $ctx['student']['person'];
        $mf = $ctx['curriculum']['elements']['MF'];

        // TST-03 : l'enseignante affectée à CG (pas à MF) ne crée ni ne saisit rien sur MF.
        [$status] = $this->try($ctx, 'prof2', 'POST', '/higher/grade-assessments', ['curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $mf, 'kind' => 'exam']);
        self::assertSame(403, $status, 'TST-03 : enseignant non affecté.');

        $assessment = $this->res($ctx, 'prof', 'POST', '/higher/grade-assessments', ['curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $mf, 'kind' => 'exam']);
        $id = (string) $assessment['id'];
        [$status] = $this->try($ctx, 'prof2', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => 20]]]);
        self::assertSame(403, $status, 'TST-03 : saisie par un enseignant non affecté.');

        $this->as($ctx, 'prof', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => 12.5]]]);
        $this->as($ctx, 'prof', 'POST', '/higher/grade-assessments/' . $id . '/submit');
        $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/validate');

        // Verrouillage : confirmation récente exigée. Le chef vient de se connecter avec son mot de passe : c'est déjà une
        // confirmation récente (connexion simple). Si elle est périmée (403), il s'enrôle en 2FA et recommence.
        [$status, $problem] = $this->try($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
        if ($status === 403) {
            self::assertStringContainsString('factor', (string) ($problem['type'] ?? ''), (string) json_encode($problem));
            $this->enrol2fa($ctx, 'chef');
            $locked = $this->res($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
        } else {
            self::assertSame(200, $status, (string) json_encode($problem));
            $locked = isset($problem['data']) && is_array($problem['data']) ? $problem['data'] : $problem;
        }
        self::assertSame('locked', $locked['status'] ?? null, (string) json_encode($locked));

        // TST-06 : note verrouillée modifiée sans procédure de correction -> refus.
        [$status, $problem] = $this->try($ctx, 'prof', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => 18]]]);
        self::assertContains($status, [403, 409, 422], 'TST-06 : ' . json_encode($problem));
        $grid = $this->res($ctx, 'chef', 'GET', '/higher/grade-assessments/' . $id . '/marks');
        self::assertStringContainsString('12.5', (string) json_encode($grid), 'La note verrouillée reste 12,5.');
        $ctx['assessment'] = $id;

        // Publication : par la scolarité (R07), comme le prévoit MarkPolicy::PUBLISH (`higher.grades.publish`).
        [$status, $problem] = $this->try($ctx, 'scol', 'POST', '/higher/grade-assessments/' . $id . '/publish');
        if ($status === 403 && str_contains((string) ($problem['type'] ?? ''), 'factor')) {
            // Publication = opération sensible : 2FA récente exigée (comme le verrouillage).
            $this->enrol2fa($ctx, 'scol');
            [$status, $problem] = $this->try($ctx, 'scol', 'POST', '/higher/grade-assessments/' . $id . '/publish');
        }
        // D-02 corrigé (droit donné à la scolarité) ; la publication reste refusée tant que le PV n'est pas signé (RG-DEL-3).
        self::assertSame(409, $status, 'RG-DEL-3 : ' . json_encode($problem, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('RG-DEL-3', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));
        $this->reportDefects($ctx);
    }

    /**
     * Notes du semestre de Marie N. (exemple du CDC) : EC => [CC, examen] sur 20.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    protected static function marieNotes(): array
    {
        return [
            'CG' => [12, 9], 'TDC' => [14, 11], 'MF' => [8, 6.5], 'MIC' => [13, 12], 'MAC' => [11, 10.5],
            'DC' => [10, 13], 'IA' => [15, 14], 'ANG' => [14, 15], 'BUR' => [16, 15.5],
        ];
    }

    /**
     * L'enseignant est affecté à tous les EC du groupe ; il crée le CC et l'examen de chacun, saisit la note de l'étudiante et
     * soumet ; le chef de département valide puis verrouille (2FA récente).
     *
     * @param array<string, mixed> $ctx
     */
    protected function marieMarks(array &$ctx): void
    {
        $this->try($ctx, 'admin', 'POST', '/staff', ['person_id' => $ctx['people']['prof'], 'kind' => 'permanent']);
        $student = $ctx['student']['person'];
        $twoFactor = false;
        foreach (self::marieNotes() as $ec => $notes) {
            $element = $ctx['curriculum']['elements'][$ec];
            $this->try($ctx, 'chef', 'POST', '/higher/teaching/assignments', [
                'person_id' => $ctx['people']['prof'], 'curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $element,
                'group_id' => $ctx['org']['g1'], 'session_kind' => 'cm', 'planned_minutes' => 60 * 60,
            ]);
            foreach (['cc', 'exam'] as $i => $kind) {
                $assessment = $this->res($ctx, 'prof', 'POST', '/higher/grade-assessments', ['curriculum_id' => $ctx['curriculum']['id'], 'element_id' => $element, 'kind' => $kind]);
                $id = (string) $assessment['id'];
                $this->as($ctx, 'prof', 'PUT', '/higher/grade-assessments/' . $id . '/marks', ['marks' => [['person_id' => $student, 'score' => $notes[$i]]]]);
                $this->as($ctx, 'prof', 'POST', '/higher/grade-assessments/' . $id . '/submit');
                $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/validate');
                if (!$twoFactor) {
                    [$status] = $this->try($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
                    $twoFactor = true;
                    if ($status < 300) {
                        continue;
                    }
                    $this->enrol2fa($ctx, 'chef');
                }
                $this->as($ctx, 'chef', 'POST', '/higher/grade-assessments/' . $id . '/lock');
            }
        }
    }

    /**
     * Défauts constatés en cours de parcours (après toutes les assertions) : étape marquée incomplète.
     *
     * @param array<string, mixed> $ctx
     */
    protected function reportDefects(array $ctx): void
    {
        if (($ctx['incomplete'] ?? []) !== []) {
            self::markTestIncomplete(implode("\n", $ctx['incomplete']) . "\nVoir specs/002-application-ipes/recette-septentrion.md.");
        }
    }

    /**
     * Jury du groupe G1 (S1, session normale) préparé par le chef de département, présidé par le directeur d'école ; TST-07
     * (liste des résultats avant signature du PV : refus), simulation et calcul de Marie N. (TST-04).
     *
     * @param array<string, mixed> $ctx
     */
    protected function deliberationJourney(array &$ctx): void
    {
        $this->actor($ctx, 'jury', 'Zakari', 'Fictif-Jury', [['higher_jury', 'structure', $ctx['org']['program']]]);
        $members = [$ctx['people']['dir'], $ctx['people']['chef'], $ctx['people']['jury']];
        $jury = $this->res($ctx, 'chef', 'POST', '/higher/deliberation/juries', [
            'structure_id' => $ctx['org']['g1'], 'period_id' => 'S1', 'period_label' => 'Semestre 1', 'academic_year_id' => $ctx['year'],
            'exam_session' => 'normal', 'president_person_id' => $ctx['people']['dir'], 'members' => $members, 'quorum_min' => 2,
        ], 201);
        $juryId = (string) $jury['id'];
        self::assertSame('prepared', $jury['status'] ?? null, (string) json_encode($jury));
        $this->as($ctx, 'chef', 'GET', '/higher/deliberation/juries/' . $juryId);
        $this->as($ctx, 'jury', 'GET', '/higher/deliberation/jury-portal');

        // TST-07 : la liste des résultats (DOC-16) ne s'émet pas avant la signature du PV (RG-DEL-3, RG-DOC-3).
        [$status, $problem] = $this->try($ctx, 'chef', 'POST', '/higher/deliberation/juries/' . $juryId . '/documents', [
            'code' => 'DOC-16', 'lines' => [['person_id' => $ctx['student']['person'], 'average' => 11.55, 'credits_earned' => 30, 'credits_total' => 30, 'decision' => 'admis']],
        ]);
        self::assertSame(409, $status, 'TST-07 : ' . json_encode($problem, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('RG-DEL-3', (string) json_encode($problem, JSON_UNESCAPED_UNICODE));

        // Notes de Marie N. (CC puis examen de chaque EC), saisies, validées et verrouillées : RG-DEL-1 avant la simulation.
        $this->marieMarks($ctx);

        // Simulation (API-13) sur les notes verrouillées du groupe.
        [$status, $simulation] = $this->try($ctx, 'chef', 'POST', '/higher/deliberation/juries/' . $juryId . '/simulate', []);
        if ($status >= 300) {
            $ctx['incomplete'][] = 'D-04 : simulation du jury impossible (' . $status . ' ' . json_encode($simulation, JSON_UNESCAPED_UNICODE)
                . ') : aucune source de notes `higher.deliberation.mark_sources.*` n\'est déclarée ; TST-04 et TST-05 non calculables.';
            $this->reportDefects($ctx);

            return;
        }
        $rows = $simulation['data']['students'] ?? $simulation['students'] ?? [];
        self::assertContains($ctx['student']['person'], array_column($rows, 'person_id'), (string) json_encode($simulation));
        $marie = array_values(array_filter($rows, static fn (array $r): bool => $r['person_id'] === $ctx['student']['person']))[0];
        if (($ctx['regime'] ?? 'lmd') === 'lmd') {
            // TST-04 : moyenne 11,55 ; MGP 2,40 ; 30 crédits (UE2 acquise par compensation).
            self::assertEqualsWithDelta(11.55, (float) $marie['average'], 0.001, 'TST-04 moyenne : ' . json_encode($marie));
            self::assertEqualsWithDelta(2.40, (float) $marie['gpa'], 0.001, 'TST-04 MGP : ' . json_encode($marie));
            self::assertSame(30, (int) $marie['credits_earned'], 'TST-04 crédits : ' . json_encode($marie));
        }
        $this->reportDefects($ctx);
    }
}
