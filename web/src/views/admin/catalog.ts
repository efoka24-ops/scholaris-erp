// Données de référence de l'administration, recopiées du serveur faute d'API de catalogue (voir le rapport).
import type { AdminKey } from '@/i18n/admin-fr';

/** Rôles du catalogue du serveur (table roles) : niveau « tenant » = client, sinon établissement. */
export const ROLE_CATALOG = [
  { code: 'super_admin', level: 'tenant' },
  { code: 'direction_groupe', level: 'tenant' },
  { code: 'directeur', level: 'establishment' },
  { code: 'comptable', level: 'establishment' },
  { code: 'caissier', level: 'establishment' },
  { code: 'enseignant', level: 'establishment' },
  { code: 'parent', level: 'establishment' },
  { code: 'apprenant', level: 'establishment' },
] as const;

export type RoleCode = (typeof ROLE_CATALOG)[number]['code'];

export function roleLevel(code: string): 'tenant' | 'establishment' {
  return ROLE_CATALOG.find((r) => r.code === code)?.level ?? 'establishment';
}

export const roleKey = (code: string): AdminKey => `admin.role.${code}` as AdminKey;

/** Types de période et de structure admis par type d'établissement (règles du serveur : PeriodKind, StructureKind). */
export function periodKindsFor(type: string): Array<'trimester' | 'sequence' | 'semester'> {
  return type === 'higher' ? ['semester'] : ['trimester', 'sequence'];
}
export function structureKindsFor(type: string): Array<'level' | 'class' | 'group' | 'track'> {
  return type === 'higher' ? ['level', 'class', 'group', 'track'] : ['level', 'class', 'group'];
}

export type SettingKind = 'number' | 'boolean' | 'text' | 'select' | 'list' | 'json';
export type SettingGroup = 'grading' | 'attendance' | 'billing' | 'messaging';

export interface SettingDef {
  key: string;
  group: SettingGroup;
  kind: SettingKind;
  min?: number;
  max?: number;
  step?: number;
  options?: string[];
  /** Éléments de la liste : entiers ou textes. */
  items?: 'int' | 'text';
  /** Valeur appliquée par le serveur quand rien n'est défini, pour l'afficher à la personne. */
  fallback?: string;
}

export const SETTINGS_CATALOG: SettingDef[] = [
  { key: 'assessment.scale', group: 'grading', kind: 'number', min: 1, max: 1000, step: 1, fallback: '20' },
  { key: 'assessment.pass_mark', group: 'grading', kind: 'number', min: 0, max: 1000, step: 0.5, fallback: '10' },
  { key: 'assessment.rounding', group: 'grading', kind: 'number', min: 0, max: 4, step: 1 },
  { key: 'assessment.unjustified_absence', group: 'grading', kind: 'select', options: ['zero', 'excluded'] },
  { key: 'assessment.weighting', group: 'grading', kind: 'select', options: ['coefficient', 'credits'] },
  { key: 'assessment.quorum_percent', group: 'grading', kind: 'number', min: 1, max: 100, step: 1, fallback: '50' },
  { key: 'assessment.mentions', group: 'grading', kind: 'json' },
  { key: 'attendance.max_unjustified_percent', group: 'attendance', kind: 'number', min: 0, max: 100, step: 1, fallback: '30' },
  { key: 'attendance.alert_unjustified_percent', group: 'attendance', kind: 'number', min: 0, max: 100, step: 1, fallback: '25' },
  { key: 'attendance.notify_parents', group: 'attendance', kind: 'boolean', fallback: 'true' },
  { key: 'attendance.qr_window_minutes', group: 'attendance', kind: 'number', min: 1, max: 120, step: 1, fallback: '10' },
  { key: 'attendance.sanction_scale', group: 'attendance', kind: 'list', items: 'text', fallback: 'avertissement, blame, retenue, exclusion_temporaire, conseil_discipline' },
  { key: 'billing.reminder_offsets', group: 'billing', kind: 'list', items: 'int', fallback: '-7, 1, 15' },
  { key: 'billing.receipt_code', group: 'billing', kind: 'text', fallback: '' },
  { key: 'billing.allocation_rule', group: 'billing', kind: 'text' },
  { key: 'billing.sibling_discount', group: 'billing', kind: 'json', fallback: '{"mode":"fixed","value":25000}' },
  { key: 'billing.blocks', group: 'billing', kind: 'json' },
  { key: 'messaging.window_start_hour', group: 'messaging', kind: 'number', min: 0, max: 24, step: 1, fallback: '7' },
  { key: 'messaging.window_end_hour', group: 'messaging', kind: 'number', min: 0, max: 24, step: 1, fallback: '21' },
];

export const SETTING_GROUPS: SettingGroup[] = ['grading', 'attendance', 'billing', 'messaging'];

/** Valeur du serveur → texte du champ. */
export function formatSettingValue(def: SettingDef, value: unknown): string {
  if (value === undefined || value === null) return '';
  switch (def.kind) {
    case 'list':
      return Array.isArray(value) ? value.join(', ') : String(value);
    case 'json':
      return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    case 'boolean':
      return value === true || value === 'true' || value === 1 ? 'true' : 'false';
    default:
      return String(value);
  }
}

export type ParseResult = { ok: true; value: unknown } | { ok: false; reason: 'empty' | 'number' | 'range' | 'list' | 'json' };

/** Texte du champ → valeur à envoyer. Retourne la raison de l'échec pour afficher un message précis. */
export function parseSettingValue(def: SettingDef, raw: string): ParseResult {
  const text = raw.trim();
  switch (def.kind) {
    case 'boolean':
      return { ok: true, value: text === 'true' };
    case 'select':
    case 'text':
      return text === '' ? { ok: false, reason: 'empty' } : { ok: true, value: text };
    case 'number': {
      if (text === '') return { ok: false, reason: 'empty' };
      const n = Number(text.replace(',', '.'));
      if (!Number.isFinite(n)) return { ok: false, reason: 'number' };
      if ((def.min !== undefined && n < def.min) || (def.max !== undefined && n > def.max)) return { ok: false, reason: 'range' };
      return { ok: true, value: n };
    }
    case 'list': {
      const parts = text.split(/[,;\n]/).map((p) => p.trim()).filter((p) => p !== '');
      if (parts.length === 0) return { ok: false, reason: 'empty' };
      if (def.items === 'int') {
        const ints = parts.map((p) => Number(p));
        if (ints.some((n) => !Number.isInteger(n))) return { ok: false, reason: 'list' };
        return { ok: true, value: ints };
      }
      return { ok: true, value: parts };
    }
    case 'json':
      if (text === '') return { ok: false, reason: 'empty' };
      try {
        return { ok: true, value: JSON.parse(text) as unknown };
      } catch {
        return { ok: false, reason: 'json' };
      }
  }
}
