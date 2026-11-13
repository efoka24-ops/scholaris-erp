<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Application;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\RateLimit\RateLimiter;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Scholaris\Modules\Socle\Identity\Contracts\AccountProvisioner;
use Scholaris\Modules\Socle\Identity\Contracts\RoleAssignmentGateway;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;
use Scholaris\Modules\Socle\Imports\Domain\RoleRules;
use Scholaris\Modules\Socle\Imports\Infrastructure\BatchStore;
use Scholaris\Modules\Socle\Imports\Infrastructure\TableReader;
use Scholaris\Modules\Socle\Integrations\Contracts\MailGateway;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsGateway;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Throwable;

/**
 * Import en masse des acteurs d'un établissement. Deux temps : l'ANALYSE (rien n'est écrit dans Identity : chaque ligne est contrôlée,
 * les doublons et les rôles non attribuables sont signalés) puis l'APPLICATION du lot, une seule fois (idempotente), qui crée les
 * personnes et leurs comptes par les contrats d'Identity et attribue les rôles avec les règles de POST /role-assignments.
 *
 * Garanties : le fichier brut n'est jamais conservé (seules les lignes normalisées, le temps du lot) ; les données personnelles des
 * lignes appliquées sont effacées ; les mots de passe provisoires ne sont rendus qu'une fois, dans la réponse de l'application, et
 * ne sont ni stockés ni journalisés ; l'audit ne contient aucune donnée de personne ; un compte existant n'est jamais modifié.
 */
final class PeopleImportService
{
    public const PERMISSION = 'imports.people.manage';
    public const PROVISIONAL = 'provisional_password';
    public const ACTIVATION = 'activation_code';

    public const PREVIEWED = 'previewed';
    public const APPLYING = 'applying';
    public const APPLIED = 'applied';

    public const STATUSES = ['valid', 'error', 'duplicate', 'applied', 'partial', 'failed'];

    /** Durée de vie d'un lot analysé et non appliqué ; après l'application, le bilan se garde 7 jours. */
    private const TTL_SECONDS = 172800;
    private const KEEP_AFTER_COMMIT = 604800;
    /** Un verrou d'application plus ancien est une application interrompue : elle peut reprendre les lignes restantes. */
    private const LOCK_STALE_SECONDS = 600;
    private const PREVIEWS_PER_HOUR = 30;
    private const COMMITS_PER_HOUR = 10;
    private const ACTIVATION_CODE_TTL = 259200;

