<script setup>
import { onMounted, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import { formatDateTime } from '@/utils/cards';

const toast = useToast();

const loading = ref(true);
const loadError = ref(null);
const apps = ref([]);
const saving = ref(false);
const uploadProgress = ref(0);

const selectedApp = ref(null);
const releaseDialog = ref(false);
const policyDialog = ref(false);
const releaseForm = ref({});
const policyForm = ref({});

function errorMessage(error, fallback) {
    const data = error?.response?.data;
    if (error?.response?.status === 413) return 'O ficheiro é maior do que o limite de upload do servidor.';
    return Object.values(data?.errors || {})[0]?.[0] || data?.message || fallback;
}

async function getData() {
    try {
        const { data } = await axios.get('/api/admin-apps');
        apps.value = data.apps ?? [];
        loadError.value = null;
    } catch (error) {
        loadError.value = error?.response?.status === 403 ? 'Só o administrador pode gerir a aplicação móvel.' : errorMessage(error, 'Não foi possível carregar.');
    } finally {
        loading.value = false;
    }
}

function nextVersionName(current) {
    if (!current) return '1.0.0';
    const parts = current.split('.');
    const last = Number(parts.at(-1));
    if (Number.isNaN(last)) return current;
    parts[parts.length - 1] = String(last + 1);
    return parts.join('.');
}

function openReleaseDialog(app) {
    selectedApp.value = app;
    releaseForm.value = {
        version_name: nextVersionName(app.latest_release?.version_name),
        version_code: (app.latest_release?.version_code ?? 0) + 1,
        changelog: '',
        force_update: false,
        apk: null
    };
    uploadProgress.value = 0;
    releaseDialog.value = true;
}

function openPolicyDialog(app) {
    selectedApp.value = app;
    policyForm.value = {
        min_version_code: app.min_version_code ?? 0,
        force_update: !!app.force_update
    };
    policyDialog.value = true;
}

function onApkSelect(event) {
    releaseForm.value.apk = event.files?.[0] ?? null;
}

async function publishRelease() {
    const form = releaseForm.value;
    if (!form.apk) {
        toast.add({ severity: 'warn', summary: 'APK em falta', detail: 'Seleccione o ficheiro .apk.', life: 3500 });
        return;
    }

    const body = new FormData();
    body.append('version_name', form.version_name);
    body.append('version_code', String(form.version_code));
    body.append('changelog', form.changelog || '');
    body.append('force_update', form.force_update ? '1' : '0');
    body.append('apk', form.apk);

    saving.value = true;
    uploadProgress.value = 0;
    try {
        const { data } = await axios.post(`/api/admin-apps/${selectedApp.value.slug}/releases`, body, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: (event) => {
                if (event.total) uploadProgress.value = Math.round((event.loaded * 100) / event.total);
            }
        });
        toast.add({ severity: 'success', summary: 'Publicado', detail: data.message, life: 4000 });
        releaseDialog.value = false;
        await getData();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: errorMessage(error, 'Não foi possível publicar a versão.'), life: 6000 });
    } finally {
        saving.value = false;
    }
}

async function savePolicy() {
    saving.value = true;
    try {
        const { data } = await axios.post(`/api/admin-apps/${selectedApp.value.slug}`, policyForm.value);
        toast.add({ severity: 'success', summary: 'Guardado', detail: data.message, life: 3000 });
        policyDialog.value = false;
        await getData();
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Erro', detail: errorMessage(error, 'Não foi possível guardar.'), life: 5000 });
    } finally {
        saving.value = false;
    }
}

async function copyLink(url) {
    try {
        await navigator.clipboard.writeText(url);
        toast.add({ severity: 'info', summary: 'Copiado', detail: 'Link do APK copiado.', life: 2000 });
    } catch {
        window.prompt('Copie o link:', url);
    }
}

function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (!value) return '—';
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function versionLabel(release) {
    return release ? `${release.version_name} (${release.version_code})` : 'Sem versão publicada';
}

