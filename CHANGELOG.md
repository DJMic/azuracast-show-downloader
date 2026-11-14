# Journal des modifications

## 1.1.3 – 2026-11-14

- Compatibilité avec les autres plugins qui ajoutent une entrée sous « Médias » (par exemple
  Sweepers) : les deux scripts déplaçaient leur entrée sans arrêt, ce qui rendait les deux onglets
  inutilisables. L'entrée « Téléchargements auto » se place maintenant après celles des autres plugins.
- Le clic sur l'entrée du menu fonctionne même si le menu est reconstruit.

## 1.1.2 – 2026-11-13

- Stockage local : avant l'import, le plugin vérifie que AzuraCast peut écrire dans le dossier de
  destination et qu'il reste assez d'espace disque, et donne la commande pour corriger les droits.
- Un ancien fichier en lecture seule (copié par root, par exemple) est supprimé avant d'être remplacé
  lorsque le dossier le permet.
- Message explicite à la place de « Unable to copy file ».

## 1.1.1 – 2026-11-13

- FTP : quand le serveur refuse l'envoi d'un fichier (RETR 550) alors qu'il le liste, le
  téléchargement est réessayé avec d'autres façons d'indiquer le chemin et une nouvelle connexion.
- FTP : le message d'erreur cite la réponse exacte du serveur et explique les causes possibles.
- « Tester la connexion » vérifie maintenant que le compte FTP a le droit de télécharger le fichier.
- Le statut « Introuvable » devient « Indisponible » (fichier absent ou refusé par le serveur).

## 1.1.0 – 2026-11-13

- Nouvel onglet « Tags » : modification facultative du titre, de l'artiste, de l'album et du genre
  du fichier téléchargé, avec les variables {date}, {date:FORMAT}, {show}, {original} et {n}.
  Les tags sont écrits dans les fichiers MP3, FLAC et OGG ; pour les autres formats, ils sont
  enregistrés dans AzuraCast uniquement.
- Le test de connexion et l'aperçu affichent les tags qui seront appliqués.

## 1.0.0 – 2026-11-13

Première version.

- Entrée « Téléchargements auto » dans le menu de chaque station.
- Sources FTP/FTPS, SFTP (mot de passe ou clé) et URL.
- Date de diffusion dans le nom du fichier (format et décalage réglables).
- Renommage avec variables, dossier de destination, ajout à une playlist.
- Planification par jours et heures, nouveaux essais, détection d'un fichier inchangé.
- Test de connexion, téléchargement immédiat, historique.
- Scripts d'installation, de mise à jour et de désinstallation.
