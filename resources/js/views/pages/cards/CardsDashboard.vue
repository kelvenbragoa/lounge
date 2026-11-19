<script setup>
import { computed, onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import CardTransactionsTable from '@/components/cards/CardTransactionsTable.vue';
import { CARD_STATUS, TRANSACTION_TYPES, apiErrorMessage, formatDateTime, formatMt } from '@/utils/cards';

const toast = useToast();

const today = new Date();
const dateFrom = ref(new Date(today));
const dateTo = ref(new Date(today));
const loading = ref(false);
const data = ref(null);

const shifts = ref([]);
const shiftsTotal = ref(0);
const shiftsPage = ref(1);
const shiftsLoading = ref(false);

const shiftDialog = ref(false);
const shiftDetail = ref(null);

const isCardCashier = computed(() => {
    try {
        return Number(JSON.parse(localStorage.getItem('user') || '{}').role_id) === 8;
    } catch {
        return false;
    }
});

const totals = computed(() => data.value?.totals ?? {});
const movements = computed(() => Object.entries(data.value?.reconciliation?.movements ?? {}).map(([type, value]) => ({ type, value })));
const cardsByStatus = computed(() =>
    Object.entries(data.value?.outstanding?.cards_by_status ?? {}).map(([status, row]) => ({ status, ...row }))
);

function toDateParam(date) {
    if (!date) return undefined;
    const d = new Date(date);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function differenceLabel(value) {
    if (!value) return 'Certo';
    return value > 0 ? `Falta ${formatMt(value)}` : `Sobra ${formatMt(-value)}`;
}

async function load() {
    loading.value = true;
    try {
        const response = await axios.get('/api/card-reports/dashboard', {
            params: { date_from: toDateParam(dateFrom.value), date_to: toDateParam(dateTo.value ?? dateFrom.value) }
        });
        data.value = response.data;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: apiErrorMessage(error), life: 4000 });
    } finally {
        loading.value = false;
    }
    loadShifts();
}

async function loadShifts() {
    shiftsLoading.value = true;
    try {
        const response = await axios.get('/api/card-shifts', {
            params: {
                page: shiftsPage.value,
                per_page: 10,
                date_from: toDateParam(dateFrom.value),
                date_to: toDateParam(dateTo.value ?? dateFrom.value)
            }
        });
        shifts.value = response.data.data;
        shiftsTotal.value = response.data.total;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: apiErrorMessage(error), life: 4000 });
    } finally {
        shiftsLoading.value = false;
    }
}

async function openShift(shift) {
    shiftDetail.value = null;
    shiftDialog.value = true;
    try {
        const response = await axios.get(`/api/card-shifts/${shift.id}`);
        shiftDetail.value = response.data;
    } catch (error) {
        shiftDialog.value = false;
        toast.add({ severity: 'error', summary: 'Erro', detail: apiErrorMessage(error), life: 4000 });
    }
}

