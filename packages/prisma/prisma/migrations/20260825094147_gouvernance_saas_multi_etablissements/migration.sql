-- CreateEnum
CREATE TYPE "TenantType" AS ENUM ('PRIMAIRE', 'SECONDAIRE', 'SUPERIEUR', 'TECHNIQUE', 'FORMATION_PRO');

-- CreateEnum
CREATE TYPE "TenantStatus" AS ENUM ('PUBLIC', 'PRIVE');

-- CreateEnum
CREATE TYPE "UserStatus" AS ENUM ('ACTIVE', 'INACTIVE', 'SUSPENDED');

-- CreateEnum
CREATE TYPE "AcademicYearStatus" AS ENUM ('ACTIVE', 'CLOSED', 'ARCHIVED');

-- CreateEnum
CREATE TYPE "PeriodType" AS ENUM ('SEQUENCE', 'TRIMESTER', 'SEMESTER');

-- CreateEnum
CREATE TYPE "GradingStatus" AS ENUM ('CLOSED', 'OPEN', 'LOCKED');

-- CreateEnum
CREATE TYPE "Section" AS ENUM ('FRANCOPHONE', 'ANGLOPHONE');

-- CreateEnum
CREATE TYPE "GroupType" AS ENUM ('TD', 'TP', 'LV2');

-- CreateEnum
CREATE TYPE "RoomType" AS ENUM ('SALLE_CLASSE', 'LABORATOIRE', 'SALLE_INFO', 'AMPHITHEATRE', 'TERRAIN_SPORT');

-- CreateEnum
CREATE TYPE "Channel" AS ENUM ('EMAIL', 'SMS', 'WHATSAPP', 'PUSH', 'INTERNAL');

-- CreateEnum
CREATE TYPE "MessageStatus" AS ENUM ('PENDING', 'SENT', 'DELIVERED', 'FAILED');

-- CreateEnum
CREATE TYPE "Gender" AS ENUM ('MALE', 'FEMALE');

-- CreateEnum
CREATE TYPE "StudentStatus" AS ENUM ('ACTIVE', 'SUSPENDED', 'GRADUATED', 'EXCLUDED', 'ABANDONED');

-- CreateEnum
CREATE TYPE "ParentRelationship" AS ENUM ('FATHER', 'MOTHER', 'GUARDIAN');

-- CreateEnum
CREATE TYPE "EnrollmentType" AS ENUM ('NEW', 'RE_ENROLLMENT', 'TRANSFER');

-- CreateEnum
CREATE TYPE "EnrollmentStatus" AS ENUM ('PENDING', 'ACTIVE', 'CANCELLED');

-- CreateEnum
CREATE TYPE "EnrollmentRegime" AS ENUM ('EXTERNAL', 'HALF_BOARD', 'BOARDING');

-- CreateEnum
CREATE TYPE "AdmissionType" AS ENUM ('EXAM', 'DOSSIER', 'DIRECT');

-- CreateEnum
CREATE TYPE "AdmissionStatus" AS ENUM ('PENDING', 'ACCEPTED', 'REJECTED', 'WAITLISTED');

-- CreateEnum
CREATE TYPE "SubjectCategory" AS ENUM ('LITERARY', 'SCIENTIFIC', 'TECHNICAL', 'LANGUAGE', 'SPORTS');

-- CreateEnum
CREATE TYPE "InvoiceStatus" AS ENUM ('PENDING', 'PARTIAL', 'PAID', 'OVERDUE');

-- CreateEnum
CREATE TYPE "PaymentMethod" AS ENUM ('CASH', 'MOBILE_MONEY', 'BANK_TRANSFER', 'CHECK');

-- CreateEnum
CREATE TYPE "DiscountType" AS ENUM ('PERCENTAGE', 'FIXED');

-- CreateEnum
CREATE TYPE "DayOfWeek" AS ENUM ('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY');

-- CreateEnum
CREATE TYPE "AttendanceStatus" AS ENUM ('PRESENT', 'ABSENT', 'LATE', 'EXCUSED');

-- CreateEnum
CREATE TYPE "IncidentType" AS ENUM ('RETARD', 'ABSENCE_INJUSTIFIEE', 'INSOLENCE', 'BAGARRE', 'TRICHERIE', 'AUTRE');

-- CreateEnum
CREATE TYPE "SanctionType" AS ENUM ('AVERTISSEMENT', 'BLAME', 'EXCLUSION_COURS', 'EXCLUSION_TEMPORAIRE', 'CONVOCATION_PARENTS', 'AUTRE');

-- CreateEnum
CREATE TYPE "AssetCategory" AS ENUM ('MOBILIER', 'EQUIPEMENT', 'BATIMENT', 'VEHICULE');

-- CreateEnum
CREATE TYPE "AssetStatus" AS ENUM ('ACTIF', 'ENDOMMAGE', 'HORS_SERVICE');

-- CreateEnum
CREATE TYPE "LeaveStatus" AS ENUM ('PENDING', 'APPROVED', 'REJECTED');

-- CreateEnum
CREATE TYPE "GradeType" AS ENUM ('TEST', 'HOMEWORK', 'EXAM', 'RESIT');

-- CreateEnum
CREATE TYPE "AnnualDecision" AS ENUM ('PASS', 'REPEAT', 'EXCLUDE');

-- CreateEnum
CREATE TYPE "EstablishmentRequestStatus" AS ENUM ('PENDING', 'APPROVED', 'REJECTED');

-- CreateEnum
CREATE TYPE "OfficialExamCode" AS ENUM ('CEP', 'BEPC', 'PROBATOIRE', 'BAC', 'CUSTOM');

-- CreateEnum
CREATE TYPE "ExamRegistrationStatus" AS ENUM ('PENDING', 'VALIDATED', 'REJECTED', 'ABSENT', 'PASSED', 'FAILED');

