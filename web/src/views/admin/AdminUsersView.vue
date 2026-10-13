<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import AdminSubnav from './AdminSubnav.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useAction } from './useAction';
import { ROLE_CATALOG, roleKey, roleLevel } from './catalog';
import {
  assignRole,
  createUser,
  delegateRole,
  getPerson,
  listAssignments,
  listOrgUnits,
  listStructures,
  revokeAssignment,
  searchPeople,
  type OrgUnit,
  type PersonLite,
  type RoleAssignment,
  type ScopeType,
  type Structure,
} from '@/api/admin';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n } from '@/i18n';
import type { AdminKey } from '@/i18n/admin-fr';
import { formatDate } from '@/lib/format';

// Utilisateurs et rôles : création d'une personne avec compte, attribution et retrait de rôles par portée, délégations.
// Design : Inscription.html (formulaires), Fondations.html (tableaux, statuts).
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();

const assignments = ref<RoleAssignment[]>([]);
const names = reactive<Record<string, string>>({});
const units = ref<OrgUnit[]>([]);
const classes = ref<Structure[]>([]);
const loaded = ref(false);
const roleFilter = ref('');
const list = useAction();
const userAction = useAction();
const assignAction = useAction();
const revokeAction = useAction();
const notice = ref<{ kind: 'user' | 'assigned' | 'delegated' | 'revoked'; text: string } | null>(null);

const canAssign = computed(() => ctx.can('identity.roles.assign', 'identity.manage'));
const isTenantActor = computed(() => ['super_admin', 'direction_groupe'].includes(ctx.activeRole ?? ''));
const roleOptions = computed(() =>
  ROLE_CATALOG.filter((r) => (r.level === 'establishment' || isTenantActor.value) && (r.code !== 'super_admin' || ctx.activeRole === 'super_admin')),
);
const establishments = computed(() => ctx.tiles.filter((x) => x.kind === 'establishment'));

// Création d'une personne et de son compte
const userForm = reactive({ first_name: '', last_name: '', phone: '', email: '', locale: 'fr' });
const userTouched = ref(false);
const contactMissing = computed(() => userTouched.value && userForm.phone.trim() === '' && userForm.email.trim() === '');

// Choix de la personne
const query = ref('');
const found = ref<PersonLite[]>([]);
const searched = ref(false);
const picked = ref<PersonLite | null>(null);
const searchAction = useAction();

// Attribution
const form = reactive({ role: '', scope_type: 'establishment' as ScopeType, scope_id: '', delegation: false, starts_at: '', ends_at: '' });
const assignTouched = ref(false);
const level = computed(() => roleLevel(form.role));
const scopeChoices = computed<ScopeType[]>(() => (level.value === 'tenant' ? ['tenant'] : ['establishment', 'org_unit', 'class']));
const scopeTargets = computed<Array<{ id: string; name: string }>>(() => {
  if (form.scope_type === 'establishment') return establishments.value.map((e) => ({ id: e.id, name: e.name }));
  if (form.scope_type === 'org_unit') return units.value.map((u) => ({ id: u.id, name: u.name }));
  if (form.scope_type === 'class') return classes.value.filter((s) => s.kind === 'class').map((s) => ({ id: s.id, name: s.name }));
  return [];
});
const needsTarget = computed(() => form.scope_type !== 'tenant');
const errors = computed(() => ({
  person: assignTouched.value && !picked.value,
  role: assignTouched.value && form.role === '',
  target: assignTouched.value && needsTarget.value && form.scope_id === '',
  end: assignTouched.value && form.delegation && form.ends_at === '',
}));

const personName = (id: string): string => names[id] ?? id;
const roleLabel = (code: string): string => (ROLE_CATALOG.some((r) => r.code === code) ? t(roleKey(code)) : code);
const scopeLabel = (a: RoleAssignment): string => {
  switch (a.scope_type) {
    case 'tenant':
      return t('admin.users.scope.tenant');
    case 'establishment':
      return `${t('admin.users.scope.establishment')} · ${tileName(a.scope_id)}`;
    case 'org_unit':
      return `${t('admin.users.scope.org_unit')} · ${units.value.find((u) => u.id === a.scope_id)?.name ?? a.scope_id ?? ''}`;
    default:
      return `${t('admin.users.scope.class')} · ${classes.value.find((c) => c.id === a.scope_id)?.name ?? a.scope_id ?? ''}`;
  }
};
const tileName = (id: string | null): string => ctx.tiles.find((x) => x.id === id)?.name ?? id ?? '';
const canRevoke = (a: RoleAssignment): boolean => canAssign.value || a.delegated_by === auth.me?.person_id;

