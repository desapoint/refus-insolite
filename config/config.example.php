<?php
declare(strict_types=1);

// Copier dans /home/USER/web/DOMAINE/private/refus-insolite.php (hors depot).
// Le depot complet est copie directement dans /home/USER/web/DOMAINE/public_html/.
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

