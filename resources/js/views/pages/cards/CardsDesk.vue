<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useRoute } from 'vue-router';
import CardReaderInput from '@/components/cards/CardReaderInput.vue';
import CardSummary from '@/components/cards/CardSummary.vue';
import CardTransactionsTable from '@/components/cards/CardTransactionsTable.vue';
import { createRequestId } from '@/utils/requestId';
import { apiErrorMessage, cardDisplayName, formatDateTime, formatMt, isNetworkError, loadCardSettings, lookupCard } from '@/utils/cards';

const toast = useToast();
const route = useRoute();
const QUICK_AMOUNTS = [500, 1000, 2000, 5000];
const basePath = computed(() => (route.path.startsWith('/admin') ? '/admin/cards' : '/cards'));

const settings = ref(null);
const shiftState = ref({ shift: null, summary: null });
const shiftOpen = computed(() => !!shiftState.value.shift && !shiftState.value.shift.closed_at);

const reading = ref(false);
const saving = ref(false);
const card = ref(null);
const transactions = ref([]);
const notFoundUid = ref(null);
const activeTab = ref('topup');

const registerForm = ref({ code: '', holder_name: '', holder_phone: '' });

const topupAmount = ref(null);
const topupTender = ref('cash');
const topupRequestId = ref(null);
const topupConfirm = ref(false);

const refundRequestId = ref(null);
const refundConfirm = ref(false);

const blockDialog = ref(false);
const blockForm = ref({ lost: false, reason: '' });

const replaceDialog = ref(false);
const replaceNewUid = ref(null);
const replaceRequestId = ref(null);

const openShiftDialog = ref(false);
const openingBalance = ref(0);

const missingDeposit = computed(() => {
    if (!card.value || !settings.value) return 0;
    return Math.max(0, Number(settings.value.deposit_amount) - Number(card.value.deposit_amount));
});

const topupError = computed(() => {
    const amount = Number(topupAmount.value ?? 0);
    if (!amount) return null;
    if (settings.value?.max_topup_amount && amount > Number(settings.value.max_topup_amount)) {
        return `O valor máximo por recarga é ${formatMt(settings.value.max_topup_amount)}.`;
    }
    if (missingDeposit.value > 0 && amount < missingDeposit.value) {
        return `A primeira recarga deve ser de pelo menos ${formatMt(missingDeposit.value)} (caução).`;
    }
    return null;
});

const tenderName = computed(() => settings.value?.tender_methods?.find((t) => t.id === topupTender.value)?.name ?? topupTender.value);
const blocked = computed(() => ['blocked', 'lost'].includes(card.value?.status));

watch([topupAmount, topupTender], () => {
    topupRequestId.value = null;
});

function notify(detail, severity = 'success') {
    toast.add({ severity, summary: severity === 'error' ? 'Erro' : 'Cartões', detail, life: severity === 'error' ? 5000 : 3500 });
}

async function loadShift() {
    try {
        const response = await axios.get('/api/card-shift/current');
        shiftState.value = response.data;
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    }
}

async function readCard(uid) {
    reading.value = true;
    notFoundUid.value = null;
    try {
        const data = await lookupCard(uid);
        setCard(data.card, data.transactions);
    } catch (error) {
        card.value = null;
        transactions.value = [];
        if (error?.response?.data?.code === 'card_not_found') {
            notFoundUid.value = error.response.data.uid ?? uid;
            registerForm.value = { code: '', holder_name: '', holder_phone: '' };
        } else {
            notify(apiErrorMessage(error), 'error');
        }
    } finally {
        reading.value = false;
    }
}

function setCard(value, txs = null) {
    card.value = value;
    if (txs) transactions.value = txs;
    topupAmount.value = null;
    topupRequestId.value = null;
    refundRequestId.value = null;
    activeTab.value = value?.status === 'returned' ? 'manage' : 'topup';
}

async function refreshCard() {
    if (!card.value) return;
    const data = await lookupCard(card.value.uid);
    card.value = data.card;
    transactions.value = data.transactions;
}

function clearCard() {
    card.value = null;
    transactions.value = [];
    notFoundUid.value = null;
}

