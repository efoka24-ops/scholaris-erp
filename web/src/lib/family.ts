import type { FamilyChild } from '@/api/types';

export interface EstablishmentDue {
  establishment_id: string;
  establishment_name: string;
  amount_xaf: number;
}

/**
 * Montant dû par établissement, à partir des soldes par enfant du portail famille.
 * Un enfant inscrit dans plusieurs établissements : son solde est attribué au premier (le portail ne détaille pas la répartition).
 */
export function dueByEstablishment(children: FamilyChild[]): EstablishmentDue[] {
  const map = new Map<string, EstablishmentDue>();
  for (const child of children) {
    if (child.balance_xaf <= 0) continue;
    const first = child.enrollments[0];
    if (!first) continue;
    const current = map.get(first.establishment_id);
    if (current) {
      current.amount_xaf += child.balance_xaf;
    } else {
      map.set(first.establishment_id, {
        establishment_id: first.establishment_id,
        establishment_name: first.establishment_name ?? first.establishment_id,
        amount_xaf: child.balance_xaf,
      });
    }
  }
  return [...map.values()];
}
