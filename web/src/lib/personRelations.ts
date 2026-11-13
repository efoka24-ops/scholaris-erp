// Liens familiaux d'une fiche personne (GET /people/{id}/relations, FIX-R32). Une ligne du serveur vaut un lien
// « related est <kind> de person » : as = « subject » quand la personne de la fiche est l'élève, « related » quand elle est le
// parent, le tuteur ou le garant. Une même personne peut porter plusieurs liens (parent et garant financier) : on les regroupe.

export type RelationKind = 'parent' | 'guardian' | 'financial_guarantor' | 'authorized_pickup' | 'emergency';

export interface RelationRow {
  id: string;
  kind: RelationKind | string;
  as: 'subject' | 'related' | string;
  person: { id: string; first_name: string | null; last_name: string | null; phone: string | null };
}

export interface RelatedPerson {
  personId: string;
  name: string;
  phone: string | null;
  /** Liens autres que « garant financier » (parent, tuteur, personne autorisée, contact d'urgence). */
  kinds: string[];
  financialGuarantor: boolean;
}

const ORDER = ['parent', 'guardian', 'financial_guarantor', 'authorized_pickup', 'emergency'];

function group(rows: RelationRow[]): RelatedPerson[] {
  const byPerson = new Map<string, RelatedPerson>();
  for (const row of rows) {
    const id = row.person.id;
    let entry = byPerson.get(id);
    if (!entry) {
      const name = [(row.person.last_name ?? '').toUpperCase(), row.person.first_name ?? ''].filter((x) => x !== '').join(' ');
      entry = { personId: id, name: name === '' ? '—' : name, phone: row.person.phone, kinds: [], financialGuarantor: false };
      byPerson.set(id, entry);
    }
    if (row.kind === 'financial_guarantor') entry.financialGuarantor = true;
    else if (!entry.kinds.includes(row.kind)) entry.kinds.push(row.kind);
  }
  const rank = (p: RelatedPerson): number => Math.min(...[...p.kinds, ...(p.financialGuarantor ? ['financial_guarantor'] : [])].map((k) => (ORDER.indexOf(k) + 1) || ORDER.length + 1));
  return [...byPerson.values()].sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
}

/** Responsables et garants de la personne de la fiche (elle est l'élève du lien). */
export function guardiansOf(rows: RelationRow[]): RelatedPerson[] {
  return group(rows.filter((r) => r.as === 'subject'));
}

/** Enfants (ou élèves) dont la personne de la fiche est parent, tuteur ou garant. */
export function childrenOf(rows: RelationRow[]): RelatedPerson[] {
  return group(rows.filter((r) => r.as === 'related'));
}
