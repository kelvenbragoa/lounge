<script setup>
import { computed, onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import CardTransactionsTable from '@/components/cards/CardTransactionsTable.vue';
import { apiErrorMessage, formatDateTime, formatMt } from '@/utils/cards';

const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const state = ref({ shift: null, summary: null });
const transactions = ref([]);
const openingBalance = ref(0);

const closeDialog = ref(false);
const declaredCash = ref(null);
const observation = ref('');
const closedResult = ref(null);

const isOpen = computed(() => !!state.value.shift && !state.value.shift.closed_at);
const byTender = computed(() => Object.entries(state.value.summary?.by_tender ?? {}).map(([id, row]) => ({ id, ...row })));
const previewDifference = computed(() =>
    declaredCash.value == null ? null : Number(state.value.summary?.expected_cash ?? 0) - Number(declaredCash.value)
);

function notify(detail, severity = 'success') {
    toast.add({ severity, summary: severity === 'error' ? 'Erro' : 'Turno', detail, life: 4000 });
}

async function load() {
    loading.value = true;
    try {
        const response = await axios.get('/api/card-shift/current');
        state.value = response.data;
        transactions.value = [];
        if (response.data.shift) {
            const detail = await axios.get(`/api/card-shifts/${response.data.shift.id}`);
            transactions.value = detail.data.transactions ?? [];
        }
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        loading.value = false;
    }
}

async function openShift() {
    saving.value = true;
    try {
        const response = await axios.post('/api/card-shift/open', { opening_balance: Number(openingBalance.value ?? 0) });
        notify(response.data.message);
        closedResult.value = null;
        await load();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function closeShift() {
    if (declaredCash.value == null) return notify('Indique o dinheiro contado.', 'warn');
    saving.value = true;
    try {
        const response = await axios.post('/api/card-shift/close', {
            closing_balance: Number(declaredCash.value),
            observation: observation.value || null
        });
        closeDialog.value = false;
        closedResult.value = response.data;
        notify(response.data.message);
        declaredCash.value = null;
        observation.value = '';
        await load();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center p-8"><ProgressSpinner style="width: 48px; height: 48px" /></div>

    <div v-else class="flex flex-col gap-4">
        <div v-if="closedResult" class="card !mb-0">
            <div class="font-semibold text-xl mb-3">Último fecho</div>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-4">
                    <small class="text-muted-color">Esperado</small>
                    <div class="text-2xl font-bold">{{ formatMt(closedResult.summary?.expected_cash) }}</div>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <small class="text-muted-color">Contado</small>
                    <div class="text-2xl font-bold">{{ formatMt(closedResult.shift?.closing_balance) }}</div>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <small class="text-muted-color">{{ closedResult.shift?.difference > 0 ? 'Em falta' : closedResult.shift?.difference < 0 ? 'Em excesso' : 'Diferença' }}</small>
                    <div class="text-2xl font-bold" :class="closedResult.shift?.difference ? 'text-red-600' : 'text-green-600'">
                        {{ formatMt(Math.abs(closedResult.shift?.difference ?? 0)) }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!isOpen" class="card max-w-xl">
            <div class="font-semibold text-xl mb-2">Turno fechado</div>
            <p class="text-muted-color">Abra o turno para recarregar e devolver cartões. Indique o dinheiro que tem na gaveta para trocos.</p>
            <label class="block mb-2">Fundo de caixa</label>
            <InputNumber v-model="openingBalance" mode="decimal" :min="0" :maxFractionDigits="2" suffix=" MT" fluid />
            <Button label="Abrir turno" icon="pi pi-lock-open" class="mt-4" :loading="saving" @click="openShift" />
        </div>

        <template v-else>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 lg:col-span-4">
                    <div class="card !mb-0 h-full expected">
                        <small>Dinheiro que deve estar na gaveta</small>
                        <div class="text-4xl font-bold my-2">{{ formatMt(state.summary?.expected_cash) }}</div>
                        <small>Aberto em {{ formatDateTime(state.shift.opened_at) }} · Fundo {{ formatMt(state.shift.opening_balance) }}</small>
                        <Button label="Fechar turno" icon="pi pi-lock" severity="danger" class="mt-4 w-full" @click="closeDialog = true" />
                    </div>
                </div>
                <div class="col-span-12 lg:col-span-8">
                    <div class="card !mb-0 h-full">
                        <div class="grid grid-cols-3 gap-4 text-center mb-4">
                            <div><div class="text-3xl font-bold">{{ state.summary?.cards_registered }}</div><small>Cartões registados</small></div>
                            <div><div class="text-3xl font-bold">{{ state.summary?.topups_count }}</div><small>Recargas</small></div>
                            <div><div class="text-3xl font-bold">{{ state.summary?.refunds_count }}</div><small>Devoluções</small></div>
                        </div>
                        <div class="summary-rows">
                            <div><span>Saldo carregado</span><strong>{{ formatMt(state.summary?.topups_total) }}</strong></div>
                            <div><span>Cauções recebidas</span><strong>{{ formatMt(state.summary?.deposits_received) }}</strong></div>
                            <div class="text-red-600"><span>Saldo devolvido</span><strong>- {{ formatMt(state.summary?.balance_refunded) }}</strong></div>
                            <div class="text-red-600"><span>Cauções devolvidas</span><strong>- {{ formatMt(state.summary?.deposits_refunded) }}</strong></div>
                            <div class="font-bold"><span>Movimento em dinheiro</span><strong>{{ formatMt(state.summary?.net_cash) }}</strong></div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="byTender.length" class="card !mb-0">
                <div class="font-semibold text-lg mb-3">Por forma de pagamento</div>
                <DataTable :value="byTender" size="small">
                    <Column field="label" header="Forma" />
                    <Column header="Recebido"><template #body="{ data }">{{ formatMt(data.received) }}</template></Column>
                    <Column header="Pago ao cliente"><template #body="{ data }">{{ formatMt(data.paid_out) }}</template></Column>
                    <Column header="Líquido"><template #body="{ data }"><strong>{{ formatMt(data.net) }}</strong></template></Column>
                </DataTable>
            </div>

            <div class="card !mb-0">
                <div class="font-semibold text-lg mb-3">Movimentos do turno</div>
                <CardTransactionsTable :transactions="transactions" show-card />
            </div>
        </template>
    </div>

    <Dialog v-model:visible="closeDialog" header="Fechar turno" modal :style="{ width: '28rem' }">
        <div class="flex flex-col gap-3">
            <div class="flex justify-between text-lg"><span>Dinheiro esperado</span><strong>{{ formatMt(state.summary?.expected_cash) }}</strong></div>
            <label>Dinheiro contado</label>
            <InputNumber v-model="declaredCash" mode="decimal" :min="0" :maxFractionDigits="2" suffix=" MT" fluid autofocus />
            <div v-if="previewDifference !== null" class="flex justify-between" :class="previewDifference ? 'text-red-600' : 'text-green-600'">
                <span>{{ previewDifference > 0 ? 'Em falta' : previewDifference < 0 ? 'Em excesso' : 'Sem diferença' }}</span>
                <strong>{{ formatMt(Math.abs(previewDifference)) }}</strong>
            </div>
            <label>Observação</label>
            <Textarea v-model="observation" rows="2" autoResize placeholder="Opcional" />
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="closeDialog = false" />
            <Button label="Fechar turno" icon="pi pi-lock" severity="danger" :loading="saving" @click="closeShift" />
        </template>
    </Dialog>
</template>

<style scoped>
.expected {
    color: #fff;
    background: linear-gradient(135deg, var(--p-primary-500), var(--p-primary-700));
}

.summary-rows > div {
    display: flex;
    justify-content: space-between;
    padding: 0.35rem 0;
    border-bottom: 1px solid var(--surface-border);
}

.summary-rows > div:last-child {
    border-bottom: none;
}
</style>
