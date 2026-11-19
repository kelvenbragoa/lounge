<script setup>
import { computed } from 'vue';
import { cardDisplayName, cardStatus, formatDateTime, formatMt } from '@/utils/cards';

const props = defineProps({
    card: { type: Object, required: true },
    clearable: { type: Boolean, default: false }
});

defineEmits(['clear']);

const status = computed(() => cardStatus(props.card.status));
</script>

<template>
    <div class="card-summary">
        <div class="card-summary__head">
            <i class="pi pi-credit-card" />
            <span class="card-summary__name">{{ cardDisplayName(card) }}</span>
            <Tag :value="status.label" :severity="status.severity" />
            <Button v-if="clearable" icon="pi pi-times" text rounded class="card-summary__clear" title="Ler outro cartão" @click="$emit('clear')" />
        </div>

        <div class="card-summary__label">Saldo disponível</div>
        <div class="card-summary__balance">{{ formatMt(card.balance) }}</div>

        <div class="card-summary__meta">
            <span><small>Caução</small> {{ formatMt(card.deposit_amount) }}</span>
            <span><small>UID</small> {{ card.uid }}</span>
            <span v-if="card.code"><small>Código</small> {{ card.code }}</span>
            <span v-if="card.holder_phone"><small>Telefone</small> {{ card.holder_phone }}</span>
            <span v-if="card.registered_at"><small>Registado</small> {{ formatDateTime(card.registered_at) }}</span>
        </div>
    </div>
</template>

<style scoped>
.card-summary {
    padding: 1.1rem 1.25rem;
    border-radius: 18px;
    color: #fff;
    background: linear-gradient(135deg, #2a2d3e, #3b4160);
}

.card-summary__head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.card-summary__name {
    flex: 1;
    font-weight: 700;
    font-size: 1.05rem;
}

.card-summary__clear {
    color: rgba(255, 255, 255, 0.75) !important;
}

.card-summary__label {
    margin-top: 0.9rem;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.65);
}

.card-summary__balance {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.15;
}

.card-summary__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 1.2rem;
    margin-top: 0.7rem;
    font-size: 0.85rem;
    font-weight: 600;
}

.card-summary__meta small {
    font-weight: 400;
    color: rgba(255, 255, 255, 0.6);
    margin-right: 0.25rem;
}
</style>
