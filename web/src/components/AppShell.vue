<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { routes } from '@/router/routes';
import { useAuthStore } from '@/stores/auth';
import { useContextStore } from '@/stores/context';
import { useOfflineStore } from '@/stores/offline';
import { usePlatformStore } from '@/stores/platform';
import { useI18n } from '@/i18n';
import ErrorNotice from './ErrorNotice.vue';
import OfflineBanner from './OfflineBanner.vue';

// Cadre de l'application, d'après les maquettes Main (lanceur : barre noire) et Dashboard / Frais (barre latérale noire + barre blanche).
const { t, locale, setLocale } = useI18n();
const auth = useAuthStore();
const ctx = useContextStore();
const platform = usePlatformStore();
const offline = useOfflineStore();
const router = useRouter();
const route = useRoute();
const menuOpen = ref(false);
const switchError = ref<unknown>(null);

const launcher = computed(() => route.name === 'home');

const navItems = computed(() =>
  routes
    .filter((r) => r.meta?.nav)
    .filter((r) => {
      const m = r.meta!;
      // Administrateur de plateforme : ses écrans et la sécurité de son compte seulement ; compte client : jamais les écrans /platform.
      if (platform.isAdmin) return !!m.platform || r.name === 'security';
      if (m.platform) return false;
      if (m.roles && !m.roles.some((role) => role === ctx.activeRole)) return false;
      if (m.perms && ctx.loaded && !ctx.can(...m.perms)) return false;
      if (m.establishmentTypes && ctx.loaded && !m.establishmentTypes.includes(ctx.activeTile?.type ?? '')) return false;
      return true;
    })
    .sort((a, b) => a.meta!.nav!.order - b.meta!.nav!.order)
    .map((r) => ({ name: String(r.name), label: t(r.meta!.nav!.labelKey) })),
);

const activeRoleLabel = computed(() => ctx.roles.find((r) => r.code === ctx.activeRole)?.label ?? '');
const alerts = computed(() => offline.pending + offline.needsAttention);
const logoLetter = computed(() => (ctx.activeTile?.name ?? 'S').trim().charAt(0).toUpperCase());

async function onContextChange(event: Event): Promise<void> {
  const id = (event.target as HTMLSelectElement).value;
  switchError.value = null;
  try {
    await ctx.switchTo(id);
    await router.replace({ name: 'home' });
  } catch (e) {
    switchError.value = e;
  }
}

async function onRoleChange(event: Event): Promise<void> {
  const role = (event.target as HTMLSelectElement).value;
  if (!ctx.activeId) return;
  switchError.value = null;
  try {
    await ctx.switchTo(ctx.activeId, role);
  } catch (e) {
    switchError.value = e;
  }
}

async function signOut(): Promise<void> {
  await auth.logout();
  ctx.clear();
  platform.clear();
  await router.replace({ name: 'login' });
}

const initials = computed(() => {
  const m = auth.me;
  return `${m?.first_name?.[0] ?? ''}${m?.last_name?.[0] ?? ''}`.toUpperCase() || '?';
});
</script>

