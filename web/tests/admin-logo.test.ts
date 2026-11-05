import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EstablishmentLogoCard from '@/views/admin/EstablishmentLogoCard.vue';
import AdminEstablishmentView from '@/views/admin/AdminEstablishmentView.vue';
import { setLocale } from '@/i18n';
import { checkLogoFile, LOGO_MAX_BYTES } from '@/api/admin';
import { flushPromises, freshEnvironment, jsonResponse, mockApi, mountView, signIn, teacherMe, tile } from './helpers';

beforeEach(freshEnvironment);
afterEach(() => vi.restoreAllMocks());

const directorMe = { ...teacherMe, context: { establishment_id: 'E1', role: 'directeur' } };
const directorTile = tile({ roles: [{ code: 'directeur', label: 'Directeur' }] });

const image = (type = 'image/png') => new Response(new Blob(['x'], { type }), { status: 200, headers: { 'Content-Type': type } });
const notFound = () => jsonResponse({ type: 'about:blank', title: 'Introuvable', status: 404, detail: 'Cet établissement n’a pas de logo.', trace_id: 'T' }, 404);
const file = (name: string, type: string, size = 100): File => {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: size });
  return f;
};

async function pick(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], f: File): Promise<void> {
  const input = wrapper.get('#logo-file');
  Object.defineProperty(input.element, 'files', { value: [f], configurable: true });
  await input.trigger('change');
}

describe('contrôle du fichier côté navigateur', () => {
  it('accepte PNG et JPEG de 1 Mo au plus, refuse le reste', () => {
    expect(checkLogoFile({ type: 'image/png', size: 1000 })).toBeNull();
    expect(checkLogoFile({ type: 'image/jpeg', size: LOGO_MAX_BYTES })).toBeNull();
    expect(checkLogoFile({ type: 'image/gif', size: 1000 })).toBe('type');
    expect(checkLogoFile({ type: 'image/svg+xml', size: 1000 })).toBe('type');
    expect(checkLogoFile({ type: 'application/pdf', size: 1000 })).toBe('type');
    expect(checkLogoFile({ type: 'image/png', size: LOGO_MAX_BYTES + 1 })).toBe('size');
    expect(checkLogoFile({ type: 'image/png', size: 0 })).toBe('size');
  });
});