onMounted(load);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="card !mb-0 flex flex-wrap items-end gap-3">
            <div class="flex-1">
                <div class="font-semibold text-xl">Dashboard de cartões</div>
                <small class="text-muted-color">{{ isCardCashier ? 'Os seus movimentos de caixa no período.' : 'Todos os caixas de cartões no período.' }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label>De</label>
                <DatePicker v-model="dateFrom" dateFormat="dd/mm/yy" showIcon />
            </div>
            <div class="flex flex-col gap-1">
                <label>Até</label>
                <DatePicker v-model="dateTo" dateFormat="dd/mm/yy" showIcon :minDate="dateFrom" />
            </div>
            <Button label="Actualizar" icon="pi pi-refresh" :loading="loading" @click="load" />
        </div>

        <template v-if="data">
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <div class="card !mb-0 stat">
                        <small>Recebido (recargas + cauções)</small>
                        <div class="stat__value">{{ formatMt(totals.received_total) }}</div>
                        <small>{{ totals.topups_count }} recargas · {{ totals.cards_registered }} cartões registados</small>
                    </div>
                </div>
                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <div class="card !mb-0 stat">
                        <small>Devolvido aos clientes</small>
                        <div class="stat__value text-red-600">{{ formatMt(totals.paid_out_total) }}</div>
                        <small>{{ totals.refunds_count }} devoluções · cauções {{ formatMt(totals.deposits_refunded) }}</small>
                    </div>
                </div>
                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <div class="card !mb-0 stat">
                        <small>Dinheiro a entregar (caixas)</small>
                        <div class="stat__value text-primary">{{ formatMt(totals.expected_cash) }}</div>
                        <small>Fundos iniciais {{ formatMt(totals.opening_balance) }} · {{ differenceLabel(totals.difference) }}</small>
                    </div>
                </div>
                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <div class="card !mb-0 stat">
                        <small>Consumo pago com cartão</small>
                        <div class="stat__value text-green-600">{{ formatMt(data.consumption.net) }}</div>
                        <small>{{ data.consumption.sales_count }} vendas · estornos {{ formatMt(data.consumption.reversed) }}</small>
                    </div>
                </div>
            </div>

            <div class="card !mb-0">
                <div class="font-semibold text-lg mb-3">Por caixa de cartões</div>
                <DataTable :value="data.per_cashier" size="small" stripedRows>
                    <template #empty>Sem movimentos no período.</template>
                    <Column field="name" header="Caixa">
                        <template #body="{ data: row }">
                            {{ row.name }}
                            <Tag v-if="row.open_shift_id" value="Turno aberto" severity="success" class="ml-2" />
                        </template>
                    </Column>
                    <Column field="cards_registered" header="Registos" />
                    <Column header="Recargas"><template #body="{ data: row }">{{ row.topups_count }} · {{ formatMt(row.topups_total) }}</template></Column>
                    <Column header="Cauções"><template #body="{ data: row }">{{ formatMt(row.deposits_received) }}</template></Column>
                    <Column header="Devoluções"><template #body="{ data: row }">{{ row.refunds_count }} · {{ formatMt(row.paid_out_total) }}</template></Column>
                    <Column header="Fundo"><template #body="{ data: row }">{{ formatMt(row.opening_balance) }}</template></Column>
                    <Column header="Deve entregar"><template #body="{ data: row }"><strong>{{ formatMt(row.expected_cash) }}</strong></template></Column>
                    <Column header="Declarado"><template #body="{ data: row }">{{ formatMt(row.declared_cash) }}</template></Column>
                    <Column header="Diferença">
                        <template #body="{ data: row }">
                            <span :class="row.difference ? 'text-red-600 font-semibold' : 'text-green-600'">{{ differenceLabel(row.difference) }}</span>
                        </template>
                    </Column>
                </DataTable>
                <small class="text-muted-color block mt-2">
                    "Deve entregar" é o fundo inicial mais o dinheiro físico recebido menos o devolvido. Pagamentos por M-Pesa, Emola e outros aparecem no detalhe do turno.
                </small>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 xl:col-span-6">
                    <div class="card !mb-0 h-full">
                        <div class="font-semibold text-lg mb-3">Consumo por vendedor</div>
                        <DataTable :value="data.consumption.by_seller" size="small">
                            <template #empty>Sem consumo no período.</template>
                            <Column field="name" header="Vendedor" />
                            <Column field="sales_count" header="Vendas" />
                            <Column header="Bruto"><template #body="{ data: row }">{{ formatMt(row.gross) }}</template></Column>
                            <Column header="Estornos"><template #body="{ data: row }">{{ formatMt(row.reversed) }}</template></Column>
                            <Column header="Líquido"><template #body="{ data: row }"><strong>{{ formatMt(row.net) }}</strong></template></Column>
                        </DataTable>
                    </div>
                </div>
                <div class="col-span-12 xl:col-span-6">
                    <div class="card !mb-0 h-full">
                        <div class="font-semibold text-lg mb-1">Dinheiro dos clientes em cartões</div>
                        <small class="text-muted-color">Saldo ainda por consumir e cauções por devolver (neste momento).</small>
                        <div class="flex gap-6 my-3">
                            <div><small>Saldos</small><div class="text-2xl font-bold">{{ formatMt(data.outstanding.balance) }}</div></div>
                            <div><small>Cauções</small><div class="text-2xl font-bold">{{ formatMt(data.outstanding.deposits) }}</div></div>
                            <div><small>Total</small><div class="text-2xl font-bold text-primary">{{ formatMt(data.outstanding.total) }}</div></div>
                        </div>
                        <DataTable :value="cardsByStatus" size="small">
                            <Column header="Estado"><template #body="{ data: row }">{{ CARD_STATUS[row.status]?.label ?? row.status }}</template></Column>
                            <Column field="cards" header="Cartões" />
                            <Column header="Saldo"><template #body="{ data: row }">{{ formatMt(row.balance) }}</template></Column>
                            <Column header="Caução"><template #body="{ data: row }">{{ formatMt(row.deposits) }}</template></Column>
                        </DataTable>
                    </div>
                </div>
            </div>

            <div v-if="!isCardCashier" class="card !mb-0">
                <div class="flex items-center gap-3 mb-3">
                    <div class="font-semibold text-lg flex-1">Conciliação</div>
                    <Tag
                        :value="data.reconciliation.balanced && data.reconciliation.ledger_matches_cards ? 'Contas certas' : 'Diferença detectada'"
                        :severity="data.reconciliation.balanced && data.reconciliation.ledger_matches_cards ? 'success' : 'danger'"
                    />
                </div>
                <div class="grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-4">
                        <small>Passivo no início</small>
                        <div class="text-xl font-bold">{{ formatMt(data.reconciliation.opening_liability) }}</div>
                    </div>
                    <div class="col-span-12 md:col-span-4">
                        <small>Passivo no fim</small>
                        <div class="text-xl font-bold">{{ formatMt(data.reconciliation.closing_liability) }}</div>
                    </div>
                    <div class="col-span-12 md:col-span-4">
                        <small>Histórico vs saldos dos cartões</small>
                        <div class="text-xl font-bold">{{ formatMt(data.reconciliation.ledger_total) }} / {{ formatMt(data.reconciliation.cards_total) }}</div>
                    </div>
                </div>
                <DataTable :value="movements" size="small" class="mt-3">
                    <Column header="Movimento"><template #body="{ data: row }">{{ TRANSACTION_TYPES[row.type] ?? row.type }}</template></Column>
                    <Column header="Valor"><template #body="{ data: row }">{{ formatMt(row.value) }}</template></Column>
                </DataTable>
            </div>

            <div class="card !mb-0">
                <div class="font-semibold text-lg mb-3">Turnos</div>
                <DataTable
                    :value="shifts"
                    :loading="shiftsLoading"
                    lazy
                    paginator
                    :rows="10"
                    :totalRecords="shiftsTotal"
                    size="small"
                    selectionMode="single"
                    @page="(e) => { shiftsPage = e.page + 1; loadShifts(); }"
                    @row-click="(e) => openShift(e.data)"
                >
                    <template #empty>Sem turnos no período.</template>
                    <Column header="Caixa"><template #body="{ data: row }">{{ row.user?.name }}</template></Column>
                    <Column header="Aberto"><template #body="{ data: row }">{{ formatDateTime(row.opened_at) }}</template></Column>
                    <Column header="Fechado">
                        <template #body="{ data: row }">
                            <span v-if="row.closed_at">{{ formatDateTime(row.closed_at) }}</span>
                            <Tag v-else value="Aberto" severity="success" />
                        </template>
                    </Column>
                    <Column header="Fundo"><template #body="{ data: row }">{{ formatMt(row.opening_balance) }}</template></Column>
                    <Column header="Esperado"><template #body="{ data: row }">{{ row.closed_at ? formatMt(row.automatic_closing_balance) : '' }}</template></Column>
                    <Column header="Declarado"><template #body="{ data: row }">{{ row.closed_at ? formatMt(row.closing_balance) : '' }}</template></Column>
                    <Column header="Diferença">
                        <template #body="{ data: row }">
                            <span v-if="row.closed_at" :class="row.difference ? 'text-red-600 font-semibold' : 'text-green-600'">{{ differenceLabel(row.difference) }}</span>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </template>
    </div>

    <Dialog v-model:visible="shiftDialog" header="Detalhe do turno" modal :style="{ width: '64rem' }" :breakpoints="{ '1024px': '95vw' }">
        <div v-if="!shiftDetail" class="flex justify-center p-6"><ProgressSpinner style="width: 40px; height: 40px" /></div>
        <div v-else class="flex flex-col gap-4">
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-6 md:col-span-3"><small>Caixa</small><div class="font-bold">{{ shiftDetail.shift.user?.name }}</div></div>
                <div class="col-span-6 md:col-span-3"><small>Recargas</small><div class="font-bold">{{ shiftDetail.summary.topups_count }} · {{ formatMt(shiftDetail.summary.topups_total) }}</div></div>
                <div class="col-span-6 md:col-span-3"><small>Devoluções</small><div class="font-bold">{{ shiftDetail.summary.refunds_count }} · {{ formatMt(Number(shiftDetail.summary.balance_refunded) + Number(shiftDetail.summary.deposits_refunded)) }}</div></div>
                <div class="col-span-6 md:col-span-3"><small>Dinheiro esperado</small><div class="font-bold">{{ formatMt(shiftDetail.summary.expected_cash) }}</div></div>
            </div>
            <DataTable v-if="Object.keys(shiftDetail.summary.by_tender ?? {}).length" :value="Object.values(shiftDetail.summary.by_tender)" size="small">
                <Column field="label" header="Forma de pagamento" />
                <Column header="Recebido"><template #body="{ data: row }">{{ formatMt(row.received) }}</template></Column>
                <Column header="Pago"><template #body="{ data: row }">{{ formatMt(row.paid_out) }}</template></Column>
                <Column header="Líquido"><template #body="{ data: row }">{{ formatMt(row.net) }}</template></Column>
            </DataTable>
            <p v-if="shiftDetail.shift.observation" class="m-0"><strong>Observação:</strong> {{ shiftDetail.shift.observation }}</p>
            <CardTransactionsTable :transactions="shiftDetail.transactions" show-card />
        </div>
    </Dialog>
</template>

<style scoped>
.stat small {
    color: var(--text-color-secondary);
}

.stat__value {
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0.3rem 0;
}
</style>
