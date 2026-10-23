<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassInfo;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure\PdoMessageBatches;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * SMS d'une école aux familles d'une classe, d'un niveau ou de toute l'école (T640, EX-COM-01). Les modèles sont des données bilingues
 * du socle (`pub.class_notice`, `pub.not14.parent`…) ; la liste des modèles diffusables est `public_schools.messages.broadcast_templates`.
 * Un enseignant écrit à sa classe seulement ; un niveau ou l'école entière relève du directeur. Idempotent par `client_uuid` : un
 * rejeu ne renvoie rien et rend l'envoi d'origine. Le coût des SMS n'est jamais facturé au parent (RG-COM-1).
 */
final class BroadcastService
{
    public const AUDIENCES = ['class', 'level', 'school'];
    /** Variables que le client peut fournir (sous-ensemble de la liste blanche du socle) ; les valeurs sont nettoyées par le socle. */
    public const CLIENT_VARIABLES = ['details', 'date', 'time', 'place', 'label', 'exam_label', 'school_year'];
    private const MAX_VALUE = 200;
    private const DEFAULT_TEMPLATES = ['pub.class_notice', 'pub.not14.parent'];

    public function __construct(
        private readonly PublicMessages $messages,
        private readonly ClassRoster $classes,
        private readonly CurrentPeriod $periods,
        private readonly PdoMessageBatches $batches,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
        private readonly ScopedByTerritory $scope,
        private readonly MessageClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input audience, target, template_key, variables, client_uuid
     * @return array{batch: array<string, mixed>, replayed: bool}
     */
    public function send(Principal $principal, Perimeter $perimeter, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $audience = is_string($input['audience'] ?? null) ? $input['audience'] : '';
        $target = is_string($input['target'] ?? null) && trim($input['target']) !== '' ? trim($input['target']) : null;
        $templateKey = is_string($input['template_key'] ?? null) ? trim($input['template_key']) : '';
        $clientUuid = is_string($input['client_uuid'] ?? null) && trim($input['client_uuid']) !== '' ? trim($input['client_uuid']) : null;
        $errors = [];
        if (!in_array($audience, self::AUDIENCES, true)) {
            $errors['audience'][] = 'Valeur attendue : ' . implode(', ', self::AUDIENCES) . '.';
        }
        if ($audience !== 'school' && $target === null) {
            $errors['target'][] = 'La classe ou le niveau visé est requis.';
        }
        if (!in_array($templateKey, $this->templates($tenantId, $establishmentId), true)) {
            $errors['template_key'][] = 'Modèle non diffusable aux familles.';
        }
        if ($clientUuid !== null && strlen($clientUuid) > 36) {
            $errors['client_uuid'][] = '36 caractères au plus.';
        }
        $variables = self::variables($input['variables'] ?? [], $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        if ($clientUuid !== null) {
            $existing = $this->batches->findByClientUuid($tenantId, $establishmentId, $clientUuid);
            if ($existing !== null) {
                return ['batch' => self::resource($existing), 'replayed' => true];
            }
        }

        $targets = $this->targets($tenantId, $establishmentId, $audience, $target);
        foreach ($targets as $class) {
            $this->scope->assertWritable($principal, $perimeter, $class->structureId, 'message_batch', $class->structureId);
        }
        if ($audience !== 'class' && !$perimeter->wholeSchool) {
            $this->scope->assertWritable($principal, $perimeter, null, 'message_batch', $audience);
            throw HttpException::forbidden('Un message à un niveau ou à toute l’école est envoyé par le directeur.');
        }

        $recipients = 0;
        foreach ($targets as $class) {
            $recipients += $this->messages->notifyClass($tenantId, $establishmentId, $class->structureId, $templateKey, $variables);
        }
        $batch = $this->batches->insert(
            $tenantId,
            $establishmentId,
            $audience,
            $target,
            $templateKey,
            $variables,
            array_map(static fn (ClassInfo $class): string => $class->structureId, $targets),
            $recipients,
            $principal->userId,
            $clientUuid,
            $this->clock->now(),
        );
        $this->audit->append($tenantId, $principal->userId, 'public_schools.message.sent', 'message_batch', (string) $batch['id'], null, [
            'audience' => $audience, 'target' => $target, 'template_key' => $templateKey, 'classes' => count($targets), 'recipients' => $recipients,
        ]);

        return ['batch' => $batch, 'replayed' => false];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $variables = $row['variables'] ?? [];
        if (is_string($variables)) {
            $decoded = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (string) $row['id'],
            'audience' => (string) $row['audience'],
            'target' => $row['target'],
            'template_key' => (string) $row['template_key'],
            'variables' => $variables,
            'classes_count' => (int) $row['classes_count'],
            'recipients_count' => (int) $row['recipients_count'],
            'sent_by' => (string) $row['sent_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return list<ClassInfo> */
    private function targets(string $tenantId, string $establishmentId, string $audience, ?string $target): array
    {
        if ($audience === 'class') {
            $class = $this->classes->find($tenantId, $establishmentId, (string) $target);
            if ($class === null) {
                throw HttpException::notFound('Classe introuvable dans cet établissement.');
            }

            return [$class];
        }
        $period = $this->periods->at($tenantId, $establishmentId, $this->clock->today());
        if ($period === null) {
            throw HttpException::validation(['audience' => ['Aucune période scolaire en cours : les classes ne sont pas déterminées.']]);
        }
        $classes = $this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId);
        if ($audience === 'level') {
            $classes = array_values(array_filter($classes, static fn (ClassInfo $class): bool => in_array($target, $class->levelCodes, true)));
            if ($classes === []) {
                throw HttpException::notFound('Aucune classe de ce niveau.');
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function templates(string $tenantId, string $establishmentId): array
    {
        $value = $this->settings->value($tenantId, $establishmentId, 'public_schools.messages.broadcast_templates', self::DEFAULT_TEMPLATES);

        return is_array($value) ? array_values(array_map('strval', $value)) : self::DEFAULT_TEMPLATES;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array<string, scalar>
     */
    private static function variables(mixed $value, array &$errors): array
    {
        if (!is_array($value)) {
            $errors['variables'][] = 'Objet attendu.';

            return [];
        }
        $clean = [];
        foreach ($value as $name => $item) {
            if (!is_string($name) || !in_array($name, self::CLIENT_VARIABLES, true)) {
                $errors['variables'][] = 'Variable non autorisée : ' . (string) $name . '.';
                continue;
            }
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > self::MAX_VALUE) {
                $errors['variables.' . $name][] = 'Texte de ' . self::MAX_VALUE . ' caractères au plus attendu.';
                continue;
            }
            $clean[$name] = trim($item);
        }

        return $clean;
    }
}
