<script setup>
import { ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { apiErrorMessage, fetchCardReceiptUrl } from '@/utils/cards';

/**
 * Mostra o recibo (PDF 80 mm) de um movimento de cartão e permite imprimir.
 * Imprimir é sempre opcional: o operador pode simplesmente fechar.
 */
const props = defineProps({
    transactionId: { type: [Number, String], default: null },
    copy: { type: Boolean, default: false }
});

const visible = defineModel('visible', { type: Boolean, default: false });

const toast = useToast();
const loading = ref(false);
const pdfUrl = ref(null);
const frame = ref(null);

function release() {
    if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value);
    pdfUrl.value = null;
}

async function load() {
    release();
    if (!props.transactionId) return;
    loading.value = true;
    try {
        pdfUrl.value = await fetchCardReceiptUrl(props.transactionId, { copy: props.copy });
    } catch (error) {
        visible.value = false;
        toast.add({ severity: 'error', summary: 'Recibo', detail: apiErrorMessage(error, 'Não foi possível obter o recibo.'), life: 4000 });
    } finally {
        loading.value = false;
    }
}

function print() {
    const target = frame.value?.contentWindow;
    if (!target) return;
    target.focus();
    target.print();
}

watch(visible, (open) => {
    if (open) load();
    else release();
});
</script>

<template>
    <Dialog v-model:visible="visible" :header="copy ? 'Recibo (2ª via)' : 'Recibo'" modal :style="{ width: '26rem' }">
        <div v-if="loading" class="flex justify-center p-6"><ProgressSpinner style="width: 2.5rem; height: 2.5rem" /></div>
        <iframe v-else-if="pdfUrl" ref="frame" :src="pdfUrl" style="width: 100%; height: 460px; border: 0" />
        <template #footer>
            <Button label="Fechar" text @click="visible = false" />
            <Button label="Imprimir" icon="pi pi-print" :disabled="!pdfUrl" @click="print" />
        </template>
    </Dialog>
</template>
