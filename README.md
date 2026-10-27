# Refus Insolites

Petit site francophone en **PHP 8.3**, **PDO** et **MariaDB**, basé sur la maquette validée. Aucun framework ni dépendance Composer. Soumission anonyme, consultation avec filtres, réactions, signalement, modération protégée et migrations automatiques.

## Installation

Pré-requis : PHP **8.3+**, extensions **pdo_mysql** et **mbstring**, MariaDB 10.6+, serveur web Apache ou nginx et HTTPS en production.

1. Créer une base de données et un utilisateur dédié dans MariaDB (adapter les noms et le mot de passe) :

   ```sql
   CREATE DATABASE refus_insolite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'refus_site'@'localhost' IDENTIFIED BY 'REMPLACER_PAR_UN_MOT_DE_PASSE_FORT';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
     ON refus_insolite.* TO 'refus_site'@'localhost';
   ```

   Remarque : si PHP se connecte à `127.0.0.1`, adapter l'utilisateur MariaDB à `'refus_site'@'127.0.0.1'` ou utiliser `host => 'localhost'` dans la configuration. MariaDB distingue ces comptes.

2. Copier **sans renommer l'exemple versionné** :

   ```sh
   cp config/config.example.php config/config.php
   ```

   Modifier **uniquement** `config/config.php` (ignoré par Git) : host, nom de la base, utilisateur et mot de passe. Mettre une clé aléatoire de 64 caractères hexadécimaux :

   ```sh
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

   Pour l'accès à la modération, créer une empreinte de mot de passe (PHP lit le mot de passe sur l'entrée standard, sans le mettre dans la ligne de commande) :

   ```sh
   php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
   ```

   Coller l'empreinte dans `app.admin_password_hash`. Le mot de passe en clair ne doit être ni versionné ni mis en exemple. L'espace de modération est fermé tant qu'aucune empreinte valide n'est configurée.

3. **Très important** : configurer la racine web (*DocumentRoot* / `root`) vers `votre-chemin/refus-insolite/public`, et **jamais** vers la racine du dépôt. Config et migrations doivent rester hors du répertoire public.

   Exemple Apache :

   ```apache
   DocumentRoot /var/www/refus-insolite/public
   <Directory /var/www/refus-insolite/public>
       Require all granted
       AllowOverride None
       Options -Indexes
   </Directory>
   ```

   Exemple nginx :

   ```nginx
   root /var/www/refus-insolite/public;
   index index.php;
   location / { try_files $uri $uri/ /index.php?$query_string; }
   location ~ \.php$ {
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
       fastcgi_pass unix:/run/php/php8.3-fpm.sock;
   }
   ```

   Ces extraits ne remplacent pas une configuration HTTPS/TLS complète. N'exposez jamais un autre répertoire du projet avec un alias web.

4. Ouvrir le site. **Les migrations s'exécutent automatiquement**, sans action manuelle, lors de la première requête et au besoin après une mise à jour. Le compte MariaDB doit disposer des droits DDL indiqués ci-dessus. La base elle-même doit être créée une fois avant le premier chargement.

## Pages

- `index.php?p=home` — Accueil, derniers témoignages publiés
- `index.php?p=explorer` — Recherche, filtres, tri, pagination
- `index.php?p=refus&id=123` — Détail, réaction unique par cookie, signalement
- `index.php?p=soumettre` — Formulaire anonyme, validation et aperçu
- `index.php?p=moderation` — Connexion, approbation/rejet, signalements

Les contenus sont **enregistrés en attente** et ne sont jamais publics avant approbation. Il n'y a aucune donnée d'exemple insérée automatiquement (l'illustration de la page d'accueil est explicitement fictive).

## Migrations automatiques

Les fichiers `migrations/NNN_nom.php` sont chargés dans l'ordre. Chaque fichier retourne une fonction `(PDO $pdo): void`. Les versions réussies sont enregistrées dans `schema_migrations`. Un verrou MariaDB `GET_LOCK()` empêche deux requêtes de migrer en même temps. Les migrations contenant du DDL doivent être **idempotentes**, car le DDL MariaDB déclenche un *commit* implicite et ne peut pas toujours être annulé après une panne. Ne **modifiez pas** les migrations déjà déployées ; ajoutez une nouvelle version.

## Sécurité et exploitation

- Aucun secret dans Git : `config/config.php` et fichiers `.env` sont ignorés ; vérifier `git status` avant un push. Attention : `.gitignore` ne supprime pas un secret déjà commité.
- Requêtes préparées PDO sans émulation, encodage `utf8mb4`, échappement HTML, listes blanches de filtres et actions, validation serveur et protection CSRF.
- Connexion administrateur par mot de passe haché, renouvellement de session, expiration après 2 heures d'inactivité, limitation des tentatives par adresse IP **hachée** ; pas de compte public.
- Soumissions, réactions et signalements limités par adresse IP hachée avec HMAC. Le site ne conserve ni adresse e-mail des auteurs ni adresse IP en clair dans la base.
- Cookies `HttpOnly` et `SameSite=Lax`, `Secure` en HTTPS ; en production, **forcer HTTPS** au niveau du serveur web. Ajouter des sauvegardes régulières MariaDB.
- La modération humaine est indispensable : les filtres automatiques ne peuvent pas garantir qu'un texte ne permet pas d'identifier quelqu'un. Demander de ne pas inclure noms, employeurs, liens, captures, téléphone ou identifiants.
- Garder les journaux PHP non publics. Installer les mises à jour de sécurité de PHP et MariaDB.
- La table `rate_limits` peut être purgée pour les entrées âgées de plus de deux jours (voir section maintenance).

Maintenance facultative : `DELETE FROM rate_limits WHERE created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY;` via un événement planifié MariaDB ou une tâche cron DB sécurisée.

## Vérification locale

```sh
find . -name '*.php' -print -exec php -l {} \;
```

L'analyse syntaxique seule ne remplace pas un essai complet avec une vraie instance MariaDB. Tester la création de la base, la migration automatique, une soumission, l'approbation, la recherche et un signalement sur un environnement de test avant de publier le domaine.

