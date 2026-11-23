<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from '@/i18n';

// Code à 6 chiffres en 6 cases (maquettes Otp.html et TwoFA.html) : une case par chiffre, passage automatique à la
// suivante, retour arrière vers la précédente, collage du code entier, saisie numérique sur mobile.
const props = withDefaults(defineProps<{ modelValue: string; length?: number; invalid?: boolean; disabled?: boolean; label?: string }>(), { length: 6, invalid: false, disabled: false, label: undefined });
const emit = defineEmits<{ 'update:modelValue': [value: string]; complete: [value: string] }>();
const { t } = useI18n();

const inputs = ref<HTMLInputElement[]>([]);
const digits = computed(() => Array.from({ length: props.length }, (_, i) => props.modelValue[i] ?? ''));

function setValue(value: string): void {
  const clean = value.replace(/\D/g, '').slice(0, props.length);
  emit('update:modelValue', clean);
  if (clean.length === props.length) emit('complete', clean);
}

function focusAt(index: number): void {
  inputs.value[Math.max(0, Math.min(props.length - 1, index))]?.focus();
}

function onInput(index: number, event: Event): void {
  const target = event.target as HTMLInputElement;
  const typed = target.value.replace(/\D/g, '');
  if (typed === '') {
    // Case vidée : le chiffre disparaît, les suivants restent à leur place.
    const next = digits.value.slice();
    next[index] = '';
    setValue(next.join(''));
    target.value = '';
    return;
  }
  const next = digits.value.slice();
  // Plusieurs chiffres d'un coup (collage ou saisie assistée) : ils remplissent les cases à partir de celle-ci.
  for (let k = 0; k < typed.length && index + k < props.length; k++) next[index + k] = typed[k] ?? '';
  const joined = next.join('').replace(/\s/g, '');
  setValue(joined);
  target.value = next[index] ?? '';
  focusAt(Math.min(index + typed.length, props.length - 1));
}

function onKeydown(index: number, event: KeyboardEvent): void {
  if (event.key === 'Backspace' && (digits.value[index] ?? '') === '') {
    event.preventDefault();
    const next = digits.value.slice();
    next[index - 1] = '';
    setValue(next.join(''));
    focusAt(index - 1);
  } else if (event.key === 'ArrowLeft') {
    event.preventDefault();
    focusAt(index - 1);
  } else if (event.key === 'ArrowRight') {
    event.preventDefault();
    focusAt(index + 1);
  }
}

function onPaste(event: ClipboardEvent): void {
  const text = event.clipboardData?.getData('text') ?? '';
  if (text.replace(/\D/g, '') === '') return;
  event.preventDefault();
  setValue(text);
  focusAt(Math.min(text.replace(/\D/g, '').length, props.length - 1));
}

defineExpose({ focus: () => focusAt(0) });
</script>

<template>
  <div class="code-boxes" role="group" :aria-label="label ?? t('code.group', { count: length })">
    <input
      v-for="(digit, index) in digits"
      :key="index"
      :ref="(el) => { if (el) inputs[index] = el as HTMLInputElement; }"
      class="code-box"
      :class="{ 'code-box--active': index === Math.min(modelValue.length, length - 1), 'code-box--bad': invalid }"
      type="text"
      inputmode="numeric"
      pattern="[0-9]*"
      maxlength="6"
      :value="digit"
      :aria-label="t('code.digit', { index: index + 1 })"
      :aria-invalid="invalid"
      :autocomplete="index === 0 ? 'one-time-code' : 'off'"
      :disabled="disabled"
      @input="onInput(index, $event)"
      @keydown="onKeydown(index, $event)"
      @paste="onPaste"
      @focus="($event.target as HTMLInputElement).select()"
    />
  </div>
</template>

<style scoped>
.code-boxes {
  display: flex;
  gap: 8px;
  justify-content: space-between;
}
.code-box {
  flex: 1 1 0;
  min-width: 0;
  height: 56px;
  text-align: center;
  font: 800 24px var(--font);
  border: 2px solid var(--border-strong, #8a8a8a);
  background: var(--surface, #fff);
  color: var(--ink, #000);
  border-radius: 0;
  padding: 0;
}
.code-box--active {
  border-color: var(--accent, #f26b1d);
}
.code-box--bad {
  border-color: var(--warning, #b34700);
}
.code-box:focus-visible {
  outline: 3px solid var(--accent, #f26b1d);
  outline-offset: 2px;
}
</style>
