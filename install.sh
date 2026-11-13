#!/usr/bin/env bash
# Installation en une commande (installation Docker d'AzuraCast dans /var/azuracast par défaut) :
#
#   curl -fsSL https://raw.githubusercontent.com/DJMic/azuracast-show-downloader/main/install.sh | sudo bash
#
# ou, depuis un clone existant :  bash install.sh
# Variables facultatives : AZURACAST_DIR (défaut /var/azuracast), REPO_URL, BRANCH (défaut main).

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/DJMic/azuracast-show-downloader.git}"
BRANCH="${BRANCH:-main}"
AZURACAST_DIR="${AZURACAST_DIR:-/var/azuracast}"
target="$AZURACAST_DIR/plugins/azuracast-show-downloader"

# Run from a clone: just install it.
here=$(cd "$(dirname "${BASH_SOURCE[0]:-.}")" 2>/dev/null && pwd -P || true)
if [[ -n "$here" && -f "$here/bin/setup.sh" && -f "$here/events.php" ]]; then
    exec bash "$here/bin/setup.sh" "$@"
fi

command -v git >/dev/null || { echo "ERREUR : git est nécessaire (apt install git)." >&2; exit 1; }
[[ -f "$AZURACAST_DIR/docker-compose.yml" ]] ||
    { echo "ERREUR : AzuraCast introuvable dans $AZURACAST_DIR (définissez AZURACAST_DIR)." >&2; exit 1; }

# With "curl | bash", questions are read from the terminal; without one, nothing is asked.
run() {
    if [[ -t 0 ]]; then exec bash "$@"; fi
    if { : < /dev/tty; } 2>/dev/null; then exec bash "$@" < /dev/tty; fi
    exec bash "$@" --yes
}

mkdir -p "$AZURACAST_DIR/plugins"
if [[ -d "$target/.git" ]]; then
    echo "Le plugin est déjà présent : mise à jour."
    run "$target/bin/update.sh" "$@"
fi
git clone --branch "$BRANCH" "$REPO_URL" "$target"
run "$target/bin/setup.sh" "$@"