async function resolveNames(rows: RoleAssignment[]): Promise<void> {
  const ids = [...new Set(rows.map((r) => r.person_id))].filter((id) => !(id in names));
  const results = await Promise.allSettled(ids.map((id) => getPerson(id)));
  results.forEach((r, i) => {
    const id = ids[i] as string;
    if (r.status === 'fulfilled') names[id] = `${r.value.last_name.toUpperCase()} ${r.value.first_name}`;
  });
}

async function load(): Promise<void> {
  const res = await list.run(() => listAssignments(roleFilter.value ? { role: roleFilter.value } : {}));
  if (res) {
    assignments.value = res;
    await resolveNames(res);
  }
  loaded.value = true;
}

async function loadScopes(): Promise<void> {
  try {
    [units.value, classes.value] = await Promise.all([listOrgUnits(), listStructures()]);
  } catch {
    /* les portées « unité » et « classe » seront simplement vides ; l'erreur réelle apparaîtra à l'enregistrement */
  }
}

async function submitUser(): Promise<void> {
  userTouched.value = true;
  notice.value = null;
  if (userForm.first_name.trim() === '' || userForm.last_name.trim() === '' || contactMissing.value) return;
  const res = await userAction.run(() =>
    createUser({
      first_name: userForm.first_name.trim(),
      last_name: userForm.last_name.trim(),
      ...(userForm.phone.trim() ? { phone: userForm.phone.trim() } : {}),
      ...(userForm.email.trim() ? { email: userForm.email.trim() } : {}),
      locale: userForm.locale,
    }),
  );
  if (!res) return;
  const person = { id: res.person_id, first_name: userForm.first_name.trim(), last_name: userForm.last_name.trim() };
  picked.value = person;
  names[person.id] = `${person.last_name.toUpperCase()} ${person.first_name}`;
  notice.value = { kind: 'user', text: t('admin.users.created', { name: `${person.first_name} ${person.last_name}` }) };
  Object.assign(userForm, { first_name: '', last_name: '', phone: '', email: '' });
  userTouched.value = false;
}

async function search(): Promise<void> {
  const res = await searchAction.run(() => searchPeople(query.value));
  if (res) found.value = res;
  searched.value = true;
}

function pick(p: PersonLite): void {
  picked.value = p;
  names[p.id] = `${p.last_name.toUpperCase()} ${p.first_name}`;
}

function onRoleChange(): void {
  form.scope_type = level.value === 'tenant' ? 'tenant' : form.scope_type === 'tenant' ? 'establishment' : form.scope_type;
  form.scope_id = '';
  if (form.scope_type === 'establishment') form.scope_id = ctx.establishmentId ?? '';
}

function onScopeChange(): void {
  form.scope_id = form.scope_type === 'establishment' ? (ctx.establishmentId ?? '') : '';
}

async function submitAssign(): Promise<void> {
  assignTouched.value = true;
  notice.value = null;
  if (errors.value.person || errors.value.role || errors.value.target || errors.value.end || !picked.value) return;
  const person = picked.value;
  const body = {
    person_id: person.id,
    role: form.role,
    scope_type: form.scope_type,
    ...(needsTarget.value ? { scope_id: form.scope_id, establishment_id: form.scope_type === 'establishment' ? form.scope_id : ctx.establishmentId } : {}),
    ...(form.starts_at ? { starts_at: new Date(form.starts_at).toISOString() } : {}),
    ...(form.ends_at ? { ends_at: new Date(`${form.ends_at}T23:59:59`).toISOString() } : {}),
  };
  const delegation = form.delegation;
  const res = await assignAction.run(() => (delegation ? delegateRole(body) : assignRole(body)));
  if (!res) return;
  notice.value = {
    kind: delegation ? 'delegated' : 'assigned',
    text: t(delegation ? 'admin.users.delegated' : 'admin.users.assigned', { name: personName(person.id), role: roleLabel(form.role) }),
  };
  assignTouched.value = false;
  form.role = '';
  form.delegation = false;
  form.starts_at = '';
  form.ends_at = '';
  await load();
}

async function revoke(a: RoleAssignment): Promise<void> {
  notice.value = null;
  const ok = await revokeAction.run(async () => {
    await revokeAssignment(a.id);
    return true;
  });
  if (!ok) return;
  notice.value = { kind: 'revoked', text: t('admin.users.revoked', { name: personName(a.person_id), role: roleLabel(a.role) }) };
  await load();
}