-- CreateTable
CREATE TABLE "tenants" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "type" "TenantType" NOT NULL,
    "status" "TenantStatus" NOT NULL DEFAULT 'PUBLIC',
    "active" BOOLEAN NOT NULL DEFAULT true,
    "address" TEXT,
    "phone" TEXT,
    "email" TEXT,
    "logo_url" TEXT,
    "public_enrollment_enabled" BOOLEAN NOT NULL DEFAULT false,
    "config_json" JSONB,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "tenants_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "users" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "password_hash" TEXT NOT NULL,
    "first_name" TEXT NOT NULL,
    "last_name" TEXT NOT NULL,
    "phone" TEXT,
    "status" "UserStatus" NOT NULL DEFAULT 'ACTIVE',
    "mfa_enabled" BOOLEAN NOT NULL DEFAULT false,
    "mfa_secret" TEXT,
    "last_login" TIMESTAMP(3),
    "avatar_url" TEXT,
    "failed_login_attempts" INTEGER NOT NULL DEFAULT 0,
    "locked_until" TIMESTAMP(3),
    "must_change_password" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "roles" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "is_system" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "roles_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "permissions" (
    "id" TEXT NOT NULL,
    "resource" TEXT NOT NULL,
    "action" TEXT NOT NULL,
    "description" TEXT,

    CONSTRAINT "permissions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "role_permissions" (
    "role_id" TEXT NOT NULL,
    "permission_id" TEXT NOT NULL,

    CONSTRAINT "role_permissions_pkey" PRIMARY KEY ("role_id","permission_id")
);

-- CreateTable
CREATE TABLE "account_activation_tokens" (
    "id" TEXT NOT NULL,
    "user_id" TEXT NOT NULL,
    "token_hash" TEXT NOT NULL,
    "expires_at" TIMESTAMP(3) NOT NULL,
    "used_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "account_activation_tokens_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "user_roles" (
    "user_id" TEXT NOT NULL,
    "role_id" TEXT NOT NULL,

    CONSTRAINT "user_roles_pkey" PRIMARY KEY ("user_id","role_id")
);

-- CreateTable
CREATE TABLE "academic_years" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "label" TEXT NOT NULL,
    "start_date" TIMESTAMP(3) NOT NULL,
    "end_date" TIMESTAMP(3) NOT NULL,
    "status" "AcademicYearStatus" NOT NULL DEFAULT 'ACTIVE',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "academic_years_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "periods" (
    "id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "type" "PeriodType" NOT NULL,
    "number" INTEGER NOT NULL,
    "start_date" TIMESTAMP(3) NOT NULL,
    "end_date" TIMESTAMP(3) NOT NULL,
    "grading_status" "GradingStatus" NOT NULL DEFAULT 'CLOSED',

    CONSTRAINT "periods_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "audit_logs" (
    "id" TEXT NOT NULL,
    "user_id" TEXT,
    "action" TEXT NOT NULL,
    "resource" TEXT NOT NULL,
    "resource_id" TEXT,
    "old_value" JSONB,
    "new_value" JSONB,
    "ip_address" TEXT,
    "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "audit_logs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "cycles" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "order" INTEGER NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "cycles_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "departments" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "head_teacher_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "departments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "programs" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "cycle_id" TEXT NOT NULL,
    "department_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "programs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "levels" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "order" INTEGER NOT NULL,
    "cycle_id" TEXT NOT NULL,
    "program_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "levels_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "rooms" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "type" "RoomType" NOT NULL DEFAULT 'SALLE_CLASSE',
    "capacity" INTEGER,
    "building" TEXT,
    "floor" TEXT,
    "equipment" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "rooms_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "classrooms" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "capacity" INTEGER NOT NULL,
    "level_id" TEXT NOT NULL,
    "main_teacher_id" TEXT,
    "room_id" TEXT,
    "section" "Section" NOT NULL DEFAULT 'FRANCOPHONE',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "classrooms_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "groups" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "type" "GroupType" NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "groups_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "communication_templates" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "channel" "Channel" NOT NULL,
    "subject_fr" TEXT,
    "subject_en" TEXT,
    "body_fr" TEXT NOT NULL,
    "body_en" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "communication_templates_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "communication_messages" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "template_id" TEXT,
    "channel" "Channel" NOT NULL,
    "recipient_user_id" TEXT NOT NULL,
    "subject" TEXT,
    "body" TEXT NOT NULL,
    "status" "MessageStatus" NOT NULL DEFAULT 'PENDING',
    "provider_message_id" TEXT,
    "error_message" TEXT,
    "sent_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "communication_messages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "user_channel_preferences" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "user_id" TEXT NOT NULL,
    "preferred_channel" "Channel" NOT NULL,
    "fallback_channel" "Channel",
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "user_channel_preferences_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "students" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "matricule" TEXT NOT NULL,
    "first_name" TEXT NOT NULL,
    "last_name" TEXT NOT NULL,
    "date_of_birth" TIMESTAMP(3) NOT NULL,
    "place_of_birth" TEXT,
    "gender" "Gender" NOT NULL,
    "nationality" TEXT NOT NULL DEFAULT 'Camerounaise',
    "photo_url" TEXT,
    "blood_group" TEXT,
    "allergies" TEXT,
    "handicap" TEXT,
    "emergency_contact" TEXT,
    "status" "StudentStatus" NOT NULL DEFAULT 'ACTIVE',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),
    "user_id" TEXT,

    CONSTRAINT "students_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "parents" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "first_name" TEXT NOT NULL,
    "last_name" TEXT NOT NULL,
    "phone" TEXT NOT NULL,
    "whatsapp" TEXT,
    "email" TEXT,
    "profession" TEXT,
    "address" TEXT,
    "relationship" "ParentRelationship" NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),
    "user_id" TEXT,

    CONSTRAINT "parents_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "student_parents" (
    "student_id" TEXT NOT NULL,
    "parent_id" TEXT NOT NULL,
    "relationship" "ParentRelationship" NOT NULL,

    CONSTRAINT "student_parents_pkey" PRIMARY KEY ("student_id","parent_id")
);

