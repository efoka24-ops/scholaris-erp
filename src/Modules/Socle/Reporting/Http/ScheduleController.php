<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Reporting\Application\RegulatoryStatistics;
use Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService;
use Scholaris\Modules\Socle\Reporting\Domain\Frequency;
use Scholaris\Modules\Socle\Reporting\Domain\ReportType;

/**
 * Rapports planifiés (reporting.manage) et statistiques réglementaires (reporting.read). Établissement actif exigé.
 * Un rapport de type « groupe » exige en plus reporting.group.read.
 */
final class ScheduleController
{
    public const MANAGE = 'reporting.manage';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
        private readonly ScheduledReportService $reports,
        private readonly RegulatoryStatistics $regulatory,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);
        $query = ListQuery::fromRequest($request, [], []);
        [$items, $next] = $this->reports->list($this->context->tenantId(), $this->context->establishmentId(), ListQuery::decodeCursor($query->cursor), $query->perPage);
        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = ListQuery::encodeCursor($next);
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json(['data' => $items, 'links' => $links, 'meta' => ['next_cursor' => $next === null ? null : ListQuery::encodeCursor($next)]]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);

        return $this->responder->json(['data' => $this->reports->get($this->context->tenantId(), $this->context->establishmentId(), $this->id($request))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->requireManage($request);
        $data = $this->validate($this->body($request), true);
        if ($data['report_type'] === ReportType::Groupe->value) {
            $this->requireGroup($principal);
        }
        $row = $this->reports->create($this->context->tenantId(), $this->context->establishmentId(), $data, $principal->userId);
        $this->journal($request, $principal, 'report_schedule.created', (string) $row['id'], null, $row);

        return $this->responder->json(['data' => $row], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->requireManage($request);
        $id = $this->id($request);
        $before = $this->reports->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        if ($before['report_type'] === ReportType::Groupe->value) {
            $this->requireGroup($principal);
        }
        $row = $this->reports->update($this->context->tenantId(), $this->context->establishmentId(), $id, $this->validate($this->body($request), false));
        $this->journal($request, $principal, 'report_schedule.updated', $id, $before, $row);

        return $this->responder->json(['data' => $row]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->requireManage($request);
        $id = $this->id($request);
        $before = $this->reports->get($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->reports->delete($this->context->tenantId(), $this->context->establishmentId(), $id);
        $this->journal($request, $principal, 'report_schedule.deleted', $id, $before, null);

        return $this->responder->json(['data' => ['deleted' => true]]);
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->requireManage($request);
        $id = $this->id($request);
        $result = $this->reports->runNow($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId);

        return $this->responder->json(['data' => $result]);
    }

    public function runs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireManage($request);

        return $this->responder->json(['data' => $this->reports->runs($this->context->tenantId(), $this->context->establishmentId(), $this->id($request))]);
    }

    public function regulatory(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $establishmentId = $this->context->establishmentId();
        if (!$this->permissions->can($principal, DashboardController::READ, $establishmentId)) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de consulter les statistiques de cet établissement.');
        }

        return $this->responder->json(['data' => $this->regulatory->build($this->context->tenantId(), $establishmentId)]);
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    private function requireManage(ServerRequestInterface $request): Principal
    {
        $principal = $this->principal($request);
        if (!$this->permissions->can($principal, self::MANAGE, $this->context->establishmentId())) {
            throw HttpException::forbidden('Vous n\'avez pas le droit de gérer les rapports planifiés.');
        }

        return $principal;
    }

    private function requireGroup(Principal $principal): void
    {
        if (!$this->permissions->can($principal, DashboardController::GROUP_READ, null)) {
            throw HttpException::forbidden('La vue consolidée est réservée à la direction de groupe.');
        }
    }

    private function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        $data = trim($raw) === '' ? null : json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $rules = [
            'name' => static function (mixed $v): string {
                if (!is_string($v) || trim($v) === '' || mb_strlen(trim($v)) > 120) {
                    throw new InvalidArgumentException('Texte de 1 à 120 caractères attendu.');
                }

                return trim($v);
            },
            'frequency' => self::enum(Frequency::values()),
            'run_day' => static function (mixed $v): int {
                if (!is_int($v) || $v < 1 || $v > 28) {
                    throw new InvalidArgumentException('Entier entre 1 et 28 attendu.');
                }

                return $v;
            },
            'recipient_ids' => static function (mixed $v): array {
                if (!is_array($v) || !array_is_list($v) || $v === []) {
                    throw new InvalidArgumentException('Liste non vide d\'identifiants de personnes attendue.');
                }
                foreach ($v as $item) {
                    if (!is_string($item) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $item) !== 1) {
                        throw new InvalidArgumentException('Identifiant de personne invalide.');
                    }
                }

                return $v;
            },
        ];
        if ($creating) {
            $rules['report_type'] = self::enum(ReportType::values());
        } else {
            $rules['status'] = self::enum(['active', 'paused']);
        }
        $required = $creating ? ['name', 'report_type', 'frequency', 'recipient_ids'] : [];
        $nullable = ['run_day'];

        $errors = [];
        $values = [];
        foreach (array_keys($data) as $name) {
            if (!isset($rules[$name])) {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        foreach ($rules as $name => $rule) {
            if (!array_key_exists($name, $data)) {
                if (in_array($name, $required, true)) {
                    $errors[$name] = ['Ce champ est obligatoire.'];
                }
                continue;
            }
            if ($data[$name] === null && in_array($name, $nullable, true)) {
                $values[$name] = null;
                continue;
            }
            try {
                $values[$name] = $rule($data[$name]);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = [$e->getMessage()];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $values;
    }

    /**
     * @param list<string> $allowed
     * @return callable(mixed): string
     */
    private static function enum(array $allowed): callable
    {
        return static function (mixed $v) use ($allowed): string {
            if (!is_string($v) || !in_array($v, $allowed, true)) {
                throw new InvalidArgumentException('Valeur non reconnue. Valeurs possibles : ' . implode(', ', $allowed) . '.');
            }

            return $v;
        };
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function journal(ServerRequestInterface $request, Principal $principal, string $action, string $id, ?array $before, ?array $after): void
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $device = $request->getHeaderLine('User-Agent');
        $this->audit->append(
            $this->context->tenantId(),
            $principal->userId,
            $action,
            'report_schedule',
            $id,
            $before,
            $after,
            is_string($remote) ? $remote : null,
            $device === '' ? null : mb_substr($device, 0, 255),
        );
    }
}
