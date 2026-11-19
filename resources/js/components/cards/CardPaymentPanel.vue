<script setup>
import { computed, ref } from 'vue';
import CardReaderInput from '@/components/cards/CardReaderInput.vue';
import CardSummary from '@/components/cards/CardSummary.vue';
import { apiErrorMessage, formatMt, lookupCard } from '@/utils/cards';

/**
 * Lê o cartão do cliente no PDV e mostra se o saldo cobre o total.
 * O cartão escolhido fica em v-model:card; o pai envia card.uid como card_uid.
 */
const props = defineProps({
    total: { type: Number, required: true },
    card: { type: Object, default: null }
});

const emit = defineEmits(['update:card']);

const loading = ref(false);
const error = ref('');

const remaining = computed(() => (props.card ? Number(props.card.balance) - Number(props.total) : 0));
const canPay = computed(() => props.card?.status === 'active' && remaining.value >= -0.0001);

async function onRead(uid) {
    loading.value = true;
    error.value = '';
    try {
        const data = await lookupCard(uid);
        emit('update:card', data.card);
        if (data.card.status !== 'active') {
            error.value = 'Este cartão não está activo e não pode pagar.';
        }
    } catch (e) {
        emit('update:card', null);
        error.value =
            e?.response?.data?.code === 'card_not_found'
                ? 'Cartão não registado. Encaminhe o cliente para o caixa de cartões.'
                : apiErrorMessage(e);
    } finally {
        loading.value = false;
    }
}

function clear() {
    emit('update:card', null);
    error.value = '';
}

defineExpose({ canPay });
</script>

<template>
    <div class="card-pay">
        <CardReaderInput v-if="!card" :loading="loading" @read="onRead" />

        <template v-else>
            <CardSummary :card="card" clearable @clear="clear" />
            <div class="card-pay__rows">
                <div><span>Saldo actual</span><strong>{{ formatMt(card.balance) }}</strong></div>
                <div><span>A pagar</span><strong>- {{ formatMt(total) }}</strong></div>
                <div class="card-pay__after" :class="canPay ? 'text-green-600' : 'text-red-600'">
                    <span>Saldo depois</span><strong>{{ formatMt(remaining) }}</strong>
                </div>
            </div>
            <Message v-if="card.status === 'active' && !canPay" severity="error" :closable="false">
                Saldo insuficiente: faltam {{ formatMt(-remaining) }}. O cliente deve recarregar ou usar outro cartão.
            </Message>
        </template>

        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    </div>
</template>

<style scoped>
.card-pay {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.card-pay__rows > div {
    display: flex;
    justify-content: space-between;
    padding: 0.25rem 0;
}

.card-pay__after {
    border-top: 1px solid var(--surface-border);
    margin-top: 0.25rem;
    padding-top: 0.5rem !important;
    font-size: 1.05rem;
}
</style>
