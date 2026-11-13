# AzuraCast Show Downloader

Plugin AzuraCast qui télécharge automatiquement vos émissions depuis un serveur **FTP/FTPS**, **SFTP** ou une **adresse web (URL)**, aux jours et heures que vous choisissez, et les range dans la médiathèque de la station.

Chaque station reçoit une nouvelle entrée **« Téléchargements auto »** dans son menu, juste sous **Médias**. La page reprend le design d'AzuraCast, en thème clair comme en sombre.

## Fonctions

- **Plusieurs émissions par station**, chacune avec sa propre source, ses horaires et sa destination.
- **Sources :**
  - FTP, FTPS explicite ou implicite, en mode passif ou actif ;
  - SFTP, par mot de passe ou par clé privée SSH ;
  - URL http/https, avec authentification facultative.
- **Choix du fichier :**
  - un nom exact ou un modèle (`*.mp3`, `matinale_*.mp3`) ;
  - le fichier le plus récent, ou tous les fichiers correspondants.
- **Date dans le nom du fichier.** Une case à cocher active ce mode. On choisit le format de la date (`YYYYMMDD`, `DD-MM-YYYY`…) et la date recherchée (le jour même, la veille, etc.).
- **Renommage facultatif.** On peut donner un nom fixe (par exemple `Matinale.mp3`, remplacé à chaque diffusion) ou un nom avec date (`Matinale {date:DD-MM-YYYY}`).
- **Destination.** Le fichier va dans un dossier de la médiathèque, avec un explorateur de dossiers. Il peut aussi être ajouté automatiquement à une playlist.
- **Fichier déjà présent :** il peut être remplacé, gardé en double ou ignoré.
- **Planification :** plusieurs horaires par émission, dans le fuseau horaire de la station.
- **Nouveaux essais.** Si le fichier n'est pas encore en ligne, ou s'il est identique au précédent, le plugin réessaie toutes les X minutes.
- **Tester la connexion.** Ce bouton montre les fichiers trouvés et le nom final, sans rien télécharger.
- **Télécharger maintenant**, **Dupliquer**, **Historique**.

## Prérequis

- AzuraCast installé avec **Docker**, dans `/var/azuracast` par défaut. Une version récente est requise : le plugin a été écrit pour le code actuel d'AzuraCast (PHP 8.4 et plus).
- `git` sur le serveur.
- Un accès `root` ou `sudo`.

Le serveur n'a pas besoin de Node.js. L'interface est compilée dans un conteneur Docker temporaire.

## Installation

```bash
sudo mkdir -p /var/azuracast/plugins
sudo git clone https://github.com/DJMic/azuracast-show-downloader.git \
    /var/azuracast/plugins/azuracast-show-downloader
cd /var/azuracast/plugins/azuracast-show-downloader
sudo bash install.sh
```

Le script s'occupe de tout :

1. il compile l'interface ;
2. il ajoute le plugin à `docker-compose.override.yml`, en gardant une copie de sauvegarde de l'ancien fichier ;
3. il active le « mode plugin » d'AzuraCast.

La première installation recrée le conteneur `web`, ce qui **coupe la diffusion environ une minute**. Le script demande donc confirmation avant de le faire. Avec `--yes`, il ne pose aucune question.

Installation en une seule commande, une fois le dépôt publié :

```bash
curl -fsSL https://raw.githubusercontent.com/DJMic/azuracast-show-downloader/main/install.sh | sudo bash
```

## Mise à jour

```bash
cd /var/azuracast/plugins/azuracast-show-downloader
sudo bash bin/update.sh            # télécharge et installe la dernière version
sudo bash bin/update.sh --check    # indique seulement si une mise à jour existe
```

Une mise à jour ne coupe pas la diffusion : seul PHP est rechargé. Les réglages des émissions sont conservés, car ils sont enregistrés dans le dossier de chaque station et non dans le plugin.

Le programme de mise à jour d'AzuraCast (`./docker.sh update`) ne met pas ce plugin à jour, mais le garde en place.

## Utilisation

Ouvrez une station, puis **Téléchargements auto** et **Ajouter une émission**. Il faut l'autorisation « Gérer les médias » de la station. La fenêtre comporte cinq onglets :

| Onglet | Contenu |
| --- | --- |
| Émission | Nom, activation |
| Source | FTP/FTPS, SFTP ou URL ; hôte, port, utilisateur, mot de passe ou clé, répertoire distant |
| Fichier | Nom ou modèle du fichier distant, date dans le nom |
| Destination | Dossier de la médiathèque, renommage, fichier existant, playlist |
| Planification | Jours et heures, nouveaux essais |

Un aperçu montre en direct le fichier recherché et le nom sous lequel il sera enregistré. Utilisez **Tester la connexion** avant d'enregistrer.

Les mots de passe enregistrés ne sont jamais renvoyés au navigateur. Laissez le champ vide pour garder le mot de passe actuel.

### Formats de date

| Code | Signification | Exemple pour le 5 mars 2026 |
| --- | --- | --- |
| `YYYY` | année | 2026 |
| `YY` | année sur 2 chiffres | 26 |
| `MM` | mois sur 2 chiffres | 03 |
| `M` | mois | 3 |
| `DD` | jour sur 2 chiffres | 05 |
| `D` | jour | 5 |

