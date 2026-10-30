<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Domain;

/**
 * Notifications NOT-01 à NOT-11 du CDC (NOT-12 et suivantes non reçues : decisions.md R37). Chaque notification a une clé de
 * modèle du Messenger (`higher.notNN.*`), ses destinataires, ses canaux et ses textes FR/EN qui n'emploient que les variables
 * permises par la messagerie du socle. `wired` : déclencheur déjà branché (l'événement existe) ; sinon gabarit prêt à brancher
 * par le module propriétaire de l'événement via Notifications\Contracts\HigherNotifier.
 */
final class NotificationCatalog
{
    /** Destinataires : l'étudiant ou le candidat concerné, ses garants, ses garants seulement avec consentement (RG-INS-7). */
    public const SUBJECT = 'subject';
    public const GUARANTORS = 'guarantors';
    public const GUARANTORS_WITH_CONSENT = 'guarantors_consent';

    /**
     * @var array<string, array{key: string, label: string, recipients: list<string>, channels: list<string>, wired: bool, trigger: string,
     *      fr: string, en: string}>
     */
    public const ALL = [
        'NOT-01' => [
            'key' => 'higher.not01.application_received', 'label' => 'Candidature reçue et complète', 'recipients' => [self::SUBJECT],
            'channels' => ['sms', 'email'], 'wired' => false, 'trigger' => 'admissions.application.completed',
            'fr' => ':full_name, votre candidature :reference est complète et a bien été reçue par :establishment_name.',
            'en' => ':full_name, your application :reference is complete and has been received by :establishment_name.',
        ],
        'NOT-02' => [
            'key' => 'higher.not02.missing_document', 'label' => 'Pièce manquante ou refusée', 'recipients' => [self::SUBJECT],
            'channels' => ['sms', 'email'], 'wired' => false, 'trigger' => 'admissions.document.rejected',
            'fr' => ':full_name, candidature :reference : pièce manquante ou refusée (:label). Merci de la déposer.',
            'en' => ':full_name, application :reference: missing or rejected document (:label). Please upload it.',
        ],
        'NOT-03' => [
            'key' => 'higher.not03.admission_decision', 'label' => 'Décision d\'admission', 'recipients' => [self::SUBJECT],
            'channels' => ['sms', 'email'], 'wired' => false, 'trigger' => 'admissions.decision.recorded',
            'fr' => ':full_name, décision sur votre candidature :reference : :label. Détails : :link',
            'en' => ':full_name, decision on your application :reference: :label. Details: :link',
        ],
        'NOT-04' => [
            'key' => 'higher.not04.enrolment_validated', 'label' => 'Inscription validée, matricule attribué', 'recipients' => [self::SUBJECT, self::GUARANTORS],
            'channels' => ['sms'], 'wired' => false, 'trigger' => 'enrolment.validated',
            'fr' => 'Inscription de :full_name validée pour :school_year. Matricule : :reference.',
            'en' => 'Enrolment of :full_name confirmed for :school_year. Registration number: :reference.',
        ],
        'NOT-05' => [
            'key' => 'higher.not05.timetable_changed', 'label' => 'Changement d\'emploi du temps', 'recipients' => [self::SUBJECT],
            'channels' => ['push'], 'wired' => false, 'trigger' => 'teaching.timetable.changed',
            'fr' => 'Emploi du temps modifié : :change_label (:subject, :date :time).',
            'en' => 'Timetable change: :change_label (:subject, :date :time).',
        ],
        'NOT-06' => [
            'key' => 'higher.not06.absence_recorded', 'label' => 'Absence enregistrée', 'recipients' => [self::SUBJECT, self::GUARANTORS_WITH_CONSENT],
            'channels' => ['push', 'sms'], 'wired' => false, 'trigger' => 'attendance.absence.recorded',
            'fr' => 'Absence de :full_name enregistrée le :absence_date en :subject.',
            'en' => 'Absence of :full_name recorded on :absence_date in :subject.',
        ],
        'NOT-07' => [
            'key' => 'higher.not07.attendance_threshold', 'label' => 'Seuil d\'assiduité presque atteint (25 %)', 'recipients' => [self::SUBJECT, self::GUARANTORS_WITH_CONSENT],
            'channels' => ['sms'], 'wired' => false, 'trigger' => 'attendance.threshold.warning',
            'fr' => ':full_name : absences en :subject proches du seuil (:details). Au-delà de 30 %, pas de composition.',
            'en' => ':full_name: absences in :subject close to the limit (:details). Above 30%, no examination.',
        ],
        'NOT-08' => [
            'key' => 'higher.not08.due_soon', 'label' => 'Échéance dans 7 jours', 'recipients' => [self::SUBJECT],
            'channels' => ['sms'], 'wired' => true, 'trigger' => 'billing.installment.due_soon (relance J-7)',
            'fr' => ':label : :amount FCFA à payer avant le :due_on.',
            'en' => ':label: :amount XAF due by :due_on.',
        ],
        'NOT-09' => [
            'key' => 'higher.not09.overdue', 'label' => 'Tranche en retard (J+1, J+15)', 'recipients' => [self::SUBJECT],
            'channels' => ['sms'], 'wired' => true, 'trigger' => 'billing.installment.overdue (relances J+1 et J+15)',
            'fr' => ':label échue le :due_on : reste :amount FCFA à régulariser.',
            'en' => ':label was due on :due_on: :amount XAF outstanding.',
        ],
        'NOT-10' => [
            'key' => 'higher.not10.payment_received', 'label' => 'Paiement reçu, reçu disponible', 'recipients' => [self::SUBJECT],
            'channels' => ['sms'], 'wired' => true, 'trigger' => 'billing.payment.confirmed',
            'fr' => 'Paiement de :amount FCFA reçu. Reçu :receipt_number. Solde : :balance FCFA.',
            'en' => 'Payment of :amount XAF received. Receipt :receipt_number. Balance: :balance XAF.',
        ],
        'NOT-11' => [
            'key' => 'higher.not11.grading_late', 'label' => 'Saisie des notes en retard', 'recipients' => [self::SUBJECT],
            'channels' => ['email', 'push'], 'wired' => false, 'trigger' => 'grades.entry.late',
            'fr' => 'Saisie des notes en retard : :subject (:details). Merci de compléter avant le :due_on.',
            'en' => 'Late grade entry: :subject (:details). Please complete by :due_on.',
        ],
    ];