onMounted(async () => {
  await Promise.all([load(), loadScopes()]);
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <p class="eyebrow">{{ t('admin.eyebrow') }}</p>
      <h1>{{ t('admin.users.title') }}</h1>
      <p class="lede">{{ t('admin.users.lede') }}</p>
    </header>

    <AdminSubnav />
    <div v-if="notice" class="notice notice--success" role="status">{{ notice.text }}</div>

    <section class="two">
      <form class="card" novalidate aria-labelledby="u-new" @submit.prevent="submitUser">
        <h2 id="u-new">{{ t('admin.users.new') }}</h2>
        <p class="muted">{{ t('admin.users.newHint') }}</p>
        <ErrorNotice :error="userAction.error.value" />
        <div class="field">
          <label for="u-ln">{{ t('people.lastName') }}</label>
          <input id="u-ln" v-model="userForm.last_name" type="text" maxlength="100" autocomplete="off" :aria-invalid="userTouched && userForm.last_name.trim() === ''" />
        </div>
        <div class="field">
          <label for="u-fn">{{ t('people.firstName') }}</label>
          <input id="u-fn" v-model="userForm.first_name" type="text" maxlength="100" autocomplete="off" :aria-invalid="userTouched && userForm.first_name.trim() === ''" />
        </div>
        <div class="field">
          <label for="u-ph">{{ t('people.phone') }}</label>
          <input id="u-ph" v-model="userForm.phone" type="tel" placeholder="+237…" autocomplete="off" :aria-invalid="contactMissing" aria-describedby="u-contact" />
        </div>
        <div class="field">
          <label for="u-em">{{ t('people.email') }}</label>
          <input id="u-em" v-model="userForm.email" type="email" autocomplete="off" :aria-invalid="contactMissing" aria-describedby="u-contact" />
          <span id="u-contact" class="hint" :class="{ error: contactMissing }">{{ t('admin.users.contactHint') }}</span>
        </div>
        <div class="field">
          <label for="u-loc">{{ t('admin.users.locale') }}</label>
          <select id="u-loc" v-model="userForm.locale">
            <option value="fr">Français</option>
            <option value="en">English</option>
          </select>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="userAction.busy.value || !canAssign">{{ t('admin.users.create') }}</button>
      </form>

      <form class="card" novalidate aria-labelledby="a-new" @submit.prevent="submitAssign">
        <h2 id="a-new">{{ t('admin.users.assign') }}</h2>
        <ErrorNotice :error="assignAction.error.value" />

        <div class="field" role="search">
          <label for="a-q">{{ t('admin.users.person') }}</label>
          <div class="row">
            <input id="a-q" v-model="query" type="search" class="grow" :placeholder="t('people.searchHint')" @keydown.enter.prevent="search" />
            <button type="button" class="btn" :disabled="searchAction.busy.value" @click="search">{{ t('people.searchGo') }}</button>
          </div>
          <ErrorNotice :error="searchAction.error.value" />
          <ul v-if="found.length > 0" class="found" :aria-label="t('admin.users.results')">
            <li v-for="p in found" :key="p.id">
              <button type="button" class="btn btn--quiet" :aria-pressed="picked?.id === p.id" @click="pick(p)">{{ p.last_name.toUpperCase() }} {{ p.first_name }}</button>
            </li>
          </ul>
          <p v-else-if="searched && !searchAction.error.value" class="hint">{{ t('people.none') }}</p>
          <p v-if="picked" class="picked" role="status">{{ t('admin.users.picked', { name: `${picked.last_name.toUpperCase()} ${picked.first_name}` }) }}</p>
          <span v-if="errors.person" class="error">{{ t('admin.users.personRequired') }}</span>
        </div>

        <div class="field">
          <label for="a-role">{{ t('admin.users.role') }}</label>
          <select id="a-role" v-model="form.role" :aria-invalid="errors.role" @change="onRoleChange">
            <option value="" disabled>{{ t('admin.users.chooseRole') }}</option>
            <option v-for="r in roleOptions" :key="r.code" :value="r.code">{{ t(roleKey(r.code)) }}</option>
          </select>
          <span v-if="errors.role" class="error">{{ t('admin.users.roleRequired') }}</span>
        </div>

        <div v-if="form.role" class="field">
          <label for="a-scope">{{ t('admin.users.scopeLabel') }}</label>
          <select id="a-scope" v-model="form.scope_type" :disabled="scopeChoices.length === 1" @change="onScopeChange">
            <option v-for="s in scopeChoices" :key="s" :value="s">{{ t(`admin.users.scope.${s}` as AdminKey) }}</option>
          </select>
          <span class="hint">{{ level === 'tenant' ? t('admin.users.scopeTenantHint') : t('admin.users.scopeHint') }}</span>
        </div>
        <div v-if="form.role && needsTarget" class="field">
          <label for="a-target">{{ t(`admin.users.target.${form.scope_type}` as AdminKey) }}</label>
          <select id="a-target" v-model="form.scope_id" :aria-invalid="errors.target">
            <option value="" disabled>{{ t('admin.users.chooseTarget') }}</option>
            <option v-for="x in scopeTargets" :key="x.id" :value="x.id">{{ x.name }}</option>
          </select>
          <span v-if="errors.target" class="error">{{ t('admin.users.targetRequired') }}</span>
        </div>

        <label class="check"><input v-model="form.delegation" type="checkbox" /> {{ t('admin.users.delegation') }}</label>
        <p v-if="form.delegation" class="hint">{{ t('admin.users.delegationHint') }}</p>
        <div class="grid grid--kpi">
          <div class="field">
            <label for="a-from">{{ t('admin.users.from') }}</label>
            <input id="a-from" v-model="form.starts_at" type="date" />
          </div>
          <div class="field">
            <label for="a-to">{{ form.delegation ? t('admin.users.toRequired') : t('admin.users.to') }}</label>
            <input id="a-to" v-model="form.ends_at" type="date" :aria-invalid="errors.end" />
            <span v-if="errors.end" class="error">{{ t('admin.users.endRequired') }}</span>
          </div>
        </div>
        <button type="submit" class="btn btn--primary" :disabled="assignAction.busy.value || !canAssign">{{ form.delegation ? t('admin.users.delegate') : t('admin.users.assignCta') }}</button>
      </form>
    </section>

    <section class="block" aria-labelledby="l-title">
      <div class="row row--between">
        <h2 id="l-title">{{ t('admin.users.list') }}</h2>
        <div class="field filter">
          <label for="l-role">{{ t('admin.users.filterRole') }}</label>
          <select id="l-role" v-model="roleFilter" @change="load">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="r in ROLE_CATALOG" :key="r.code" :value="r.code">{{ t(roleKey(r.code)) }}</option>
          </select>
        </div>
      </div>
      <ErrorNotice :error="list.error.value" />
      <ErrorNotice :error="revokeAction.error.value" />
      <p v-if="loaded && assignments.length === 0 && !list.error.value" class="card muted">{{ t('admin.users.none') }}</p>
      <div v-else-if="assignments.length > 0" class="table-wrap">
        <table>
          <caption class="sr-only">{{ t('admin.users.list') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('admin.users.person') }}</th>
              <th scope="col">{{ t('admin.users.role') }}</th>
              <th scope="col">{{ t('admin.users.scopeLabel') }}</th>
              <th scope="col">{{ t('admin.users.period') }}</th>
              <th scope="col"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in assignments" :key="a.id">
              <th scope="row">{{ personName(a.person_id) }}</th>
              <td>{{ roleLabel(a.role) }} <span v-if="a.delegated_by" class="tag tag--warning">{{ t('admin.users.delegatedTag') }}</span></td>
              <td>{{ scopeLabel(a) }}</td>
              <td>{{ a.ends_at ? t('admin.users.until', { date: formatDate(a.ends_at, locale) }) : t('admin.users.permanent') }}</td>
              <td>
                <button v-if="canRevoke(a)" type="button" class="btn btn--quiet" :disabled="revokeAction.busy.value" :aria-label="t('admin.users.revokeNamed', { name: personName(a.person_id), role: roleLabel(a.role) })" @click="revoke(a)">{{ t('admin.users.revoke') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted small">{{ t('admin.users.listNote') }}</p>
    </section>
  </div>
</template>

<style scoped>
.two {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 16px;
  align-items: start;
}
.two .card + .card {
  margin-top: 0;
}
.block {
  margin-top: 32px;
}
.grow {
  flex: 1;
  min-width: 160px;
}
.found {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.found .btn[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
}
.picked {
  font-weight: 700;
  margin: 8px 0 0;
}
.filter {
  margin: 0;
  min-width: 220px;
}
.small {
  font-size: 13px;
}
</style>
