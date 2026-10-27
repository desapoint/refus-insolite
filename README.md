# Refus Insolites

Petit site francophone en **PHP 8.3**, **PDO** et **MariaDB**, basé sur la maquette validée. Aucun framework ni dépendance Composer. Soumission anonyme, consultation avec filtres, réactions, signalement, modération protégée et migrations automatiques.

## Installation HestiaCP avec votre script de déploiement actuel

**Aucun changement de script ou de DocumentRoot n'est nécessaire.** Votre mécanisme copie déjà `web/private/<sous-domaine>/` dans le `public_html` du domaine correspondant : le dépôt entier est directement compatible. `index.php` à la racine devient le point d’entrée ; ne déplacez pas le contenu de `public/`.

Pré-requis : **PHP 8.3**, extensions `pdo_mysql` et `mbstring`, **MariaDB 10.6+**, HTTPS et **Apache 2.4 avec mod_rewrite et AllowOverride All** (éventuellement Nginx comme proxy devant Apache). Le **mode Nginx seul** n'est pas pris en charge car il ignore les règles `.htaccess`.

### Dossiers et secrets

Exemple pour `refus.desapoint.ca`, avec un clone dans `web/private/refus/` :

```text
/home/USER/web/private/
  deploy.sh                         # Votre script existant, inchangé
  refus/                            # Clone Git contenant index.php et .htaccess

/home/USER/web/refus.desapoint.ca/
  private/
    refus-insolite.php              # Configuration réelle ; PAS dans le dépôt
  public_html/
    .htaccess                       # Copie depuis le clone
    index.php                       # Copie depuis le clone
    config/config.example.php      # Exemple sans identifiants réels
    src/ migrations/ public/ tests/
```

Il est essentiel que le déploiement copie **aussi les fichiers cachés** (en particulier `.htaccess` ; `rsync -a` les conserve). Un script qui exclut les dotfiles ne peut pas sécuriser ce déploiement via Apache.

Créer d'abord dans HestiaCP une base MariaDB et un utilisateur dédié. Alternative SQL pour l'administrateur MariaDB (adapter les noms, potentiellement préfixés par HestiaCP) :

```sql
CREATE DATABASE refus_insolite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'refus_site'@'localhost' IDENTIFIED BY 'REMPLACER_PAR_UN_MOT_DE_PASSE_FORT';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON refus_insolite.* TO 'refus_site'@'localhost';
```

Le compte MariaDB peut différer selon que PHP se connecte par `localhost` ou `127.0.0.1`. Utiliser les coordonnées réellement créées.

Ensuite, copier **une seule fois** le modèle de configuration vers le dossier `private/` du **domaine déployé** (et non `web/private/` où se trouvent les dépôts). Exemple :

```sh
cp /home/USER/web/private/refus/config/config.example.php \
   /home/USER/web/refus.desapoint.ca/private/refus-insolite.php
chmod 600 /home/USER/web/refus.desapoint.ca/private/refus-insolite.php
```

Dans ce fichier privé, renseigner les identifiants MariaDB, la clé `app.secret_key` et l'empreinte `app.admin_password_hash` pour la modération. Générer les deux valeurs avec :

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;'
```

Depuis `public_html/src/app.php`, l'application résout automatiquement `dirname(__DIR__, 2) . '/private/refus-insolite.php'`, soit `/home/USER/web/DOMAINE/private/refus-insolite.php` : c'est l'emplacement privé prévu par HestiaCP et accessible depuis son PHP-FPM habituel.

**Ne créez pas de vrai `config/config.php` dans le dépôt.** Votre script ne synchronisant que le contenu vers `public_html`, il ne touche pas au fichier `private/refus-insolite.php` du domaine.

Exécuter votre script de déploiement normalement. **Les migrations s'appliquent automatiquement** lors de la première requête et lorsqu'une nouvelle version apparaît ; aucune modification au script n'est requise. La base MariaDB doit déjà exister et l'utilisateur doit avoir les droits DDL ci-dessus.

Vérification HTTP **après** le déploiement :

```sh
bash tests/check-deployment.sh https://refus.desapoint.ca
```

Les pages et CSS/JS doivent répondre **200**, les sources et migrations **403 ou 404**. Si la vérification échoue, ne mettez pas le site en service. Un HTTP **503** peut indiquer un problème de configuration ou de connexion PDO. Les règles `.htaccess` ne protègent pas des fichiers statiques que Nginx sert directement ; le mode Nginx seul nécessite une configuration serveur supplémentaire.

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

- Aucun secret dans Git : le fichier réel est `private/refus-insolite.php` à côté du `public_html` du domaine. Il n'est ni cloné ni publié. `.gitignore` ne supprime pas un secret déjà commité.
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