<template>
  <div class="shell" :class="{ 'shell--launcher': launcher }">
    <a href="#contenu" class="skip-link">{{ t('shell.skip') }}</a>

    <aside v-if="!launcher" class="side" :class="{ open: menuOpen }">
      <div class="brand">
        <span class="logo-sq">{{ logoLetter }}</span>
        <div>
          <div class="brand__name">{{ ctx.activeTile?.name ?? 'SCHOLARIS' }}</div>
          <div class="brand__sub">{{ t('shell.via') }}</div>
        </div>
      </div>
      <p v-if="platform.isAdmin" class="eyebrow side__space">{{ t('plat.space') }}</p>
      <p v-else-if="activeRoleLabel" class="eyebrow side__space">{{ t('shell.space', { role: activeRoleLabel }) }}</p>
      <nav id="nav" :aria-label="t('shell.navigation')">
        <router-link v-for="item in navItems" :key="item.name" class="nav" :to="{ name: item.name }" @click="menuOpen = false">{{ item.label }}</router-link>
      </nav>
      <div class="side__foot">
        <router-link v-if="platform.isAdmin" :to="{ name: 'platform-dashboard' }" class="side__strong">← {{ t('plat.shell.home') }}</router-link>
        <router-link v-else :to="{ name: 'home' }" class="side__strong">← {{ t('shell.myEstablishments') }}</router-link>
        <button type="button" class="side__link" @click="signOut">{{ t('shell.signOut') }}</button>
      </div>
    </aside>

    <div class="main-col">
      <header class="topbar" :class="{ 'topbar--dark': launcher }">
        <button v-if="!launcher" type="button" class="sel burger" :aria-expanded="menuOpen" aria-controls="nav" @click="menuOpen = !menuOpen">
          <span aria-hidden="true">☰</span><span class="sr-only">{{ t('shell.menu') }}</span>
        </button>
        <router-link v-if="launcher" :to="{ name: 'home' }" class="logo-link">
          <span class="logo-sq">S</span><span class="logo-text">SCHOLARIS</span>
        </router-link>

        <div class="spacer" />

        <label v-if="!platform.isAdmin" class="sel sel--label">
          <span class="sel__k">{{ t('shell.establishment') }}</span>
          <select v-if="ctx.tiles.length > 1" id="ctx-est" :value="ctx.activeId ?? ''" :aria-label="t('shell.establishment')" @change="onContextChange">
            <option v-if="!ctx.activeId" value="" disabled>{{ t('shell.chooseEstablishment') }}</option>
            <option v-for="tile in ctx.tiles" :key="tile.id" :value="tile.id">{{ tile.name }}</option>
          </select>
          <span v-else>{{ ctx.activeTile?.name ?? '—' }}</span>
        </label>
        <label v-if="ctx.roles.length > 0" class="sel sel--label">
          <span class="sel__k">{{ t('shell.role') }}</span>
          <select v-if="ctx.roles.length > 1" id="ctx-role" :value="ctx.activeRole ?? ''" :aria-label="t('shell.role')" @change="onRoleChange">
            <option v-for="r in ctx.roles" :key="r.code" :value="r.code">{{ r.label }}</option>
          </select>
          <span v-else>{{ ctx.roles[0]?.label }}</span>
        </label>

        <div class="lang" role="group" :aria-label="t('shell.language')">
          <button type="button" class="sel" :aria-pressed="locale === 'fr'" @click="setLocale('fr')">FR</button>
          <button type="button" class="sel" :aria-pressed="locale === 'en'" @click="setLocale('en')">EN</button>
        </div>
        <router-link :to="{ name: 'sync' }" class="sel bell" :aria-label="t('shell.notifications', { count: alerts })">
          <span aria-hidden="true">🔔</span>
          <span v-if="alerts > 0" class="count">{{ alerts }}</span>
        </router-link>
        <span class="avatar" aria-hidden="true">{{ initials }}</span>
        <button v-if="launcher" type="button" class="sel signout" @click="signOut">{{ t('shell.signOut') }}</button>
      </header>

      <OfflineBanner />
      <ErrorNotice v-if="switchError" :error="switchError" />

      <main id="contenu" class="content" tabindex="-1">
        <slot />
      </main>

      <footer v-if="launcher" class="foot">
        <span>SCHOLARIS · {{ t('shell.hosted') }}</span>
        <router-link :to="{ name: 'privacy' }">{{ t('shell.privacyLink') }}</router-link>
        <router-link :to="{ name: 'security' }">{{ t('nav.security') }}</router-link>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: flex;
}
.side {
  background: var(--ink);
  color: #fff;
  flex: 0 0 240px;
  padding: 24px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px 20px;
}
.brand__name {
  font-weight: 800;
  font-size: 15px;
}
.brand__sub {
  font-size: 11px;
  color: var(--on-dark-muted);
}
.side__space {
  color: #8a8a8a;
  padding: 0 4px 4px;
}
.side nav {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.nav {
  display: flex;
  align-items: center;
  min-height: 40px;
  padding: 0 12px;
  color: #fff;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  border: 1px solid var(--dark-line);
}
.nav:hover {
  color: #fff;
  border-color: #8a8a8a;
}
.nav.router-link-exact-active,
.nav[aria-current='page'] {
  background: #fff;
  color: var(--ink);
  border-color: #fff;
}
.side__foot {
  border-top: 1px solid var(--dark-line);
  margin-top: 16px;
  padding-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 13px;
}
.side__strong {
  color: #fff;
  font-weight: 700;
  text-decoration: none;
}
.side__link {
  background: none;
  border: 0;
  padding: 0;
  min-height: 32px;
  text-align: left;
  font: inherit;
  color: var(--on-dark-muted);
  cursor: pointer;
}
.main-col {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.topbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 12px 32px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}
.topbar--dark {
  background: var(--ink);
  color: #fff;
  border-bottom: 0;
  gap: 16px;
}
.logo-link {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #fff;
  text-decoration: none;
}
.logo-text {
  font-weight: 800;
  font-size: 18px;
  letter-spacing: 0.04em;
}
.sel {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: var(--tap);
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  background: var(--surface);
  color: var(--ink);
  font: 600 14px var(--font);
  cursor: pointer;
  text-decoration: none;
}
.topbar--dark .sel {
  background: var(--dark-control);
  border-color: var(--dark-control-border);
  color: #fff;
}
.sel--label select {
  width: auto;
  max-width: 240px;
  min-height: 40px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  padding: 0;
}
.sel--label select option {
  color: var(--ink);
}
.sel__k {
  color: var(--muted);
  font-weight: 500;
}
.topbar--dark .sel__k {
  color: var(--on-dark-muted);
}
.lang {
  display: flex;
}
.lang .sel {
  padding: 0 12px;
  min-width: var(--tap);
  justify-content: center;
}
.lang .sel[aria-pressed='true'] {
  background: var(--ink);
  color: #fff;
  border-color: var(--ink);
}
.topbar--dark .lang .sel[aria-pressed='true'] {
  background: var(--accent);
  color: var(--on-accent);
  border-color: var(--accent);
}
.bell {
  position: relative;
  padding: 0 12px;
}
.count {
  background: var(--accent);
  color: var(--on-accent);
  font-size: 11px;
  font-weight: 800;
  padding: 1px 6px;
}
.avatar {
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  color: var(--ink);
  font-weight: 800;
  border: 1px solid var(--border-strong);
}
.content {
  flex: 1;
  min-width: 0;
}
.shell--launcher .content {
  width: 100%;
}
.foot {
  border-top: 1px solid var(--border);
  padding: 20px 32px;
  font-size: 13px;
  color: var(--muted);
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
}
.burger {
  display: none;
}
@media (max-width: 900px) {
  .shell {
    flex-direction: column;
  }
  .topbar {
    padding: 12px 16px;
  }
  .burger {
    display: inline-flex;
  }
  .side {
    display: none;
    flex: none;
  }
  .side.open {
    display: flex;
  }
  .foot {
    padding: 20px 16px;
  }
}
</style>
