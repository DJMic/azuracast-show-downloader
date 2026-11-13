#!/usr/bin/env bash
# Updates the plugin from its GitHub repository, then re-runs the installation.
#
#   cd /var/azuracast/plugins/azuracast-show-downloader
#   bash bin/update.sh            # update to the latest version of the current branch
#   bash bin/update.sh --check    # only tell whether an update is available
#   bash bin/update.sh --yes      # no questions
#
# Station settings are kept: they are stored in each station's folder, not in the plugin.

set -euo pipefail

plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$plugin_dir"

check_only=false
setup_args=()
for arg in "$@"; do
    case "$arg" in
        --check) check_only=true ;;
        -y|--yes) setup_args+=(--yes) ;;
        -h|--help) sed -n '2,10p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "Option inconnue : $arg" >&2; exit 1 ;;
    esac
done

command -v git >/dev/null || { echo "ERREUR : git est nécessaire." >&2; exit 1; }
[[ -d .git ]] || { echo "ERREUR : ce dossier n'est pas un clone git ; réinstallez avec git clone (voir README)." >&2; exit 1; }

branch=$(git rev-parse --abbrev-ref HEAD)
current=$(tr -d '[:space:]' < VERSION)

echo "Version installée : $current (branche $branch)"
echo "Recherche de mises à jour…"
git fetch --quiet --tags origin "$branch"

if [[ $(git rev-parse HEAD) == $(git rev-parse "origin/$branch") ]]; then
    echo "Le plugin est à jour."
    $check_only && exit 0
    read -r -p "Relancer quand même l'installation ? [o/N] " answer || answer=""
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]] || exit 0
    exec bash bin/setup.sh ${setup_args[@]+"${setup_args[@]}"}
fi

latest=$(git show "origin/$branch:VERSION" | tr -d '[:space:]')
echo "Nouvelle version disponible : $latest"
echo
echo "Changements :"
git log --no-merges --format='  - %s' "HEAD..origin/$branch" | head -40
$check_only && exit 0

if [[ -n $(git status --porcelain --untracked-files=no) ]]; then
    echo
    echo "ERREUR : des fichiers du plugin ont été modifiés localement :" >&2
    git status --short --untracked-files=no >&2
    echo "Annulez ces modifications (git checkout -- .) ou sauvegardez-les, puis relancez." >&2
    exit 1
fi

previous=$(git rev-parse HEAD)
git merge --ff-only --quiet "origin/$branch"
echo "Code mis à jour ($current → $latest)."

if ! bash bin/setup.sh ${setup_args[@]+"${setup_args[@]}"}; then
    echo
    echo "L'installation de la nouvelle version a échoué. Pour revenir à la version précédente :" >&2
    echo "  cd $plugin_dir && git reset --hard $previous && bash bin/setup.sh" >&2
    exit 1
fi
