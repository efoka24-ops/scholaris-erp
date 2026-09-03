<?php

declare(strict_types=1);

namespace Scholaris\Service;

use RuntimeException;
use Scholaris\Database\Connection;
use Scholaris\Database\Table;
use Scholaris\Tenant\TenantContext;

/**
 * Module 6 : bulletins scolaires.
 *
 * Le bulletin fige un etat : ses notes, moyennes et rangs sont copies dans une
 * colonne data au moment de l'emission. Un recalcul ulterieur ne modifie donc
 * pas un bulletin deja remis a la famille, ce qui serait indefendable.
 *
 * Le document est produit en HTML pret a imprimer plutot qu'en PDF : generer
 * un PDF demanderait une bibliotheque, et l'application n'a aucune dependance.
 * Le navigateur imprime en PDF nativement, ce qui donne le meme resultat sans
 * rien installer sur l'hebergement.
 */
final class BulletinGenerator
{
    private Connection $db;

    private TenantContext $tenant;

    public function __construct(Connection $db, TenantContext $tenant)
    {
        $this->db = $db;
        $this->tenant = $tenant;
    }

    /**
     * Genere les bulletins de toute une classe pour une periode.
     *
     * @return array{generated: int, skipped: int}
     */
    public function generateForClassroom(string $classroomId, string $periodId): array
    {
        $results = $this->db->select(
            'SELECT r.*, s.matricule, s.first_name, s.last_name, s.date_of_birth
             FROM period_results r
             INNER JOIN students s ON s.id = r.student_id
             WHERE r.tenant_id = :tenant AND r.classroom_id = :classroom AND r.period_id = :period
               AND r.deleted_at IS NULL',
            [
                'tenant' => $this->tenant->requireId(),
                'classroom' => $classroomId,
                'period' => $periodId,
            ]
        );

        if ($results === []) {
            throw new RuntimeException(
                'Aucune moyenne calculee pour cette classe et cette periode. Lancez le calcul avant de generer.'
            );
        }

        $generated = 0;
        $skipped = 0;

        foreach ($results as $result) {
            // Un bulletin deja emis n'est pas regenere : il fait foi tel quel.
            $existing = $this->db->scalar(
                'SELECT id FROM bulletins
                 WHERE tenant_id = :tenant AND student_id = :student AND period_id = :period
                   AND deleted_at IS NULL',
                [
                    'tenant' => $this->tenant->requireId(),
                    'student' => $result['student_id'],
                    'period' => $periodId,
                ]
            );

            if ($existing !== null) {
                $skipped++;

                continue;
            }

            $this->create($result, $classroomId, $periodId);
            $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function create(array $result, string $classroomId, string $periodId): void
    {
        $subjects = $this->db->select(
            'SELECT g.calculated_average, g.coefficient, g.weighted_total, g.rank_position,
                    s.name AS subject_name, s.code AS subject_code
             FROM grade_calculations g
             INNER JOIN subjects s ON s.id = g.subject_id
             WHERE g.tenant_id = :tenant AND g.student_id = :student AND g.period_id = :period
               AND g.deleted_at IS NULL
             ORDER BY s.name',
            [
                'tenant' => $this->tenant->requireId(),
                'student' => $result['student_id'],
                'period' => $periodId,
            ]
        );

        $context = $this->db->selectOne(
            'SELECT c.name AS classroom_name, c.section, l.name AS level_name,
                    p.number AS period_number, p.type AS period_type, y.label AS year_label,
                    t.name AS tenant_name, t.address AS tenant_address, t.phone AS tenant_phone
             FROM classrooms c
             INNER JOIN levels l ON l.id = c.level_id
             INNER JOIN periods p ON p.id = :period
             INNER JOIN academic_years y ON y.id = p.academic_year_id
             INNER JOIN tenants t ON t.id = c.tenant_id
             WHERE c.id = :classroom AND c.tenant_id = :tenant',
            [
                'period' => $periodId,
                'classroom' => $classroomId,
                'tenant' => $this->tenant->requireId(),
            ]
        );

        // Snapshot : tout ce qui doit figurer sur le document imprime, fige au
        // moment de l'emission.
        $snapshot = [
            'student' => [
                'matricule' => $result['matricule'],
                'first_name' => $result['first_name'],
                'last_name' => $result['last_name'],
                'date_of_birth' => $result['date_of_birth'],
            ],
            'context' => $context,
            'subjects' => $subjects,
            'summary' => [
                'general_average' => $result['general_average'],
                'rank' => $result['rank_position'],
                'total_students' => $result['total_students'],
                'mention' => $result['mention'],
                'decision' => $result['decision'],
                'teacher_comment' => $result['teacher_comment'],
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->execute(
            'INSERT INTO bulletins (id, tenant_id, student_id, period_id, classroom_id,
                 verification_code, status, data, created_at, updated_at)
             VALUES (:id, :tenant, :student, :period, :classroom, :code, :status, :data, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenant->requireId(),
                'student' => $result['student_id'],
                'period' => $periodId,
                'classroom' => $classroomId,
                'code' => self::verificationCode(),
                'status' => 'draft',
                'data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Code de verification imprime sur le bulletin.
     *
     * Il permet a un tiers (etablissement d'accueil, employeur) de controler
     * l'authenticite d'un document papier. Il doit donc etre imprevisible :
     * un compteur sequentiel se falsifierait trivialement.
     */
    public static function verificationCode(): string
    {
        return strtoupper(bin2hex(random_bytes(6)));
    }
}
