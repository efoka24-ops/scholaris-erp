<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use RuntimeException;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\BulletinGenerator;

/**
 * Module 6 : bulletins et diplomes.
 */
final class BulletinController extends Controller
{
    public function index(Request $request): Response
    {
        $classroomId = $request->string('classroom');
        $periodId = $request->string('period');

        $bulletins = [];

        if ($classroomId !== '' && $periodId !== '') {
            $bulletins = $this->app->db()->select(
                'SELECT b.*, s.matricule, s.first_name, s.last_name
                 FROM bulletins b
                 INNER JOIN students s ON s.id = b.student_id
                 WHERE b.tenant_id = :tenant AND b.classroom_id = :classroom AND b.period_id = :period
                   AND b.deleted_at IS NULL
                 ORDER BY s.last_name, s.first_name',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'classroom' => $classroomId,
                    'period' => $periodId,
                ]
            );
        }

        return $this->view('bulletins.index', [
            'classrooms' => $this->classrooms(),
            'periods' => $this->periods(),
            'classroomId' => $classroomId,
            'periodId' => $periodId,
            'bulletins' => $bulletins,
            'published' => count(array_filter($bulletins, static fn (array $b): bool => $b['status'] !== 'draft')),
        ]);
    }

    public function generate(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $periodId = (string) $request->attribute('period');

        $this->findOrFail('classrooms', $classroomId);

        $generator = new BulletinGenerator($this->app->db(), $this->app->tenant());

        try {
            $result = $generator->generateForClassroom($classroomId, $periodId);
        } catch (RuntimeException $e) {
            return $this->redirectWithError($this->listUrl($classroomId, $periodId), $e->getMessage());
        }

        $message = $result['generated'].' bulletin(s) genere(s).';

        if ($result['skipped'] > 0) {
            // Un bulletin deja emis n'est pas reecrit : il fait foi tel quel.
            $message .= ' '.$result['skipped'].' deja emis, laisse(s) inchange(s).';
        }

        return $this->redirectWithSuccess($this->listUrl($classroomId, $periodId), $message);
    }

    /**
     * Bulletin pret a imprimer.
     *
     * Le navigateur se charge de la conversion en PDF : produire le PDF
     * cote serveur exigerait une bibliotheque, et l'application n'a aucune
     * dependance.
     */
    public function show(Request $request): Response
    {
        $bulletin = $this->findOrFail('bulletins', (string) $request->attribute('id'));

        $data = json_decode((string) $bulletin['data'], true);

        if (! is_array($data)) {
            throw new HttpException(500, 'Bulletin illisible.');
        }

        return $this->view('bulletins.show', [
            'bulletin' => $bulletin,
            'data' => $data,
        ]);
    }

    /**
     * Publication : le bulletin devient visible des familles.
     */
    public function publish(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $periodId = (string) $request->attribute('period');

        $this->findOrFail('classrooms', $classroomId);

        $count = $this->table('bulletins')
            ->where('classroom_id', $classroomId)
            ->where('period_id', $periodId)
            ->where('status', 'draft')
            ->update(['status' => 'published', 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->redirectWithSuccess(
            $this->listUrl($classroomId, $periodId),
            $count.' bulletin(s) publie(s). Les familles peuvent desormais les consulter.'
        );
    }

    /**
     * Verification publique d'un bulletin par son code.
     *
     * Ne revele que le strict necessaire pour authentifier le document :
     * eleve, classe, periode, moyenne. Pas le detail des notes, qui ne regarde
     * pas le tiers qui verifie.
     */
    public function verify(Request $request): Response
    {
        $code = strtoupper(trim($request->string('code')));
        $bulletin = null;

        if ($code !== '') {
            $bulletin = $this->app->tenant()->global(fn () => $this->app->db()->selectOne(
                'SELECT b.*, s.first_name, s.last_name, s.matricule, t.name AS tenant_name
                 FROM bulletins b
                 INNER JOIN students s ON s.id = b.student_id
                 INNER JOIN tenants t ON t.id = b.tenant_id
                 WHERE b.verification_code = :code AND b.deleted_at IS NULL AND b.status <> :draft',
                ['code' => $code, 'draft' => 'draft']
            ));
        }

        return $this->view('bulletins.verify', [
            'code' => $code,
            'bulletin' => $bulletin,
            'searched' => $code !== '',
        ]);
    }

    private function listUrl(string $classroomId, string $periodId): string
    {
        return '/bulletins?classroom='.urlencode($classroomId).'&period='.urlencode($periodId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classrooms(): array
    {
        return $this->app->db()->select(
            'SELECT c.id, c.name, l.name AS level_name FROM classrooms c
             INNER JOIN levels l ON l.id = c.level_id
             WHERE c.tenant_id = :tenant ORDER BY l.sort_order, c.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function periods(): array
    {
        return $this->app->db()->select(
            'SELECT p.* FROM periods p
             INNER JOIN academic_years y ON y.id = p.academic_year_id
             WHERE y.tenant_id = :tenant AND y.status = :status
             ORDER BY p.number',
            ['tenant' => $this->app->tenant()->requireId(), 'status' => 'ACTIVE']
        );
    }
}
