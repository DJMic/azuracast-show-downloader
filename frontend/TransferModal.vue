<template>
    <div
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sd-transfer-title"
    >
        <div
            class="modal-dialog modal-dialog-scrollable"
            :class="mode === 'import' && preview ? 'modal-xl' : 'modal-lg'"
        >
            <div class="modal-content">
                <div class="modal-header">
                    <h1
                        id="sd-transfer-title"
                        class="modal-title fs-5"
                    >
                        {{ mode === 'export' ? 'Exporter les émissions' : 'Importer des émissions' }}
                    </h1>
                    <button
                        type="button"
                        class="btn-close"
                        aria-label="Fermer"
                        @click="close"
                    />
                </div>

                <!-- Export -->
                <div
                    v-if="mode === 'export'"
                    class="modal-body"
                >
                    <p>
                        Toutes les émissions de cette station sont enregistrées dans un fichier JSON, que vous pourrez
                        importer dans une autre station, ici ou sur une autre instance AzuraCast.
                    </p>
                    <div class="form-check mb-2">
                        <input
                            id="sd-export-secrets"
                            v-model="withSecrets"
                            class="form-check-input"
                            type="checkbox"
                        >
                        <label
                            class="form-check-label"
                            for="sd-export-secrets"
                        >Inclure les mots de passe et les clés SSH</label>
                    </div>
                    <div
                        v-if="withSecrets"
                        class="alert alert-warning py-2"
                    >
                        Les mots de passe seront lisibles en clair dans le fichier. Conservez-le en lieu sûr et
                        supprimez-le après l'import.
                    </div>
                    <div
                        v-else
                        class="form-text"
                    >
                        Sans mots de passe, les émissions importées dans une nouvelle station seront désactivées
                        jusqu'à ce que vous saisissiez leur mot de passe. Une émission qui en remplace une
                        existante garde le mot de passe déjà enregistré.
                    </div>
                </div>

                <!-- Import, step 1: file -->
                <div
                    v-else-if="!preview"
                    class="modal-body"
                >
                    <label
                        class="form-label"
                        for="sd-import-file"
                    >Fichier exporté (.json)</label>
                    <input
                        id="sd-import-file"
                        type="file"
                        class="form-control"
                        accept=".json,application/json"
                        @change="readFile"
                    >
                    <div class="form-text">
                        Rien n'est modifié avant la confirmation : vous pourrez d'abord choisir les émissions,
                        leurs dossiers de destination et leurs playlists.
                    </div>
                </div>

                <!-- Import, step 2: choices -->
                <div
                    v-else
                    class="modal-body"
                >
                    <p class="small text-body-secondary mb-2">
                        Fichier <strong>{{ fileName }}</strong>{{ origin }}
                    </p>
                    <div
                        v-if="preview.timezone_warning"
                        class="alert alert-warning py-2"
                    >
                        {{ preview.timezone_warning }}
                    </div>

                    <fieldset class="border rounded p-2 mb-3">
                        <legend class="fs-6 fw-semibold float-none w-auto px-1 mb-1">
                            Changer le dossier de toutes les émissions
                        </legend>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label
                                    class="form-label small mb-1"
                                    for="sd-prefix-from"
                                >Remplacer le début</label>
                                <input
                                    id="sd-prefix-from"
                                    v-model.trim="prefixFrom"
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="Emissions"
                                >
                            </div>
                            <div class="col-md-5">
                                <label
                                    class="form-label small mb-1"
                                    for="sd-prefix-to"
                                >par</label>
                                <input
                                    id="sd-prefix-to"
                                    v-model.trim="prefixTo"
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="Podcasts/Programmes"
                                >
                            </div>
                            <div class="col-md-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-secondary w-100"
                                    @click="replacePrefix"
                                >
                                    Appliquer
                                </button>
                            </div>
                        </div>
                        <div class="form-text">
                            Exemple : « Emissions » par « Podcasts » transforme Emissions/Matinale en Podcasts/Matinale.
                            Laissez le premier champ vide pour placer toutes les émissions dans un dossier.
                        </div>
                    </fieldset>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            aria-label="Tout sélectionner"
                                            :checked="allSelected"
                                            @change="selectAll($event.target.checked)"
                                        >
                                    </th>
                                    <th>Émission</th>
                                    <th style="min-width: 16rem">
                                        Dossier de destination
                                    </th>
                                    <th style="min-width: 11rem">
                                        Playlist
                                    </th>
                                    <th style="min-width: 11rem">
                                        Si elle existe déjà
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in preview.jobs"
                                    :key="row.index"
                                    :class="{ 'text-body-secondary': !choices[row.index].import }"
                                >
                                    <td class="text-center">
                                        <input
                                            v-model="choices[row.index].import"
                                            class="form-check-input"
                                            type="checkbox"
                                            :disabled="!!row.error"
                                            :aria-label="`Importer ${row.name}`"
                                        >
                                    </td>
                                    <td class="small">
                                        <div class="fw-semibold">
                                            {{ row.name }}
                                        </div>
                                        <div class="text-break text-body-secondary">
                                            <span class="badge text-bg-secondary me-1">{{ row.type.toUpperCase() }}</span>
                                            {{ row.summary }}
                                        </div>
                                        <div
                                            v-if="row.error"
                                            class="text-danger"
                                        >
                                            Impossible à importer : {{ row.error }}
                                        </div>
                                        <div
                                            v-else-if="row.missing_secrets && !(row.existing_id && choices[row.index].mode === 'replace')"
                                            class="text-warning-emphasis"
                                        >
                                            Mot de passe absent du fichier : l'émission sera importée désactivée.
                                        </div>
                                    </td>
                                    <td class="small">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">/</span>
                                            <input
                                                v-model.trim="choices[row.index].directory"
                                                type="text"
                                                class="form-control"
                                                :disabled="!!row.error"
                                                :aria-label="`Dossier de ${row.name}`"
                                                @input="scheduleCheck"
                                            >
                                        </div>
                                        <span
                                            v-if="checking"
                                            class="text-body-secondary"
                                        >Vérification…</span>
                                        <span
                                            v-else-if="row.directory_exists === true"
                                            class="text-success"
                                        >✓ Le dossier existe</span>
                                        <span
                                            v-else-if="row.directory_exists === false"
                                            class="text-warning-emphasis"
                                        >Dossier absent : {{ createDirectories ? 'il sera créé' : 'il sera créé au premier téléchargement' }}</span>
                                        <div
                                            v-if="row.original_directory !== choices[row.index].directory"
                                            class="text-body-secondary"
                                        >
                                            À l'origine : /{{ row.original_directory }}
                                        </div>
                                    </td>
                                    <td class="small">
                                        <select
                                            v-model="choices[row.index].playlist_id"
                                            class="form-select form-select-sm"
                                            :disabled="!!row.error"
                                            :aria-label="`Playlist de ${row.name}`"
                                        >
                                            <option :value="null">
                                                Aucune
                                            </option>
                                            <option
                                                v-for="playlist in playlists"
                                                :key="playlist.id"
                                                :value="playlist.id"
                                            >
                                                {{ playlist.name }}
                                            </option>
                                        </select>
                                        <div
                                            v-if="!row.playlist_found"
                                            class="text-warning-emphasis"
                                        >
                                            « {{ row.playlist_name }} » introuvable ici
                                        </div>
                                    </td>
                                    <td class="small">
                                        <select
                                            v-if="row.existing_id"
                                            v-model="choices[row.index].mode"
                                            class="form-select form-select-sm"
                                            :disabled="!!row.error"
                                            :aria-label="`Si ${row.name} existe déjà`"
                                        >
                                            <option value="replace">
                                                Remplacer l'existante
                                            </option>
                                            <option value="add">
                                                Ajouter une copie
                                            </option>
                                        </select>
                                        <span
                                            v-else
                                            class="text-body-secondary"
                                        >Nouvelle émission</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-check">
                        <input
                            id="sd-create-dirs"
                            v-model="createDirectories"
                            class="form-check-input"
                            type="checkbox"
                        >
                        <label
                            class="form-check-label"
                            for="sd-create-dirs"
                        >Créer maintenant les dossiers absents de la médiathèque</label>
                        <div class="form-text">
                            Sinon, ils seront créés automatiquement au premier téléchargement.
                        </div>
                    </div>
                </div>

                <div
                    v-if="error"
                    class="px-3"
                >
                    <div
                        class="alert alert-danger"
                        role="alert"
                    >
                        {{ error }}
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        v-if="mode === 'import' && preview"
                        type="button"
                        class="btn btn-outline-secondary me-auto"
                        @click="reset"
                    >
                        Choisir un autre fichier
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="close"
                    >
                        Annuler
                    </button>
                    <button
                        v-if="mode === 'export'"
                        type="button"
                        class="btn btn-primary"
                        :disabled="busy"
                        @click="doExport"
                    >
                        Télécharger le fichier
                    </button>
                    <button
                        v-else-if="preview"
                        type="button"
                        class="btn btn-primary"
                        :disabled="busy || checking || selectedCount === 0"
                        @click="doImport"
                    >
                        <span
                            v-if="busy"
                            class="spinner-border spinner-border-sm me-1"
                        />
                        Importer {{ selectedCount }} émission(s)
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show" />
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    mode: { type: String, required: true },
    api: { type: Object, required: true },
    playlists: { type: Array, default: () => [] },
});
const emit = defineEmits(["close", "done"]);