-- CreateTable
CREATE TABLE "enrollments" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "enrollment_date" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "type" "EnrollmentType" NOT NULL DEFAULT 'NEW',
    "status" "EnrollmentStatus" NOT NULL DEFAULT 'ACTIVE',
    "regime" "EnrollmentRegime" NOT NULL DEFAULT 'EXTERNAL',
    "is_repeater" BOOLEAN NOT NULL DEFAULT false,
    "previous_school" TEXT,
    "previous_average" DOUBLE PRECISION,
    "documents" JSONB,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "enrollments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "admission_applications" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "applicant_name" TEXT NOT NULL,
    "applicant_info" JSONB,
    "type" "AdmissionType" NOT NULL DEFAULT 'DOSSIER',
    "score" DOUBLE PRECISION,
    "rank" INTEGER,
    "status" "AdmissionStatus" NOT NULL DEFAULT 'PENDING',
    "academic_year_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "admission_applications_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "matricule_sequences" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "year" TEXT NOT NULL,
    "last_number" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "matricule_sequences_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "internal_messages" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "sender_user_id" TEXT NOT NULL,
    "recipient_user_id" TEXT NOT NULL,
    "body" TEXT NOT NULL,
    "read_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "internal_messages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "subjects" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "coefficient" DECIMAL(5,2) NOT NULL,
    "weekly_hours" INTEGER NOT NULL,
    "category" "SubjectCategory" NOT NULL,
    "is_eliminatory" BOOLEAN NOT NULL DEFAULT false,
    "eliminatory_threshold" DECIMAL(5,2) NOT NULL DEFAULT 0,
    "level_ids" TEXT[] DEFAULT ARRAY[]::TEXT[],
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "subjects_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "teaching_units" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "credits" INTEGER NOT NULL,
    "semester" INTEGER NOT NULL,
    "is_fundamental" BOOLEAN NOT NULL DEFAULT false,
    "department_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "teaching_units_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "course_elements" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "credits" INTEGER NOT NULL,
    "hours_cm" INTEGER NOT NULL DEFAULT 0,
    "hours_td" INTEGER NOT NULL DEFAULT 0,
    "hours_tp" INTEGER NOT NULL DEFAULT 0,
    "coefficient" DECIMAL(5,2) NOT NULL DEFAULT 1,
    "teaching_unit_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "course_elements_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "subject_assignments" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "subject_id" TEXT,
    "course_element_id" TEXT,
    "teacher_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "subject_assignments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "fee_structures" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "level_id" TEXT,
    "academic_year_id" TEXT NOT NULL,
    "total_amount" DOUBLE PRECISION NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "fee_structures_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "fee_installments" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "fee_structure_id" TEXT NOT NULL,
    "label" TEXT NOT NULL,
    "amount" DOUBLE PRECISION NOT NULL,
    "due_date" TIMESTAMP(3) NOT NULL,
    "order" INTEGER NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "fee_installments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "invoices" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "enrollment_id" TEXT NOT NULL,
    "fee_structure_id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "total_amount" DOUBLE PRECISION NOT NULL,
    "paid_amount" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "balance" DOUBLE PRECISION NOT NULL,
    "due_date" TIMESTAMP(3),
    "status" "InvoiceStatus" NOT NULL DEFAULT 'PENDING',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "invoices_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "payments" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "invoice_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "amount" DOUBLE PRECISION NOT NULL,
    "method" "PaymentMethod" NOT NULL,
    "reference" TEXT,
    "receipt_number" TEXT NOT NULL,
    "paid_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "received_by" TEXT,
    "notes" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "payments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "discounts" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT,
    "invoice_id" TEXT,
    "type" "DiscountType" NOT NULL,
    "value" DOUBLE PRECISION NOT NULL,
    "reason" TEXT,
    "approved_by" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "discounts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "receipt_sequences" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "year" TEXT NOT NULL,
    "last_number" INTEGER NOT NULL DEFAULT 0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "receipt_sequences_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "timetable_slots" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "subject_id" TEXT NOT NULL,
    "teacher_id" TEXT NOT NULL,
    "room_id" TEXT,
    "day_of_week" "DayOfWeek" NOT NULL,
    "start_time" TEXT NOT NULL,
    "end_time" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "timetable_slots_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "attendances" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "status" "AttendanceStatus" NOT NULL,
    "reason" TEXT,
    "justified_by" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "attendances_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "discipline_incidents" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "reported_by" TEXT NOT NULL,
    "type" "IncidentType" NOT NULL,
    "description" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "sanction" "SanctionType",
    "sanction_details" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "discipline_incidents_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "course_log_entries" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "subject_id" TEXT NOT NULL,
    "teacher_id" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "period_covered" TEXT,
    "content" TEXT NOT NULL,
    "homework" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "course_log_entries_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "health_records" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "blood_type" TEXT,
    "allergies" TEXT,
    "chronic_diseases" TEXT,
    "medications" TEXT,
    "vaccinations" TEXT,
    "emergency_contact" TEXT,
    "notes" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "health_records_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "clubs" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "supervisor_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "clubs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "club_members" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "club_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "joined_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "club_members_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "school_events" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "description" TEXT,
    "start_date" TIMESTAMP(3) NOT NULL,
    "end_date" TIMESTAMP(3) NOT NULL,
    "location" TEXT,
    "organizer_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "school_events_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "library_books" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "title" TEXT NOT NULL,
    "author" TEXT,
    "isbn" TEXT,
    "category" TEXT,
    "quantity" INTEGER NOT NULL DEFAULT 1,
    "available" INTEGER NOT NULL DEFAULT 1,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "library_books_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "library_borrows" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "book_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "borrow_date" TIMESTAMP(3) NOT NULL,
    "due_date" TIMESTAMP(3) NOT NULL,
    "return_date" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "library_borrows_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "transport_routes" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "vehicle_id" TEXT,
    "stops" TEXT,
    "schedule" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "transport_routes_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "transport_vehicles" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "capacity" INTEGER NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'ACTIVE',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "transport_vehicles_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "transport_subscriptions" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "route_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "stop_name" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "transport_subscriptions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "catering_menus" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "meal" TEXT NOT NULL,
    "items" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "catering_menus_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "assets" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "category" "AssetCategory" NOT NULL,
    "acquisition_date" TIMESTAMP(3),
    "acquisition_value" DOUBLE PRECISION,
    "status" "AssetStatus" NOT NULL DEFAULT 'ACTIF',
    "location" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "assets_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "asset_maintenances" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "asset_id" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "description" TEXT NOT NULL,
    "cost" DOUBLE PRECISION,
    "technician" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "asset_maintenances_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "employees" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "user_id" TEXT,
    "first_name" TEXT NOT NULL,
    "last_name" TEXT NOT NULL,
    "position" TEXT NOT NULL,
    "department" TEXT,
    "hire_date" TIMESTAMP(3) NOT NULL,
    "salary" DOUBLE PRECISION,
    "status" TEXT NOT NULL DEFAULT 'ACTIVE',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "employees_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "leave_requests" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "employee_id" TEXT NOT NULL,
    "start_date" TIMESTAMP(3) NOT NULL,
    "end_date" TIMESTAMP(3) NOT NULL,
    "reason" TEXT NOT NULL,
    "status" "LeaveStatus" NOT NULL DEFAULT 'PENDING',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "leave_requests_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "grades" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "subject_id" TEXT,
    "course_element_id" TEXT,
    "period_id" TEXT NOT NULL,
    "teacher_id" TEXT NOT NULL,
    "type" "GradeType" NOT NULL DEFAULT 'TEST',
    "value" DECIMAL(5,2),
    "max_value" DECIMAL(5,2) NOT NULL DEFAULT 20,
    "weight" DECIMAL(5,2) NOT NULL DEFAULT 1,
    "date" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "comment" TEXT,
    "is_absent" BOOLEAN NOT NULL DEFAULT false,
    "is_justified" BOOLEAN NOT NULL DEFAULT false,
    "is_locked" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "grades_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "grade_calculations" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "period_id" TEXT NOT NULL,
    "subject_id" TEXT,
    "course_element_id" TEXT,
    "classroom_id" TEXT NOT NULL,
    "calculated_average" DECIMAL(5,2) NOT NULL,
    "coefficient" DECIMAL(5,2) NOT NULL DEFAULT 1,
    "weighted_total" DECIMAL(8,2) NOT NULL,
    "rank" INTEGER,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "grade_calculations_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "period_results" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "period_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "general_average" DECIMAL(5,2) NOT NULL,
    "rank" INTEGER,
    "total_students" INTEGER,
    "mention" TEXT,
    "decision" TEXT,
    "observations" TEXT,
    "teacher_comment" TEXT,
    "is_published" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "period_results_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "annual_results" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "academic_year_id" TEXT NOT NULL,
    "annual_average" DECIMAL(5,2) NOT NULL,
    "rank" INTEGER,
    "mention" TEXT,
    "decision" "AnnualDecision",
    "credits_validated" INTEGER,
    "gpa" DECIMAL(4,2),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "annual_results_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "bulletins" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "period_id" TEXT NOT NULL,
    "classroom_id" TEXT NOT NULL,
    "verification_code" TEXT NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'draft',
    "pdf_url" TEXT,
    "data" JSONB NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "bulletins_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "establishment_requests" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "type" "TenantType" NOT NULL,
    "status" "TenantStatus" NOT NULL DEFAULT 'PUBLIC',
    "address" TEXT,
    "phone" TEXT,
    "email" TEXT,
    "director_first_name" TEXT NOT NULL,
    "director_last_name" TEXT NOT NULL,
    "director_email" TEXT NOT NULL,
    "director_phone" TEXT,
    "request_status" "EstablishmentRequestStatus" NOT NULL DEFAULT 'PENDING',
    "rejection_reason" TEXT,
    "created_tenant_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "establishment_requests_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "official_exams" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "code" "OfficialExamCode" NOT NULL,
    "level_id" TEXT,
    "academic_year_id" TEXT NOT NULL,
    "registration_start" TIMESTAMP(3) NOT NULL,
    "registration_end" TIMESTAMP(3) NOT NULL,
    "exam_start" TIMESTAMP(3),
    "exam_end" TIMESTAMP(3),
    "fee_amount" DECIMAL(12,2) NOT NULL DEFAULT 0,
    "min_age" INTEGER,
    "max_age" INTEGER,
    "required_sequences" INTEGER NOT NULL DEFAULT 0,
    "pass_mark" DECIMAL(5,2) NOT NULL DEFAULT 10,
    "oral_min_mark" DECIMAL(5,2),
    "is_official" BOOLEAN NOT NULL DEFAULT true,
    "config_json" JSONB,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,
    "deleted_at" TIMESTAMP(3),

    CONSTRAINT "official_exams_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "exam_registrations" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "exam_id" TEXT NOT NULL,
    "student_id" TEXT NOT NULL,
    "registration_number" TEXT NOT NULL,
    "center_code" TEXT,
    "center_name" TEXT,
    "series" TEXT,
    "status" "ExamRegistrationStatus" NOT NULL DEFAULT 'PENDING',
    "fee_paid" BOOLEAN NOT NULL DEFAULT false,
    "average" DECIMAL(5,2),
    "mention" TEXT,
    "rank" INTEGER,
    "registered_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "validated_by" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "exam_registrations_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "exam_results" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "registration_id" TEXT NOT NULL,
    "subject" TEXT NOT NULL,
    "coefficient" DECIMAL(5,2) NOT NULL DEFAULT 1,
    "mark" DECIMAL(5,2),
    "is_absent" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "exam_results_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "payment_transactions" (
    "id" TEXT NOT NULL,
    "tenant_id" TEXT NOT NULL,
    "gateway_id" TEXT,
    "external_reference" TEXT,
    "amount" DECIMAL(12,2) NOT NULL,
    "currency" TEXT NOT NULL DEFAULT 'XAF',
    "phone_number" TEXT NOT NULL,
    "network" TEXT,
    "status" TEXT NOT NULL DEFAULT 'PENDING',
    "fees" DECIMAL(12,2),
    "net_amount" DECIMAL(12,2),
    "student_id" TEXT,
    "invoice_id" TEXT,
    "raw_response" JSONB,
    "notified_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "payment_transactions_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "tenants_code_key" ON "tenants"("code");

