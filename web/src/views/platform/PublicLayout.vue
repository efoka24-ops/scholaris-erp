<script setup lang="ts">
import { useI18n } from '@/i18n';

// Cadre des pages publiques (sans connexion) — design : Login.html (bandeau noir, logo carré à l'accent, angles droits).
const { t, locale, setLocale } = useI18n();
</script>

<template>
  <div class="pub">
    <a href="#pub-main" class="skip-link">{{ t('shell.skip') }}</a>
    <header class="pub__bar">
      <router-link :to="{ name: 'public-home' }" class="pub__brand">
        <span class="logo-sq">S</span><span class="pub__name">SCHOLARIS</span>
      </router-link>
      <nav class="pub__nav" :aria-label="t('pub.nav.label')">
        <router-link :to="{ name: 'public-catalog' }">{{ t('pub.nav.catalog') }}</router-link>
        <router-link :to="{ name: 'access-request' }">{{ t('pub.nav.request') }}</router-link>
        <router-link :to="{ name: 'login' }">{{ t('pub.nav.login') }}</router-link>
      </nav>
      <div class="pub__lang" role="group" :aria-label="t('shell.language')">
        <button type="button" :aria-pressed="locale === 'fr'" @click="setLocale('fr')">FR</button>
        <button type="button" :aria-pressed="locale === 'en'" @click="setLocale('en')">EN</button>
      </div>
    </header>
    <main id="pub-main" class="pub__main" tabindex="-1">
      <slot />
    </main>
    <footer class="pub__foot">{{ t('pub.footer') }}</footer>
  </div>
</template>

<style scoped>
.pub {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--bg);
}
.pub__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 24px;
  padding: 12px 32px;
  background: var(--ink);
  color: #fff;
}
.pub__brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: var(--tap);
  color: #fff;
  text-decoration: none;
}
.pub__name {
  font-weight: 800;
  font-size: 18px;
  letter-spacing: 0.04em;
}
.pub__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  flex: 1;
}
.pub__nav a {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap);
  padding: 0 12px;
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
  border: 1px solid transparent;
}
.pub__nav a:hover {
  color: #fff;
  border-color: var(--dark-control-border);
}
.pub__nav a.router-link-active {
  border-color: #fff;
}
.pub__lang {
  display: flex;
  gap: 6px;
}
.pub__lang button {
  min-height: var(--tap);
  min-width: var(--tap);
  font: 700 14px var(--font);
  background: var(--dark-control);
  color: #fff;
  border: 1px solid var(--dark-control-border);
  cursor: pointer;
}
.pub__lang button[aria-pressed='true'] {
  background: var(--accent);
  color: var(--on-accent);
  border-color: var(--accent);
}
.pub__main {
  flex: 1;
}
.pub__foot {
  border-top: 1px solid var(--border);
  padding: 20px 32px;
  font-size: 13px;
  color: var(--muted);
}
@media (max-width: 640px) {
  .pub__bar,
  .pub__foot {
    padding-left: 16px;
    padding-right: 16px;
  }
  .pub__nav {
    order: 3;
    flex-basis: 100%;
  }
}
</style>
