<script setup lang="ts">
import PublicLayout from './PublicLayout.vue';
import { useI18n } from '@/i18n';

// Accueil public léger — design : Login.html (panneau noir, titre en trois temps) et Main.html (tuiles).
const { t } = useI18n();
const steps = [
  { title: 'pub.home.step1Title', text: 'pub.home.step1' },
  { title: 'pub.home.step2Title', text: 'pub.home.step2' },
  { title: 'pub.home.step3Title', text: 'pub.home.step3' },
] as const;
</script>

<template>
  <PublicLayout>
    <section class="hero" aria-labelledby="pub-home-title">
      <div class="hero__inner">
        <p class="eyebrow hero__eb">{{ t('pub.home.eyebrow') }}</p>
        <h1 id="pub-home-title" class="hero__title">{{ t('pub.home.heading') }}</h1>
        <p class="hero__text">{{ t('pub.home.text') }}</p>
        <p class="hero__trial">{{ t('pub.home.trial') }}</p>
        <div class="row">
          <router-link class="btn btn--primary btn--large" :to="{ name: 'access-request' }">{{ t('pub.home.ctaRequest') }}</router-link>
          <router-link class="btn btn--large hero__ghost" :to="{ name: 'public-catalog' }">{{ t('pub.home.ctaCatalog') }}</router-link>
        </div>
        <router-link class="hero__login" :to="{ name: 'login' }">{{ t('pub.home.ctaLogin') }} →</router-link>
      </div>
    </section>
    <section class="page" aria-labelledby="pub-steps-title">
      <h2 id="pub-steps-title">{{ t('pub.home.stepsTitle') }}</h2>
      <ol class="grid grid--tiles steps">
        <li v-for="s in steps" :key="s.title" class="card">
          <h3>{{ t(s.title) }}</h3>
          <p class="muted">{{ t(s.text) }}</p>
        </li>
      </ol>
    </section>
  </PublicLayout>
</template>

<style scoped>
.hero {
  background: var(--ink);
  color: #fff;
  padding: 56px 32px 64px;
}
.hero__inner {
  max-width: 1240px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.hero__eb {
  color: var(--on-dark-muted);
}
.hero__title {
  font-size: 48px;
  line-height: 1.05;
  letter-spacing: -0.02em;
  max-width: 820px;
  margin: 0;
}
.hero__text {
  max-width: 680px;
  font-size: 17px;
  color: var(--on-dark-muted);
  margin: 0;
}
.hero__trial {
  font-weight: 700;
  margin: 0;
}
.hero__ghost {
  background: transparent;
  color: #fff;
  border-color: #fff;
}
.hero__ghost:hover:not(:disabled) {
  background: var(--dark-control);
  color: #fff;
}
.hero__login {
  color: #fff;
  font-weight: 700;
  min-height: var(--tap);
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
}
.hero__login:hover {
  color: var(--on-dark-muted);
}
.steps {
  list-style: none;
  padding: 0;
  margin: 16px 0 0;
}
.steps h3 {
  margin-bottom: 8px;
}
@media (max-width: 640px) {
  .hero {
    padding: 32px 16px 40px;
  }
  .hero__title {
    font-size: 32px;
  }
}
</style>