-- CreateIndex
CREATE UNIQUE INDEX "users_tenant_id_email_key" ON "users"("tenant_id", "email");

-- CreateIndex
CREATE UNIQUE INDEX "roles_tenant_id_name_key" ON "roles"("tenant_id", "name");

-- CreateIndex
CREATE UNIQUE INDEX "permissions_resource_action_key" ON "permissions"("resource", "action");

-- CreateIndex
CREATE INDEX "account_activation_tokens_token_hash_idx" ON "account_activation_tokens"("token_hash");

-- CreateIndex
CREATE UNIQUE INDEX "academic_years_tenant_id_label_key" ON "academic_years"("tenant_id", "label");

-- CreateIndex
CREATE UNIQUE INDEX "periods_academic_year_id_type_number_key" ON "periods"("academic_year_id", "type", "number");

-- CreateIndex
CREATE UNIQUE INDEX "cycles_tenant_id_code_key" ON "cycles"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "departments_tenant_id_code_key" ON "departments"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "programs_tenant_id_code_key" ON "programs"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "levels_tenant_id_code_key" ON "levels"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "rooms_tenant_id_code_key" ON "rooms"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "classrooms_tenant_id_code_key" ON "classrooms"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "groups_tenant_id_classroom_id_code_key" ON "groups"("tenant_id", "classroom_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "communication_templates_tenant_id_code_key" ON "communication_templates"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "user_channel_preferences_user_id_key" ON "user_channel_preferences"("user_id");

-- CreateIndex
CREATE UNIQUE INDEX "students_user_id_key" ON "students"("user_id");

-- CreateIndex
CREATE INDEX "students_tenant_id_last_name_first_name_idx" ON "students"("tenant_id", "last_name", "first_name");

-- CreateIndex
CREATE UNIQUE INDEX "students_tenant_id_matricule_key" ON "students"("tenant_id", "matricule");

-- CreateIndex
CREATE UNIQUE INDEX "parents_user_id_key" ON "parents"("user_id");

-- CreateIndex
CREATE INDEX "enrollments_tenant_id_classroom_id_status_idx" ON "enrollments"("tenant_id", "classroom_id", "status");

-- CreateIndex
CREATE INDEX "enrollments_tenant_id_student_id_academic_year_id_idx" ON "enrollments"("tenant_id", "student_id", "academic_year_id");

-- CreateIndex
CREATE INDEX "admission_applications_tenant_id_status_idx" ON "admission_applications"("tenant_id", "status");

-- CreateIndex
CREATE UNIQUE INDEX "matricule_sequences_tenant_id_year_key" ON "matricule_sequences"("tenant_id", "year");

-- CreateIndex
CREATE UNIQUE INDEX "subjects_tenant_id_code_key" ON "subjects"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "teaching_units_tenant_id_code_key" ON "teaching_units"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "course_elements_tenant_id_code_key" ON "course_elements"("tenant_id", "code");

-- CreateIndex
CREATE UNIQUE INDEX "subject_assignments_tenant_id_subject_id_classroom_id_acade_key" ON "subject_assignments"("tenant_id", "subject_id", "classroom_id", "academic_year_id");

-- CreateIndex
CREATE UNIQUE INDEX "subject_assignments_tenant_id_course_element_id_classroom_i_key" ON "subject_assignments"("tenant_id", "course_element_id", "classroom_id", "academic_year_id");

-- CreateIndex
CREATE INDEX "fee_structures_tenant_id_academic_year_id_level_id_idx" ON "fee_structures"("tenant_id", "academic_year_id", "level_id");

-- CreateIndex
CREATE INDEX "fee_installments_tenant_id_fee_structure_id_idx" ON "fee_installments"("tenant_id", "fee_structure_id");

-- CreateIndex
CREATE INDEX "invoices_tenant_id_student_id_status_idx" ON "invoices"("tenant_id", "student_id", "status");

-- CreateIndex
CREATE INDEX "invoices_tenant_id_enrollment_id_idx" ON "invoices"("tenant_id", "enrollment_id");

-- CreateIndex
CREATE INDEX "payments_tenant_id_invoice_id_idx" ON "payments"("tenant_id", "invoice_id");

-- CreateIndex
CREATE INDEX "payments_tenant_id_student_id_idx" ON "payments"("tenant_id", "student_id");

-- CreateIndex
CREATE UNIQUE INDEX "payments_tenant_id_receipt_number_key" ON "payments"("tenant_id", "receipt_number");

-- CreateIndex
CREATE INDEX "discounts_tenant_id_student_id_idx" ON "discounts"("tenant_id", "student_id");

-- CreateIndex
CREATE INDEX "discounts_tenant_id_invoice_id_idx" ON "discounts"("tenant_id", "invoice_id");

-- CreateIndex
CREATE UNIQUE INDEX "receipt_sequences_tenant_id_year_key" ON "receipt_sequences"("tenant_id", "year");

-- CreateIndex
CREATE INDEX "course_log_entries_tenant_id_classroom_id_date_idx" ON "course_log_entries"("tenant_id", "classroom_id", "date");

-- CreateIndex
CREATE UNIQUE INDEX "health_records_student_id_key" ON "health_records"("student_id");

-- CreateIndex
CREATE UNIQUE INDEX "health_records_tenant_id_student_id_key" ON "health_records"("tenant_id", "student_id");

-- CreateIndex
CREATE UNIQUE INDEX "club_members_club_id_student_id_key" ON "club_members"("club_id", "student_id");

-- CreateIndex
CREATE INDEX "grades_tenant_id_student_id_period_id_idx" ON "grades"("tenant_id", "student_id", "period_id");

-- CreateIndex
CREATE INDEX "grades_tenant_id_subject_id_period_id_idx" ON "grades"("tenant_id", "subject_id", "period_id");

-- CreateIndex
CREATE INDEX "grades_tenant_id_course_element_id_period_id_idx" ON "grades"("tenant_id", "course_element_id", "period_id");

-- CreateIndex
CREATE INDEX "grades_tenant_id_period_id_is_locked_idx" ON "grades"("tenant_id", "period_id", "is_locked");

-- CreateIndex
CREATE INDEX "grade_calculations_tenant_id_classroom_id_period_id_idx" ON "grade_calculations"("tenant_id", "classroom_id", "period_id");

-- CreateIndex
CREATE UNIQUE INDEX "grade_calculations_tenant_id_student_id_period_id_subject_i_key" ON "grade_calculations"("tenant_id", "student_id", "period_id", "subject_id", "course_element_id");

-- CreateIndex
CREATE INDEX "period_results_tenant_id_classroom_id_period_id_idx" ON "period_results"("tenant_id", "classroom_id", "period_id");

-- CreateIndex
CREATE UNIQUE INDEX "period_results_tenant_id_student_id_period_id_key" ON "period_results"("tenant_id", "student_id", "period_id");

-- CreateIndex
CREATE INDEX "annual_results_tenant_id_classroom_id_academic_year_id_idx" ON "annual_results"("tenant_id", "classroom_id", "academic_year_id");

-- CreateIndex
CREATE UNIQUE INDEX "annual_results_tenant_id_student_id_academic_year_id_key" ON "annual_results"("tenant_id", "student_id", "academic_year_id");

-- CreateIndex
CREATE UNIQUE INDEX "bulletins_verification_code_key" ON "bulletins"("verification_code");

-- CreateIndex
CREATE INDEX "bulletins_tenant_id_classroom_id_period_id_idx" ON "bulletins"("tenant_id", "classroom_id", "period_id");

-- CreateIndex
CREATE UNIQUE INDEX "bulletins_tenant_id_student_id_period_id_key" ON "bulletins"("tenant_id", "student_id", "period_id");

-- CreateIndex
CREATE INDEX "establishment_requests_request_status_idx" ON "establishment_requests"("request_status");

-- CreateIndex
CREATE INDEX "official_exams_tenant_id_idx" ON "official_exams"("tenant_id");

-- CreateIndex
CREATE UNIQUE INDEX "official_exams_tenant_id_academic_year_id_code_name_key" ON "official_exams"("tenant_id", "academic_year_id", "code", "name");

-- CreateIndex
CREATE INDEX "exam_registrations_tenant_id_idx" ON "exam_registrations"("tenant_id");

-- CreateIndex
CREATE INDEX "exam_registrations_exam_id_idx" ON "exam_registrations"("exam_id");

-- CreateIndex
CREATE UNIQUE INDEX "exam_registrations_exam_id_student_id_key" ON "exam_registrations"("exam_id", "student_id");

-- CreateIndex
CREATE INDEX "exam_results_tenant_id_idx" ON "exam_results"("tenant_id");

-- CreateIndex
CREATE INDEX "exam_results_registration_id_idx" ON "exam_results"("registration_id");

-- CreateIndex
CREATE INDEX "payment_transactions_tenant_id_idx" ON "payment_transactions"("tenant_id");

-- CreateIndex
CREATE INDEX "payment_transactions_gateway_id_idx" ON "payment_transactions"("gateway_id");

-- AddForeignKey
ALTER TABLE "users" ADD CONSTRAINT "users_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "roles" ADD CONSTRAINT "roles_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "role_permissions" ADD CONSTRAINT "role_permissions_role_id_fkey" FOREIGN KEY ("role_id") REFERENCES "roles"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "role_permissions" ADD CONSTRAINT "role_permissions_permission_id_fkey" FOREIGN KEY ("permission_id") REFERENCES "permissions"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "account_activation_tokens" ADD CONSTRAINT "account_activation_tokens_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_roles" ADD CONSTRAINT "user_roles_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_roles" ADD CONSTRAINT "user_roles_role_id_fkey" FOREIGN KEY ("role_id") REFERENCES "roles"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "academic_years" ADD CONSTRAINT "academic_years_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "periods" ADD CONSTRAINT "periods_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "audit_logs" ADD CONSTRAINT "audit_logs_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "cycles" ADD CONSTRAINT "cycles_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "departments" ADD CONSTRAINT "departments_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "departments" ADD CONSTRAINT "departments_head_teacher_id_fkey" FOREIGN KEY ("head_teacher_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "programs" ADD CONSTRAINT "programs_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "programs" ADD CONSTRAINT "programs_cycle_id_fkey" FOREIGN KEY ("cycle_id") REFERENCES "cycles"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "programs" ADD CONSTRAINT "programs_department_id_fkey" FOREIGN KEY ("department_id") REFERENCES "departments"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "levels" ADD CONSTRAINT "levels_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "levels" ADD CONSTRAINT "levels_cycle_id_fkey" FOREIGN KEY ("cycle_id") REFERENCES "cycles"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "levels" ADD CONSTRAINT "levels_program_id_fkey" FOREIGN KEY ("program_id") REFERENCES "programs"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "rooms" ADD CONSTRAINT "rooms_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "classrooms" ADD CONSTRAINT "classrooms_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "classrooms" ADD CONSTRAINT "classrooms_level_id_fkey" FOREIGN KEY ("level_id") REFERENCES "levels"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "classrooms" ADD CONSTRAINT "classrooms_main_teacher_id_fkey" FOREIGN KEY ("main_teacher_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "classrooms" ADD CONSTRAINT "classrooms_room_id_fkey" FOREIGN KEY ("room_id") REFERENCES "rooms"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "groups" ADD CONSTRAINT "groups_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "groups" ADD CONSTRAINT "groups_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "communication_templates" ADD CONSTRAINT "communication_templates_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "communication_messages" ADD CONSTRAINT "communication_messages_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "communication_messages" ADD CONSTRAINT "communication_messages_template_id_fkey" FOREIGN KEY ("template_id") REFERENCES "communication_templates"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "communication_messages" ADD CONSTRAINT "communication_messages_recipient_user_id_fkey" FOREIGN KEY ("recipient_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_channel_preferences" ADD CONSTRAINT "user_channel_preferences_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_channel_preferences" ADD CONSTRAINT "user_channel_preferences_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "students" ADD CONSTRAINT "students_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "students" ADD CONSTRAINT "students_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "parents" ADD CONSTRAINT "parents_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "parents" ADD CONSTRAINT "parents_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "student_parents" ADD CONSTRAINT "student_parents_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "student_parents" ADD CONSTRAINT "student_parents_parent_id_fkey" FOREIGN KEY ("parent_id") REFERENCES "parents"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "enrollments" ADD CONSTRAINT "enrollments_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "enrollments" ADD CONSTRAINT "enrollments_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "enrollments" ADD CONSTRAINT "enrollments_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "enrollments" ADD CONSTRAINT "enrollments_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "admission_applications" ADD CONSTRAINT "admission_applications_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "admission_applications" ADD CONSTRAINT "admission_applications_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "matricule_sequences" ADD CONSTRAINT "matricule_sequences_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "internal_messages" ADD CONSTRAINT "internal_messages_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "internal_messages" ADD CONSTRAINT "internal_messages_sender_user_id_fkey" FOREIGN KEY ("sender_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "internal_messages" ADD CONSTRAINT "internal_messages_recipient_user_id_fkey" FOREIGN KEY ("recipient_user_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subjects" ADD CONSTRAINT "subjects_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "teaching_units" ADD CONSTRAINT "teaching_units_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "teaching_units" ADD CONSTRAINT "teaching_units_department_id_fkey" FOREIGN KEY ("department_id") REFERENCES "departments"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_elements" ADD CONSTRAINT "course_elements_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_elements" ADD CONSTRAINT "course_elements_teaching_unit_id_fkey" FOREIGN KEY ("teaching_unit_id") REFERENCES "teaching_units"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_subject_id_fkey" FOREIGN KEY ("subject_id") REFERENCES "subjects"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_course_element_id_fkey" FOREIGN KEY ("course_element_id") REFERENCES "course_elements"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_teacher_id_fkey" FOREIGN KEY ("teacher_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "subject_assignments" ADD CONSTRAINT "subject_assignments_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "fee_structures" ADD CONSTRAINT "fee_structures_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "fee_structures" ADD CONSTRAINT "fee_structures_level_id_fkey" FOREIGN KEY ("level_id") REFERENCES "levels"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "fee_structures" ADD CONSTRAINT "fee_structures_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "fee_installments" ADD CONSTRAINT "fee_installments_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "fee_installments" ADD CONSTRAINT "fee_installments_fee_structure_id_fkey" FOREIGN KEY ("fee_structure_id") REFERENCES "fee_structures"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "invoices" ADD CONSTRAINT "invoices_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "invoices" ADD CONSTRAINT "invoices_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "invoices" ADD CONSTRAINT "invoices_enrollment_id_fkey" FOREIGN KEY ("enrollment_id") REFERENCES "enrollments"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "invoices" ADD CONSTRAINT "invoices_fee_structure_id_fkey" FOREIGN KEY ("fee_structure_id") REFERENCES "fee_structures"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "invoices" ADD CONSTRAINT "invoices_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payments" ADD CONSTRAINT "payments_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payments" ADD CONSTRAINT "payments_invoice_id_fkey" FOREIGN KEY ("invoice_id") REFERENCES "invoices"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payments" ADD CONSTRAINT "payments_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "payments" ADD CONSTRAINT "payments_received_by_fkey" FOREIGN KEY ("received_by") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discounts" ADD CONSTRAINT "discounts_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discounts" ADD CONSTRAINT "discounts_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discounts" ADD CONSTRAINT "discounts_invoice_id_fkey" FOREIGN KEY ("invoice_id") REFERENCES "invoices"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discounts" ADD CONSTRAINT "discounts_approved_by_fkey" FOREIGN KEY ("approved_by") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "receipt_sequences" ADD CONSTRAINT "receipt_sequences_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_subject_id_fkey" FOREIGN KEY ("subject_id") REFERENCES "subjects"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_teacher_id_fkey" FOREIGN KEY ("teacher_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "timetable_slots" ADD CONSTRAINT "timetable_slots_room_id_fkey" FOREIGN KEY ("room_id") REFERENCES "rooms"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "attendances" ADD CONSTRAINT "attendances_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discipline_incidents" ADD CONSTRAINT "discipline_incidents_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discipline_incidents" ADD CONSTRAINT "discipline_incidents_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "discipline_incidents" ADD CONSTRAINT "discipline_incidents_reported_by_fkey" FOREIGN KEY ("reported_by") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_log_entries" ADD CONSTRAINT "course_log_entries_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_log_entries" ADD CONSTRAINT "course_log_entries_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_log_entries" ADD CONSTRAINT "course_log_entries_subject_id_fkey" FOREIGN KEY ("subject_id") REFERENCES "subjects"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "course_log_entries" ADD CONSTRAINT "course_log_entries_teacher_id_fkey" FOREIGN KEY ("teacher_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "health_records" ADD CONSTRAINT "health_records_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "health_records" ADD CONSTRAINT "health_records_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "clubs" ADD CONSTRAINT "clubs_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "clubs" ADD CONSTRAINT "clubs_supervisor_id_fkey" FOREIGN KEY ("supervisor_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "club_members" ADD CONSTRAINT "club_members_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "club_members" ADD CONSTRAINT "club_members_club_id_fkey" FOREIGN KEY ("club_id") REFERENCES "clubs"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "club_members" ADD CONSTRAINT "club_members_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "school_events" ADD CONSTRAINT "school_events_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "school_events" ADD CONSTRAINT "school_events_organizer_id_fkey" FOREIGN KEY ("organizer_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "library_books" ADD CONSTRAINT "library_books_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "library_borrows" ADD CONSTRAINT "library_borrows_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "library_borrows" ADD CONSTRAINT "library_borrows_book_id_fkey" FOREIGN KEY ("book_id") REFERENCES "library_books"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "library_borrows" ADD CONSTRAINT "library_borrows_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_routes" ADD CONSTRAINT "transport_routes_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_routes" ADD CONSTRAINT "transport_routes_vehicle_id_fkey" FOREIGN KEY ("vehicle_id") REFERENCES "transport_vehicles"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_vehicles" ADD CONSTRAINT "transport_vehicles_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_subscriptions" ADD CONSTRAINT "transport_subscriptions_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_subscriptions" ADD CONSTRAINT "transport_subscriptions_route_id_fkey" FOREIGN KEY ("route_id") REFERENCES "transport_routes"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "transport_subscriptions" ADD CONSTRAINT "transport_subscriptions_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "catering_menus" ADD CONSTRAINT "catering_menus_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "assets" ADD CONSTRAINT "assets_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "asset_maintenances" ADD CONSTRAINT "asset_maintenances_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "asset_maintenances" ADD CONSTRAINT "asset_maintenances_asset_id_fkey" FOREIGN KEY ("asset_id") REFERENCES "assets"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "employees" ADD CONSTRAINT "employees_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "leave_requests" ADD CONSTRAINT "leave_requests_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "leave_requests" ADD CONSTRAINT "leave_requests_employee_id_fkey" FOREIGN KEY ("employee_id") REFERENCES "employees"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_subject_id_fkey" FOREIGN KEY ("subject_id") REFERENCES "subjects"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_course_element_id_fkey" FOREIGN KEY ("course_element_id") REFERENCES "course_elements"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_period_id_fkey" FOREIGN KEY ("period_id") REFERENCES "periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grades" ADD CONSTRAINT "grades_teacher_id_fkey" FOREIGN KEY ("teacher_id") REFERENCES "users"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_period_id_fkey" FOREIGN KEY ("period_id") REFERENCES "periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_subject_id_fkey" FOREIGN KEY ("subject_id") REFERENCES "subjects"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_course_element_id_fkey" FOREIGN KEY ("course_element_id") REFERENCES "course_elements"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "grade_calculations" ADD CONSTRAINT "grade_calculations_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "period_results" ADD CONSTRAINT "period_results_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "period_results" ADD CONSTRAINT "period_results_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "period_results" ADD CONSTRAINT "period_results_period_id_fkey" FOREIGN KEY ("period_id") REFERENCES "periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "period_results" ADD CONSTRAINT "period_results_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "annual_results" ADD CONSTRAINT "annual_results_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "annual_results" ADD CONSTRAINT "annual_results_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "annual_results" ADD CONSTRAINT "annual_results_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "annual_results" ADD CONSTRAINT "annual_results_academic_year_id_fkey" FOREIGN KEY ("academic_year_id") REFERENCES "academic_years"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "bulletins" ADD CONSTRAINT "bulletins_tenant_id_fkey" FOREIGN KEY ("tenant_id") REFERENCES "tenants"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "bulletins" ADD CONSTRAINT "bulletins_student_id_fkey" FOREIGN KEY ("student_id") REFERENCES "students"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "bulletins" ADD CONSTRAINT "bulletins_period_id_fkey" FOREIGN KEY ("period_id") REFERENCES "periods"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "bulletins" ADD CONSTRAINT "bulletins_classroom_id_fkey" FOREIGN KEY ("classroom_id") REFERENCES "classrooms"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "exam_registrations" ADD CONSTRAINT "exam_registrations_exam_id_fkey" FOREIGN KEY ("exam_id") REFERENCES "official_exams"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "exam_results" ADD CONSTRAINT "exam_results_registration_id_fkey" FOREIGN KEY ("registration_id") REFERENCES "exam_registrations"("id") ON DELETE CASCADE ON UPDATE CASCADE;
