<template>
    <div class="show-downloader-app">
        <div
            v-if="notice"
            class="alert alert-dismissible"
            :class="notice.type === 'error' ? 'alert-danger' : 'alert-success'"
            role="alert"
        >
            {{ notice.text }}
            <button
                type="button"
                class="btn-close"
                aria-label="Fermer"
                @click="notice = null"
            />
        </div>

        <section
            class="card mb-4"
            role="region"
            aria-labelledby="sd-jobs-title"
        >
            <div class="card-header text-bg-primary">
                <h2
                    id="sd-jobs-title"
                    class="card-title"
                >
                    Téléchargements automatiques
                </h2>
            </div>

            <div
                class="card-body alert alert-info mb-0 rounded-0"
                role="alert"
            >
                Programmez la récupération de vos émissions depuis un serveur FTP, SFTP ou une adresse web.
                Les fichiers sont placés dans la médiathèque de la station
                <strong v-if="station">{{ station.name }}</strong>
                aux jours et heures choisis (fuseau horaire de la station :
                <strong>{{ station ? station.timezone : '…' }}</strong>).
            </div>

            <div class="card-body buttons">
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="openEditor(null)"
                >
                    <svg
                        class="icon"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    ><path
                        fill="currentColor"
                        d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"
                    /></svg>
                    <span>Ajouter une émission</span>
                </button>
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="loading"
                    @click="reload()"
                >
                    Actualiser
                </button>
            </div>

            <div
                v-if="loading && !loaded"
                class="card-body"
            >
                <div
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                />
                Chargement…
            </div>

            <div
                v-else-if="loadError"
                class="card-body"
            >
                <div class="alert alert-danger mb-0">
                    {{ loadError }}
                </div>
            </div>

            <div
                v-else-if="jobs.length === 0"
                class="card-body"
            >
                <p class="card-text mb-0">
                    Aucune émission programmée pour l'instant. Cliquez sur « Ajouter une émission » pour configurer
                    le premier téléchargement.
                </p>
            </div>

            <div
                v-else
                class="table-responsive"
            >
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Émission</th>
                            <th>Planification</th>
                            <th>Destination</th>
                            <th>Dernier téléchargement</th>
                            <th class="text-end">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="job in jobs"
                            :key="job.id"
                            :class="{ 'text-body-secondary': !job.enabled }"
                        >
                            <td>
                                <h5 class="m-0">
                                    {{ job.name }}
                                </h5>
                                <div class="small text-body-secondary text-break">
                                    <span class="badge text-bg-secondary me-1">{{ sourceLabel(job) }}</span>
                                    {{ sourceSummary(job) }}
                                </div>
                                <span
                                    v-if="!job.enabled"
                                    class="badge text-bg-warning mt-1"
                                >Désactivée</span>
                            </td>
                            <td class="small">
                                <div
                                    v-for="(slot, i) in job.schedule.slots"
                                    :key="i"
                                >
                                    {{ daysLabel(slot.days) }} à {{ slot.time }}
                                </div>
                                <div v-if="job.schedule.slots.length === 0">
                                    Manuel uniquement
                                </div>
                                <div
                                    v-if="job.status.next_run"
                                    class="text-body-secondary"
                                >
                                    Prochain : {{ formatDateTime(job.status.next_run) }}
                                </div>
                            </td>
                            <td class="small text-break">
                                <code>/{{ job.target.directory }}</code>
                                <div
                                    v-if="job.target.rename_enabled"
                                    class="text-body-secondary"
                                >
                                    Renommé : {{ job.target.rename_pattern }}
                                </div>
                                <div
                                    v-if="playlistName(job.target.playlist_id)"
                                    class="text-body-secondary"
                                >
                                    Playlist : {{ playlistName(job.target.playlist_id) }}
                                </div>
                            </td>
                            <td class="small sd-status">
                                <template v-if="job.status.running">
                                    <span class="badge text-bg-info">
                                        <span class="spinner-border spinner-border-sm me-1" />
                                        En cours
                                    </span>
                                </template>
                                <template v-else-if="job.status.run_requested">
                                    <span class="badge text-bg-info">Démarrage dans la minute</span>
                                </template>
                                <template v-if="job.status.last_status">
                                    <span
                                        class="badge"
                                        :class="statusClass(job.status.last_status)"
                                    >{{ statusLabel(job.status.last_status) }}</span>
                                    <span class="ms-1 text-body-secondary">{{ formatDateTime(job.status.last_run) }}</span>
                                    <div class="text-break">
                                        {{ job.status.last_message }}
                                    </div>
                                </template>
                                <span
                                    v-else-if="!job.status.running && !job.status.run_requested"
                                    class="text-body-secondary"
                                >Jamais exécuté</span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        @click="openEditor(job)"
                                    >
                                        Modifier
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        :disabled="job.status.running || job.status.run_requested"
                                        @click="runNow(job)"
                                    >
                                        Télécharger maintenant
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        @click="duplicate(job)"
                                    >
                                        Dupliquer
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger"
                                        @click="remove(job)"
                                    >
                                        Supprimer
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            class="card"
            role="region"
            aria-labelledby="sd-history-title"
        >
            <div class="card-header text-bg-primary d-flex align-items-center">
                <h2
                    id="sd-history-title"
                    class="card-title flex-fill"
                >
                    Historique
                </h2>
            </div>
            <div class="card-body buttons d-flex flex-wrap align-items-center gap-2">
                <select
                    v-model="historyJob"
                    class="form-select w-auto"
                    aria-label="Filtrer par émission"
                    @change="loadHistory()"
                >
                    <option value="">
                        Toutes les émissions
                    </option>
                    <option
                        v-for="job in jobs"
                        :key="job.id"
                        :value="job.id"
                    >
                        {{ job.name }}
                    </option>
                </select>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="loadHistory()"
                >
                    Actualiser
                </button>
                <button
                    type="button"
                    class="btn btn-outline-danger ms-auto"
                    :disabled="history.length === 0"
                    @click="clearHistory()"
                >
                    Vider l'historique
                </button>
            </div>
            <div
                v-if="history.length === 0"
                class="card-body pt-0"
            >
                <p class="card-text text-body-secondary mb-0">
                    Aucun téléchargement enregistré.
                </p>
            </div>
            <div
                v-else
                class="table-responsive"
            >
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Émission</th>
                            <th>Déclenchement</th>
                            <th>Résultat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in history.slice(0, historyLimit)"
                            :key="i"
                        >
                            <td class="text-nowrap small">
                                {{ formatDateTime(row.at * 1000) }}
                            </td>
                            <td class="small">
                                {{ row.name }}
                            </td>
                            <td class="small">
                                {{ triggerLabel(row.trigger) }}
                            </td>
                            <td class="small text-break">
                                <span
                                    class="badge me-1"
                                    :class="statusClass(row.status)"
                                >{{ statusLabel(row.status) }}</span>
                                {{ row.message }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    v-if="history.length > historyLimit"
                    class="card-body"
                >
                    <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        @click="historyLimit += 50"
                    >
                        Afficher plus
                    </button>
                </div>
            </div>
        </section>

        <p class="small text-body-secondary mt-2 mb-0">
            Show Downloader v{{ version }}
        </p>

        <job-modal
            v-if="editing"
            :job="editing"
            :api="api"
            :playlists="playlists"
            @close="editing = null"
            @saved="onSaved"
        />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { createApi } from "./api.js";
import JobModal from "./JobModal.vue";
import { DAYS, emptyJob } from "./defaults.js";

const props = defineProps({
    base: { type: String, required: true },
});

const api = createApi(props.base);

const loading = ref(false);
const loaded = ref(false);
const loadError = ref("");
const station = ref(null);
const version = ref("");
const jobs = ref([]);
const playlists = ref([]);
const history = ref([]);
const historyJob = ref("");
const historyLimit = ref(50);
const editing = ref(null);
const notice = ref(null);
let timer = null;

const busy = computed(() => jobs.value.some((j) => j.status.running || j.status.run_requested));

async function reload(silent = false) {
    if (!silent) loading.value = true;
    try {
        const data = await api.state();
        station.value = data.station;
        version.value = data.version;
        jobs.value = data.jobs;
        playlists.value = data.playlists;
        loadError.value = "";
        loaded.value = true;
    } catch (error) {
        if (!silent || !loaded.value) loadError.value = error.message;
    } finally {
        loading.value = false;
    }
    if (!silent || busy.value) await loadHistory();
    scheduleRefresh();
}

async function loadHistory() {
    try {
        history.value = (await api.history(historyJob.value)).rows;
    } catch (error) {
        notify("error", error.message);
    }
}

function scheduleRefresh() {
    clearTimeout(timer);
    timer = setTimeout(() => reload(true), busy.value ? 5000 : 30000);
}

function notify(type, text) {
    notice.value = { type, text };
    if (type !== "error") setTimeout(() => notice.value?.text === text && (notice.value = null), 6000);
}

function openEditor(job) {
    editing.value = job ? JSON.parse(JSON.stringify(job)) : emptyJob();
}

function duplicate(job) {
    const copy = JSON.parse(JSON.stringify(job));
    copy.id = "";
    copy.name = `${job.name} (copie)`;
    // Secrets are not sent to the browser and must be entered again.
    copy.source.password = "";
    copy.source.private_key = "";
    copy.source.has_password = false;
    copy.source.has_private_key = false;
    editing.value = copy;
}

async function onSaved(job) {
    editing.value = null;
    notify("success", `Émission « ${job.name} » enregistrée.`);
    await reload();
}

async function runNow(job) {
    try {
        const result = await api.run(job.id);
        notify("success", result.message);
        await reload(true);
    } catch (error) {
        notify("error", error.message);
    }
}

async function remove(job) {
    if (!window.confirm(`Supprimer l'émission « ${job.name} » ? Les fichiers déjà téléchargés restent dans la médiathèque.`)) {
        return;
    }
    try {
        await api.remove(job.id);
        notify("success", `Émission « ${job.name} » supprimée.`);
        await reload();
    } catch (error) {
        notify("error", error.message);
    }
}

async function clearHistory() {
    if (!window.confirm("Vider tout l'historique de cette station ?")) return;
    try {
        await api.clearHistory();
        history.value = [];
    } catch (error) {
        notify("error", error.message);
    }
}

function sourceLabel(job) {
    if (job.source.type === "url") return "URL";
    if (job.source.type === "sftp") return "SFTP";
    return job.source.encryption === "none" ? "FTP" : "FTPS";
}

function sourceSummary(job) {
    if (job.source.type === "url") return job.source.url;
    const dir = job.source.remote_dir ? `/${job.source.remote_dir.replace(/^\/+/, "")}` : "";
    return `${job.source.username}@${job.source.host}${dir}/${job.file.pattern}`;
}

function daysLabel(days) {
    if (days.length === 7) return "Tous les jours";
    if (days.join() === "1,2,3,4,5") return "Du lundi au vendredi";
    if (days.join() === "6,7") return "Le week-end";
    return days.map((d) => DAYS[d - 1].short).join(", ");
}

function playlistName(id) {
    return playlists.value.find((p) => p.id === id)?.name || "";
}

function formatDateTime(value) {
    if (!value) return "";
    const options = { dateStyle: "short", timeStyle: "short" };
    if (station.value?.timezone) options.timeZone = station.value.timezone;
    try {
        return new Intl.DateTimeFormat("fr-BE", options).format(new Date(value));
    } catch {
        return new Date(value).toLocaleString();
    }
}

const STATUS = {
    success: ["Réussi", "text-bg-success"],
    skipped: ["Déjà présent", "text-bg-secondary"],
    unchanged: ["Inchangé", "text-bg-warning"],
    not_found: ["Introuvable", "text-bg-warning"],
    error: ["Erreur", "text-bg-danger"],
};
const statusLabel = (status) => STATUS[status]?.[0] || status;
const statusClass = (status) => STATUS[status]?.[1] || "text-bg-secondary";
const triggerLabel = (trigger) => ({
    schedule: "Planifié",
    retry: "Nouvel essai",
    manual: "Manuel",
    missed: "Horaire manqué",
})[trigger] || trigger;

onMounted(() => reload());
onBeforeUnmount(() => clearTimeout(timer));
</script>
