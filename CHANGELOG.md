# Journal des modifications

## 1.2.1 – 2026-11-16

- Correction : avec d'autres plugins présents dans le menu sous « Médias » (Animateurs virtuels, Sweepers…), les pages des plugins restaient vides dans Chrome. Les entrées de menu s'échangeaient leur place sans arrêt, ce qui bloquait la page. Une entrée déjà placée parmi celles des plugins n'est plus jamais déplacée, et son état « actif » est mis à jour sans la recréer.

## 1.2.0 – 2026-11-14

- Nouveaux boutons « Exporter » et « Importer » : copie des émissions d'une station vers une autre
  station ou une autre instance AzuraCast, via un fichier JSON.
- Export avec ou sans mots de passe et clés SSH. Les playlists sont exportées par leur nom.
- Import avec aperçu : choix des émissions, du dossier de destination (vérifié sur la station,
  remplacement du début du chemin pour toutes les émissions), de la playlist (retrouvée par son nom)
  et de ce qu'il faut faire si une émission du même nom existe déjà (remplacer ou ajouter une copie).
- Les dossiers absents peuvent être créés à l'import ; sinon ils le sont au premier téléchargement.
- Une émission importée sans son mot de passe est désactivée jusqu'à ce qu'il soit saisi ; une
  émission remplacée garde le mot de passe déjà enregistré.
- Avertissement si le fuseau horaire de la station d'origine est différent.

## 1.1.4 – 2026-11-14

- Correction de l'erreur « SAVEPOINT DOCTRINE_2 does not exist » lors de certains téléchargements.
  La tâche planifiée gardait une transaction de base de données ouverte pendant toute la durée du
  téléchargement ; MariaDB pouvait l'annuler entre-temps. Les stations sont maintenant parcourues
  sans transaction et la connexion est vérifiée après chaque téléchargement.
- En cas d'erreur temporaire de base de données (verrou, connexion perdue), l'enregistrement du
  fichier, des tags et de l'ajout à la playlist est retenté une fois.
- Les messages d'erreur de base de données indiquent aussi leur cause réelle.

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
