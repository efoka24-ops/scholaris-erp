<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;

/**
 * Aperçu du bulletin d'un élève (écran du bulletin) : variables `secondary_*` produites à la demande (avant émission) et dernier
 * instantané enregistré à l'émission. Classe du conseil dans le périmètre de lecture ; un conseil d'un autre client est introuvable.
 */
final class ReportCardPreview
{
    public const READ = ['secondary.reportcard.read', 'secondary.reportcard.validate', 'secondary.council.prepare', 'secondary.council.preside'];

    public function __construct(
        private readonly Access $access,
        private readonly CouncilDirectory $councils,
        private readonly ReportCardBuilder $builder,
        private readonly Store $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $councilId, string $personId, ?string $locale): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $council = $this->councils->find($principal->tenantId, $establishmentId, $councilId) ?? throw HttpException::notFound('Conseil de classe introuvable.');
        $this->access->assertReadableClass($perimeter, $council['structure_id']);
        if (!in_array($personId, array_column($council['lines'], 'person_id'), true)) {
            throw HttpException::notFound('Élève introuvable dans ce conseil.');
        }
        $variables = $this->builder->build($principal->tenantId, $establishmentId, $councilId, $personId, $locale);
        if ($variables === []) {
            throw HttpException::notFound('Ce conseil n\'est pas celui d\'une classe du secondaire.');
        }
        $snapshot = $this->store->select(SecondaryReportCardVariables::TABLE, $principal->tenantId, $establishmentId, ['council_id' => $councilId, 'person_id' => $personId], 'built_at DESC', 1)[0] ?? null;

        return [
            'council_id' => $councilId, 'person_id' => $personId, 'status' => $council['status'], 'variables' => $variables,
            'snapshot' => $snapshot === null ? null : ['built_at' => (string) $snapshot['built_at'], 'variables' => json_decode((string) $snapshot['variables'], true)],
        ];
    }
}