onMounted(getData);
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h4 class="m-0">Aplicação móvel</h4>
                <span class="text-muted-color">Publique novas versões do APK. Os telemóveis são avisados ao abrir a aplicação.</span>
            </div>
            <Button icon="pi pi-refresh" label="Actualizar" outlined :loading="loading" @click="getData" />
        </div>

        <div v-if="loading && !apps.length" class="card"><Skeleton height="14rem" /></div>

        <Message v-else-if="loadError" severity="error" :closable="false">{{ loadError }}</Message>

        <div v-for="app in apps" v-else :key="app.slug" class="card">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="app-icon"><i class="pi pi-android" /></div>
                    <div>
                        <h5 class="m-0">{{ app.name }}</h5>
                        <div class="text-muted-color text-sm">{{ app.package_name }} · {{ app.description }}</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button label="Publicar nova versão" icon="pi pi-upload" @click="openReleaseDialog(app)" />
                    <Button label="Política de actualização" icon="pi pi-cog" outlined :disabled="!app.latest_release" @click="openPolicyDialog(app)" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                <div class="stat">
                    <span>Versão actual</span>
                    <strong>{{ versionLabel(app.latest_release) }}</strong>
                </div>
                <div class="stat">
                    <span>Mínimo obrigatório (version code)</span>
                    <strong>{{ app.min_version_code || 'Nenhum' }}</strong>
                </div>
                <div class="stat">
                    <span>Actualização</span>
                    <strong>
                        <Tag :value="app.force_update ? 'Obrigatória' : 'Opcional'" :severity="app.force_update ? 'danger' : 'secondary'" />
                    </strong>
                </div>
            </div>

            <div v-if="app.latest_release" class="flex flex-wrap items-center gap-2 mt-3">
                <a :href="app.latest_release.download_url" class="p-button p-component p-button-outlined p-button-sm no-underline" target="_blank" rel="noopener">
                    <span class="p-button-icon p-button-icon-left pi pi-download" />
                    <span class="p-button-label">Descarregar APK actual</span>
                </a>
                <Button label="Copiar link" icon="pi pi-link" size="small" text @click="copyLink(app.latest_release.download_url)" />
            </div>

            <h6 class="mt-5 mb-2">Histórico de versões</h6>
            <DataTable :value="app.releases" size="small" stripedRows :rows="10" :paginator="app.releases.length > 10">
                <template #empty>Ainda não foi publicada nenhuma versão.</template>
                <Column header="Versão">
                    <template #body="{ data }"><strong>{{ data.version_name }}</strong></template>
                </Column>
                <Column field="version_code" header="Code" />
                <Column header="Tamanho">
                    <template #body="{ data }">{{ formatBytes(data.file_size) }}</template>
                </Column>
                <Column header="Tipo">
                    <template #body="{ data }">
                        <Tag :value="data.force_update ? 'Obrigatória' : 'Opcional'" :severity="data.force_update ? 'danger' : 'secondary'" />
                    </template>
                </Column>
                <Column header="Notas">
                    <template #body="{ data }"><span class="notes">{{ data.changelog || '—' }}</span></template>
                </Column>
                <Column header="Publicada">
                    <template #body="{ data }">
                        {{ formatDateTime(data.created_at) }}
                        <div v-if="data.published_by" class="text-muted-color text-xs">{{ data.published_by }}</div>
                    </template>
                </Column>
                <Column style="width: 3rem">
                    <template #body="{ data }">
                        <a :href="data.download_url" target="_blank" rel="noopener" title="Descarregar"><i class="pi pi-download" /></a>
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="releaseDialog" modal header="Publicar nova versão" :style="{ width: '34rem' }" :closable="!saving">
            <div class="flex flex-col gap-4">
                <Message severity="info" :closable="false" class="text-sm">
                    No <code>pubspec.yaml</code> a linha <code>version: {{ releaseForm.version_name }}+{{ releaseForm.version_code }}</code> tem de
                    corresponder a estes dois campos, e o APK tem de ser assinado com a mesma chave das instalações actuais.
                </Message>
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-2">
                        <label for="version_name">Nome da versão</label>
                        <InputText id="version_name" v-model="releaseForm.version_name" placeholder="1.0.1" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="version_code">Version code (+N)</label>
                        <InputNumber v-model="releaseForm.version_code" inputId="version_code" :min="1" :useGrouping="false" />
                    </div>
                </div>
                <small class="text-muted-color -mt-2">
                    O version code tem de ser maior do que {{ selectedApp?.latest_release?.version_code ?? 0 }}. É ele que decide se há actualização.
                </small>

                <div class="flex flex-col gap-2">
                    <label>Ficheiro APK</label>
                    <FileUpload
                        mode="basic"
                        name="apk"
                        accept=".apk,application/vnd.android.package-archive"
                        :maxFileSize="157286400"
                        :auto="false"
                        chooseLabel="Escolher APK"
                        customUpload
                        :disabled="saving"
                        @select="onApkSelect"
                    />
                    <small v-if="releaseForm.apk" class="text-muted-color">{{ releaseForm.apk.name }} · {{ formatBytes(releaseForm.apk.size) }}</small>
                </div>

                <div class="flex flex-col gap-2">
                    <label for="changelog">O que mudou</label>
                    <Textarea id="changelog" v-model="releaseForm.changelog" rows="3" placeholder="Ex.: pagamento com cartão nas mesas." />
                </div>

                <div class="flex items-start gap-2">
                    <Checkbox v-model="releaseForm.force_update" :binary="true" inputId="force_update" />
                    <label for="force_update">
                        <strong>Actualização obrigatória</strong>
                        <div class="text-muted-color text-sm">
                            Quem tiver uma versão anterior só consegue usar a aplicação depois de actualizar. Sem esta opção pode carregar em "Agora não".
                        </div>
                    </label>
                </div>

                <div v-if="saving">
                    <ProgressBar :value="uploadProgress" />
                    <small class="text-muted-color">{{ uploadProgress < 100 ? 'A enviar o APK…' : 'A validar e publicar…' }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text :disabled="saving" @click="releaseDialog = false" />
                <Button label="Publicar" icon="pi pi-check" :loading="saving" @click="publishRelease" />
            </template>
        </Dialog>

        <Dialog v-model:visible="policyDialog" modal header="Política de actualização" :style="{ width: '30rem' }">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <label for="min_version_code">Mínimo obrigatório (version code)</label>
                    <InputNumber
                        v-model="policyForm.min_version_code"
                        inputId="min_version_code"
                        :min="0"
                        :max="selectedApp?.latest_release?.version_code ?? 0"
                        :useGrouping="false"
                    />
                    <small class="text-muted-color">Telemóveis com um version code abaixo deste são obrigados a actualizar. 0 = nenhum.</small>
                </div>
                <div class="flex items-start gap-2">
                    <Checkbox v-model="policyForm.force_update" :binary="true" inputId="policy_force" />
                    <label for="policy_force">
                        <strong>Obrigar todos a instalar a última versão</strong>
                        <div class="text-muted-color text-sm">Remove o botão "Agora não" para quem não tiver a versão {{ versionLabel(selectedApp?.latest_release) }}.</div>
                    </label>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" text :disabled="saving" @click="policyDialog = false" />
                <Button label="Guardar" icon="pi pi-check" :loading="saving" @click="savePolicy" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.app-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--p-green-50, #ecfdf5);
    color: var(--p-green-600, #16a34a);
    font-size: 1.5rem;
}

.stat {
    border: 1px solid var(--surface-border);
    border-radius: 0.75rem;
    padding: 0.75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat span {
    font-size: 0.85rem;
    color: var(--text-color-secondary);
}

.notes {
    white-space: pre-line;
    font-size: 0.85rem;
}
</style>
