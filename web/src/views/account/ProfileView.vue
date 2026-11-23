<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fetchProfile, logoutOtherDevices, updatePreferences, updateProfile, type MyProfile } from '@/api/accountProfile';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';

// « Mon profil » (C-112 point 6), pour tout utilisateur, super administrateur compris : nom, téléphone, e-mail, langue,
// mot de passe et double authentification (liens vers leurs écrans), sessions ouvertes et « déconnecter les autres appareils »,
// canaux de notification et résumé quotidien (rôles de la plateforme).
const { t, locale, setLocale } = useI18n();
const profile = ref<MyProfile | null>(null);
const error = ref<unknown>(null);
const saved = ref<string | null>(null);
const busy = ref(false);
const form = reactive({ first_name: '', last_name: '', phone: '', email: '', locale: 'fr' as 'fr' | 'en' });

function fill(p: MyProfile): void {
  profile.value = p;
  Object.assign(form, { first_name: p.first_name, last_name: p.last_name, phone: p.phone ?? '', email: p.email ?? '', locale: p.locale });
}

async function run(work: () => Promise<void>, message: string): Promise<void> {
  busy.value = true;
  error.value = null;
  saved.value = null;
  try {
    await work();
    if (message) saved.value = message;
  } catch (e) {
    error.value = e;
  } finally {
    busy.value = false;
  }
}

const saveIdentity = () =>
  run(async () => {
    const p = await updateProfile({
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      phone: form.phone.trim() === '' ? null : form.phone.trim(),
      email: form.email.trim() === '' ? null : form.email.trim(),
      locale: form.locale,
    });
    fill(p);
    if (p.locale !== locale.value) setLocale(p.locale);
  }, t('prof.saved'));

const savePreference = (key: 'notify_sms' | 'notify_email' | 'daily_digest', value: boolean) => run(async () => fill(await updatePreferences({ [key]: value })), t('prof.saved'));

const logoutOthers = () =>
  run(async () => {
    const count = await logoutOtherDevices();
    fill(await fetchProfile());
    saved.value = t('prof.loggedOut', { count });
  }, '');

onMounted(async () => {
  try {
    fill(await fetchProfile());
  } catch (e) {
    error.value = e;
  }
});
</script>

<template>
  <div class="page">
    <header class="page-head">
      <h1>{{ t('prof.title') }}</h1>
      <p class="lede">{{ t('prof.lede') }}</p>
    </header>
    <ErrorNotice :error="error" />
    <p v-if="saved" class="notice notice--success" role="status">{{ saved }}</p>
    <template v-if="profile">
      <form class="card form" novalidate @submit.prevent="saveIdentity">
        <h2>{{ t('prof.identity') }}</h2>
        <div class="grid2">
          <div class="field"><label for="pf-first">{{ t('pa.firstName') }}</label><input id="pf-first" v-model="form.first_name" type="text" maxlength="80" autocomplete="given-name" /></div>
          <div class="field"><label for="pf-last">{{ t('pa.lastName') }}</label><input id="pf-last" v-model="form.last_name" type="text" maxlength="80" autocomplete="family-name" /></div>
          <div class="field"><label for="pf-phone">{{ t('pa.phone') }}</label><input id="pf-phone" v-model="form.phone" type="tel" maxlength="25" autocomplete="tel" /></div>
          <div class="field"><label for="pf-email">{{ t('pa.email') }}</label><input id="pf-email" v-model="form.email" type="email" maxlength="190" autocomplete="email" /></div>
          <div class="field">
            <label for="pf-locale">{{ t('prof.locale') }}</label>
            <select id="pf-locale" v-model="form.locale"><option value="fr">Français</option><option value="en">English</option></select>
          </div>
        </div>
        <p class="hint">{{ t('prof.loginHint') }}</p>
        <button type="submit" class="btn btn--primary" :disabled="busy" data-test="profile-save">{{ t('pa.save') }}</button>
      </form>

      <section class="card block">
        <h2>{{ t('prof.security') }}</h2>
        <p>{{ t('prof.twoFactor') }} : <strong>{{ profile.two_factor_enabled ? t('pa.yes') : t('pa.no') }}</strong></p>
        <div class="row links">
          <router-link class="btn" :to="{ name: 'change-password' }">{{ t('prof.password') }}</router-link>
          <router-link class="btn" :to="{ name: 'security' }">{{ t('prof.manage2fa') }}</router-link>
        </div>
      </section>

      <section class="card block">
        <h2>{{ t('prof.sessions') }}</h2>
        <ul class="list">
          <li v-for="s in profile.sessions" :key="s.id">
            {{ s.device ?? t('prof.unknownDevice') }} · {{ t('pk.since') }} {{ formatDateTime(s.created_at, locale) }}
            <span v-if="s.current" class="tag tag--success">{{ t('prof.current') }}</span>
          </li>
        </ul>
        <button type="button" class="btn" data-test="logout-others" :disabled="busy || profile.sessions.filter((s) => !s.current).length === 0" @click="logoutOthers">{{ t('prof.logoutOthers') }}</button>
      </section>

      <section class="card block">
        <h2>{{ t('prof.notifications') }}</h2>
        <p class="hint">{{ t('prof.appAlways') }}</p>
        <label class="check"><input type="checkbox" :checked="profile.preferences.channels.email" data-test="pref-email" @change="savePreference('notify_email', ($event.target as HTMLInputElement).checked)" /> {{ t('prof.byEmail') }}</label>
        <label class="check"><input type="checkbox" :checked="profile.preferences.channels.sms" @change="savePreference('notify_sms', ($event.target as HTMLInputElement).checked)" /> {{ t('prof.bySms') }}</label>
        <label v-if="profile.platform_member" class="check"><input type="checkbox" :checked="profile.preferences.daily_digest" data-test="pref-digest" @change="savePreference('daily_digest', ($event.target as HTMLInputElement).checked)" /> {{ t('prof.digest') }}</label>
      </section>
    </template>
  </div>
</template>

<style scoped>
.grid2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 0 16px;
}
.block {
  margin-top: 20px;
}
.links {
  gap: 8px;
  flex-wrap: wrap;
}
.list {
  padding-left: 18px;
}
.check {
  display: block;
  margin: 6px 0;
}
</style>