Les autres caractères sont recopiés tels quels. Par exemple, `DD-MM-YYYY` donne `05-03-2026`.

### Variables du nom de fichier

| Variable | Où | Valeur |
| --- | --- | --- |
| `{date}` | nom distant, URL, nouveau nom | date de diffusion au format choisi |
| `{date:FORMAT}` | partout | date dans un autre format, par ex. `{date:DD.MM.YYYY}` |
| `{original}` | nouveau nom | nom d'origine sans extension |
| `{ext}` | nouveau nom | extension d'origine (`mp3`…) |
| `{show}` | nouveau nom | nom de l'émission |
| `{n}` | nouveau nom | numéro du fichier quand plusieurs sont téléchargés |

Si le nouveau nom n'a pas d'extension, l'extension d'origine est ajoutée.

### Exemples

**Émission quotidienne sur FTP, datée de la veille.** Le producteur dépose chaque nuit `matinale_260304.mp3`. Vous voulez la diffuser sous un nom fixe.

- Source : FTP, `ftp.producteur.be`, répertoire `/export`.
- Fichier : `matinale_{date}.mp3`. Cochez « La date figure dans le nom », format `YYMMDD`, date recherchée « La veille ».
- Destination : `Emissions/Matinale`, renommer en `Matinale`, remplacer le fichier existant, playlist « Matinale ».
- Planification : du lundi au vendredi à 05:30, 8 nouveaux essais toutes les 15 minutes.

**Podcast à adresse fixe.** L'adresse `https://exemple.org/hebdo/last.mp3` change de contenu chaque semaine.

- Source : URL.
- Destination : renommer en `Hebdo {date:YYYY-MM-DD}` pour garder un fichier par semaine.
- Planification : le samedi à 08:00. Cochez « Considérer un fichier identique au précédent comme pas encore disponible » : le plugin attendra la nouvelle version au lieu de réimporter l'ancienne.

## Fonctionnement et dépannage

- **Contrôle chaque minute.** Le plugin s'appuie sur la synchronisation d'AzuraCast, la même que pour les autres tâches automatiques. Un téléchargement démarre donc au plus tard une minute après l'heure prévue.
- **Pas de rattrapage.** Un horaire est ignoré si son premier essai n'a pas pu démarrer dans l'heure (serveur arrêté…), ou dans le délai des nouveaux essais si ce délai est plus long. L'historique l'indique comme « Horaire manqué ». Une émission créée, réactivée ou replanifiée ne rattrape pas non plus les horaires déjà passés : utilisez « Télécharger maintenant ».
- **Import.** Le fichier est d'abord téléchargé dans le dossier temporaire de la station. Il est ensuite importé comme un envoi manuel : lecture des métadonnées, puis ajout à la playlist.
- **Erreurs détaillées :** journaux système d'AzuraCast (fichier `app.log`).
- **En ligne de commande**, pour lister les émissions d'une station ou en lancer une tout de suite :

  ```bash
  cd /var/azuracast
  docker compose exec web azuracast_cli show-downloader:run <id_station>
  docker compose exec web azuracast_cli show-downloader:run <id_station> <id_émission>
  ```

- **L'entrée n'apparaît pas dans le menu ?** Rechargez la page avec Ctrl+F5. Vérifiez ensuite que `docker-compose.override.yml` contient le volume du plugin, puis relancez `sudo bash bin/setup.sh`.

### Données enregistrées

Le plugin écrit trois fichiers dans le dossier de configuration de chaque station (`/var/azuracast/stations/<station>/config/` dans le conteneur) :

| Fichier | Contenu |
| --- | --- |
| `show-downloader.json` | Émissions et identifiants de connexion. Lisible uniquement par AzuraCast (droits 0600). |
| `show-downloader-state.json` | État de la planification. |
| `show-downloader-history.json` | Les 300 derniers résultats. |

Les mots de passe sont stockés en clair dans ce fichier protégé. Utilisez de préférence un compte FTP/SFTP en **lecture seule** dédié à la radio.

## Désinstallation

```bash
cd /var/azuracast/plugins/azuracast-show-downloader
sudo bash bin/uninstall.sh
sudo rm -rf /var/azuracast/plugins/azuracast-show-downloader
```

Les fichiers déjà téléchargés restent dans la médiathèque.

## Développement

```text
events.php               point d'entrée du plugin (chargé par AzuraCast)
src/EventHandler/        routes, tâche planifiée, commande console
src/Service/             planification, noms de fichiers, exécution, stockage
src/Source/              clients FTP (cURL), SFTP (phpseclib), HTTP (cURL)
src/Sync/DownloadTask    tâche lancée chaque minute par AzuraCast
frontend/                interface Vue 3 (compilée dans dist/ par Vite) et navigation.js
bin/                     installation, mise à jour, désinstallation
tests/                   tests sans AzuraCast : php tests/run.php et npm test
```

Pour publier une nouvelle version :

1. mettez à jour `VERSION` et `CHANGELOG.md` ;
2. faites un commit sur `main`, puis ajoutez une étiquette avec `git tag v1.1.0 && git push --tags`.

Les serveurs se mettent à jour avec `bin/update.sh`.

## Remerciements

L'intégration dans le menu et le routeur d'AzuraCast s'inspire de la méthode du plugin [azuracast-sweepers](https://github.com/RM-FM/azuracast-sweepers) de RM-FM.

## Licence

MIT
