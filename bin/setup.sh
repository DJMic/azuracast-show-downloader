#!/usr/bin/env bash
# Installs or updates the AzuraCast Show Downloader plugin (Docker installations).
#
#   cd /var/azuracast/plugins/azuracast-show-downloader
#   bash bin/setup.sh          # asks before restarting AzuraCast
#   bash bin/setup.sh --yes    # no questions (for automation)
#
# What it does:
#   1. builds the web interface (in a temporary Node.js container, nothing is installed on the host);
#   2. adds the plugin to docker-compose.override.yml (a backup of the previous file is kept);
#   3. restarts the "web" container if needed, otherwise just reloads PHP.
# Station configurations (show-downloader*.json in each station's config folder) are never modified.

set -euo pipefail

PLUGIN_NAME="azuracast-show-downloader"
NODE_IMAGE="node:22-alpine"

plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
azuracast_dir=$(cd "$plugin_dir/../.." && pwd -P)
override="$azuracast_dir/docker-compose.override.yml"
assume_yes=false

for arg in "$@"; do
    case "$arg" in
        -y|--yes) assume_yes=true ;;
        -h|--help) sed -n '2,13p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "Option inconnue : $arg (utilisez --yes ou --help)" >&2; exit 1 ;;
    esac
done

bold=$(tput bold 2>/dev/null || true); normal=$(tput sgr0 2>/dev/null || true)
step() { printf '\n%s==> %s%s\n' "$bold" "$*" "$normal"; }
fail() { printf '\nERREUR : %s\n' "$*" >&2; exit 1; }
ask() {
    $assume_yes && return 0
    [[ -t 0 ]] || fail "Confirmation nécessaire. Relancez avec --yes pour ne pas poser de question."
    local answer
    read -r -p "$1 [o/N] " answer || answer=""
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]]
}

version=$(tr -d '[:space:]' < "$plugin_dir/VERSION")
step "AzuraCast Show Downloader $version"

# --- Checks -------------------------------------------------------------------
[[ "$plugin_dir" == "$azuracast_dir/plugins/$PLUGIN_NAME" ]] ||
    fail "Le plugin doit se trouver dans <dossier AzuraCast>/plugins/$PLUGIN_NAME (actuellement : $plugin_dir)."
[[ -f "$azuracast_dir/docker-compose.yml" ]] ||
    fail "docker-compose.yml introuvable dans $azuracast_dir. Ce script est prévu pour une installation Docker d'AzuraCast."
command -v docker >/dev/null || fail "Docker est nécessaire."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 (« docker compose ») est nécessaire."
[[ -w "$azuracast_dir" ]] || fail "Droits insuffisants sur $azuracast_dir : relancez avec sudo."
[[ ! -L "$override" ]] || fail "docker-compose.override.yml est un lien symbolique : modifiez-le à la main (voir README)."
[[ ! -e "$azuracast_dir/docker-compose.override.yaml" ]] ||
    fail "Un fichier docker-compose.override.yaml existe : fusionnez-le dans docker-compose.override.yml d'abord."

cd "$azuracast_dir"
docker compose config --services 2>/dev/null | grep -Fxq web ||
    fail "Service « web » introuvable dans la configuration Docker Compose d'AzuraCast."

work=$(mktemp -d "$azuracast_dir/.show-downloader-setup.XXXXXX")
cleanup() { rm -rf "$work" "$plugin_dir/node_modules"; }
trap cleanup EXIT

# --- 1. Build the interface ---------------------------------------------------
step "Compilation de l'interface (conteneur $NODE_IMAGE temporaire)…"
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache -e npm_config_update_notifier=false \
    -v "$plugin_dir:/plugin" -v "$work:/work" -v "$azuracast_dir:/azuracast:ro" -w /plugin "$NODE_IMAGE" \
    sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run --silent build \
        && node bin/compose-override.mjs /azuracast /work/docker-compose.override.yml'
[[ -s "$plugin_dir/dist/app.js" ]] || fail "La compilation de l'interface a échoué."

# --- 2. Docker Compose override ------------------------------------------------
step "Vérification de docker-compose.override.yml…"
docker compose -f docker-compose.yml -f "$work/docker-compose.override.yml" config --quiet ||
    fail "La configuration générée est invalide ; aucun fichier n'a été modifié."

if [[ ! -f "$override" ]] || ! cmp -s "$override" "$work/docker-compose.override.yml"; then
    if [[ -f "$override" ]]; then
        backup="$override.bak-$(date +%Y%m%d-%H%M%S)"
        cp -p "$override" "$backup"
        echo "Sauvegarde de l'ancien fichier : $backup"
    fi
    cp "$work/docker-compose.override.yml" "$override"
    echo "docker-compose.override.yml mis à jour."
else
    echo "Déjà à jour."
fi

# --- 3. Apply -------------------------------------------------------------------
container=$(docker compose ps -q web 2>/dev/null || true)
needs_recreate=true
if [[ -n "$container" ]] && [[ $(docker inspect -f '{{.State.Running}}' "$container") == true ]]; then
    mounts=$(docker inspect -f '{{range .Mounts}}{{println .Destination}}{{end}}' "$container")
    envs=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$container")
    if grep -Fxq "/var/azuracast/www/plugins/$PLUGIN_NAME" <<< "$mounts" &&
        grep -Fxq "AZURACAST_PLUGIN_MODE=true" <<< "$envs" &&
        grep -Fxq "COMPOSER_PLUGIN_MODE=true" <<< "$envs"; then
        needs_recreate=false
    fi
fi

if $needs_recreate; then
    step "Activation du plugin"
    echo "Le conteneur « web » d'AzuraCast doit être recréé pour charger le plugin."
    echo "ATTENTION : la diffusion de toutes les stations sera interrompue pendant environ une minute."
    if ! ask "Redémarrer AzuraCast maintenant ?"; then
        echo
        echo "Rien n'a été redémarré. Quand vous serez prêt, lancez :"
        echo "  cd $azuracast_dir && docker compose up -d"
        exit 0
    fi
    docker compose up -d
    echo "Attente du démarrage d'AzuraCast (jusqu'à 10 minutes)…"
    ready=false
    for _ in $(seq 1 120); do
        if docker compose exec -T web supervisorctl status php-fpm 2>/dev/null | grep -q RUNNING; then
            ready=true
            break
        fi
        sleep 5
    done
    $ready || fail "AzuraCast n'a pas redémarré à temps. Consultez : docker compose logs web"
else
    step "Rechargement de PHP (sans interruption de la diffusion)…"
    docker compose exec -T web supervisorctl signal USR2 php-fpm >/dev/null
    docker compose exec -T web supervisorctl restart php-worker-fast php-worker-slow >/dev/null 2>&1 || true
fi
docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php || true

step "Terminé : Show Downloader $version est installé."
echo "Ouvrez une station dans AzuraCast : l'entrée « Téléchargements auto » se trouve sous « Médias »."
echo "(Rechargez la page du navigateur si elle était déjà ouverte.)"