async function registerCard() {
    saving.value = true;
    try {
        const response = await axios.post('/api/cards', { uid: notFoundUid.value, ...registerForm.value });
        notify(response.data.message);
        notFoundUid.value = null;
        setCard(response.data.card, []);
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function reactivateReturned() {
    saving.value = true;
    try {
        const response = await axios.post('/api/cards', {
            uid: card.value.uid,
            holder_name: registerForm.value.holder_name || null,
            holder_phone: registerForm.value.holder_phone || null
        });
        notify('Cartão reactivado para um novo cliente.');
        setCard(response.data.card, []);
        await refreshCard();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

function askTopup() {
    if (!Number(topupAmount.value)) return notify('Indique o valor recebido.', 'warn');
    if (topupError.value) return notify(topupError.value, 'warn');
    topupConfirm.value = true;
}

async function confirmTopup() {
    topupRequestId.value ??= createRequestId();
    saving.value = true;
    try {
        const response = await axios.post(`/api/cards/${card.value.id}/topup`, {
            amount: Number(topupAmount.value),
            tender_method: topupTender.value,
            request_id: topupRequestId.value
        });
        topupConfirm.value = false;
        notify(response.data.message);
        card.value = response.data.card;
        topupAmount.value = null;
        topupRequestId.value = null;
        await Promise.all([refreshCard(), loadShift()]);
    } catch (error) {
        if (!isNetworkError(error)) topupRequestId.value = null;
        notify(isNetworkError(error) ? `${apiErrorMessage(error)} Ao repetir, a recarga não será duplicada.` : apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function confirmRefund() {
    refundRequestId.value ??= createRequestId();
    saving.value = true;
    try {
        const response = await axios.post(`/api/cards/${card.value.id}/refund`, { request_id: refundRequestId.value });
        refundConfirm.value = false;
        refundRequestId.value = null;
        notify(response.data.message);
        card.value = response.data.card;
        activeTab.value = 'manage';
        await Promise.all([refreshCard(), loadShift()]);
    } catch (error) {
        if (!isNetworkError(error)) refundRequestId.value = null;
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function confirmBlock() {
    saving.value = true;
    try {
        const response = await axios.post(`/api/cards/${card.value.id}/block`, blockForm.value);
        blockDialog.value = false;
        notify(response.data.message);
        await refreshCard();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function unblock() {
    saving.value = true;
    try {
        const response = await axios.post(`/api/cards/${card.value.id}/unblock`);
        notify(response.data.message);
        await refreshCard();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

function openReplace() {
    replaceNewUid.value = null;
    replaceRequestId.value = null;
    replaceDialog.value = true;
}

async function confirmReplace() {
    replaceRequestId.value ??= createRequestId();
    saving.value = true;
    try {
        const response = await axios.post(`/api/cards/${card.value.id}/replace`, {
            new_uid: replaceNewUid.value,
            request_id: replaceRequestId.value
        });
        replaceDialog.value = false;
        notify(response.data.message);
        const data = await lookupCard(response.data.new_card.uid);
        setCard(data.card, data.transactions);
    } catch (error) {
        if (!isNetworkError(error)) replaceRequestId.value = null;
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

async function openShift() {
    saving.value = true;
    try {
        const response = await axios.post('/api/card-shift/open', { opening_balance: Number(openingBalance.value ?? 0) });
        openShiftDialog.value = false;
        notify(response.data.message);
        await loadShift();
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    loadShift();
    try {
        settings.value = await loadCardSettings();
        topupTender.value = settings.value.tender_methods?.[0]?.id ?? 'cash';
    } catch (error) {
        notify(apiErrorMessage(error), 'error');
    }
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="card shift-banner" :class="shiftOpen ? 'shift-banner--open' : 'shift-banner--closed'">
            <template v-if="shiftOpen">
                <i class="pi pi-lock-open" />
                <div class="flex-1">
                    <div class="font-semibold">Turno aberto desde {{ formatDateTime(shiftState.shift.opened_at) }}</div>
                    <small>
                        Recargas {{ shiftState.summary?.topups_count ?? 0 }} · Devoluções {{ shiftState.summary?.refunds_count ?? 0 }} ·
                        Cartões registados {{ shiftState.summary?.cards_registered ?? 0 }}
                    </small>
                </div>
                <div class="text-right">
                    <small>Dinheiro esperado na gaveta</small>
                    <div class="text-xl font-bold">{{ formatMt(shiftState.summary?.expected_cash) }}</div>
                </div>
                <router-link :to="`${basePath}/shift`"><Button label="Ver turno" text /></router-link>
            </template>
            <template v-else>
                <i class="pi pi-lock" />
                <div class="flex-1">
                    <div class="font-semibold">Turno fechado</div>
                    <small>Pode registar e consultar cartões, mas recargas e devoluções exigem o turno aberto.</small>
                </div>
                <Button label="Abrir turno" icon="pi pi-lock-open" @click="openShiftDialog = true" />
            </template>
        </div>

        <div class="card">
            <CardReaderInput :loading="reading" @read="readCard" />
        </div>

        <div v-if="notFoundUid" class="card">
            <div class="font-semibold text-xl mb-1">Registar cartão novo</div>
            <p class="text-muted-color mb-4">
                O cartão <strong class="font-mono">{{ notFoundUid }}</strong> ainda não está registado. Os dados do cliente são opcionais.
            </p>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                    <label>Código impresso</label>
                    <InputText v-model="registerForm.code" placeholder="Opcional" />
                </div>
                <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                    <label>Nome do cliente</label>
                    <InputText v-model="registerForm.holder_name" placeholder="Opcional" />
                </div>
                <div class="col-span-12 md:col-span-4 flex flex-col gap-2">
                    <label>Telefone</label>
                    <InputText v-model="registerForm.holder_phone" placeholder="Opcional" />
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <Button label="Cancelar" text @click="clearCard" />
                <Button label="Registar cartão" icon="pi pi-check" :loading="saving" @click="registerCard" />
            </div>
        </div>

        <div v-if="card" class="grid grid-cols-12 gap-4">
            <div class="col-span-12 xl:col-span-5 flex flex-col gap-4">
                <CardSummary :card="card" clearable @clear="clearCard" />

                <div class="card !mb-0">
                    <SelectButton
                        v-model="activeTab"
                        :options="[
                            { label: 'Recarregar', value: 'topup' },
                            { label: 'Devolver', value: 'refund' },
                            { label: 'Gerir', value: 'manage' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :allowEmpty="false"
                        class="w-full mb-4"
                    />

                    <div v-if="activeTab === 'topup'" class="flex flex-col gap-3">
                        <Message v-if="card.status !== 'active'" severity="warn" :closable="false">Só cartões activos podem ser recarregados.</Message>
                        <template v-else>
                            <label class="font-semibold">Valor recebido</label>
                            <InputNumber v-model="topupAmount" mode="decimal" :min="0" :minFractionDigits="0" :maxFractionDigits="2" suffix=" MT" inputClass="text-xl font-bold" fluid />
                            <div class="flex flex-wrap gap-2">
                                <Button v-for="value in QUICK_AMOUNTS" :key="value" :label="formatMt(value)" size="small" outlined @click="topupAmount = value" />
                            </div>
                            <label class="font-semibold mt-2">Forma de pagamento</label>
                            <SelectButton v-model="topupTender" :options="settings?.tender_methods ?? []" optionLabel="name" optionValue="id" :allowEmpty="false" />
                            <Message v-if="missingDeposit > 0" severity="info" :closable="false">
                                Primeira recarga: {{ formatMt(missingDeposit) }} ficam retidos como caução e são devolvidos quando o cliente entregar o cartão.
                            </Message>
                            <small v-if="topupError" class="text-red-600">{{ topupError }}</small>
                            <Button label="Recarregar" icon="pi pi-plus" class="mt-2" :disabled="!shiftOpen || !topupAmount || !!topupError" @click="askTopup" />
                            <small v-if="!shiftOpen" class="text-muted-color">Abra o turno para recarregar.</small>
                        </template>
                    </div>

                    <div v-else-if="activeTab === 'refund'" class="flex flex-col gap-2">
                        <Message v-if="card.status === 'returned'" severity="info" :closable="false">Este cartão já foi devolvido.</Message>
                        <template v-else>
                            <div class="flex justify-between"><span>Saldo a devolver</span><strong>{{ formatMt(card.balance) }}</strong></div>
                            <div class="flex justify-between"><span>Caução a devolver</span><strong>{{ formatMt(card.deposit_amount) }}</strong></div>
                            <Divider />
                            <div class="flex justify-between text-xl"><span>Total a entregar</span><strong>{{ formatMt(Number(card.balance) + Number(card.deposit_amount)) }}</strong></div>
                            <Button
                                :label="`Devolver ${formatMt(Number(card.balance) + Number(card.deposit_amount))}`"
                                icon="pi pi-replay"
                                severity="danger"
                                class="mt-3"
                                :disabled="!shiftOpen"
                                @click="refundConfirm = true"
                            />
                            <small v-if="!shiftOpen" class="text-muted-color">Abra o turno para devolver.</small>
                        </template>
                    </div>

                    <div v-else class="flex flex-col gap-3">
                        <template v-if="card.status === 'returned'">
                            <p class="m-0">Cartão devolvido. Pode ser entregue a um novo cliente.</p>
                            <InputText v-model="registerForm.holder_name" placeholder="Nome do novo cliente (opcional)" />
                            <InputText v-model="registerForm.holder_phone" placeholder="Telefone (opcional)" />
                            <Button label="Reactivar para novo cliente" icon="pi pi-refresh" :loading="saving" @click="reactivateReturned" />
                        </template>
                        <template v-else>
                            <Button v-if="card.status === 'active'" label="Bloquear / marcar perdido" icon="pi pi-ban" severity="danger" outlined @click="blockDialog = true" />
                            <Button v-if="blocked" label="Desbloquear" icon="pi pi-lock-open" outlined :loading="saving" @click="unblock" />
                            <Button label="Substituir por cartão novo" icon="pi pi-sync" outlined @click="openReplace" />
                        </template>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-7">
                <div class="card !mb-0">
                    <div class="font-semibold text-lg mb-3">Últimos movimentos</div>
                    <CardTransactionsTable :transactions="transactions" />
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:visible="topupConfirm" header="Confirmar recarga" modal :style="{ width: '26rem' }">
        <div v-if="card" class="flex flex-col gap-2">
            <div class="flex justify-between"><span>Cartão</span><strong>{{ cardDisplayName(card) }}</strong></div>
            <div class="flex justify-between"><span>Recebido ({{ tenderName }})</span><strong>{{ formatMt(topupAmount) }}</strong></div>
            <div v-if="missingDeposit > 0" class="flex justify-between text-orange-600"><span>Caução retida</span><strong>- {{ formatMt(missingDeposit) }}</strong></div>
            <div class="flex justify-between"><span>Crédito no cartão</span><strong>{{ formatMt(Number(topupAmount) - missingDeposit) }}</strong></div>
            <Divider />
            <div class="flex justify-between text-lg"><span>Saldo final</span><strong>{{ formatMt(Number(card.balance) + Number(topupAmount) - missingDeposit) }}</strong></div>
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="topupConfirm = false" />
            <Button label="Recarregar" icon="pi pi-check" :loading="saving" @click="confirmTopup" />
        </template>
    </Dialog>

    <Dialog v-model:visible="refundConfirm" header="Confirmar devolução" modal :style="{ width: '26rem' }">
        <div v-if="card" class="flex flex-col gap-2">
            <p class="m-0">Recolha o cartão e entregue ao cliente:</p>
            <div class="text-3xl font-bold text-center my-2">{{ formatMt(Number(card.balance) + Number(card.deposit_amount)) }}</div>
            <small class="text-muted-color">Saldo {{ formatMt(card.balance) }} + caução {{ formatMt(card.deposit_amount) }}</small>
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="refundConfirm = false" />
            <Button label="Devolver" icon="pi pi-replay" severity="danger" :loading="saving" @click="confirmRefund" />
        </template>
    </Dialog>

    <Dialog v-model:visible="blockDialog" header="Bloquear cartão" modal :style="{ width: '26rem' }" @show="blockForm = { lost: false, reason: '' }">
        <div class="flex flex-col gap-3">
            <p class="m-0">O cartão deixa de poder pagar até ser desbloqueado.</p>
            <div class="flex items-center gap-2">
                <Checkbox v-model="blockForm.lost" binary inputId="block_lost" />
                <label for="block_lost">Marcar como perdido</label>
            </div>
            <InputText v-model="blockForm.reason" placeholder="Motivo (opcional)" />
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="blockDialog = false" />
            <Button label="Bloquear" icon="pi pi-ban" severity="danger" :loading="saving" @click="confirmBlock" />
        </template>
    </Dialog>

    <Dialog v-model:visible="replaceDialog" header="Substituir cartão" modal :style="{ width: '30rem' }">
        <div v-if="card" class="flex flex-col gap-3">
            <p class="m-0">
                O saldo ({{ formatMt(card.balance) }}) e a caução ({{ formatMt(card.deposit_amount) }}) passam para o cartão novo. O cartão antigo deixa de
                funcionar.
            </p>
            <CardReaderInput v-if="!replaceNewUid" placeholder="Passe o cartão NOVO no leitor" @read="(uid) => (replaceNewUid = uid)" />
            <div v-else class="flex items-center justify-between">
                <span>Cartão novo: <strong class="font-mono">{{ replaceNewUid }}</strong></span>
                <Button label="Ler outro" text size="small" @click="replaceNewUid = null" />
            </div>
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="replaceDialog = false" />
            <Button label="Substituir" icon="pi pi-sync" :disabled="!replaceNewUid" :loading="saving" @click="confirmReplace" />
        </template>
    </Dialog>

    <Dialog v-model:visible="openShiftDialog" header="Abrir turno" modal :style="{ width: '24rem' }">
        <div class="flex flex-col gap-2">
            <label>Fundo de caixa (troco inicial)</label>
            <InputNumber v-model="openingBalance" mode="decimal" :min="0" :maxFractionDigits="2" suffix=" MT" fluid />
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="openShiftDialog = false" />
            <Button label="Abrir turno" icon="pi pi-lock-open" :loading="saving" @click="openShift" />
        </template>
    </Dialog>
</template>

<style scoped>
.shift-banner {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0;
}

.shift-banner > i {
    font-size: 1.6rem;
}

.shift-banner--open {
    border-left: 5px solid var(--p-green-500);
}

.shift-banner--open > i {
    color: var(--p-green-500);
}

.shift-banner--closed {
    border-left: 5px solid var(--p-orange-500);
}

.shift-banner--closed > i {
    color: var(--p-orange-500);
}
</style>
