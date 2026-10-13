<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorNotice from '@/components/ErrorNotice.vue';
import AdminLauncher from '@/views/admin/AdminLauncher.vue';
import ApplicationsTile from '@/views/platform/ApplicationsTile.vue';
import { ApiError, http } from '@/api/http';
import type { ContextTile, FamilyPortal, TodoItem } from '@/api/types';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useI18n, type MessageKey } from '@/i18n';
import { landingFor } from '@/lib/landing';
import { formatDateTime, formatXaf } from '@/lib/format';

// Lanceur « Mes établissements » + « À traiter » + « Mes enfants » — design : Main.html.
const { t, locale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const router = useRouter();

const todo = ref<TodoItem[]>([]);
const todoLoaded = ref(false);
const family = ref<FamilyPortal | null>(null);
const error = ref<unknown>(null);
const opening = ref<string | null>(null);

const greeting = computed(() => (auth.displayName ? t('home.greeting', { name: auth.displayName }) : t('home.greetingAnon')));
const typeLabel = (tile: ContextTile): string => (tile.kind === 'group' ? t('home.consolidated') : tile.type ? t(`type.${tile.type}` as MessageKey) : '');
const roleCount = computed(() => new Set(ctx.tiles.flatMap((x) => x.roles.map((r) => r.code))).size);
const initials = (name: string): string =>
  name
    .split(/\s+/)
    .map((w) => w.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase();

async function loadAll(): Promise<void> {
  error.value = null;
  try {
    if (!ctx.loaded && auth.me) await ctx.load(auth.me.context);
  } catch (e) {
    error.value = e;
  }
  try {
    const res = await http.get<{ data: TodoItem[] }>('/me/todo');
    todo.value = res.data;
    todoLoaded.value = true;
  } catch (e) {
    // Hors ligne : l'accueil reste affiché avec ce qui est déjà en mémoire.
    if (!(e instanceof ApiError && e.network)) error.value ??= e;
  }
  if (ctx.activeRole === 'parent') {
    try {
      family.value = (await http.get<{ data: FamilyPortal }>('/portal/family')).data;
    } catch {
      family.value = null; // le bloc « Mes enfants » est un raccourci : l'écran dédié affichera l'erreur
    }
  }
}

async function open(tile: ContextTile): Promise<void> {
  opening.value = tile.id;
  error.value = null;
  try {
    await ctx.switchTo(tile.id);
    await router.push({ name: landingFor(ctx.activeRole) });
  } catch (e) {
    error.value = e;
  } finally {
    opening.value = null;
  }
}

function todoLink(item: TodoItem): string | null {
  const link = item.link;
  if (!link || !link.startsWith('/') || link.startsWith('/api')) return null;
  return link.replace(/^\/app(?=\/|$)/, '') || '/';
}

onMounted(loadAll);
</script>

<template>
  <div class="page launcher">
    <header class="page-head">
      <p class="eyebrow">{{ t('home.eyebrow') }}</p>
      <h1 class="display">{{ greeting }}</h1>
      <p class="lede">{{ t('home.intro') }}</p>
    </header>

    <ErrorNotice :error="error" />
    <AdminLauncher v-if="ctx.loaded && ctx.tenantAdmin" :empty="ctx.tiles.length === 0" />
    <ApplicationsTile v-if="ctx.loaded && ctx.can('applications.manage', 'organisation.manage')" />

    <section aria-labelledby="tiles-title" class="block">
      <div class="row row--between head">
        <h2 id="tiles-title" class="h2">{{ t('home.myEstablishments') }}</h2>
        <span class="muted small">{{ t('home.counts', { establishments: ctx.tiles.filter((x) => x.kind === 'establishment').length, roles: roleCount }) }}</span>
      </div>
      <p v-if="ctx.loaded && ctx.tiles.length === 0 && !ctx.tenantAdmin" class="notice notice--warning">{{ t('home.noContext') }}</p>
      <ul class="grid grid--tiles tiles">
        <li v-for="tile in ctx.tiles" :key="tile.id">
          <article class="tile" :class="{ 'tile--ink': tile.kind === 'group', 'tile--active': tile.id === ctx.activeId }">
            <div class="row row--between">
              <span class="tag" :class="tile.kind === 'group' ? 'tag--onink' : tile.id === ctx.activeId ? 'tag--accent' : ''">{{ typeLabel(tile) }}</span>
              <span v-if="tile.period" class="period">{{ tile.period }}</span>
            </div>
            <div>
              <div class="tile__name">{{ tile.name }}</div>
              <div class="tile__roles">{{ tile.kind === 'group' ? t('home.groupOnly') : tile.roles.map((r) => r.label).join(' · ') }}</div>
            </div>
            <dl v-if="tile.kpis && tile.kpis.length" class="kpis">
              <div v-for="k in tile.kpis" :key="k.label">
                <dd>{{ k.value }}</dd>
                <dt>{{ k.label }}</dt>
              </div>
            </dl>
            <button type="button" class="tile__open" :disabled="opening !== null" :aria-label="t('home.openNamed', { name: tile.name })" @click="open(tile)">
              {{ opening === tile.id ? t('common.loading') : tile.kind === 'group' ? t('home.openGroup') : tile.id === ctx.activeId ? t('home.continue') : t('home.open') }} →
            </button>
          </article>
        </li>
      </ul>
    </section>

    <section class="two">
      <div class="card" aria-labelledby="todo-title">
        <div class="row row--between head">
          <h2 id="todo-title" class="h2">{{ t('home.todo') }}</h2>
          <span class="tag">{{ todo.length }}</span>
        </div>
        <p v-if="todoLoaded && todo.length === 0" class="muted">{{ t('home.todoEmpty') }}</p>
        <ul v-else class="todo">
          <li v-for="(item, i) in todo" :key="i" class="todo__item">
            <span class="todo__bar" :class="{ 'todo__bar--accent': i === 0 }" aria-hidden="true" />
            <div class="todo__text">
              <div class="todo__title">{{ item.title }}</div>
              <div class="muted small">{{ item.kind }}<template v-if="item.due_at"> · {{ t('home.dueAt', { date: formatDateTime(item.due_at, locale) }) }}</template></div>
            </div>
            <router-link v-if="todoLink(item)" class="todo__act" :to="todoLink(item) as string">{{ t('home.handle') }}</router-link>
          </li>
        </ul>
      </div>

      <div v-if="ctx.activeRole === 'parent'" class="card kids" aria-labelledby="kids-title">
        <h2 id="kids-title" class="h2">{{ t('home.myChildren') }}</h2>
        <ul v-if="family" class="kids__list">
          <li v-for="c in family.children" :key="c.person.id" class="row kid">
            <span class="avatar-sq">{{ initials(c.person.name) }}</span>
            <div class="grow">
              <div class="kid__name">{{ c.person.name }}</div>
              <div class="muted small">{{ c.enrollments.map((e) => e.establishment_name).filter(Boolean).join(' · ') }}</div>
            </div>
            <span class="tag" :class="c.balance_xaf > 0 ? 'tag--warning' : 'tag--success'">{{ c.balance_xaf > 0 ? t('family.due', { amount: formatXaf(c.balance_xaf, locale) }) : t('family.upToDate') }}</span>
          </li>
        </ul>
        <div class="row actions">
          <router-link class="btn btn--primary" :to="{ name: 'portal-family' }">{{ t('home.payAll') }}</router-link>
          <router-link class="btn" :to="{ name: 'portal-family' }">{{ t('home.seeFamily') }}</router-link>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.launcher {
  display: flex;
  flex-direction: column;
  gap: 40px;
  padding-top: 48px;
  padding-bottom: 64px;
}
.display {
  font-size: 44px;
  letter-spacing: -0.01em;
  margin: 8px 0;
}
.h2 {
  font-size: 24px;
  margin: 0;
}
.head {
  margin-bottom: 16px;
}
.small {
  font-size: 13px;
}
.tiles {
  list-style: none;
  padding: 0;
  margin: 0;
}
.tile {
  background: var(--surface);
  border: 1px solid var(--border);
  padding: 24px;
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.tile--active {
  border: 2px solid var(--ink);
  padding: 23px;
}
.tile--ink {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.tag--onink {
  background: transparent;
  color: #fff;
  border-color: #fff;
}
.period {
  font-size: 12px;
  font-weight: 700;
  color: var(--muted);
}
.tile__name {
  font-size: 21px;
  font-weight: 800;
}
.tile__roles {
  font-size: 14px;
  color: var(--muted);
  margin-top: 4px;
}
.tile--ink .tile__roles {
  color: var(--on-dark-muted);
}
.kpis {
  display: flex;
  gap: 24px;
  border-top: 1px solid var(--border);
  padding-top: 16px;
  margin: 0;
}
.tile--ink .kpis {
  border-top-color: var(--text-2);
}
.kpis dd {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
}
.kpis dt {
  font-size: 12px;
  color: var(--muted);
}
.tile__open {
  margin-top: auto;
  align-self: flex-start;
  min-height: var(--tap);
  padding: 0;
  background: none;
  border: 0;
  font: 700 15px var(--font);
  color: inherit;
  cursor: pointer;
  text-align: left;
}
.two {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 16px;
}
.two .card + .card {
  margin-top: 0;
}
.todo {
  list-style: none;
  margin: 0;
  padding: 0;
}
.todo__item {
  display: flex;
  gap: 14px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
}
.todo__bar {
  width: 4px;
  background: var(--ink);
  flex: none;
}
.todo__bar--accent {
  background: var(--accent);
}
.todo__text {
  flex: 1;
}
.todo__title {
  font-weight: 700;
}
.todo__act {
  font-weight: 700;
  font-size: 14px;
  align-self: center;
}
.kids {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.kids__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.kid {
  flex-wrap: nowrap;
  gap: 14px;
}
.kid__name {
  font-weight: 700;
}
.grow {
  flex: 1;
}
.actions {
  margin-top: auto;
}
@media (max-width: 640px) {
  .launcher {
    padding-top: 24px;
    gap: 24px;
  }
  .display {
    font-size: 30px;
  }
}
</style>
