import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { routes } from '@/router/routes';
import { permissionsForRoles } from '@/lib/permissions';
import { cellId, loadDraft, setCell, type SheetMark } from '@/views/primary/offlineSheet';
import { partKey } from '@/views/primary/offlineCall';

describe('primaire : saisie hors ligne', () => {
  it('garde la saisie sur l appareil, avec la version vue, et la retire au second appui', async () => {
    const mark: SheetMark = { id: 'M1', person_id: 'p1', competency_id: 'c1', scale_code: 'in_progress', absence_code: null, remark: null, status: 'draft', version: 3 };
    let draft = await loadDraft('E1', 'K1', 'P1');
    expect(draft).toEqual({});
    draft = await setCell('E1', 'K1', 'P1', draft, mark, 'p1', 'c1', 'acquired');
    const cell = draft[cellId('p1', 'c1')]!;
    expect(cell.scale_code).toBe('acquired');
    expect(cell.version).toBe(3);
    expect(cell.client_uuid.length).toBeGreaterThan(10);
    expect(await loadDraft('E1', 'K1', 'P1')).toEqual(draft);
    draft = await setCell('E1', 'K1', 'P1', draft, mark, 'p1', 'c1', 'acquired');
    expect(draft).toEqual({});
    expect(await loadDraft('E1', 'K1', 'P1')).toEqual({});
  });

  it('nouvelle évaluation : aucune version envoyée', async () => {
    const draft = await setCell('E1', 'K2', 'P1', {}, undefined, 'p2', 'c1', 'not_acquired');
    expect(draft[cellId('p2', 'c1')]?.version).toBeUndefined();
  });

  it('la clé de séance par demi-journée suit la forme serveur', () => {
    expect(partKey('K1', 'morning')).toBe('dp:K1:morning');
  });

  it('les routes /primaire sont réservées aux établissements primaires et à des permissions primary', () => {
    const own = routes.filter((r) => r.path.startsWith('/primaire'));
    expect(own.length).toBeGreaterThanOrEqual(8);
    for (const r of own) {
      expect(r.meta?.establishmentTypes).toEqual(['primary']);
      expect(r.meta?.needsEstablishment).toBe(true);
    }
    const teacher = permissionsForRoles(['enseignant']);
    expect(teacher.has('primary.competency.enter')).toBe(true);
    expect(permissionsForRoles(['parent']).has('primary.competency.enter')).toBe(false);
  });
});
