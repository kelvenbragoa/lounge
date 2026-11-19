<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { normalizeUid } from '@/utils/cards';

/**
 * Leitor USB em modo teclado: escreve o UID e envia Enter.
 * Também aceita escrita manual do UID seguida de Enter ou do botão.
 */
const props = defineProps({
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: true },
    placeholder: { type: String, default: 'Passe o cartão no leitor ou escreva o UID' }
});

const emit = defineEmits(['read']);

const value = ref('');
const inputRef = ref(null);

function focus() {
    nextTick(() => inputRef.value?.$el?.focus?.());
}

function submit() {
    const uid = normalizeUid(value.value);
    if (uid.length < 4 || props.disabled || props.loading) return;
    value.value = '';
    emit('read', uid);
}

watch(
    () => [props.loading, props.disabled],
    ([loading, disabled]) => {
        if (!loading && !disabled && props.autofocus) focus();
    }
);

onMounted(() => {
    if (props.autofocus) focus();
});

defineExpose({ focus });
</script>

<template>
    <div class="card-reader">
        <i class="pi pi-id-card card-reader__icon" />
        <InputText
            ref="inputRef"
            v-model="value"
            class="card-reader__input"
            :placeholder="placeholder"
            :disabled="disabled"
            autocomplete="off"
            spellcheck="false"
            @keydown.enter.prevent="submit"
        />
        <Button icon="pi pi-search" label="Ler" :loading="loading" :disabled="disabled" @click="submit" />
    </div>
</template>

<style scoped>
.card-reader {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.5rem 0.5rem 0.9rem;
    border: 2px dashed var(--p-primary-color);
    border-radius: 14px;
    background: color-mix(in srgb, var(--p-primary-color) 6%, transparent);
}

.card-reader__icon {
    font-size: 1.4rem;
    color: var(--p-primary-color);
}

.card-reader__input {
    flex: 1;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
</style>
