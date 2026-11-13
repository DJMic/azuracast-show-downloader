<template>
    <div
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sd-modal-title"
    >
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form
                class="modal-content"
                novalidate
                @submit.prevent="save"
            >
                <div class="modal-header">
                    <h1
                        id="sd-modal-title"
                        class="modal-title fs-5"
                    >
                        {{ form.id ? `Modifier « ${originalName} »` : 'Ajouter une émission' }}
                    </h1>
                    <button
                        type="button"
                        class="btn-close"
                        aria-label="Fermer"
                        @click="close"
                    />
                </div>

                <div class="modal-body">
                    <ul
                        class="nav nav-tabs mb-3"
                        role="tablist"
                    >
                        <li
                            v-for="tab in tabs"
                            :key="tab.key"
                            class="nav-item"
                            role="presentation"
                        >
                            <button
                                type="button"
                                class="nav-link"
                                :class="{ active: activeTab === tab.key }"
                                role="tab"
                                :aria-selected="activeTab === tab.key"
                                @click="activeTab = tab.key"
                            >
                                {{ tab.label }}
                            </button>
                        </li>
                    </ul>

                    <!-- Émission -->
                    <div v-show="activeTab === 'general'">
                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="sd-name"
                            >Nom de l'émission</label>
                            <input
                                id="sd-name"
                                v-model.trim="form.name"
                                type="text"
                                class="form-control"
                                maxlength="120"
                                required
                            >
                            <div class="form-text">
                                Utilisé dans la liste, l'historique et dans le renommage avec <code>{show}</code>.
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input
                                id="sd-enabled"
                                v-model="form.enabled"
                                class="form-check-input"
                                type="checkbox"
                            >
                            <label
                                class="form-check-label"
                                for="sd-enabled"
                            >Téléchargement automatique activé</label>
                            <div class="form-text">
                                Désactivée, l'émission ne se télécharge plus aux horaires prévus, mais reste disponible
                                avec « Télécharger maintenant ».
                            </div>
                        </div>
                    </div>

                    <!-- Source -->
                    <div v-show="activeTab === 'source'">
                        <div class="mb-3">
                            <span class="form-label d-block">Type de source</span>
                            <div
                                class="btn-group"
                                role="group"
                            >
                                <template
                                    v-for="type in sourceTypes"
                                    :key="type.value"
                                >
                                    <input
                                        :id="`sd-type-${type.value}`"
                                        v-model="form.source.type"
                                        type="radio"
                                        class="btn-check"
                                        :value="type.value"
                                    >
                                    <label
                                        class="btn btn-outline-primary"
                                        :for="`sd-type-${type.value}`"
                                    >{{ type.label }}</label>
                                </template>
                            </div>
                        </div>

                        <template v-if="form.source.type === 'url'">
                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="sd-url"
                                >Adresse du fichier (URL)</label>
                                <input
                                    id="sd-url"
                                    v-model.trim="form.source.url"
                                    type="url"
                                    class="form-control"
                                    placeholder="https://exemple.org/podcasts/matinale_{date}.mp3"
                                >
                                <div class="form-text">
                                    Lien direct vers le fichier audio. Si la date de diffusion figure dans l'adresse,
                                    cochez « La date figure dans le nom » dans l'onglet Fichier et écrivez
                                    <code>{date}</code> à sa place.
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-http-user"
                                    >Utilisateur (facultatif)</label>
                                    <input
                                        id="sd-http-user"
                                        v-model.trim="form.source.username"
                                        type="text"
                                        class="form-control"
                                        autocomplete="off"
                                    >
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-http-pass"
                                    >Mot de passe (facultatif)</label>
                                    <input
                                        id="sd-http-pass"
                                        v-model="password"
                                        type="password"
                                        class="form-control"
                                        autocomplete="new-password"
                                        :placeholder="passwordPlaceholder"
                                    >
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="row g-3 mb-3">
                                <div class="col-md-8">
                                    <label
                                        class="form-label"
                                        for="sd-host"
                                    >Nom d'hôte</label>
                                    <input
                                        id="sd-host"
                                        v-model.trim="form.source.host"
                                        type="text"
                                        class="form-control"
                                        placeholder="ftp.exemple.org"
                                        autocomplete="off"
                                    >
                                </div>
                                <div class="col-md-4">
                                    <label
                                        class="form-label"
                                        for="sd-port"
                                    >Port</label>
                                    <input
                                        id="sd-port"
                                        v-model.number="form.source.port"
                                        type="number"
                                        min="0"
                                        max="65535"
                                        class="form-control"
                                        :placeholder="String(defaultPort)"
                                    >
                                    <div class="form-text">
                                        Vide ou 0 : {{ defaultPort }}
                                    </div>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-user"
                                    >Nom d'utilisateur</label>
                                    <input
                                        id="sd-user"
                                        v-model.trim="form.source.username"
                                        type="text"
                                        class="form-control"
                                        autocomplete="off"
                                    >
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-pass"
                                    >{{ form.source.type === 'sftp' && privateKeyInUse ? 'Phrase de passe de la clé' : 'Mot de passe' }}</label>
                                    <input
                                        id="sd-pass"
                                        v-model="password"
                                        type="password"
                                        class="form-control"
                                        autocomplete="new-password"
                                        :placeholder="passwordPlaceholder"
                                    >
                                    <button
                                        v-if="form.source.has_password && !passwordTouched"
                                        type="button"
                                        class="btn btn-link btn-sm p-0"
                                        @click="clearPassword"
                                    >
                                        Effacer le mot de passe enregistré
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="sd-dir"
                                >Répertoire distant (facultatif)</label>
                                <input
                                    id="sd-dir"
                                    v-model.trim="form.source.remote_dir"
                                    type="text"
                                    class="form-control"
                                    placeholder="/emissions/matinale"
                                >
                                <div class="form-text">
                                    Vide : le répertoire où vous arrivez après la connexion.
                                </div>
                            </div>

                            <template v-if="form.source.type === 'ftp'">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label
                                            class="form-label"
                                            for="sd-enc"
                                        >Chiffrement</label>
                                        <select
                                            id="sd-enc"
                                            v-model="form.source.encryption"
                                            class="form-select"
                                        >
                                            <option value="none">
                                                Aucun (FTP)
                                            </option>
                                            <option value="explicit">
                                                FTPS explicite (TLS, port 21)
                                            </option>
                                            <option value="implicit">
                                                FTPS implicite (port 990)
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 pt-md-4">
                                        <div class="form-check">
                                            <input
                                                id="sd-passive"
                                                v-model="form.source.passive"
                                                class="form-check-input"
                                                type="checkbox"
                                            >
                                            <label
                                                class="form-check-label"
                                                for="sd-passive"
                                            >Mode passif (recommandé)</label>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div
                                v-if="form.source.type === 'sftp'"
                                class="mb-3"
                            >
                                <label
                                    class="form-label"
                                    for="sd-key"
                                >Clé privée SSH (facultatif)</label>
                                <textarea
                                    id="sd-key"
                                    v-model="privateKey"
                                    class="form-control font-monospace small"
                                    rows="4"
                                    spellcheck="false"
                                    :placeholder="form.source.has_private_key && !privateKeyTouched
                                        ? 'Clé enregistrée (inchangée). Collez une nouvelle clé pour la remplacer.'
                                        : '-----BEGIN OPENSSH PRIVATE KEY-----'"
                                />
                                <button
                                    v-if="form.source.has_private_key && !privateKeyTouched"
                                    type="button"
                                    class="btn btn-link btn-sm p-0"
                                    @click="clearPrivateKey"
                                >
                                    Supprimer la clé enregistrée
                                </button>
                                <div class="form-text">
                                    Avec une clé, le champ « mot de passe » sert de phrase de passe de la clé (laissez-le vide s'il n'y en a pas).
                                </div>
                            </div>
                        </template>

                        <div
                            v-if="form.source.type !== 'sftp' && (form.source.type === 'url' || form.source.encryption !== 'none')"
                            class="form-check mb-3"
                        >
                            <input
                                id="sd-tls"
                                v-model="form.source.verify_tls"
                                class="form-check-input"
                                type="checkbox"
                            >
                            <label
                                class="form-check-label"
                                for="sd-tls"
                            >Vérifier le certificat TLS</label>
                            <div class="form-text">
                                Décochez uniquement pour un serveur avec un certificat auto-signé.
                            </div>
                        </div>
                    </div>

                    <!-- Fichier -->
                    <div v-show="activeTab === 'file'">
                        <div
                            v-if="form.source.type !== 'url'"
                            class="mb-3"
                        >
                            <label
                                class="form-label"
                                for="sd-pattern"
                            >Nom du fichier distant</label>
                            <input
                                id="sd-pattern"
                                v-model.trim="form.file.pattern"
                                type="text"
                                class="form-control"
                                :placeholder="form.file.date_enabled ? 'matinale_{date}.mp3' : 'matinale.mp3 ou *.mp3'"
                            >
                            <div class="form-text">
                                Nom exact, ou modèle avec <code>*</code> (n'importe quels caractères) et <code>?</code>
                                (un caractère). Vide : tous les fichiers du répertoire.
                            </div>
                        </div>
                        <div
                            v-if="form.source.type !== 'url'"
                            class="mb-3"
                        >
                            <span class="form-label d-block">Si plusieurs fichiers correspondent</span>
                            <div class="form-check form-check-inline">
                                <input
                                    id="sd-sel-newest"
                                    v-model="form.file.select"
                                    class="form-check-input"
                                    type="radio"
                                    value="newest"
                                >
                                <label
                                    class="form-check-label"
                                    for="sd-sel-newest"
                                >Prendre le plus récent</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input
                                    id="sd-sel-all"
                                    v-model="form.file.select"
                                    class="form-check-input"
                                    type="radio"
                                    value="all"
                                >
                                <label
                                    class="form-check-label"
                                    for="sd-sel-all"
                                >Les télécharger tous (50 au maximum)</label>
                            </div>
                        </div>

                        <fieldset class="border rounded p-3 mb-3">
                            <div class="form-check form-switch">
                                <input
                                    id="sd-date"
                                    v-model="form.file.date_enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label
                                    class="form-check-label fw-semibold"
                                    for="sd-date"
                                >La date de diffusion figure dans le nom du fichier</label>
                            </div>
                            <div class="form-text mb-2">
                                Écrivez <code>{date}</code> dans le nom distant{{ form.source.type === 'url' ? ' (ici : dans l\'URL)' : '' }}
                                à l'endroit où apparaît la date. Exemple : <code>matinale_{date}.mp3</code>.
                            </div>
                            <div
                                v-if="form.file.date_enabled"
                                class="row g-3"
                            >
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-date-format"
                                    >Format de la date</label>
                                    <input
                                        id="sd-date-format"
                                        v-model.trim="form.file.date_format"
                                        type="text"
                                        class="form-control"
                                        list="sd-date-formats"
                                    >
                                    <datalist id="sd-date-formats">
                                        <option
                                            v-for="format in DATE_FORMATS"
                                            :key="format"
                                            :value="format"
                                        />
                                    </datalist>
                                    <div class="form-text">
                                        YYYY = année (2026), YY = 26, MM = mois (03), M = 3, DD = jour (05), D = 5.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-date-offset"
                                    >Date de diffusion recherchée</label>
                                    <select
                                        id="sd-date-offset"
                                        v-model.number="form.file.date_offset"
                                        class="form-select"
                                    >
                                        <option
                                            v-for="offset in offsets"
                                            :key="offset.value"
                                            :value="offset.value"
                                        >
                                            {{ offset.label }}
                                        </option>
                                    </select>
                                    <div class="form-text">
                                        Par rapport au jour du téléchargement planifié.
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                        <preview-box
                            :lines="previewLines"
                        />
                    </div>

                    <!-- Destination -->
                    <div v-show="activeTab === 'target'">
                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="sd-target-dir"
                            >Dossier dans la médiathèque de la station</label>
                            <div class="input-group">
                                <span class="input-group-text">/</span>
                                <input
                                    id="sd-target-dir"
                                    v-model.trim="form.target.directory"
                                    type="text"
                                    class="form-control"
                                    placeholder="Emissions/Matinale"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    @click="toggleBrowser"
                                >
                                    Parcourir
                                </button>
                            </div>
                            <div class="form-text">
                                Vide : racine de la médiathèque. Un dossier qui n'existe pas encore est créé automatiquement.
                            </div>
                            <div
                                v-if="browser.open"
                                class="card mt-2"
                            >
                                <div class="card-body p-2">
                                    <nav aria-label="Dossier courant">
                                        <ol class="breadcrumb mb-2 small">
                                            <li class="breadcrumb-item">
                                                <a
                                                    href="#"
                                                    @click.prevent="browse('')"
                                                >Médiathèque</a>
                                            </li>
                                            <li
                                                v-for="crumb in crumbs"
                                                :key="crumb.path"
                                                class="breadcrumb-item"
                                            >
                                                <a
                                                    href="#"
                                                    @click.prevent="browse(crumb.path)"
                                                >{{ crumb.name }}</a>
                                            </li>
                                        </ol>
                                    </nav>
                                    <div
                                        v-if="browser.loading"
                                        class="small"
                                    >
                                        Chargement…
                                    </div>
                                    <div
                                        v-else-if="browser.error"
                                        class="text-danger small"
                                    >
                                        {{ browser.error }}
                                    </div>
                                    <div
                                        v-else
                                        class="list-group list-group-flush sd-folders"
                                    >
                                        <button
                                            v-for="folder in browser.folders"
                                            :key="folder.path"
                                            type="button"
                                            class="list-group-item list-group-item-action py-1"
                                            @click="browse(folder.path)"
                                        >
                                            📁 {{ folder.name }}
                                        </button>
                                        <div
                                            v-if="browser.folders.length === 0"
                                            class="small text-body-secondary px-2"
                                        >
                                            Aucun sous-dossier.
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary mt-2"
                                        @click="pickFolder"
                                    >
                                        Choisir « /{{ browser.dir }} »
                                    </button>
                                </div>
                            </div>
                        </div>

                        <fieldset class="border rounded p-3 mb-3">
                            <div class="form-check form-switch">
                                <input
                                    id="sd-rename"
                                    v-model="form.target.rename_enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label
                                    class="form-check-label fw-semibold"
                                    for="sd-rename"
                                >Renommer le fichier après téléchargement</label>
                            </div>
                            <div
                                v-if="form.target.rename_enabled"
                                class="mt-2"
                            >
                                <label
                                    class="form-label"
                                    for="sd-rename-pattern"
                                >Nouveau nom</label>
                                <input
                                    id="sd-rename-pattern"
                                    v-model.trim="form.target.rename_pattern"
                                    type="text"
                                    class="form-control"
                                    placeholder="Matinale ou Matinale_{date:DD-MM-YYYY}"
                                >
                                <div class="form-text">
                                    L'extension d'origine est ajoutée si vous ne l'écrivez pas. Variables :
                                    <code>{date}</code>, <code>{date:DD-MM-YYYY}</code> (autre format),
                                    <code>{original}</code> (nom d'origine sans extension), <code>{ext}</code>,
                                    <code>{show}</code> (nom de l'émission), <code>{n}</code> (numéro du fichier).
                                    Un nom fixe permet de garder le même fichier dans une playlist, remplacé à chaque diffusion.
                                </div>
                            </div>
                        </fieldset>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="sd-exists"
                                >Si le fichier existe déjà</label>
                                <select
                                    id="sd-exists"
                                    v-model="form.target.on_exists"
                                    class="form-select"
                                >
                                    <option value="overwrite">
                                        Le remplacer
                                    </option>
                                    <option value="rename">
                                        Garder les deux (ajouter « (2) »)
                                    </option>
                                    <option value="skip">
                                        Ne pas le télécharger
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="sd-playlist"
                                >Ajouter à la playlist (facultatif)</label>
                                <select
                                    id="sd-playlist"
                                    v-model="form.target.playlist_id"
                                    class="form-select"
                                >
                                    <option :value="null">
                                        Aucune
                                    </option>
                                    <option
                                        v-for="playlist in playlists"
                                        :key="playlist.id"
                                        :value="playlist.id"
                                    >
                                        {{ playlist.name }}{{ playlist.enabled ? '' : ' (désactivée)' }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <preview-box :lines="previewLines" />
                    </div>

                    <!-- Tags -->
                    <div v-show="activeTab === 'tags'">
                        <div class="form-check form-switch">
                            <input
                                id="sd-tags"
                                v-model="form.tags.enabled"
                                class="form-check-input"
                                type="checkbox"
                            >
                            <label
                                class="form-check-label fw-semibold"
                                for="sd-tags"
                            >Modifier les tags du fichier téléchargé</label>
                        </div>
                        <div class="form-text mb-3">
                            Remplacez le titre, l'artiste, l'album ou le genre. Un champ laissé vide garde la valeur
                            d'origine du fichier. Ce sont ces informations qu'AzuraCast affiche et annonce à l'antenne.
                        </div>
                        <template v-if="form.tags.enabled">
                            <div class="row g-3 mb-2">
                                <div
                                    v-for="field in TAG_FIELDS"
                                    :key="field.key"
                                    class="col-md-6"
                                >
                                    <label
                                        class="form-label"
                                        :for="`sd-tag-${field.key}`"
                                    >{{ field.label }}</label>
                                    <input
                                        :id="`sd-tag-${field.key}`"
                                        v-model="form.tags[field.key]"
                                        type="text"
                                        class="form-control"
                                        maxlength="255"
                                        :placeholder="`Inchangé — ex. : ${field.placeholder}`"
                                    >
                                </div>
                            </div>
                            <div class="form-text mb-3">
                                Variables : <code>{date}</code>, <code>{date:DD/MM/YYYY}</code> (autre format),
                                <code>{show}</code> (nom de l'émission), <code>{original}</code> (nom du fichier
                                d'origine sans extension), <code>{n}</code> (numéro du fichier).
                                Les tags sont écrits dans les fichiers MP3, FLAC et OGG. Pour les autres formats
                                (M4A, AAC, WAV…), ils sont enregistrés seulement dans AzuraCast.
                            </div>
                            <preview-box :lines="previewLines" />
                        </template>
                    </div>

                    <!-- Planification -->
                    <div v-show="activeTab === 'schedule'">
                        <p class="text-body-secondary small">
                            Le téléchargement démarre à l'heure indiquée (fuseau horaire de la station), au plus tard
                            une minute après.
                        </p>
                        <div
                            v-for="(slot, index) in form.schedule.slots"
                            :key="index"
                            class="border rounded p-2 mb-2 d-flex flex-wrap align-items-center gap-2"
                        >
                            <div
                                class="btn-group btn-group-sm flex-wrap"
                                role="group"
                                :aria-label="`Jours de l'horaire ${index + 1}`"
                            >
                                <template
                                    v-for="day in DAYS"
                                    :key="day.value"
                                >
                                    <input
                                        :id="`sd-slot-${index}-${day.value}`"
                                        v-model="slot.days"
                                        type="checkbox"
                                        class="btn-check"
                                        :value="day.value"
                                    >
                                    <label
                                        class="btn btn-outline-primary"
                                        :for="`sd-slot-${index}-${day.value}`"
                                        :title="day.long"
                                    >{{ day.short }}</label>
                                </template>
                            </div>
                            <label
                                class="visually-hidden"
                                :for="`sd-slot-time-${index}`"
                            >Heure</label>
                            <input
                                :id="`sd-slot-time-${index}`"
                                v-model="slot.time"
                                type="time"
                                class="form-control form-control-sm w-auto"
                                required
                            >
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger ms-auto"
                                @click="form.schedule.slots.splice(index, 1)"
                            >
                                Retirer
                            </button>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-secondary mb-3"
                            @click="form.schedule.slots.push({ days: [1, 2, 3, 4, 5], time: '06:00' })"
                        >
                            Ajouter un horaire
                        </button>
                        <div
                            v-if="form.schedule.slots.length === 0"
                            class="alert alert-warning py-2"
                        >
                            Sans horaire, l'émission ne se télécharge qu'avec « Télécharger maintenant ».
                        </div>

                        <fieldset class="border rounded p-3">
                            <legend class="fs-6 fw-semibold float-none w-auto px-1 mb-0">
                                Si le fichier n'est pas encore disponible
                            </legend>
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-retry-count"
                                    >Nombre de nouveaux essais</label>
                                    <input
                                        id="sd-retry-count"
                                        v-model.number="form.schedule.retry_count"
                                        type="number"
                                        min="0"
                                        max="96"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="sd-retry-interval"
                                    >Intervalle entre deux essais (minutes)</label>
                                    <input
                                        id="sd-retry-interval"
                                        v-model.number="form.schedule.retry_interval"
                                        type="number"
                                        min="5"
                                        max="720"
                                        class="form-control"
                                    >
                                </div>
                            </div>
                            <div class="form-text">
                                {{ retrySummary }}
                            </div>
                            <div class="form-check mt-2">
                                <input
                                    id="sd-unchanged"
                                    v-model="form.schedule.skip_unchanged"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label
                                    class="form-check-label"
                                    for="sd-unchanged"
                                >Considérer un fichier identique au précédent comme « pas encore disponible »</label>
                                <div class="form-text">
                                    Utile quand le fichier distant garde toujours le même nom : on attend la nouvelle
                                    version au lieu de réimporter l'ancienne.
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div
                        v-if="testResult"
                        class="alert mt-3 mb-0"
                        :class="testResult.success ? 'alert-success' : 'alert-warning'"
                        role="status"
                    >
                        <strong>{{ testResult.message }}</strong>
                        <div
                            v-if="testResult.date"
                            class="small"
                        >
                            Date de diffusion utilisée pour le test : {{ testResult.date }}
                        </div>
                        <ul
                            v-if="testResult.files && testResult.files.length"
                            class="small mb-0 mt-2"
                        >
                            <li
                                v-for="file in testResult.files"
                                :key="file.name"
                            >
                                <span :class="{ 'fw-semibold': file.selected }">{{ file.name }}</span>
                                <span
                                    v-if="file.size"
                                    class="text-body-secondary"
                                > ({{ formatSize(file.size) }})</span>
                                <template v-if="file.selected">
                                    → <code>/{{ file.target }}</code>
                                    <div
                                        v-if="file.tags && Object.keys(file.tags).length"
                                        class="text-body-secondary"
                                    >
                                        Tags : {{ describeTags(file.tags) }}
                                    </div>
                                </template>
                            </li>
                        </ul>
                        <div
                            v-if="testResult.available && testResult.available.length"
                            class="small mt-2"
                        >
                            Fichiers présents : {{ testResult.available.join(', ') }}
                        </div>
                    </div>
                    <div
                        v-if="error"
                        class="alert alert-danger mt-3 mb-0"
                        role="alert"
                    >
                        {{ error }}
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-primary me-auto"
                        :disabled="testing"
                        @click="test"
                    >
                        <span
                            v-if="testing"
                            class="spinner-border spinner-border-sm me-1"
                        />
                        Tester la connexion
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="close"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="saving"
                    >
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop fade show" />
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { DATE_FORMATS, DAYS, TAG_FIELDS, emptyTags } from "./defaults.js";
import { nameDate, render, splitName, targetPath } from "./naming.js";

const props = defineProps({
    job: { type: Object, required: true },
    api: { type: Object, required: true },
    playlists: { type: Array, default: () => [] },
});
const emit = defineEmits(["close", "saved"]);

const form = reactive(JSON.parse(JSON.stringify(props.job)));
// Jobs saved with version 1.0 have no tag settings yet.
form.tags = { ...emptyTags(), ...(form.tags || {}) };
const originalName = props.job.name;

// Secrets: an untouched field keeps the stored value (sent as null).
const passwordTouched = ref(!props.job.id);
const privateKeyTouched = ref(!props.job.id);
const passwordValue = ref("");
const privateKeyValue = ref("");
const password = computed({
    get: () => passwordValue.value,
    set: (value) => {
        passwordValue.value = value;
        passwordTouched.value = true;
    },
});
const privateKey = computed({
    get: () => privateKeyValue.value,
    set: (value) => {
        privateKeyValue.value = value;
        privateKeyTouched.value = true;
    },
});
const passwordPlaceholder = computed(() =>
    form.source.has_password && !passwordTouched.value ? "•••••••• (inchangé)" : "");
const privateKeyInUse = computed(() =>
    privateKeyValue.value.trim() !== "" || (form.source.has_private_key && !privateKeyTouched.value));

function clearPassword() {
    password.value = "";
}
function clearPrivateKey() {
    privateKey.value = "";
}

const tabs = [
    { key: "general", label: "Émission" },
    { key: "source", label: "Source" },
    { key: "file", label: "Fichier" },
    { key: "target", label: "Destination" },
    { key: "tags", label: "Tags" },
    { key: "schedule", label: "Planification" },
];
const activeTab = ref("general");
const sourceTypes = [
    { value: "ftp", label: "FTP / FTPS" },
    { value: "sftp", label: "SFTP" },
    { value: "url", label: "URL (http/https)" },
];
const offsets = [-7, -6, -5, -4, -3, -2, -1, 0, 1].map((value) => ({
    value,
    label: value === 0 ? "Le jour même"
        : value === -1 ? "La veille (−1 jour)"
            : value === 1 ? "Le lendemain (+1 jour)"
                : `${Math.abs(value)} jours avant`,
}));

const defaultPort = computed(() => {
    if (form.source.type === "sftp") return 22;
    return form.source.encryption === "implicit" ? 990 : 21;
});

// Switching protocol: a port that was only the old default follows the new default.
watch(defaultPort, (port, oldPort) => {
    if (!form.source.port || Number(form.source.port) === oldPort) form.source.port = port;
});

const saving = ref(false);
const testing = ref(false);
const error = ref("");
const testResult = ref(null);

// Live preview with today's date.
const previewLines = computed(() => {
    const date = nameDate(form);
    const lines = [];
    let original;
    if (form.source.type === "url") {
        const url = form.file.date_enabled
            ? render(form.source.url || "", {}, date, form.file.date_format || "YYYYMMDD")
            : form.source.url || "";
        lines.push(["Adresse téléchargée", url || "—"]);
        try {
            original = decodeURIComponent(new URL(url).pathname.split("/").pop()) || "emission.mp3";
        } catch {
            original = "emission.mp3";
        }
    } else {
        const pattern = form.file.date_enabled
            ? render(form.file.pattern || "", {}, date, form.file.date_format || "YYYYMMDD")
            : form.file.pattern || "*";
        lines.push(["Fichier recherché", pattern || "*"]);
        original = /[*?[]/.test(pattern) ? pattern.replace(/[*?]/g, "x").replace(/\[[^\]]*]/g, "x") : pattern;
        if (!original.includes(".")) original += ".mp3";
    }
    lines.push(["Enregistré sous", `/${targetPath(form, original, 1, date)}`]);
    if (form.tags.enabled) {
        const [base] = splitName(original);
        for (const field of TAG_FIELDS) {
            const pattern = (form.tags[field.key] || "").trim();
            if (!pattern) continue;
            const value = render(pattern, { original: base, show: form.name, n: 1 }, date, form.file.date_format || "YYYYMMDD");
            lines.push([field.label, value]);
        }
    }
    return lines;
});

const retrySummary = computed(() => {
    const count = Number(form.schedule.retry_count) || 0;
    const interval = Number(form.schedule.retry_interval) || 15;
    if (count === 0) return "Aucun nouvel essai : une seule tentative par horaire.";
    const total = count * interval;
    const duration = total >= 60 ? `${Math.floor(total / 60)} h ${String(total % 60).padStart(2, "0")}` : `${total} min`;
    return `Nouvel essai toutes les ${interval} min, pendant ${duration} au maximum après l'heure prévue.`;
});

// Folder browser.
const browser = reactive({ open: false, dir: "", folders: [], loading: false, error: "" });
const crumbs = computed(() => {
    const parts = browser.dir ? browser.dir.split("/") : [];
    return parts.map((name, i) => ({ name, path: parts.slice(0, i + 1).join("/") }));
});
async function browse(dir) {
    browser.dir = dir;
    browser.loading = true;
    browser.error = "";
    try {
        const data = await props.api.folders(dir);
        browser.folders = data.folders;
        browser.dir = data.dir;
    } catch (e) {
        browser.error = e.message;
        browser.folders = [];
    } finally {
        browser.loading = false;
    }
}
function toggleBrowser() {
    browser.open = !browser.open;
    if (browser.open) browse(form.target.directory.replace(/^\/+|\/+$/g, ""));
}
function pickFolder() {
    form.target.directory = browser.dir;
    browser.open = false;
}

function payload() {
    const job = JSON.parse(JSON.stringify(form));
    job.source.password = passwordTouched.value ? passwordValue.value : null;
    job.source.private_key = privateKeyTouched.value ? privateKeyValue.value : null;
    delete job.source.has_password;
    delete job.source.has_private_key;
    delete job.status;
    return job;
}

const TAB_HINTS = [
    [/nom de l'émission/i, "general"],
    [/hôte|utilisateur|url|répertoire distant|chiffrement|source/i, "source"],
    [/fichier distant|date/i, "file"],
    [/dossier|nouveau nom|playlist/i, "target"],
    [/tag/i, "tags"],
    [/heure|horaire|jour/i, "schedule"],
];
function showError(message) {
    error.value = message;
    const tab = TAB_HINTS.find(([regex]) => regex.test(message));
    if (tab) activeTab.value = tab[1];
}

async function save() {
    saving.value = true;
    error.value = "";
    try {
        const result = await props.api.save(payload());
        emit("saved", result.job);
    } catch (e) {
        showError(e.message);
    } finally {
        saving.value = false;
    }
}

async function test() {
    testing.value = true;
    error.value = "";
    testResult.value = null;
    try {
        testResult.value = await props.api.test(payload());
    } catch (e) {
        showError(e.message);
    } finally {
        testing.value = false;
    }
}

function close() {
    emit("close");
}

function describeTags(tags) {
    return TAG_FIELDS.filter((f) => tags[f.key]).map((f) => `${f.label} « ${tags[f.key]} »`).join(", ");
}

function formatSize(bytes) {
    if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} Mo`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`;
    return `${bytes} o`;
}

const PreviewBox = defineComponent({
    props: { lines: { type: Array, required: true } },
    setup(p) {
        return () => h("div", { class: "sd-preview border rounded p-2 small" }, [
            h("div", { class: "fw-semibold mb-1" }, "Exemple avec la date d'aujourd'hui"),
            ...p.lines.map(([label, value]) => h("div", { class: "text-break" }, [
                h("span", { class: "text-body-secondary" }, `${label} : `),
                h("code", value),
            ])),
        ]);
    },
});

function onKey(event) {
    if (event.key === "Escape") close();
}
onMounted(() => {
    document.addEventListener("keydown", onKey);
    document.body.classList.add("modal-open");
});
onBeforeUnmount(() => {
    document.removeEventListener("keydown", onKey);
    document.body.classList.remove("modal-open");
});
</script>