describe('carte Logo de la fiche établissement', () => {
  beforeEach(() => {
    signIn(directorMe, [directorTile], 'directeur');
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:logo'), revokeObjectURL: vi.fn() }));
  });

  it('affiche l\'aperçu du logo actuel, sans en-tête d\'établissement', async () => {
    const calls = mockApi({ 'GET /establishments/E1/logo': image() });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    expect(wrapper.get('img').attributes('src')).toBe('blob:logo');
    expect(wrapper.get('img').attributes('alt')).toBe('Logo actuel de l’établissement');
    expect(calls[0]?.headers['X-Establishment-Id']).toBeUndefined();
    expect(wrapper.text()).toContain('Remplacer le logo');
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('sans logo : message d\'absence, pas d\'erreur, bouton d\'envoi désactivé tant qu\'aucun fichier n\'est choisi', async () => {
    mockApi({ 'GET /establishments/E1/logo': notFound() });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.text()).toContain('Aucun logo');
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect(wrapper.get('button.btn--primary').attributes('disabled')).toBeDefined();
  });

  it('refuse un GIF et un fichier trop lourd avant tout envoi', async () => {
    const calls = mockApi({ 'GET /establishments/E1/logo': notFound() });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    await pick(wrapper, file('logo.gif', 'image/gif'));
    expect(wrapper.get('#logo-hint').text()).toContain('seuls les formats PNG et JPEG');
    expect(wrapper.get('#logo-file').attributes('aria-invalid')).toBe('true');

    await pick(wrapper, file('gros.png', 'image/png', LOGO_MAX_BYTES + 1));
    expect(wrapper.get('#logo-hint').text()).toContain('1 Mo au plus');
    expect(wrapper.get('button.btn--primary').attributes('disabled')).toBeDefined();
    expect(calls.filter((c) => c.method !== 'GET')).toHaveLength(0);
  });

  it('téléverse en fichier joint (POST multipart, champ file), puis recharge l\'aperçu', async () => {
    let stored = false;
    const calls = mockApi({
      'GET /establishments/E1/logo': () => (stored ? image() : notFound()),
      'POST /establishments/E1/logo': () => {
        stored = true;
        return { data: { logo: { path: 'tenants/T/establishments/E1/logo/x.png', mime: 'image/png', size: 100 } } };
      },
      'GET /me/contexts': { data: [directorTile] },
    });
    const append = vi.spyOn(FormData.prototype, 'append');
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    await pick(wrapper, file('mon-logo.png', 'image/png'));
    expect(wrapper.text()).toContain('Image choisie : mon-logo.png');
    await wrapper.get('button.btn--primary').trigger('click');
    await flushPromises();

    const post = calls.find((c) => c.path === '/establishments/E1/logo' && c.method === 'POST');
    expect(post).toBeDefined();
    expect(post?.headers['X-HTTP-Method-Override']).toBeUndefined();
    expect(post?.headers['Content-Type']).toBeUndefined(); // posé par le navigateur, avec la frontière multipart
    expect(post?.headers['X-Establishment-Id']).toBeUndefined();
    expect(append).toHaveBeenCalledWith('file', expect.any(File), 'mon-logo.png');
    expect(wrapper.get('img').attributes('src')).toBe('blob:logo');
    expect(wrapper.text()).toContain('Logo enregistré');
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('supprime après confirmation : DELETE envoyé en POST avec X-HTTP-Method-Override', async () => {
    let removed = false;
    const calls = mockApi({
      'GET /establishments/E1/logo': () => (removed ? notFound() : image()),
      'DELETE /establishments/E1/logo': () => {
        removed = true;
        return { data: { logo: null } };
      },
    });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    await wrapper.findAll('button').find((b) => b.text() === 'Supprimer le logo')?.trigger('click');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    await wrapper.findAll('button').find((b) => b.text() === 'Confirmer la suppression')?.trigger('click');
    await flushPromises();

    const del = calls.find((c) => c.method === 'DELETE');
    expect(del?.headers['X-HTTP-Method-Override']).toBe('DELETE');
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.text()).toContain('Logo supprimé');
  });

  it('affiche l\'erreur du serveur par ErrorNotice (image refusée)', async () => {
    mockApi({
      'GET /establishments/E1/logo': notFound(),
      'POST /establishments/E1/logo': jsonResponse({ type: 'https://api.scholaris.cm/errors/validation', title: 'Erreur', status: 422, detail: 'Le logo doit être une image PNG ou JPEG valide.', trace_id: 'TR-LOGO' }, 422),
    });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    await pick(wrapper, file('faux.png', 'image/png'));
    await wrapper.get('button.btn--primary').trigger('click');
    await flushPromises();

    expect(wrapper.get('[role="alert"]').text()).toContain('Le logo doit être une image PNG ou JPEG valide.');
    expect(wrapper.get('[role="alert"]').text()).toContain('TR-LOGO');
  });

  it('est disponible en anglais', async () => {
    setLocale('en');
    mockApi({ 'GET /establishments/E1/logo': notFound() });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: true });

    expect(wrapper.text()).toContain('No logo: documents are printed without one.');
    expect(wrapper.text()).toContain('Upload the logo');
    expect(wrapper.get('#logo-hint').text()).toBe('PNG or JPEG, at most 1 Mo.');
    setLocale('fr');
  });

  it('en lecture seule : aucun champ de fichier ni bouton d\'écriture', async () => {
    mockApi({ 'GET /establishments/E1/logo': image() });
    const { wrapper } = await mountView(EstablishmentLogoCard, { id: 'E1', canManage: false });

    expect(wrapper.find('#logo-file').exists()).toBe(false);
    expect(wrapper.find('button').exists()).toBe(false);
    expect(wrapper.find('img').exists()).toBe(true);
  });

  it('est présente sur la fiche de l\'établissement', async () => {
    mockApi({
      'GET /establishments/E1': { data: { id: 'E1', name: 'Collège Les Palmiers', type: 'secondary', branding: {}, modules_enabled: ['people'] } },
      'GET /establishments/E1/branding': { data: { accent: '#F26B1D' } },
      'GET /establishments/E1/modules': { data: { modules_enabled: ['people'], available: ['people'] } },
      'GET /establishments/E1/logo': notFound(),
    });
    const { wrapper } = await mountView(AdminEstablishmentView, { id: 'E1' }, '/administration/etablissements/E1');

    expect(wrapper.get('#logo-title').text()).toBe('Logo');
    expect(wrapper.html()).not.toContain('v-html');
  });
});
