<script setup>
import { TRANSACTION_TYPES, formatDateTime, formatMt } from '@/utils/cards';

defineProps({
    transactions: { type: Array, default: () => [] },
    showCard: { type: Boolean, default: false },
    loading: { type: Boolean, default: false }
});
</script>

<template>
    <DataTable :value="transactions" :loading="loading" size="small" stripedRows scrollable scrollHeight="420px">
        <template #empty>Sem movimentos.</template>
        <Column header="Data" style="min-width: 9rem">
            <template #body="{ data }">{{ formatDateTime(data.created_at) }}</template>
        </Column>
        <Column v-if="showCard" header="Cartão">
            <template #body="{ data }">{{ data.card?.holder_name || data.card?.code || data.card?.uid }}</template>
        </Column>
        <Column header="Tipo">
            <template #body="{ data }">{{ TRANSACTION_TYPES[data.type] ?? data.type }}</template>
        </Column>
        <Column header="Valor" style="text-align: right">
            <template #body="{ data }">
                <span :class="data.amount >= 0 ? 'text-green-600' : 'text-red-600'" class="font-semibold">
                    {{ data.amount >= 0 ? '+' : '' }}{{ formatMt(data.amount) }}
                </span>
            </template>
        </Column>
        <Column header="Saldo depois" style="text-align: right">
            <template #body="{ data }">{{ formatMt(data.balance_after) }}</template>
        </Column>
        <Column header="Pagamento">
            <template #body="{ data }">{{ data.tender_method ?? (data.order_id ? `Pedido #${data.order_id}` : '') }}</template>
        </Column>
        <Column header="Operador">
            <template #body="{ data }">{{ data.user?.name }}</template>
        </Column>
    </DataTable>
</template>
