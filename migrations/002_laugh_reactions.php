<?php
declare(strict_types=1);

// Nouvelle reaction indépendante : rire. Migration idempotente pour HestiaCP.
return static function (PDO $pdo): void {
    $column = $pdo->query("SHOW COLUMNS FROM refus LIKE 'laughs_count'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE refus ADD COLUMN laughs_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER likes_count");
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS laugh_reactions (
  refus_id BIGINT UNSIGNED NOT NULL,
  visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (refus_id, visitor_hash),
  CONSTRAINT fk_laugh_refus FOREIGN KEY (refus_id) REFERENCES refus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
