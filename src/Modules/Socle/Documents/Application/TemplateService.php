<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Domain\NumberPattern;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Infrastructure\TemplateRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;

/** Types de document et modèles HTML versionnés : variables en liste blanche, validation avant emploi, jamais de modification d'une version validée. */
final class TemplateService
{
    public function __construct(
        private readonly TypeRepository $types,
        private readonly TemplateRepository $templates,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input code, label, category, type_letter, number_template?, qr_enabled?, blockable?, variables?
     * @return array<string, mixed>
     */
    public function createType(string $tenantId, string $establishmentId, string $actor, array $input): array
    {
        $this->assertNumberPattern((string) ($input['number_template'] ?? NumberPattern::DEFAULT));
        $variables = $this->assertVariables((array) ($input['variables'] ?? []));
        if ($this->types->existsOwn($tenantId, $establishmentId, (string) $input['code'])) {
            throw HttpException::conflict('Un type de document porte déjà ce code dans cet établissement.');
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->types->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'code' => (string) $input['code'],
            'label' => (string) $input['label'],
            'category' => (string) $input['category'],
            'type_letter' => (string) $input['type_letter'],
            'number_template' => (string) ($input['number_template'] ?? NumberPattern::DEFAULT),
            'qr_enabled' => ($input['qr_enabled'] ?? true) === true ? 1 : 0,
            'blockable' => ($input['blockable'] ?? false) === true ? 1 : 0,
            'variables' => json_encode($variables, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'signer_role' => isset($input['signer_role']) ? (string) $input['signer_role'] : null,
            'updated_at' => $now,
        ]);
        $this->audit->append($tenantId, $actor, 'document_type.created', 'document_type', $id, null, ['code' => $input['code']]);

        return $this->types->findById($tenantId, $establishmentId, $id) ?? [];
    }

    /**
     * @param array<string, mixed> $changes label, category, number_template, qr_enabled, blockable, variables
     * @return array<string, mixed>
     */
    public function updateType(string $tenantId, string $establishmentId, string $actor, string $id, array $changes): array
    {
        $type = $this->types->findById($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Type de document introuvable.');
        if ($type['system'] === true) {
            throw HttpException::forbidden('Les types fournis par la plateforme ne se modifient pas : créez un type du même code dans votre établissement.');
        }
        $values = [];
        foreach (['label', 'category'] as $key) {
            if (isset($changes[$key])) {
                $values[$key] = (string) $changes[$key];
            }
        }
        if (isset($changes['number_template'])) {
            $this->assertNumberPattern((string) $changes['number_template']);
            $values['number_template'] = (string) $changes['number_template'];
        }
        foreach (['qr_enabled', 'blockable'] as $key) {
            if (isset($changes[$key])) {
                $values[$key] = $changes[$key] === true ? 1 : 0;
            }
        }
        if (isset($changes['signer_role'])) {
            $values['signer_role'] = $changes['signer_role'] === '' ? null : (string) $changes['signer_role'];
        }
        if (isset($changes['variables'])) {
            $values['variables'] = json_encode($this->assertVariables((array) $changes['variables']), JSON_THROW_ON_ERROR);
        }
        if ($values !== []) {
            $values['updated_at'] = $this->clock->now();
            $this->types->update($tenantId, $establishmentId, $id, $values);
            $this->audit->append($tenantId, $actor, 'document_type.updated', 'document_type', $id, null, ['changed' => array_keys($values)]);
        }

        return $this->types->findById($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> brouillon (version suivante du couple type + langue) */
    public function createTemplate(string $tenantId, string $establishmentId, string $actor, string $typeCode, string $locale, string $html): array
    {
        $type = $this->types->find($tenantId, $establishmentId, $typeCode)
            ?? throw HttpException::validation(['type_code' => ['Type de document inconnu : ' . $typeCode . '.']]);
        /** @var list<string> $declared */
        $declared = $type['variables'];
        TemplateGuard::assertValid($html, $declared);

        $id = (string) new Ulid();
        $this->templates->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'type_code' => $typeCode,
            'locale' => $locale,
            'html' => $html,
            'version' => $this->templates->nextVersion($tenantId, $establishmentId, $typeCode, $locale),
            'validated_at' => null,
            'created_by' => $actor,
            'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $actor, 'document_template.created', 'document_template', $id, null, ['type' => $typeCode, 'locale' => $locale]);

        return $this->templates->find($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> */
    public function updateTemplate(string $tenantId, string $establishmentId, string $actor, string $id, string $html): array
    {
        $template = $this->editable($tenantId, $establishmentId, $id);
        TemplateGuard::assertValid($html, $this->declaredFor($tenantId, $establishmentId, (string) $template['type_code']));
        $this->templates->updateHtml($tenantId, $establishmentId, $id, $html);
        $this->audit->append($tenantId, $actor, 'document_template.updated', 'document_template', $id, null, ['version' => (int) $template['version']]);

        return $this->templates->find($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> */
    public function validate(string $tenantId, string $establishmentId, string $actor, string $id): array
    {
        $template = $this->editable($tenantId, $establishmentId, $id);
        TemplateGuard::assertValid((string) $template['html'], $this->declaredFor($tenantId, $establishmentId, (string) $template['type_code']));
        $this->templates->validate($tenantId, $establishmentId, $id, $this->clock->now());
        $this->audit->append($tenantId, $actor, 'document_template.validated', 'document_template', $id, null, ['version' => (int) $template['version']]);

        return $this->templates->find($tenantId, $establishmentId, $id) ?? [];
    }

    /** @return array<string, mixed> nouvelle version (brouillon) à partir d'un modèle existant, du client ou de la plateforme */
    public function newVersion(string $tenantId, string $establishmentId, string $actor, string $id, ?string $html): array
    {
        $base = $this->templates->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Modèle introuvable.');

        return $this->createTemplate($tenantId, $establishmentId, $actor, (string) $base['type_code'], (string) $base['locale'], $html ?? (string) $base['html']);
    }

    public function delete(string $tenantId, string $establishmentId, string $actor, string $id): void
    {
        $template = $this->editable($tenantId, $establishmentId, $id);
        if ($this->templates->isUsed($tenantId, $id)) {
            throw HttpException::conflict('Ce modèle a servi à émettre des documents : il ne peut pas être supprimé.');
        }
        $this->templates->delete($tenantId, $establishmentId, $id);
        $this->audit->append($tenantId, $actor, 'document_template.deleted', 'document_template', $id, ['version' => (int) $template['version']], null);
    }

    /** @return array<string, mixed> brouillon appartenant à l'établissement */
    private function editable(string $tenantId, string $establishmentId, string $id): array
    {
        $template = $this->templates->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Modèle introuvable.');
        if ($template['tenant_id'] === '*') {
            throw HttpException::forbidden('Les modèles de la plateforme ne se modifient pas : créez-en une nouvelle version dans votre établissement.');
        }
        if ($template['validated_at'] !== null) {
            throw HttpException::conflict('Une version validée ne se modifie plus : créez une nouvelle version.');
        }

        return $template;
    }

    /** @return list<string> */
    private function declaredFor(string $tenantId, string $establishmentId, string $typeCode): array
    {
        $type = $this->types->find($tenantId, $establishmentId, $typeCode)
            ?? throw HttpException::validation(['type_code' => ['Type de document inconnu : ' . $typeCode . '.']]);
        /** @var list<string> $declared */
        $declared = $type['variables'];

        return $declared;
    }

    private function assertNumberPattern(string $pattern): void
    {
        if (!NumberPattern::isValid($pattern)) {
            throw HttpException::validation(['number_template' => ['Gabarit invalide. Jetons permis : {CODE} {AA} {AAAA} {LETTRE} {N4} à {N9} ; au moins un compteur {Nx}.']]);
        }
    }

    /**
     * @param array<mixed> $variables
     * @return list<string>
     */
    private function assertVariables(array $variables): array
    {
        $clean = [];
        foreach ($variables as $name) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
                throw HttpException::validation(['variables' => ['Nom de variable invalide (minuscules, chiffres et _ ; 40 caractères au plus).']]);
            }
            if (in_array($name, TemplateGuard::SYSTEM, true)) {
                throw HttpException::validation(['variables' => ['« ' . $name . ' » est une variable réservée du moteur.']]);
            }
            $clean[$name] = $name;
        }

        return array_values($clean);
    }
}
