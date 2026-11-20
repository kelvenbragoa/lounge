<script setup>
import { ref } from 'vue';
import CardReceiptDialog from '@/components/cards/CardReceiptDialog.vue';
import { TRANSACTION_TYPES, cardUidLabel, formatDateTime, formatMt } from '@/utils/cards';

defineProps({
    transactions: { type: Array, default: () => [] },
    showCard: { type: Boolean, default: false },
    loading: { type: Boolean, default: false }
});

const expandedRows = ref({});
const receiptVisible = ref(false);
const receiptId = ref(null);

function printReceipt(transaction) {
    receiptId.value = transaction.id;
    receiptVisible.value = true;
}

function activeItems(transaction) {
    return (transaction.items ?? []).filter((item) => !item.deleted_at);
}

function annulledItems(transaction) {
    return (transaction.items ?? []).filter((item) => item.deleted_at);
}
</script>

<template>
    <DataTable v-model:expandedRows="expandedRows" :value="transactions" dataKey="id" :loading="loading" size="small" stripedRows scrollable scrollHeight="420px">
        <template #empty>Sem movimentos.</template>
        <Column expander style="width: 3rem" />
        <Column header="Data" style="min-width: 9rem">
            <template #body="{ data }">{{ formatDateTime(data.created_at) }}</template>
        </Column>
        <Column v-if="showCard" header="Cartão">
            <template #body="{ data }">
                {{ data.card?.holder_name || data.card?.code }}
                <span class="font-mono text-muted-color">{{ cardUidLabel(data.card) }}</span>
            </template>
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
        <Column style="width: 3.5rem">
            <template #body="{ data }">
                <Button icon="pi pi-print" text rounded size="small" title="Imprimir recibo (2ª via)" @click="printReceipt(data)" />
            </template>
        </Column>

        <template #expansion="{ data }">
            <div class="statement-detail">
                <div v-if="data.order?.table?.name" class="statement-detail__line"><span>Mesa</span><strong>{{ data.order.table.name }}</strong></div>

                <table v-if="activeItems(data).length" class="statement-detail__items">
                    <thead>
                        <tr><th>Qtd</th><th>Produto</th><th class="r">Preço</th><th class="r">Total</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in activeItems(data)" :key="item.id">
                            <td>{{ item.quantity }}</td>
                            <td>{{ item.product?.name ?? 'Produto' }}</td>
                            <td class="r">{{ formatMt(item.price) }}</td>
                            <td class="r">{{ formatMt(item.total) }}</td>
                        </tr>
                        <tr v-for="item in annulledItems(data)" :key="`a${item.id}`" class="annulled">
                            <td>{{ item.quantity }}</td>
                            <td>{{ item.product?.name ?? 'Produto' }} (anulado)</td>
                            <td class="r">{{ formatMt(item.price) }}</td>
                            <td class="r">{{ formatMt(item.total) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="statement-detail__line"><span>Saldo antes</span><strong>{{ formatMt(data.balance_before) }}</strong></div>
                <div class="statement-detail__line"><span>Saldo depois</span><strong>{{ formatMt(data.balance_after) }}</strong></div>
                <div v-if="data.notes" class="statement-detail__note">{{ data.notes }}</div>
            </div>
        </template>
    </DataTable>

    <CardReceiptDialog v-model:visible="receiptVisible" :transaction-id="receiptId" copy />
</template>

<style scoped>
.statement-detail {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.5rem 1rem 0.75rem 3rem;
    font-size: 0.9rem;
}

.statement-detail__line {
    display: flex;
    justify-content: space-between;
    max-width: 22rem;
}

.statement-detail__items {
    width: 100%;
    max-width: 34rem;
    border-collapse: collapse;
    margin-bottom: 0.4rem;
}

.statement-detail__items th,
.statement-detail__items td {
    padding: 0.2rem 0.5rem 0.2rem 0;
    text-align: left;
}

.statement-detail__items .r {
    text-align: right;
}

.statement-detail__items .annulled {
    text-decoration: line-through;
    opacity: 0.6;
}

.statement-detail__note {
    color: var(--text-color-secondary);
    font-style: italic;
}
</style>
