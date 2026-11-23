import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import PlatformHealthView from '@/views/platform/PlatformHealthView.vue';
import PlatformDashboardView from '@/views/platform/PlatformDashboardView.vue';
import { usePlatformStore } from '@/stores/platform';
import type { Me } from '@/api/types';
import { freshEnvironment, mockApi, mountView, signIn, teacherMe } from './helpers';

// C-112 point 1 : santé de la plateforme (page détaillée et tuile du tableau de bord).
beforeEach(() => freshEnvironment());

const platformMe: Me = { ...teacherMe, id: 'UP', person_id: 'PP', first_name: 'Awa', last_name: 'Ngo', greeting: 'Awa', context: { establishment_id: null, role: null } };
function signInPlatform(): void {
  signIn(platformMe, [], null);
  const p = usePlatformStore();
  p.isAdmin = true;
  p.checkedFor = platformMe.id;
}

describe('santé de la plateforme', () => {
  it('affiche chaque sonde avec son état, sa valeur, ses seuils et les identifiants de trace', async () => {
    signInPlatform();
    mockApi({
      'GET /platform/health': { data: { status: 'critical', as_of: '2026-11-06T09:00:00+01:00', probes: [
        { key: 'database', status: 'ok', value: 4, unit: 'ms', thresholds: { warning: 300, critical: 2000, direction: 'above' }, detail: null },
        { key: 'errors_24h', status: 'critical', value: 60, unit: 'errors', thresholds: { warning: 5, critical: 50, direction: 'above' }, detail: null, trace_ids: ['abc123'] },
        { key: 'document_batches', status: 'unknown', value: null, unit: 'batches', thresholds: null, detail: 'Pas de contrat.' },
      ] } },
    });
    const { wrapper } = await mountView(PlatformHealthView, {}, '/platform/sante');
    expect(wrapper.get('h1').text()).toBe('Santé de la plateforme');
    expect(wrapper.get('[data-test="probe-database"]').text()).toContain('4 ms');
    expect(wrapper.get('[data-test="probe-errors_24h"]').text()).toContain('Critique');
    expect(wrapper.get('[data-test="probe-errors_24h"]').text()).toContain('abc123');
    expect(wrapper.get('[data-test="probe-errors_24h"]').text()).toContain('alerte dès 5, critique dès 50');
    expect(wrapper.get('[data-test="probe-document_batches"]').text()).toContain('Inconnu');
  });

  it('la tuile du tableau de bord remplace « Indisponible » et liste les alertes', async () => {
    signInPlatform();
    mockApi({
      'GET /platform/dashboard': { data: { as_of: '2026-11-06T09:00:00+01:00', clients: { available: true, total: 1 },
        health: { available: true, status: 'warning', counts: { ok: 8, warning: 1, critical: 0, unknown: 1 }, alerts: [{ key: 'backup', status: 'warning' }] } } },
    });
    const { wrapper } = await mountView(PlatformDashboardView, {}, '/platform');
    const tile = wrapper.get('[data-test="health-tile"]');
    expect(tile.text()).toContain('Alerte');
    expect(tile.text()).toContain('Dernière sauvegarde');
  });
});