const withSecrets = ref(false);
const busy = ref(false);
const checking = ref(false);
const error = ref("");
const fileName = ref("");
const fileData = ref(null);
const preview = ref(null);
const choices = ref({});
const createDirectories = ref(true);
const prefixFrom = ref("");
const prefixTo = ref("");
let checkTimer = null;

const origin = computed(() => {
    const parts = [];
    if (preview.value?.station?.name) parts.push(` — station « ${preview.value.station.name} »`);
    if (preview.value?.exported_at) parts.push(`, exportée le ${formatDate(preview.value.exported_at)}`);
    return parts.join("");
});
const selectableRows = computed(() => (preview.value?.jobs || []).filter((row) => !row.error));
const selectedCount = computed(() => selectableRows.value.filter((row) => choices.value[row.index]?.import).length);
const allSelected = computed(() => selectableRows.value.length > 0
    && selectableRows.value.every((row) => choices.value[row.index].import));

async function doExport() {
    busy.value = true;
    error.value = "";
    try {
        const name = await props.api.exportFile(withSecrets.value);
        emit("done", `Export enregistré : ${name}`);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function readFile(event) {
    error.value = "";
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        error.value = "Fichier trop volumineux (5 Mo au maximum).";
        return;
    }
    try {
        fileData.value = JSON.parse(await file.text());
    } catch {
        error.value = "Ce fichier n'est pas un fichier JSON valide.";
        return;
    }
    fileName.value = file.name;
    await loadPreview(true);
}

function choicesPayload() {
    return (preview.value?.jobs || []).map((row) => {
        const choice = choices.value[row.index];
        return {
            import: choice.import,
            directory: choice.directory,
            playlist_id: choice.playlist_id,
            mode: choice.mode,
        };
    });
}

async function loadPreview(initial = false) {
    checking.value = !initial;
    busy.value = initial;
    error.value = "";
    try {
        const result = await props.api.importPreview(fileData.value, initial ? [] : choicesPayload());
        if (initial) {
            const next = {};
            for (const row of result.jobs) {
                next[row.index] = {
                    import: row.choice.import && !row.error,
                    directory: row.choice.directory,
                    playlist_id: row.choice.playlist_id,
                    mode: row.choice.mode,
                };
            }
            choices.value = next;
        } else {
            // Keep what the user is typing; only refresh the checks.
            for (const row of result.jobs) {
                if (row.error) choices.value[row.index].import = false;
            }
        }
        preview.value = result;
    } catch (e) {
        error.value = e.message;
        if (initial) preview.value = null;
    } finally {
        checking.value = false;
        busy.value = false;
    }
}

function scheduleCheck() {
    clearTimeout(checkTimer);
    checking.value = true;
    checkTimer = setTimeout(() => loadPreview(false), 600);
}

function replacePrefix() {
    const from = prefixFrom.value.replace(/^\/+|\/+$/g, "");
    const to = prefixTo.value.replace(/^\/+|\/+$/g, "");
    for (const row of preview.value.jobs) {
        const choice = choices.value[row.index];
        const dir = (choice.directory || "").replace(/^\/+|\/+$/g, "");
        if (from === "") {
            choice.directory = [to, dir].filter(Boolean).join("/");
        } else if (dir === from || dir.startsWith(`${from}/`)) {
            choice.directory = to + dir.slice(from.length);
            choice.directory = choice.directory.replace(/^\/+/, "");
        }
    }
    scheduleCheck();
}

function selectAll(checked) {
    for (const row of selectableRows.value) choices.value[row.index].import = checked;
}

async function doImport() {
    busy.value = true;
    error.value = "";
    try {
        const result = await props.api.importJobs(fileData.value, choicesPayload(), createDirectories.value);
        emit("done", result.message);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function reset() {
    preview.value = null;
    fileData.value = null;
    choices.value = {};
    error.value = "";
}

function close() {
    emit("close");
}

function formatDate(value) {
    try {
        return new Intl.DateTimeFormat("fr-BE", { dateStyle: "short", timeStyle: "short" }).format(new Date(value));
    } catch {
        return value;
    }
}

function onKey(event) {
    if (event.key === "Escape") close();
}
onMounted(() => {
    document.addEventListener("keydown", onKey);
    document.body.classList.add("modal-open");
});
onBeforeUnmount(() => {
    clearTimeout(checkTimer);
    document.removeEventListener("keydown", onKey);
    document.body.classList.remove("modal-open");
});
</script>