    /** Variables permises par la messagerie du socle (Messaging TemplateRenderer::ALLOWED). */
    public const MESSENGER_VARIABLES = [
        'receipt_number', 'amount', 'balance', 'due_date', 'payment_reference', 'first_name', 'last_name', 'full_name', 'child_name',
        'class_name', 'establishment_name', 'date', 'absence_date', 'link', 'reference', 'school_year', 'due_on', 'label', 'offset',
        'subject', 'time', 'change_label', 'details',
    ];

    private function __construct()
    {
    }

    /** @return array{key: string, label: string, recipients: list<string>, channels: list<string>, wired: bool, trigger: string, fr: string, en: string}|null */
    public static function find(string $code): ?array
    {
        return self::ALL[$code] ?? null;
    }

    /** @return list<string> variables employées par un texte */
    public static function placeholders(string $body): array
    {
        preg_match_all('/:([a-z][a-z0-9_]*)/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Modèles à installer dans la messagerie du socle (POST /message-templates), un par langue et par canal.
     *
     * @return list<array{template_key: string, locale: string, channel: string, subject: ?string, body: string}>
     */
    public static function messengerTemplates(string $code): array
    {
        $entry = self::ALL[$code] ?? throw new \InvalidArgumentException('Notification inconnue : ' . $code);
        $out = [];
        foreach ($entry['channels'] as $channel) {
            foreach (['fr', 'en'] as $locale) {
                $out[] = [
                    'template_key' => $entry['key'], 'locale' => $locale, 'channel' => $channel,
                    'subject' => $channel === 'email' ? $entry['label'] : null, 'body' => $entry[$locale],
                ];
            }
        }

        return $out;
    }
}
