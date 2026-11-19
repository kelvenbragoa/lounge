import axios from 'axios';

export const CARD_STATUS = {
    active: { label: 'Activo', severity: 'success' },
    blocked: { label: 'Bloqueado', severity: 'danger' },
    lost: { label: 'Perdido', severity: 'danger' },
    returned: { label: 'Devolvido', severity: 'secondary' }
};

export const TRANSACTION_TYPES = {
    deposit_in: 'Caução recebida',
    topup: 'Recarga',
    purchase: 'Consumo',
    purchase_reversal: 'Estorno de consumo',
    refund_balance: 'Devolução de saldo',
    deposit_out: 'Devolução de caução',
    transfer_in: 'Transferência recebida',
    transfer_out: 'Transferência enviada',
    adjustment: 'Ajuste'
};

const mtFormatter = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatMt(value) {
    return `${mtFormatter.format(Number(value ?? 0))} MT`;
}

export function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}

/** Mesmo formato do servidor: hex maiúsculo sem separadores. */
export function normalizeUid(raw) {
    return String(raw ?? '')
        .replace(/[^0-9a-fA-F]/g, '')
        .toUpperCase();
}

export function cardStatus(status) {
    return CARD_STATUS[status] ?? { label: status, severity: 'secondary' };
}

export function cardDisplayName(card) {
    return card?.holder_name || card?.code || (card ? `Cartão #${card.id}` : '');
}

export function apiErrorMessage(error, fallback = 'Ocorreu um erro inesperado.') {
    const data = error?.response?.data;
    if (data?.code === 'card_shift_closed') {
        return 'O seu turno de cartões está fechado. Abra o turno antes de recarregar ou devolver.';
    }
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    if (data?.message) return data.message;
    if (!error?.response) return 'Sem ligação ao servidor. Verifique a rede e tente novamente.';
    return fallback;
}

export function isNetworkError(error) {
    return !error?.response;
}

let settingsPromise = null;

export function loadCardSettings() {
    settingsPromise ??= axios
        .get('/api/cards/settings')
        .then((response) => response.data)
        .catch((error) => {
            settingsPromise = null;
            throw error;
        });
    return settingsPromise;
}

export async function lookupCard(uid) {
    const response = await axios.post('/api/cards/lookup', { uid });
    return response.data;
}
