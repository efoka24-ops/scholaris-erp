<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Documents\Domain;

/** Documents administratifs du primaire (EX-PRI-15, 40) : nature demandée => type du socle (catalogue plateforme). */
final class DocumentKinds
{
    public const CERTIFICATE = 'certificate';
    public const ATTENDANCE = 'attendance';
    public const SCHOOL_CARD = 'school_card';
    public const INFO_SHEET = 'info_sheet';
    public const TRANSFER = 'transfer';
    public const EXAM_LIST = 'exam_list';

    /** @var array<string, string> */
    public const SOCLE_TYPES = [
        self::CERTIFICATE => 'pri_school_certificate',
        self::ATTENDANCE => 'pri_attendance_letter',
        self::SCHOOL_CARD => 'pri_school_card',
        self::INFO_SHEET => 'pri_info_sheet',
        self::TRANSFER => 'pri_transfer',
        self::EXAM_LIST => 'pri_exam_list',
    ];

    /** Natures émises à partir d'une inscription par POST /primary/documents. */
    public const ENROLMENT_KINDS = [self::CERTIFICATE, self::ATTENDANCE, self::SCHOOL_CARD, self::INFO_SHEET];

    private function __construct()
    {
    }
}
