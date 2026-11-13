#!/usr/bin/env bash
# Removes the plugin from AzuraCast (Docker installations).
#
#   cd /var/azuracast/plugins/azuracast-show-downloader
#   bash bin/uninstall.sh [--yes]
#
# The plugin mount is removed from docker-compose.override.yml (a backup is kept) and the
# "web" container is recreated. Downloaded files stay in the media libraries. Each station's
# settings (show-downloader*.json) are kept unless you delete them yourself.

set -euo pipefail

PLUGIN_NAME="azuracast-show-downloader"
plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
azuracast_dir=$(cd "$plugin_dir/../.." && pwd -P)
override="$azuracast_dir/docker-compose.override.yml"
assume_yes=false
[[ "${1:-}" == "--yes" || "${1:-}" == "-y" ]] && assume_yes=true

[[ -f "$override" ]] || { echo "Aucun docker-compose.override.yml : rien à faire."; exit 0; }
cd "$azuracast_dir"

work=$(mktemp -d)
trap 'rm -rf "$work" "$plugin_dir/node_modules"' EXIT
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache -e npm_config_update_notifier=false \
    -v "$plugin_dir:/plugin" -v "$work:/work" -v "$azuracast_dir:/azuracast:ro" -w /plugin node:22-alpine \
    sh -c 'npm ci --no-audit --no-fund --loglevel=error >/dev/null && node bin/compose-override.mjs /azuracast /work/override.yml --remove'

backup="$override.bak-$(date +%Y%m%d-%H%M%S)"
cp -p "$override" "$backup"
cp "$work/override.yml" "$override"
echo "Plugin retiré de docker-compose.override.yml (sauvegarde : $backup)."

if ! $assume_yes; then
    read -r -p "Recréer maintenant le conteneur web (coupure d'environ une minute) ? [o/N] " answer || answer=""
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]] || { echo "À faire plus tard : cd $azuracast_dir && docker compose up -d"; exit 0; }
fi
docker compose up -d
echo "Terminé. Vous pouvez supprimer le dossier $plugin_dir."
