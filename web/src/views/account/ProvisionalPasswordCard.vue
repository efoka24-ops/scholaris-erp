<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import type { ProvisionalAccount } from '@/api/account';
import { copyText } from '@/views/platform/clipboard';
import { useI18n } from '@/i18n';

// Mot de passe provisoire remis à l'administrateur : affiché UNE fois, avec copie et fiche imprimable. Le parent le jette à la fermeture.
const props = defineProps<{ account: ProvisionalAccount; name: string; variant: 'created' | 'reissued' }>();
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
const box = ref<HTMLElement | null>(null);
const copied = ref<'' | 'ok' | 'failed'>('');

onMounted(async () => {
  await nextTick();
  box.value?.focus();
});

async function copy(): Promise<void> {
  copied.value = (await copyText(props.account.provisional_password)) ? 'ok' : 'failed';
}

const escapeHtml = (value: string): string => value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);

/** Fiche à remettre à la personne : imprimée depuis un cadre masqué (rien ne reste dans la page ni dans l'historique). */
function print(): void {
  const site = window.location.origin;
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(t('acc.card.sheetTitle'))}</title>
<style>body{font-family:Arial,sans-serif;margin:32px;color:#111}h1{font-size:22px}dl{margin:24px 0}dt{font-size:13px;color:#555;margin-top:14px}
dd{margin:2px 0 0;font-size:20px;font-weight:700}.pw{font-family:Consolas,monospace;letter-spacing:.12em;font-size:26px}p{font-size:14px;line-height:1.5;max-width:520px}</style></head>
<body><h1>${escapeHtml(t('acc.card.sheetTitle'))}</h1><p>${escapeHtml(props.name)}</p>
<dl><dt>${escapeHtml(t('acc.card.login'))}</dt><dd>${escapeHtml(props.account.login)}</dd>
<dt>${escapeHtml(t('acc.card.password'))}</dt><dd class="pw">${escapeHtml(props.account.provisional_password)}</dd></dl>
<p>${escapeHtml(t('acc.card.sheetSteps', { site }))}</p></body></html>`;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
  document.body.appendChild(frame);
  const doc = frame.contentDocument;
  if (!doc || !frame.contentWindow) {
    frame.remove();
    return;
  }
  doc.open();
  doc.write(html);
  doc.close();
  frame.contentWindow.focus();
  frame.contentWindow.print();
  window.setTimeout(() => frame.remove(), 1000);
}
</script>

<template>
  <section ref="box" class="card pw-card" role="alert" tabindex="-1" aria-labelledby="pw-card-title" data-test="provisional-card">
    <h2 id="pw-card-title">{{ variant === 'created' ? t('acc.card.createdTitle') : t('acc.card.reissuedTitle') }}</h2>
    <p>{{ t('acc.card.lead', { name }) }}</p>
    <dl class="pw-fields">
      <dt>{{ t('acc.card.login') }}</dt>
      <dd data-test="provisional-login">{{ account.login }}</dd>
      <dt>{{ t('acc.card.password') }}</dt>
      <dd class="pw-secret" data-test="provisional-password">{{ account.provisional_password }}</dd>
    </dl>
    <p class="notice notice--warning">{{ t('acc.card.once') }}</p>
    <div class="row">
      <button type="button" class="btn btn--ink" @click="copy">{{ t('acc.card.copy') }}</button>
      <button type="button" class="btn" @click="print">{{ t('acc.card.print') }}</button>
      <button type="button" class="btn btn--primary" data-test="provisional-close" @click="emit('close')">{{ t('acc.card.close') }}</button>
    </div>
    <p v-if="copied === 'ok'" class="hint" role="status">{{ t('acc.card.copied') }}</p>
    <p v-else-if="copied === 'failed'" class="error" role="status">{{ t('acc.card.copyFailed') }}</p>
  </section>
</template>

<style scoped>
.pw-card {
  border-left: 6px solid var(--accent);
  margin-bottom: 16px;
}
.pw-card:focus {
  outline: 3px solid var(--ink);
}
.pw-fields {
  margin: 12px 0;
}
.pw-fields dt {
  font-size: 13px;
  color: var(--muted);
  margin-top: 10px;
}
.pw-fields dd {
  margin: 2px 0 0;
  font-size: 18px;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.pw-secret {
  font-family: Consolas, 'Courier New', monospace;
  letter-spacing: 0.12em;
  font-size: 24px;
  user-select: all;
}
</style>
