<?php
declare(strict_types=1);

// Copier en config/config.php (IGNORÉ PAR GIT), puis adapter les valeurs.
// Le document root doit viser le dossier public/, pas la racine du projet.
return [
    'app' => [
        'name' => 'Refus Insolites',
        'secret_key' => 'REMPLACER_PAR_64_CARACTERES_HEXA_ALEATOIRES',
        // Produire avec : php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'admin_password_hash' => '',
        // Produire avec : php -r "echo password_hash('VOTRE_MOT_DE_PASSE', PASSWORD_DEFAULT), PHP_EOL;"
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'refus_insolite',
        'username' => 'refus_site',
        'password' => 'CHANGER_CE_MOT_DE_PASSE',
    ],
];