    public function __construct(
        private readonly BatchStore $store,
        private readonly TableReader $reader,
        private readonly LineChecker $checker,
        private readonly RoleAssignmentGateway $roles,
        private readonly AccountAdministration $accounts,
        private readonly AccountProvisioner $provisioner,
        private readonly EstablishmentDirectory $establishments,
        private readonly AuditLog $audit,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly MailGateway $mail,
        private readonly SmsGateway $sms,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public function template(): array
    {
        return ['headers' => PeopleColumns::headers(), 'rows' => PeopleColumns::samples()];
    }

    /**
     * Analyse un fichier SANS rien écrire dans Identity. Crée le lot (lignes normalisées) et renvoie le bilan ligne par ligne.
     *
     * @return array<string, mixed>
     */
    public function preview(Principal $principal, string $establishmentId, string $fileName, string $content, ?string $ip): array
    {
        $this->rateLimit('preview', $principal, self::PREVIEWS_PER_HOUR);
        $info = $this->establishments->find($principal->tenantId, $establishmentId) ?? throw HttpException::notFound();
        $roles = $this->importableRoles($principal, $establishmentId, $info->type);
        if ($roles === []) {
            throw HttpException::forbidden('Vous n\'avez le droit d\'attribuer aucun rôle dans cet établissement : l\'import ne peut pas créer d\'acteurs.');
        }

        $table = $this->reader->read($content);
        $now = $this->now();
        $this->store->purgeExpired($principal->tenantId, $establishmentId, $now);

        $checked = [];
        foreach ($table['rows'] as $lineNo => $raw) {
            $checked[$lineNo] = $this->checker->check($principal->tenantId, $establishmentId, $raw, $roles);
        }
        $this->flagDuplicates($principal->tenantId, $checked);

        $batchId = (string) new Ulid();
        $counts = ['total' => count($checked), 'valid' => 0, 'error' => 0, 'duplicate' => 0];
        $this->store->transaction(function () use ($principal, $establishmentId, $table, $fileName, $batchId, $now, &$checked, &$counts): void {
            foreach ($checked as $lineNo => $line) {
                ++$counts[$line['status']];
                $this->store->insertLine([
                    'id' => (string) new Ulid(), 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'batch_id' => $batchId,
                    'line_no' => $lineNo, 'line_status' => $line['status'], 'last_name' => $line['last_name'], 'first_name' => $line['first_name'],
                    'email' => $line['email'], 'phone' => $line['phone'], 'locale' => $line['locale'], 'role_code' => $line['role_code'],
                    'scope_type' => $line['scope_type'], 'scope_code' => $line['scope_code'], 'scope_id' => $line['scope_id'],
                    'starts_on' => $line['starts_on'], 'ends_on' => $line['ends_on'], 'note' => $line['note'] === '' ? null : $line['note'],
                    'messages_json' => json_encode($line['messages'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'person_id' => null, 'user_id' => null,
                ]);
            }
            $this->store->insertBatch([
                'id' => $batchId, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'created_by' => $principal->userId,
                'source_format' => $table['format'], 'file_name' => self::safeName($fileName), 'batch_status' => self::PREVIEWED, 'credentials_mode' => null,
                'send_codes' => 0, 'lines_total' => $counts['total'], 'lines_valid' => $counts['valid'], 'lines_error' => $counts['error'],
                'lines_duplicate' => $counts['duplicate'], 'lines_applied' => 0, 'lines_failed' => 0, 'created_at' => $now,
                'expires_at' => $this->at($this->clock->now() + self::TTL_SECONDS), 'locked_at' => null, 'committed_by' => null, 'committed_at' => null,
            ]);
        });
        // Aucune donnée de personne dans le journal : un identifiant de lot, un format et des compteurs.
        $this->audit->append($principal->tenantId, $principal->userId, 'imports.people.previewed', 'import_batch', $batchId, null, [
            'establishment_id' => $establishmentId, 'format' => $table['format'], 'counts' => $counts,
        ], $ip);

        $view = $this->view($this->batchOrFail($principal, $establishmentId, $batchId), null);
        $view['unknown_columns'] = $table['unknown'];
        $view['allowed_roles'] = array_values(array_map(static fn (array $role): array => ['code' => $role['code'], 'label' => $role['label_fr']], $roles));

        return $view;
    }

    /** 404 neutre si le lot n'existe pas, a expiré ou appartient à un autre client ou établissement. */
    public function assertExists(Principal $principal, string $establishmentId, string $id): void
    {
        $this->batchOrFail($principal, $establishmentId, $id);
    }

    /**
     * Bilan et lignes d'un lot (filtrables par état). Les identifiants créés y figurent, jamais un mot de passe.
     *
     * @return array<string, mixed>
     */
    public function show(Principal $principal, string $establishmentId, string $id, ?string $status): array
    {
        return $this->view($this->batchOrFail($principal, $establishmentId, $id), $status === null ? null : [$status]);
    }

    /**
     * Lignes à corriger (erreurs, doublons, échecs), dans les colonnes du modèle : le fichier corrigé peut être renvoyé tel quel.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function errors(Principal $principal, string $establishmentId, string $id): array
    {
        $batch = $this->batchOrFail($principal, $establishmentId, $id);
        $rows = [];
        foreach ($this->store->lines($principal->tenantId, $establishmentId, (string) $batch['id'], ['error', 'duplicate', 'failed', 'partial']) as $line) {
            $rows[] = [
                (string) $line['line_no'], (string) $line['line_status'], implode(' | ', self::messages($line)),
                (string) $line['last_name'], (string) $line['first_name'], (string) $line['email'], (string) $line['phone'], (string) $line['locale'],
                (string) $line['role_code'], self::scopeWord((string) $line['scope_type']), (string) $line['scope_code'],
                (string) $line['starts_on'], (string) $line['ends_on'], (string) $line['note'],
            ];
        }

        return ['headers' => ['ligne', 'état', 'problème', ...PeopleColumns::headers()], 'rows' => $rows];
    }

    /**
     * Applique le lot UNE fois. Le premier appel crée les comptes et rend les mots de passe provisoires ; un appel ultérieur rend le
     * bilan sans aucun secret (`replay`). Une application interrompue reprend les lignes restantes.
     *
     * @return array{view: array<string, mixed>, credentials: list<array<string, mixed>>, replay: bool}
     */
    public function commit(Principal $principal, string $establishmentId, string $id, string $mode, bool $sendCodes, ?string $ip): array
    {
        $batch = $this->batchOrFail($principal, $establishmentId, $id);
        if ($batch['batch_status'] === self::APPLIED) {
            return ['view' => $this->view($batch, null), 'credentials' => [], 'replay' => true];
        }
        $this->rateLimit('commit', $principal, self::COMMITS_PER_HOUR);
        $now = $this->now();
        if (!$this->store->claim($principal->tenantId, $establishmentId, $id, $now, $this->at($this->clock->now() - self::LOCK_STALE_SECONDS), $principal->userId, $mode, $sendCodes)) {
            $current = $this->batchOrFail($principal, $establishmentId, $id);
            if ($current['batch_status'] === self::APPLIED) {
                return ['view' => $this->view($current, null), 'credentials' => [], 'replay' => true];
            }
            throw HttpException::conflict('Ce lot est en cours d\'application : patientez, puis consultez son bilan.');
        }

        $info = $this->establishments->find($principal->tenantId, $establishmentId) ?? throw HttpException::notFound();
        $assignable = array_column($this->importableRoles($principal, $establishmentId, $info->type), 'code');
        $credentials = [];
        foreach ($this->store->lines($principal->tenantId, $establishmentId, $id, ['valid']) as $line) {
            $outcome = $this->applyLine($principal, $establishmentId, $info->name, $line, $assignable, $mode, $sendCodes);
            $this->store->updateLine($principal->tenantId, (string) $line['id'], $outcome['values']);
            if ($outcome['credential'] !== null) {
                $credentials[] = $outcome['credential'];
            }
        }

        $this->store->scrubApplied($principal->tenantId, $establishmentId, $id);
        $lines = $this->store->lines($principal->tenantId, $establishmentId, $id);
        $summary = self::summary($lines);
        $this->store->updateBatch($principal->tenantId, $establishmentId, $id, [
            'batch_status' => self::APPLIED, 'committed_at' => $this->now(), 'locked_at' => null,
            'lines_applied' => $summary['applied'] + $summary['partial'], 'lines_failed' => $summary['failed'] + $summary['partial'],
            'expires_at' => $this->at($this->clock->now() + self::KEEP_AFTER_COMMIT),
        ]);
        $this->audit->append($principal->tenantId, $principal->userId, 'imports.people.committed', 'import_batch', $id, null, [
            'establishment_id' => $establishmentId, 'credentials' => $mode, 'send_codes' => $sendCodes, 'summary' => $summary,
        ], $ip);

        return ['view' => $this->view($this->batchOrFail($principal, $establishmentId, $id), null), 'credentials' => $credentials, 'replay' => false];
    }

    /**
     * @param array<string, mixed> $line
     * @param list<string> $assignable
     * @return array{values: array<string, scalar|null>, credential: array<string, mixed>|null}
     */
    private function applyLine(Principal $principal, string $establishmentId, string $establishmentName, array $line, array $assignable, string $mode, bool $sendCodes): array
    {
        $tenantId = $principal->tenantId;
        $messages = [];
        $personId = null;
        $userId = null;
        $credential = null;
        try {
            if (!in_array((string) $line['role_code'], $assignable, true)) {
                return $this->outcome('failed', ['Vous ne pouvez plus attribuer ce rôle : la ligne n\'a pas été appliquée.']);
            }
            $phone = self::nullable($line['phone']);
            $email = self::nullable($line['email']);
            // Un identifiant a pu être pris depuis l'analyse : jamais d'écrasement, jamais de rôle ajouté à un compte existant.
            $taken = $this->roles->identifiersInUse($tenantId, $phone === null ? [] : [$phone], $email === null ? [] : [$email]);
            if ($taken['phones'] !== [] || $taken['emails'] !== []) {
                return $this->outcome('duplicate', ['Un compte utilise déjà ce téléphone ou cet e-mail : ligne ignorée.']);
            }

            if ($mode === self::PROVISIONAL) {
                $created = $this->accounts->createWithProvisionalPassword($tenantId, (string) $line['first_name'], (string) $line['last_name'], $phone, $email, (string) $line['locale'], $principal->userId);
                [$personId, $userId] = [$created['person_id'], $created['user_id']];
                $credential = [
                    'line' => (int) $line['line_no'], 'user_id' => $userId, 'person_id' => $personId, 'login' => $created['login'],
                    'last_name' => (string) $line['last_name'], 'first_name' => (string) $line['first_name'], 'role' => (string) $line['role_code'],
                    'provisional_password' => $created['provisional_password'],
                ];
            } else {
                $created = $this->provisioner->provision($tenantId, (string) $line['first_name'], (string) $line['last_name'], $phone, $email, (string) $line['locale']);
                [$personId, $userId] = [$created['person_id'], $created['user_id']];
            }
        } catch (HttpException $e) {
            return $this->outcome($e->status === 409 ? 'duplicate' : 'failed', [$e->status === 422 ? 'Données refusées : ' . self::flatten($e) : $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('Import des acteurs : ligne non appliquée', ['error' => $e::class]);

            return $this->outcome('failed', ['Erreur interne sur cette ligne : elle n\'a pas été appliquée.']);
        }

        $status = 'applied';
        try {
            $scopeType = (string) $line['scope_type'];
            $this->roles->assign(
                $tenantId,
                $principal->userId,
                $personId,
                (string) $line['role_code'],
                $scopeType,
                (string) $line['scope_id'],
                $scopeType === 'establishment' ? null : $establishmentId,
                self::startOfDay(self::nullable($line['starts_on'])),
                self::endOfDay(self::nullable($line['ends_on'])),
            );
        } catch (HttpException $e) {
            $status = 'partial';
            $messages[] = 'Compte créé mais rôle non attribué (' . ($e->status === 422 ? self::flatten($e) : $e->getMessage()) . ') : attribuez-le dans Administration > Utilisateurs et rôles.';
        } catch (Throwable $e) {
            $status = 'partial';
            $this->logger->error('Import des acteurs : rôle non attribué', ['error' => $e::class]);
            $messages[] = 'Compte créé mais rôle non attribué (erreur interne) : attribuez-le dans Administration > Utilisateurs et rôles.';
        }

        if ($mode === self::ACTIVATION && $sendCodes) {
            $messages[] = $this->deliverCode($tenantId, $establishmentName, $principal->userId, $userId, $line);
        }

        $outcome = $this->outcome($status, $messages);
        $outcome['values'] += ['person_id' => $personId, 'user_id' => $userId];
        $outcome['credential'] = $credential;

        return $outcome;
    }

    /**
     * Crée un code d'activation et l'envoie (e-mail d'abord, sinon SMS mobile). Le code n'est ni journalisé ni conservé ; un échec
     * d'envoi n'annule rien : la personne demande son code elle-même sur l'écran d'activation.
     *
     * @param array<string, mixed> $line
     */
    private function deliverCode(string $tenantId, string $establishmentName, string $actorId, string $userId, array $line): string
    {
        $email = self::nullable($line['email']);
        $phone = self::nullable($line['phone']);
        $english = (string) $line['locale'] === 'en';
        if ($email === null && ($phone === null || preg_match('/^\+2376\d{8}$/', $phone) !== 1)) {
            return 'Aucun e-mail ni mobile camerounais : la personne demandera son code sur l\'écran d\'activation.';
        }
        try {
            $issued = $this->accounts->issueAccessCode($tenantId, $userId, $actorId, self::ACTIVATION_CODE_TTL);
            $base = rtrim((string) $this->config->get('app.url', ''), '/');
            $days = intdiv($issued['expires_in'], 86400);
            $first = (string) $line['first_name'];
            if ($email !== null) {
                $text = $english
                    ? sprintf("Hello %s,\n\nYour SCHOLARIS access for %s is ready.\nLogin: %s\nActivation code: %s (valid for %d days)\n\nOpen %s/activation, enter your login and the code, then choose your password.\nNever share this code.", $first, $establishmentName, $issued['login'], $issued['code'], $days, $base)
                    : sprintf("Bonjour %s,\n\nVotre accès SCHOLARIS pour %s est prêt.\nIdentifiant : %s\nCode d'activation : %s (valable %d jours)\n\nOuvrez %s/activation, saisissez votre identifiant et le code, puis choisissez votre mot de passe.\nNe communiquez ce code à personne.", $first, $establishmentName, $issued['login'], $issued['code'], $days, $base);
                $this->mail->send($email, $english ? 'SCHOLARIS: your access to ' . $establishmentName : 'SCHOLARIS : votre accès à ' . $establishmentName, $text);

                return 'Code d\'activation envoyé par e-mail.';
            }
            $sms = $english
                ? sprintf('SCHOLARIS %s: activation code %s (valid %d days). Open %s/activation. Login: %s', $establishmentName, $issued['code'], $days, $base, $issued['login'])
                : sprintf('SCHOLARIS %s : code d\'activation %s (valable %d jours). Ouvrez %s/activation. Identifiant : %s', $establishmentName, $issued['code'], $days, $base, $issued['login']);
            $this->sms->send((string) $phone, mb_substr($sms, 0, 480));

            return 'Code d\'activation envoyé par SMS.';
        } catch (Throwable $e) {
            $this->logger->warning('Import des acteurs : code d\'activation non envoyé', ['error' => $e::class]);

            return 'Envoi du code impossible : la personne demandera son code sur l\'écran d\'activation.';
        }
    }

    /**
     * Rôles du fichier acceptés ici : ceux que l'importeur peut attribuer (règle de POST /role-assignments, via Identity), de la
     * famille du type d'établissement, jamais de niveau client.
     *
     * @return array<string, array{code: string, label_fr: string, label_en: string}>
     */
    private function importableRoles(Principal $principal, string $establishmentId, string $establishmentType): array
    {
        $result = [];
        foreach ($this->roles->assignableRoles($principal->tenantId, $principal->userId, $establishmentId) as $role) {
            if ($role['level'] === 'tenant' || !RoleRules::isFamilyOf($role['code'], $establishmentType)) {
                continue;
            }
            $result[$role['code']] = ['code' => $role['code'], 'label_fr' => $role['label_fr'], 'label_en' => $role['label_en']];
        }

        return $result;
    }

    /**
     * Marque les doublons : même identifiant que celui d'une ligne précédente du fichier, ou déjà utilisé par un compte du client.
     * Une ligne sans autre problème devient « duplicate » ; sinon elle reste en erreur avec les deux messages.
     *
     * @param array<int, array<string, mixed>> $checked
     */
    private function flagDuplicates(string $tenantId, array &$checked): void
    {
        /** @var array<int, list<string>> $found */
        $found = [];
        /** @var array<string, int> $seenPhones */
        $seenPhones = [];
        /** @var array<string, int> $seenEmails */
        $seenEmails = [];
        foreach ($checked as $lineNo => $line) {
            $duplicates = [];
            if (is_string($line['phone'])) {
                if (isset($seenPhones[$line['phone']])) {
                    $duplicates[] = 'Même téléphone que la ligne ' . $seenPhones[$line['phone']] . ' : une seule ligne par personne.';
                } else {
                    $seenPhones[$line['phone']] = $lineNo;
                }
            }
            if (is_string($line['email'])) {
                if (isset($seenEmails[$line['email']])) {
                    $duplicates[] = 'Même e-mail que la ligne ' . $seenEmails[$line['email']] . ' : une seule ligne par personne.';
                } else {
                    $seenEmails[$line['email']] = $lineNo;
                }
            }
            $found[$lineNo] = $duplicates;
        }

        $known = $this->roles->identifiersInUse(
            $tenantId,
            array_map('strval', array_keys($seenPhones)),
            array_map('strval', array_keys($seenEmails)),
        );
        foreach ($checked as $lineNo => $line) {
            $duplicates = $found[$lineNo];
            if (is_string($line['phone']) && in_array($line['phone'], $known['phones'], true)) {
                $duplicates[] = 'Ce téléphone est déjà utilisé par un compte de ce client : il ne sera ni modifié ni écrasé.';
            }
            if (is_string($line['email']) && in_array($line['email'], $known['emails'], true)) {
                $duplicates[] = 'Cet e-mail est déjà utilisé par un compte de ce client : il ne sera ni modifié ni écrasé.';
            }
            /** @var list<string> $messages */
            $messages = $line['messages'];
            $status = $messages !== [] ? 'error' : ($duplicates !== [] ? 'duplicate' : 'valid');
            $checked[$lineNo]['messages'] = [...$messages, ...$duplicates];
            $checked[$lineNo]['status'] = $status;
        }
    }

    /**
     * @param array<string, mixed> $batch
     * @param list<string>|null $statuses
     * @return array<string, mixed>
     */
    private function view(array $batch, ?array $statuses): array
    {
        $all = $this->store->lines((string) $batch['tenant_id'], (string) $batch['establishment_id'], (string) $batch['id']);
        $shown = $statuses === null ? $all : array_values(array_filter($all, static fn (array $line): bool => in_array($line['line_status'], $statuses, true)));

        return [
            'id' => (string) $batch['id'],
            'status' => (string) $batch['batch_status'],
            'source_format' => (string) $batch['source_format'],
            'file_name' => $batch['file_name'] === null ? null : (string) $batch['file_name'],
            'created_at' => (string) $batch['created_at'],
            'expires_at' => (string) $batch['expires_at'],
            'committed_at' => $batch['committed_at'] === null ? null : (string) $batch['committed_at'],
            'credentials' => $batch['credentials_mode'] === null ? null : (string) $batch['credentials_mode'],
            'send_codes' => (int) $batch['send_codes'] === 1,
            'summary' => self::summary($all),
            'lines' => array_map(static fn (array $line): array => [
                'line' => (int) $line['line_no'],
                'status' => (string) $line['line_status'],
                'messages' => self::messages($line),
                'last_name' => self::nullable($line['last_name']),
                'first_name' => self::nullable($line['first_name']),
                'email' => self::nullable($line['email']),
                'phone' => self::nullable($line['phone']),
                'locale' => self::nullable($line['locale']),
                'role' => self::nullable($line['role_code']),
                'scope_type' => self::nullable($line['scope_type']),
                'scope_code' => self::nullable($line['scope_code']),
                'starts_on' => self::nullable($line['starts_on']),
                'ends_on' => self::nullable($line['ends_on']),
                'note' => self::nullable($line['note']),
                'person_id' => self::nullable($line['person_id']),
                'user_id' => self::nullable($line['user_id']),
            ], $shown),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{total: int, valid: int, error: int, duplicate: int, applied: int, partial: int, failed: int}
     */
    private static function summary(array $lines): array
    {
        $summary = ['total' => count($lines), 'valid' => 0, 'error' => 0, 'duplicate' => 0, 'applied' => 0, 'partial' => 0, 'failed' => 0];
        foreach ($lines as $line) {
            $status = (string) $line['line_status'];
            if (isset($summary[$status])) {
                ++$summary[$status];
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $line
     * @return list<string>
     */
    private static function messages(array $line): array
    {
        $decoded = is_string($line['messages_json'] ?? null) ? json_decode($line['messages_json'], true) : null;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @param list<string> $messages
     * @return array{values: array<string, scalar|null>, credential: null}
     */
    private function outcome(string $status, array $messages): array
    {
        return ['values' => ['line_status' => $status, 'messages_json' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], 'credential' => null];
    }

    /** @return array<string, mixed> */
    private function batchOrFail(Principal $principal, string $establishmentId, string $id): array
    {
        $batch = $this->store->batch($principal->tenantId, $establishmentId, $id);
        if ($batch === null) {
            throw HttpException::notFound('Lot d\'import introuvable.');
        }
        if ((string) $batch['expires_at'] < $this->now()) {
            $this->store->delete($principal->tenantId, $establishmentId, $id);
            throw HttpException::notFound('Lot d\'import introuvable.');
        }

        return $batch;
    }

    private function rateLimit(string $action, Principal $principal, int $perHour): void
    {
        $wait = $this->limiter->hit('imports.people.' . $action . '|' . $principal->tenantId . '|' . $principal->userId, $perHour, 3600);
        if ($wait > 0) {
            throw HttpException::tooManyRequests($wait);
        }
    }

    private function now(): string
    {
        return $this->at($this->clock->now());
    }

    private function at(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Africa/Douala'))->format(DATE_ATOM);
    }

    private static function startOfDay(?string $date): ?string
    {
        return $date === null ? null : $date . 'T00:00:00+01:00';
    }

    private static function endOfDay(?string $date): ?string
    {
        return $date === null ? null : $date . 'T23:59:59+01:00';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function flatten(HttpException $e): string
    {
        $parts = [];
        foreach ($e->errors as $messages) {
            array_push($parts, ...$messages);
        }

        return $parts === [] ? $e->getMessage() : implode(' ', $parts);
    }

    private static function scopeWord(string $type): string
    {
        return match ($type) {
            ScopeResolver::ORG_UNIT => 'unité',
            ScopeResolver::STRUCTURE => 'structure',
            ScopeResolver::ESTABLISHMENT => 'établissement',
            default => '',
        };
    }

    /** Nom de fichier d'origine réduit à un libellé sûr (aucun chemin, longueur bornée). */
    private static function safeName(string $name): ?string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename(str_replace('\\', '/', $name))));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
