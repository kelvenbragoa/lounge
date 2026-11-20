<script setup>
import { onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import { debounce } from 'lodash-es';
import CardSummary from '@/components/cards/CardSummary.vue';
import CardTransactionsTable from '@/components/cards/CardTransactionsTable.vue';
import { CARD_STATUS, apiErrorMessage, cardStatus, cardUidLabel, formatDateTime, formatMt } from '@/utils/cards';

const toast = useToast();

const loading = ref(false);
const cards = ref([]);
const totalRecords = ref(0);
const page = ref(1);
const perPage = ref(15);
const query = ref('');
const status = ref(null);
const statusOptions = [{ label: 'Todos', value: null }, ...Object.entries(CARD_STATUS).map(([value, s]) => ({ label: s.label, value }))];

const detailDialog = ref(false);
const detailCard = ref(null);
const detailTransactions = ref([]);
const detailLoading = ref(false);

async function load() {
    loading.value = true;
    try {
        const response = await axios.get('/api/cards', {
            params: { page: page.value, per_page: perPage.value, query: query.value || undefined, status: status.value || undefined }
        });
        cards.value = response.data.data;
        totalRecords.value = response.data.total;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: apiErrorMessage(error), life: 4000 });
    } finally {
        loading.value = false;
    }
}

const search = debounce(() => {
    page.value = 1;
    load();
}, 350);

function onPage(event) {
    page.value = event.page + 1;
    perPage.value = event.rows;
    load();
}

async function openDetail(card) {
    detailCard.value = card;
    detailTransactions.value = [];
    detailDialog.value = true;
    detailLoading.value = true;
    try {
        const response = await axios.get(`/api/cards/${card.id}/transactions`, { params: { per_page: 100 } });
        detailTransactions.value = response.data.data;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: apiErrorMessage(error), life: 4000 });
    } finally {
        detailLoading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="card">
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <div class="font-semibold text-xl flex-1">Cartões</div>
            <IconField>
                <InputIcon class="pi pi-search" />
                <InputText v-model="query" placeholder="UID, código, nome ou telefone" @input="search" />
            </IconField>
            <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Estado" @change="search" />
        </div>

        <DataTable
            :value="cards"
            :loading="loading"
            lazy
            paginator
            :rows="perPage"
            :totalRecords="totalRecords"
            :rowsPerPageOptions="[15, 30, 50]"
            stripedRows
            size="small"
            selectionMode="single"
            @page="onPage"
            @row-click="(e) => openDetail(e.data)"
        >
            <template #empty>Nenhum cartão encontrado.</template>
            <Column field="uid" header="Cartão"><template #body="{ data }"><span class="font-mono">{{ cardUidLabel(data) }}</span></template></Column>
            <Column field="code" header="Código" />
            <Column field="holder_name" header="Cliente" />
            <Column field="holder_phone" header="Telefone" />
            <Column header="Saldo"><template #body="{ data }"><strong>{{ formatMt(data.balance) }}</strong></template></Column>
            <Column header="Caução"><template #body="{ data }">{{ formatMt(data.deposit_amount) }}</template></Column>
            <Column header="Estado">
                <template #body="{ data }"><Tag :value="cardStatus(data.status).label" :severity="cardStatus(data.status).severity" /></template>
            </Column>
            <Column header="Registado">
                <template #body="{ data }">{{ formatDateTime(data.registered_at) }} <small class="text-muted-color">{{ data.registered_by?.name }}</small></template>
            </Column>
        </DataTable>
    </div>

    <Dialog v-model:visible="detailDialog" header="Detalhe do cartão" modal :style="{ width: '60rem' }" :breakpoints="{ '960px': '95vw' }">
        <div v-if="detailCard" class="flex flex-col gap-4">
            <CardSummary :card="detailCard" />
            <CardTransactionsTable :transactions="detailTransactions" :loading="detailLoading" />
        </div>
    </Dialog>
</template>
