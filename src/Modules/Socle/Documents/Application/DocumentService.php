<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Application;

use PDO;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentReissuer;
use Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssuanceContext;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Documents\Contracts\IssueOptions;
use Scholaris\Modules\Socle\Documents\Domain\LightPdfProfile;
use Scholaris\Modules\Socle\Documents\Domain\NameMask;
use Scholaris\Modules\Socle\Documents\Domain\NumberPattern;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Domain\TemplateGuard;
use Scholaris\Modules\Socle\Documents\Domain\TemplateRenderer;
use Scholaris\Modules\Socle\Documents\Domain\VerificationToken;
use Scholaris\Modules\Socle\Documents\Infrastructure\DocumentRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\SealRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\SignerRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TemplateRepository;
use Scholaris\Modules\Socle\Documents\Infrastructure\TypeRepository;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Émission, révocation, duplicata et impression des documents officiels.
 *
 * Le numéro est pris dans la transaction qui insère le document : si l'émission échoue (rendu, QR, insertion),
 * la transaction est annulée et le numéro n'est pas consommé. Le contenu rendu est figé à l'émission
 * (version de modèle conservée) et son empreinte SHA-256 est enregistrée.
 */
final class DocumentService implements ExtendedDocumentIssuer, DocumentReissuer
{
    public const LOCALES = ['fr', 'en'];
    public const BLOCKED_REASON = 'Pension impayée : blocage financier paramétré pour les documents.';
    public const RECTIFY_SAME_NUMBER = 'same_number';
    public const RECTIFY_NEW_NUMBER = 'new_number';

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly TypeRepository $types,
        private readonly TemplateRepository $templates,
        private readonly DocumentRepository $documents,
        private readonly SealRepository $seals,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly QrCodeGenerator $qr,
        private readonly PdfRenderer $pdf,
        private readonly AuditLog $audit,
        private readonly DocumentClock $clock,
        private readonly SignerRepository $signers,
        private readonly IssuanceGuards $guards,
        private readonly DebtorDirectory $debtors,
        private readonly SettingsReader $settings,
    ) {
    }

    public function issueWithOptions(
        string $tenantId,
        string $establishmentId,
        string $type,
        string $subjectPersonId,
        array $data,
        string $issuedBy,
        string $locale,
        IssueOptions $options,
    ): IssuedDocument {
        return $this->issueVia($tenantId, $establishmentId, $type, $subjectPersonId, $data, $issuedBy, $locale, 'api', null, $options);
    }

    public function issue(
        string $tenantId,
        string $establishmentId,
        string $type,
        string $subjectPersonId,
        array $data,
        string $issuedBy,
        string $locale = 'fr',
    ): IssuedDocument {
        return $this->issueVia($tenantId, $establishmentId, $type, $subjectPersonId, $data, $issuedBy, $locale, 'api');
    }

    /**
     * @param array<string, scalar|null> $data
     * @param (callable(string): void)|null $within exécuté dans la transaction d'émission (reçoit l'identifiant du document)
     * @param array{number: string, revision: int, of: string, reason: string, new_number?: bool}|null $reissue réédition : numéro conservé (pas de
     *        nouveau numéro de séquence), ou nouveau numéro portant la mention du numéro d'origine si `new_number` (SS12)
     */
    public function issueVia(
        string $tenantId,
        string $establishmentId,
        string $type,
        string $subjectPersonId,
        array $data,
        string $issuedBy,
        string $locale,
        string $via,
        ?callable $within = null,
        ?IssueOptions $options = null,
        ?array $reissue = null,
    ): IssuedDocument {
        if (!in_array($locale, self::LOCALES, true)) {
            throw HttpException::validation(['locale' => ['Langue non prise en charge : fr ou en.']]);
        }
        $establishment = $this->establishments->find($tenantId, $establishmentId) ?? throw HttpException::notFound('Établissement introuvable.');
        $definition = $this->types->find($tenantId, $establishmentId, $type)
            ?? throw HttpException::validation(['type' => ['Type de document inconnu : ' . $type . '.']]);

        /** @var list<string> $declared */
        $declared = $definition['variables'];
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $declared));
        if ($unknown !== []) {
            throw HttpException::validation(['data' => ['Variables non déclarées pour ce type : ' . implode(', ', $unknown) . '.']]);
        }
        $values = [];
        foreach ($data as $name => $value) {
            if (!self::isSimpleValue($value)) {
                throw HttpException::validation(['data.' . $name => ['Valeur simple attendue (texte, nombre ou booléen).']]);
            }
            $values[(string) $name] = (string) $value;
        }

        $template = $this->templates->latestValidated($tenantId, $establishmentId, $type, $locale)
            ?? ($locale !== 'fr' ? $this->templates->latestValidated($tenantId, $establishmentId, $type, 'fr') : null)
            ?? throw HttpException::validation(['type' => ['Aucun modèle validé pour ce type de document. Validez un modèle avant d\'émettre.']]);
        TemplateGuard::assertValid((string) $template['html'], $declared);

        $person = $this->people->find($tenantId, $subjectPersonId)
            ?? throw HttpException::validation(['subject_person_id' => ['Personne introuvable dans ce client.']]);

        $options ??= new IssueOptions();
        $pattern = (string) $definition['number_template'];
        $school = $this->schoolCode($pattern, $options);
        $this->assertIssuable($tenantId, $establishmentId, $type, $subjectPersonId, $values, $locale, $issuedBy, $via, $definition, $options);

        $signerRole = $options->signerRole ?? (isset($definition['signer_role']) ? (string) $definition['signer_role'] : null);
        $signer = $signerRole !== null ? $this->signers->resolve($tenantId, $establishmentId, $signerRole, $options->orgUnitId) : null;
        $seal = $signer ?? $this->seals->find($tenantId, $establishmentId);
        $year = $this->clock->year();
        $id = (string) new Ulid();
        $token = VerificationToken::generate();
        $url = $this->clock->verificationBase() . $token;
        $issuedAt = $this->clock->now();
        $holder = $person->fullName();
        $kind = $school === null ? 'doc:' . $type : self::schoolKind($type, $school);
        $extra = array_filter(['org_unit_id' => $options->orgUnitId, 'signer_role' => $signer !== null ? $signerRole : null], static fn (?string $v): bool => $v !== null);

        $renumbered = $reissue !== null && ($reissue['new_number'] ?? false) === true;
        $result = $this->sequences->transaction(function (PDO $pdo) use (
            $tenantId,
            $establishmentId,
            $definition,
            $template,
            $establishment,
            $person,
            $holder,
            $values,
            $seal,
            $year,
            $id,
            $token,
            $url,
            $issuedAt,
            $issuedBy,
            $via,
            $within,
            $type,
            $subjectPersonId,
            $pattern,
            $kind,
            $locale,
            $school,
            $extra,
            $reissue,
            $renumbered,
        ): array {
            if ($reissue === null || $renumbered) {
                $counter = $this->sequences->next($tenantId, $establishmentId, $kind, $year);
                $number = NumberPattern::format(
                    $pattern,
                    NumberPattern::establishmentCode($establishment->name),
                    $year,
                    (string) $definition['type_letter'],
                    $counter,
                    $school,
                );
            } else {
                $number = $reissue['number'];
            }

            $html = (string) $template['html'];
            [$safe, $signedAt] = $this->safeFragments($html, $definition['qr_enabled'] === true, $url, $seal, $issuedAt, $locale);
            $content = TemplateRenderer::render($html, $values + [
                'number' => $number,
                'verification_url' => $url,
                'holder_name' => $holder,
                'establishment_name' => $establishment->name,
                'issued_on' => $this->clock->moment()->format($locale === 'fr' ? 'd/m/Y' : 'Y-m-d'),
                'year' => (string) $year,
            ], $safe);
            if ($reissue !== null) {
                $content = self::withRectificatif($content, $locale, $reissue['reason'], $this->clock->moment(), $renumbered ? $reissue['number'] : null);
                $extra += ['revision' => $reissue['revision'], 'reissue_of' => $reissue['of']];
            }
            $this->documents->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'establishment_id' => $establishmentId,
                'type_code' => $type,
                'category' => (string) $definition['category'],
                'number' => $number,
                'verification_token' => $token,
                'fingerprint' => hash('sha256', $content),
                'status' => IssuedDocument::VALID,
                'template_id' => (string) $template['id'],
                'template_version' => (int) $template['version'],
                'locale' => $locale,
                'subject_person_id' => $subjectPersonId,
                'holder_name' => $holder,
                'holder_mask' => NameMask::mask($person->firstName, $person->lastName),
                'data_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'content' => $content,
                'issued_by' => $issuedBy,
                'issued_via' => $via,
                'issued_at' => $issuedAt,
                'signed_at' => $signedAt,
            ] + $extra);
            if ($within !== null) {
                $within($id);
            }

            return ['number' => $number, 'fingerprint' => hash('sha256', $content)];
        });
        /** @var array{number: string, fingerprint: string} $result */
        $this->audit->append($tenantId, $issuedBy, 'document.issued', 'document', $id, null, [
            'number' => $result['number'], 'type' => $type, 'subject_person_id' => $subjectPersonId, 'via' => $via,
            'template_version' => (int) $template['version'],
        ]);

        return new IssuedDocument($id, $result['number'], $token, IssuedDocument::VALID, $type, $subjectPersonId);
    }

    public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy, ?string $replacedBy = null): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Un motif de 1 à 500 caractères est obligatoire pour révoquer un document.']]);
        }
        $document = $this->documents->find($tenantId, $documentId) ?? throw HttpException::notFound('Document introuvable.');
        if ($document['status'] === IssuedDocument::REVOKED) {
            throw HttpException::conflict('Ce document est déjà révoqué.');
        }
        if ($replacedBy !== null) {
            $replacement = $this->documents->find($tenantId, $replacedBy);
            if ($replacement === null || $replacement['id'] === $documentId || $replacement['status'] !== IssuedDocument::VALID
                || $replacement['subject_person_id'] !== $document['subject_person_id']) {
                throw HttpException::validation(['replaced_by' => ['Le document de remplacement doit être un autre document valide du même titulaire.']]);
            }
        }

        $at = $this->clock->now();
        if ($this->documents->markRevoked($tenantId, $documentId, $reason, $revokedBy, $at, $replacedBy) === 0) {
            throw HttpException::conflict('Ce document est déjà révoqué.');
        }
        $this->audit->append(
            $tenantId,
            $revokedBy,
            'document.revoked',
            'document',
            $documentId,
            ['status' => IssuedDocument::VALID],
            ['status' => IssuedDocument::REVOKED, 'reason' => $reason, 'replaced_by' => $replacedBy],
        );
    }

    public function reissue(string $tenantId, string $establishmentId, string $documentId, array $data, string $reason, string $reissuedBy): IssuedDocument
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Un motif de 1 à 500 caractères est obligatoire pour rectifier un document.']]);
        }
        $old = $this->documents->find($tenantId, $documentId, $establishmentId) ?? throw HttpException::notFound('Document introuvable.');
        if ($old['status'] !== IssuedDocument::VALID) {
            throw HttpException::conflict('Seul un document valide peut être rectifié.');
        }
        $stored = json_decode((string) $old['data_json'], true);
        $values = $data === [] ? (is_array($stored) ? $stored : []) : $data;
        $at = $this->clock->now();
        $renumber = $this->rectifyWithNewNumber($tenantId, $establishmentId);
        $revision = $renumber ? 1 : (int) $old['revision'] + 1;

        $issued = $this->issueVia(
            $tenantId,
            $establishmentId,
            (string) $old['type_code'],
            (string) $old['subject_person_id'],
            $values,
            $reissuedBy,
            (string) $old['locale'],
            'reissue',
            function (string $newId) use ($tenantId, $documentId, $reason, $reissuedBy, $at): void {
                if ($this->documents->markRevoked($tenantId, $documentId, $reason, $reissuedBy, $at, $newId) === 0) {
                    throw HttpException::conflict('Ce document a déjà été rectifié ou révoqué.');
                }
            },
            null,
            ['number' => (string) $old['number'], 'revision' => $revision, 'of' => $documentId, 'reason' => $reason, 'new_number' => $renumber],
        );
        $this->audit->append($tenantId, $reissuedBy, 'document.reissued', 'document', $issued->id, ['status' => IssuedDocument::VALID, 'replaces' => $documentId], [
            'number' => $issued->number, 'revision' => $revision, 'reason' => $reason, 'original_number' => (string) $old['number'],
        ]);

        return $issued;
    }

    /** Mention « Rectificatif » ; avec un nouveau numéro (SS12), elle cite le numéro du document d'origine (RG-DOC-6). */
    private static function withRectificatif(string $html, string $locale, string $reason, \DateTimeImmutable $moment, ?string $originalNumber = null): string
    {
        $banner = $locale === 'en'
            ? 'Corrected version' . ($originalNumber !== null ? ' of document No. ' . $originalNumber : '') . ' — issued on ' . $moment->format('Y-m-d') . ' (reason: ' . $reason . ')'
            : 'Rectificatif' . ($originalNumber !== null ? ' du document n° ' . $originalNumber : '') . ' — émis le ' . $moment->format('d/m/Y') . ' (motif : ' . $reason . ')';
        $notice = '<p class="rectificatif"><strong>' . htmlspecialchars($banner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        $count = 0;
        $withBanner = (string) preg_replace('/(<body\b[^>]*>)/i', '$1' . $notice, $html, 1, $count);

        return $count === 1 ? $withBanner : $notice . $html;
    }

    /** @return string HTML du duplicata : contenu et numéro d'origine, version de modèle de l'émission, mention et date de réimpression */
    public function duplicate(string $tenantId, string $establishmentId, string $id, string $by): string
    {
        $document = $this->documents->find($tenantId, $id, $establishmentId) ?? throw HttpException::notFound('Document introuvable.');
        if ($document['status'] !== IssuedDocument::VALID) {
            throw HttpException::conflict('Un document révoqué ne peut pas être réimprimé.');
        }
        $moment = $this->clock->moment();
        $banner = $document['locale'] === 'en'
            ? 'Duplicate — reprinted on ' . $moment->format('Y-m-d')
            : 'Duplicata — réimprimé le ' . $moment->format('d/m/Y');
        $html = (string) $document['content'];
        $notice = '<p class="duplicata"><strong>' . htmlspecialchars($banner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        $count = 0;
        $withBanner = (string) preg_replace('/(<body\b[^>]*>)/i', '$1' . $notice, $html, 1, $count);

        $this->audit->append($tenantId, $by, 'document.reprinted', 'document', $id, null, ['number' => $document['number'], 'template_version' => (int) $document['template_version']]);

        return $count === 1 ? $withBanner : $notice . $html;
    }

    /** @return string contenu d'origine tel qu'émis */
    public function content(string $tenantId, string $establishmentId, string $id): string
    {
        $document = $this->documents->find($tenantId, $id, $establishmentId) ?? throw HttpException::notFound('Document introuvable.');

        return (string) $document['content'];
    }

    /** @param string $profile standard ou light (PP15 : polices de base, grandes images retirées, PDF parent < 200 Ko) */
    public function pdf(string $html, string $profile = LightPdfProfile::STANDARD): string
    {
        if ($profile === LightPdfProfile::LIGHT) {
            return $this->pdf->render(LightPdfProfile::apply($this->withPrintStyle($html, LightPdfProfile::STYLE)));
        }

        return $this->pdf->render($this->withPrintStyle($html));
    }

    /** Feuille de style d'impression minimale (marges, titres centrés, code QR) injectée au début du corps. */
    private function withPrintStyle(string $html, ?string $style = null): string
    {
        $style ??= '<style>@page{margin:2cm}body{font-family:"DejaVu Sans",sans-serif;font-size:12px;line-height:1.5;color:#111}'
            . 'h1{text-align:center;font-size:20px;border-bottom:1px solid #444;padding-bottom:6px}h2{text-align:center;font-size:16px}'
            . '.qr{margin:12px 0}.qr figcaption{font-size:9px;color:#555}.duplicata{text-align:center;color:#a00}.rectificatif{text-align:center;color:#a00}'
            . '.page-break{page-break-after:always}</style>';
        $count = 0;
        $styled = (string) preg_replace('/(<body\b[^>]*>)/i', '$1' . $style, $html, 1, $count);

        return $count === 1 ? $styled : $style . $html;
    }

    /**
     * @return array{0: array<string, string>, 1: ?string} fragments sûrs (qr, signature, cachet) et date de signature
     * @param array<string, mixed>|null $seal
     */
    private function safeFragments(string $html, bool $qrEnabled, string $url, ?array $seal, string $issuedAt, string $locale): array
    {
        $safe = ['qr' => $qrEnabled ? $this->qr->markup($url) : '', 'signature' => '', 'stamp' => ''];
        $signedAt = null;
        $used = TemplateGuard::placeholders($html);

        if ($seal !== null && in_array('signature', $used, true)) {
            $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $image = $seal['signature_image'] !== null ? '<img src="' . $e($seal['signature_image']) . '" alt="Signature" height="60">' : '';
            $title = $seal['signer_title'] !== null ? ', ' . $e($seal['signer_title']) : '';
            $stamp = $this->clock->moment()->format($locale === 'fr' ? 'd/m/Y H:i' : 'Y-m-d H:i');
            $safe['signature'] = '<div class="signature">' . $image . '<div>' . $e($seal['signer_name']) . $title . '</div><small>'
                . ($locale === 'fr' ? 'Signé électroniquement le ' : 'Electronically signed on ') . $stamp . '</small></div>';
            $signedAt = $issuedAt;
        }
        if ($seal !== null && $seal['stamp_image'] !== null && in_array('stamp', $used, true)) {
            $safe['stamp'] = '<img class="stamp" src="' . htmlspecialchars((string) $seal['stamp_image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Cachet" height="90">';
            $signedAt ??= $issuedAt;
        }

        return [$safe, $signedAt];
    }

    /** Code d'école du jeton {ECOLE} (S16) : obligatoire si le gabarit l'utilise, ignoré sinon. */
    private function schoolCode(string $pattern, IssueOptions $options): ?string
    {
        if (!NumberPattern::usesSchool($pattern)) {
            return null;
        }
        if ($options->schoolCode === null || preg_match(NumberPattern::SCHOOL_CODE, $options->schoolCode) !== 1) {
            throw HttpException::validation(['school_code' => ['Ce type numérote par école : un code d\'école (1 à 10 majuscules ou chiffres) est obligatoire.']]);
        }

        return $options->schoolCode;
    }

    /** Suite propre à une école : clé de séquence bornée à 40 caractères. */
    private static function schoolKind(string $type, string $school): string
    {
        $kind = 'doc:' . $type . '@' . $school;

        return strlen($kind) <= 40 ? $kind : 'doc:' . substr(hash('sha256', $type), 0, 24) . '@' . $school;
    }

    /**
     * Blocage pour impayé à l'émission unitaire (S12) et gardes enregistrées (S18).
     *
     * @param array<string, string> $values
     * @param array<string, mixed> $definition
     */
    private function assertIssuable(string $tenantId, string $establishmentId, string $type, string $subjectPersonId, array $values, string $locale, string $issuedBy, string $via, array $definition, IssueOptions $options): void
    {
        if ($definition['blockable'] === true
            && $this->debtors->isBlocked($tenantId, $establishmentId, $subjectPersonId, 'documents')
            && ($options->enforceFinancialBlock || $this->singleIssuanceBlocks($tenantId, $establishmentId))) {
            throw HttpException::validation(['subject_person_id' => [self::BLOCKED_REASON]]);
        }
        $refusal = $this->guards->refusal(new IssuanceContext($tenantId, $establishmentId, $type, $subjectPersonId, $values, $locale, $issuedBy, $via, $options->orgUnitId));
        if ($refusal !== null) {
            throw HttpException::validation(['issuance' => [$refusal]]);
        }
    }

    /**
     * Paramètre `documents.rectify_numbering` (SS12) : « same_number » (défaut, PP15 : même numéro, révision + 1) ou « new_number »
     * (nouveau numéro de la séquence, mention du numéro d'origine).
     */
    private function rectifyWithNewNumber(string $tenantId, string $establishmentId): bool
    {
        try {
            return $this->settings->value($tenantId, $establishmentId, 'documents.rectify_numbering', self::RECTIFY_SAME_NUMBER) === self::RECTIFY_NEW_NUMBER;
        } catch (HttpException) {
            return false;
        }
    }

    /** Paramètre `documents.block_single_issuance` (faux par défaut : le socle ne bloquait que les lots). */
    private function singleIssuanceBlocks(string $tenantId, string $establishmentId): bool
    {
        try {
            return $this->settings->value($tenantId, $establishmentId, 'documents.block_single_issuance', false) === true;
        } catch (HttpException) {
            return false;
        }
    }

    /** Valeur simple (texte, nombre, booléen) ou nulle : les données viennent de l'extérieur, on ne se fie pas au typage. */
    private static function isSimpleValue(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }
}
