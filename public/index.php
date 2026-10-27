<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

header_remove('X-Powered-By');
if (PHP_VERSION_ID < 80300) fail_public(503, 'Le site est momentanément indisponible. Réessayez plus tard.');
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; img-src 'self' data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'");
header('Cache-Control: private, no-store');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) fail_public(413, 'Le formulaire est trop volumineux.');
session_start_secure();
$config = config_load();
try {
    $db = db_open($config);
    migrate($db, $config['database']['name']);
} catch (Throwable $exception) {
    error_log('[Refus Insolites] Initialisation : ' . $exception->getMessage());
    fail_public(503, 'Service temporairement indisponible. Réessayez plus tard.');
}
$requested = $_GET['p'] ?? 'home';
$page = is_string($requested) && in_array($requested, ['home','explorer','refus','soumettre','merci','moderation'], true) ? $requested : 'home';
$formError = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        switch ($action) {
            case 'submit':
                $page = 'soumettre';
                $old = $_POST;
                if (!empty($_POST['website'])) { // piège à robots, réponse volontairement neutre
                    if (submit_async_requested()) submit_reply(201, true, 'Votre histoire a bien été reçue et attend sa relecture.');
                    redirect_to('merci');
                }
                $data = validated_story($_POST);
                if (!admin_logged() && !allow_action($db, $config, 'submit', 3, 60)) {
                    if (submit_async_requested()) submit_reply(429, false, 'Notre boîte à refus est pleine pour le moment. Réessayez dans une heure.');
                    throw new DomainException('Trop de soumissions. Réessayez dans une heure.');
                }
                $insert = $db->prepare("INSERT INTO refus (title, quote_text, context_text, category, sector, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                $insert->execute(array_values($data));
                if (submit_async_requested()) submit_reply(201, true, 'AHAHA, c’est dans la boîte ! Votre pépite attend sa validation avant publication.');
                redirect_to('merci');
            case 'login':
                $page = 'moderation';
                if (!admin_ready($config)) throw new DomainException('Le mot de passe administrateur n’a pas encore été configuré.');
                if (!allow_action($db, $config, 'login', 10, 15)) throw new DomainException('Trop de tentatives. Réessayez plus tard.');
                $password = $_POST['password'] ?? '';
                if (!is_string($password) || !password_verify($password, $config['app']['admin_password_hash'])) throw new DomainException('Mot de passe incorrect.');
                session_regenerate_id(true);
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_last'] = time();
                redirect_to('moderation');
            case 'logout':
                unset($_SESSION['admin_authenticated'], $_SESSION['admin_last']);
                session_regenerate_id(true);
                redirect_to('home');
            case 'delete_story':
                if (!admin_logged()) fail_public(403, 'Connexion requise.');
                if (($_POST['confirmation'] ?? '') !== 'SUPPRIMER') fail_public(400, 'Veuillez confirmer la suppression.');
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!$id) fail_public(400, 'Témoignage invalide.');
                // Les réactions et signalements associés sont effacés par les clés étrangères.
                $delete = $db->prepare('DELETE FROM refus WHERE id = ?');
                $delete->execute([$id]);
                flash($delete->rowCount() ? 'Témoignage définitivement supprimé.' : 'Témoignage déjà supprimé.', $delete->rowCount() > 0);
                redirect_to('moderation');
            case 'moderate':
                if (!admin_logged()) fail_public(403, 'Vous devez vous connecter pour accéder à la modération.');
                $decision = $_POST['decision'] ?? '';
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!$id || !in_array($decision, ['approve', 'reject'], true)) fail_public(400, 'Action invalide.');
                $status = $decision === 'approve' ? 'approved' : 'rejected';
                $s = $db->prepare("UPDATE refus SET status = ?, reviewed_at = UTC_TIMESTAMP(), published_at = CASE WHEN ? = 'approved' THEN UTC_TIMESTAMP() ELSE NULL END WHERE id = ? AND status = 'pending'");
                $s->execute([$status, $status, $id]);
                flash($s->rowCount() ? 'Témoignage traité.' : 'Ce témoignage a déjà été traité.', $s->rowCount() > 0);
                redirect_to('moderation');
            case 'review_report':
                if (!admin_logged()) fail_public(403, 'Connexion requise.');
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                $decision = $_POST['decision'] ?? '';
                if (!$id || !in_array($decision, ['dismiss','hide'], true)) fail_public(400, 'Action invalide.');
                $db->beginTransaction();
                $s = $db->prepare("SELECT refus_id FROM reports WHERE id = ? AND status = 'pending' FOR UPDATE");
                $s->execute([$id]); $storyId = $s->fetchColumn();
                if ($storyId !== false) {
                    if ($decision === 'hide') {
                        $s = $db->prepare("UPDATE refus SET status = 'rejected', reviewed_at = UTC_TIMESTAMP() WHERE id = ?");
                        $s->execute([$storyId]);
                    }
                    $s = $db->prepare("UPDATE reports SET status = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?");
                    $s->execute([$decision === 'hide' ? 'actioned' : 'dismissed', $id]);
                }
                $db->commit();
                flash('Signalement traité.');
                redirect_to('moderation');
            case 'react':
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!$id) fail_public(400, 'Témoignage invalide.');
                $kind = $_POST['kind'] ?? 'relate';
                if (!is_string($kind) || !in_array($kind, ['relate', 'laugh'], true)) fail_public(400, 'Réaction invalide.');
                $cookie = $_COOKIE['ri_visitor'] ?? '';
                if (!is_string($cookie) || !preg_match('/\\A[a-f0-9]{64}\\z/D', $cookie)) {
                    $cookie = bin2hex(random_bytes(32));
                    setcookie('ri_visitor', $cookie, ['expires'=>time()+31536000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','httponly'=>true,'samesite'=>'Lax']);
                }
                $visitor = hash_hmac('sha256', $cookie, $config['app']['secret_key']);
                if (!allow_action($db, $config, 'react', 60, 60)) throw new DomainException('Trop de réactions. Réessayez plus tard.');
                $result = record_reaction($db, (int)$id, $visitor, $kind);
                if (reaction_async_requested()) reaction_reply(200, $result);
                redirect_to('refus', ['id'=>$id]);
            case 'report':
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                $reason = $_POST['reason'] ?? '';
                if (!$id || !is_string($reason) || !in_array($reason, ['privacy','harassment','false','other'], true)) fail_public(400, 'Signalement invalide.');
                if (!allow_action($db, $config, 'report', 5, 1440)) throw new DomainException('Trop de signalements. Réessayez demain.');
                $s = $db->prepare("INSERT INTO reports (refus_id, reason) SELECT id, ? FROM refus WHERE id = ? AND status = 'approved'");
                $s->execute([$reason, $id]);
                flash('Signalement transmis à la modération.');
                redirect_to('refus', ['id'=>$id]);
            default:
                fail_public(400, 'Action inconnue.');
        }
    } catch (DomainException $exception) {
        if (submit_async_requested()) submit_reply(422, false, $exception->getMessage());
        if (reaction_async_requested()) reaction_reply(422, ['ok' => false, 'message' => $exception->getMessage()]);
        $formError = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[Refus Insolites] Requête : ' . $exception->getMessage());
        fail_public(500, 'Oups, la boîte à refus a trébuché. Réessayez plus tard.');
    }
}

$flash = take_flash();
$filters = [
    'q' => trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''),
    'category' => is_string($_GET['category'] ?? null) ? $_GET['category'] : 'all',
    'sector' => is_string($_GET['sector'] ?? null) ? $_GET['sector'] : 'all',
    'sort' => is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'recent',
];
$filters['q'] = mb_substr($filters['q'], 0, 100, 'UTF-8');
$catalogue = []; $count = 0; $currentPage = 1;
if ($page === 'explorer') {
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 1;
    [$catalogue, $count, $currentPage] = query_stories($db, $filters, $requestedPage);
}
if ($page === 'home') {
    $s = $db->query("SELECT * FROM refus WHERE status='approved' ORDER BY published_at DESC, id DESC LIMIT 3");
    $featured = $s->fetchAll();
}
if ($page === 'refus') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $s = $db->prepare("SELECT * FROM refus WHERE id=? AND status='approved'"); $s->execute([$id ?: 0]);
    $story = $s->fetch();
    if (!$story) { http_response_code(404); $page = 'notfound'; }
    else {
        $visitorCookie = $_COOKIE['ri_visitor'] ?? '';
        $reacted = false;
        $laughed = false;
        if (is_string($visitorCookie) && preg_match('/\A[a-f0-9]{64}\z/D', $visitorCookie)) {
            $visitorHash = hash_hmac('sha256', $visitorCookie, $config['app']['secret_key']);
            $s = $db->prepare('SELECT 1 FROM reactions WHERE refus_id=? AND visitor_hash=?');
            $s->execute([$id, $visitorHash]);
            $reacted = $s->fetchColumn() !== false;
            $s = $db->prepare('SELECT 1 FROM laugh_reactions WHERE refus_id=? AND visitor_hash=?');
            $s->execute([$id, $visitorHash]);
            $laughed = $s->fetchColumn() !== false;
        }
    }
}
if ($page === 'moderation' && admin_logged()) {
    $stats = [];
    foreach ($db->query('SELECT status, COUNT(*) AS total FROM refus GROUP BY status') as $row) $stats[$row['status']] = (int)$row['total'];
    $adminStatus = is_string($_GET['statut'] ?? null) && in_array($_GET['statut'], ['all','pending','approved','rejected'], true) ? $_GET['statut'] : 'all';
    $adminPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 1;
    $whereStatus = $adminStatus === 'all' ? '1=1' : 'status = :status';
    $countQuery = $db->prepare('SELECT COUNT(*) FROM refus WHERE ' . $whereStatus);
    if ($adminStatus !== 'all') $countQuery->bindValue(':status', $adminStatus);
    $countQuery->execute();
    $adminTotal = (int)$countQuery->fetchColumn();
    $adminPage = min($adminPage, max(1, (int)ceil($adminTotal / 20)));
    $listQuery = $db->prepare('SELECT * FROM refus WHERE ' . $whereStatus . ' ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET :offset');
    if ($adminStatus !== 'all') $listQuery->bindValue(':status', $adminStatus);
    $listQuery->bindValue(':offset', ($adminPage - 1) * 20, PDO::PARAM_INT);
    $listQuery->execute();
    $adminStories = $listQuery->fetchAll();
    $reports = $db->query("SELECT reports.*, refus.title FROM reports JOIN refus ON refus.id = reports.refus_id WHERE reports.status = 'pending' ORDER BY reports.created_at ASC LIMIT 100")->fetchAll();
}
$displayName = ['home'=>'Accueil','explorer'=>'Explorer les refus','refus'=>'Témoignage','soumettre'=>'Soumettre un refus','merci'=>'Merci','moderation'=>'Modération','notfound'=>'Page introuvable'][$page];

function story_card(array $s): void {
    $pill = ['absurde'=>'peach','contradictoire'=>'purple','fantome'=>'green','salaire'=>'blue','entretien'=>'yellow','autre'=>'gray'][$s['category']] ?? 'gray';
    ?>
    <a class="story-card" href="<?= e(link_to('refus', ['id'=>$s['id']])) ?>">
      <div class="meta"><span class="pill pill-<?= e($pill) ?>"><?= e(CATEGORIES[$s['category']] ?? 'Autre') ?></span><span>· <?= e(date('d/m/Y', strtotime($s['published_at'] ?? $s['created_at']))) ?></span></div>
      <h3><?= e($s['title']) ?></h3><p class="excerpt">« <?= e(mb_strimwidth($s['quote_text'], 0, 190, '…', 'UTF-8')) ?> »</p>
      <div class="card-bottom"><span class="reaction">💗 <?= (int)$s['likes_count'] ?> · 😂 <?= (int)$s['laughs_count'] ?> réactions</span><span><?= e(SECTORS[$s['sector']] ?? 'Autre') ?> &nbsp;→</span></div>
    </a>
    <?php
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#f8f7f2"><meta name="description" content="Refus Insolites : partagez et découvrez anonymement les refus de candidature les plus étonnants.">
  <title><?= e($displayName) ?> — Refus Insolites</title>
  <link rel="stylesheet" href="/assets/site.css?v=<?= (int)filemtime(__DIR__ . '/assets/site.css') ?>"><script src="/assets/site.js?v=<?= (int)filemtime(__DIR__ . '/assets/site.js') ?>" defer></script>
</head>
<body>
<div class="utility"><i></i> TÉMOIGNAGES ANONYMISÉS · MODÉRATION AVANT PUBLICATION</div>
<header><div class="container nav">
  <a href="<?= e(link_to()) ?>" class="brand"><span class="brand-mark"><span>RI!</span></span>refus<span class="brand-dot">.</span>insolites</a>
  <nav class="nav-links" id="navLinks" aria-label="Navigation principale">
    <a class="nav-link <?= $page === 'home' ? 'active' : '' ?>" href="<?= e(link_to()) ?>">Accueil</a>
    <a class="nav-link <?= in_array($page, ['explorer','refus'], true) ? 'active' : '' ?>" href="<?= e(link_to('explorer')) ?>">Explorer les refus</a>
    <a class="nav-link <?= $page === 'moderation' ? 'active' : '' ?>" href="<?= e(link_to('moderation')) ?>">Modération</a>
  </nav>
  <a class="btn btn-primary cta" href="<?= e(link_to('soumettre')) ?>">+ Partager un refus</a>
  <button class="mobile-menu btn btn-soft btn-small" id="mobileMenu" aria-expanded="false" aria-controls="navLinks" type="button">Menu</button>
</div></header>
<?php if ($flash || $formError): ?><div class="container site-alert <?= $flash && !$formError && $flash['ok'] ? 'ok' : '' ?>" role="status"><?= e($formError ?: $flash['message']) ?></div><?php endif ?>
<main>
<?php if ($page === 'home'): ?>
<section class="page">
  <div class="container hero">
    <div><span class="eyebrow">Bienvenue dans le musée du « non »</span><h1 class="serif">Les refus qu’on <em>n’inventerait</em> pas.</h1>
      <p>« Vous êtes trop motivé. » « Votre expérience nous intimide. » Certaines réponses semblent écrites par une photocopieuse en plein délire. Ici, on partage ces petites perles, sans nommer ni humilier personne.</p>
      <div class="hero-actions"><a href="<?= e(link_to('explorer')) ?>" class="btn btn-primary">Explorer les pépites →</a><a href="<?= e(link_to('soumettre')) ?>" class="btn btn-soft">Partager mon histoire</a></div>
      <div class="hero-trust"><span><span class="small-icon">✓</span> Anonyme</span><span><span class="small-icon">✓</span> Modéré avant publication</span><span><span class="small-icon">✓</span> Gratuit</span></div>
    </div>
    <div class="stage" aria-label="Illustration fictive de refus"><div class="stage-bg"></div><div class="paper-card"><div class="paper-top"><span>EXEMPLE FICTIF</span><span>↗ RI</span></div><div class="paper-heading serif">Bonjour,</div><div class="paper-text">« Nous avons apprécié votre candidature, mais vous semblez beaucoup trop enthousiaste pour notre environnement de travail. »</div><div class="paper-footer"><span>— Service recrutement</span><span>Réponse insolite</span></div><div class="stamp">REFUSÉ</div></div><div class="float-label">Crime : motivation suspecte ↗</div></div>
  </div>
  <section class="section section-muted"><div class="container"><div class="section-head"><div><span class="eyebrow">Derniers témoignages</span><h2 class="serif">Le non-sens a du talent.</h2></div><a href="<?= e(link_to('explorer')) ?>" class="arrow-link">Voir tous les refus →</a></div>
    <?php if ($featured): ?><div class="cards"><?php foreach($featured as $s) story_card($s); ?></div><?php else: ?><div class="empty visible"><h3>Le musée du « non » attend ses premiers chefs-d’œuvre.</h3><p>Les témoignages validés par la modération apparaîtront ici.</p><a href="<?= e(link_to('soumettre')) ?>" class="btn btn-primary">Soumettre le premier refus</a></div><?php endif ?>
  </div></section>
  <section class="section"><div class="container"><span class="eyebrow">Comment ça fonctionne ?</span><div class="features"><article><div class="feature-icon">✎</div><h3>1. Racontez</h3><p>Décrivez votre refus en quelques lignes, sans nom ni coordonnées.</p></article><article><div class="feature-icon">✓</div><h3>2. On vérifie</h3><p>Chaque texte passe en modération pour éviter les données personnelles et accusations.</p></article><article><div class="feature-icon">♡</div><h3>3. On partage</h3><p>Après validation, votre histoire rejoint la collection consultable par tous.</p></article></div></div></section>
  <div class="container"><div class="banner"><div><h2>Vous avez déjà reçu un refus lunaire ?</h2><p>Ce serait dommage de le garder pour vous. À condition de respecter l’anonymat.</p></div><a href="<?= e(link_to('soumettre')) ?>" class="btn">Raconter mon histoire →</a></div></div>
</section>
<?php elseif ($page === 'explorer'): ?>
<section class="page"><div class="container"><div class="page-title"><span class="eyebrow">La collection</span><h1 class="serif">Le catalogue des <span class="coral">non.</span></h1><p>Découvrez les anecdotes anonymisées sélectionnées par la modération.</p></div>
<form class="filters" action="/explorer" method="get" role="search">
  <div class="field"><label for="search">Rechercher</label><input id="search" name="q" class="control search" type="search" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Ex. expérience, salaire…"></div>
  <div class="field"><label for="category">Catégorie</label><select id="category" name="category" class="control"><option value="all">Toutes</option><?php foreach(CATEGORIES as $k=>$v): ?><option value="<?= e($k) ?>" <?= $filters['category']===$k ? 'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
  <div class="field"><label for="sector">Secteur</label><select id="sector" name="sector" class="control"><option value="all">Tous</option><?php foreach(SECTORS as $k=>$v): ?><option value="<?= e($k) ?>" <?= $filters['sector']===$k ? 'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
  <div class="field"><label for="sort">Trier par</label><select id="sort" name="sort" class="control"><option value="recent" <?= $filters['sort']==='recent'?'selected':'' ?>>Plus récents</option><option value="popular" <?= $filters['sort']==='popular'?'selected':'' ?>>Plus populaires</option><option value="oldest" <?= $filters['sort']==='oldest'?'selected':'' ?>>Plus anciens</option></select><button type="submit" class="btn btn-primary btn-small">Filtrer</button></div>
</form>
<div class="result-head"><span><strong><?= $count ?></strong> témoignage<?= $count!==1 ? 's':'' ?> publié<?= $count!==1 ? 's':'' ?></span><a href="<?= e(link_to('explorer')) ?>">Effacer les filtres</a></div>
<?php if ($catalogue): ?><div class="catalogue-grid"><?php foreach ($catalogue as $s) story_card($s); ?></div><?php else: ?><div class="empty visible"><h3>Aucun refus trouvé.</h3><p>Essayez d’autres filtres ou partagez votre histoire.</p><a class="btn btn-soft" href="<?= e(link_to('soumettre')) ?>">Soumettre un refus</a></div><?php endif ?>
<?php $maxPage = (int)ceil($count / 12); if ($maxPage > 1): ?><nav class="pager" aria-label="Pagination">
<?php if ($currentPage > 1): ?><a href="<?= e(link_to('explorer', $filters + ['page'=>$currentPage-1])) ?>">← Précédent</a><?php endif ?><span>Page <?= $currentPage ?> / <?= $maxPage ?></span><?php if($currentPage<$maxPage): ?><a href="<?= e(link_to('explorer', $filters + ['page'=>$currentPage+1])) ?>">Suivant →</a><?php endif ?></nav><?php endif ?>
</div></section>
<?php elseif ($page === 'refus'): ?>
<section class="page"><div class="container"><div class="breadcrumbs"><a href="<?= e(link_to('explorer')) ?>">← Tous les refus</a> / Détail du témoignage</div><div class="detail-layout">
<article class="detail-card"><div class="detail-top"><span class="pill pill-peach"><?= e(CATEGORIES[$story['category']] ?? 'Autre') ?></span><h1 class="serif"><?= e($story['title']) ?></h1><div class="detail-meta"><span><?= e(date('d/m/Y', strtotime($story['published_at'] ?? $story['created_at']))) ?></span><span>•</span><span><?= e(SECTORS[$story['sector']] ?? 'Autre') ?></span><span>•</span><span>Entreprise anonymisée</span></div></div>
<div class="detail-body"><h2>Le refus reçu</h2><blockquote class="bigquote quote-text">« <?= e($story['quote_text']) ?> »</blockquote><h2>Le contexte</h2><p class="context-text"><?= e($story['context_text']) ?></p></div>
<div class="detail-action">
  <div class="reaction-panel" id="reactionPanel">
    <div class="reaction-intro"><span class="eyebrow">Le verdict du public</span><h3>Ça vous parle… ou ça vous fait exploser de rire ?</h3><p>Un vote de chaque sorte par personne. Promis, pas de réunion pour valider votre rire.</p></div>
    <div class="reaction-choices">
      <form class="reaction-form" data-kind="relate" action="<?= e(link_to('refus', ['id'=>$story['id']])) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="react"><input type="hidden" name="kind" value="relate"><input type="hidden" name="id" value="<?= (int)$story['id'] ?>">
        <button class="reaction-button relate-button <?= $reacted ? 'is-picked' : '' ?>" type="submit" <?= $reacted ? 'disabled':'' ?> aria-label="Ça m’est déjà arrivé"><span class="reaction-icon" aria-hidden="true">🙋</span><span class="reaction-words"><strong>Ça m’est déjà arrivé</strong><small>Club des recalés</small></span><span class="reaction-count" data-count><?= (int)$story['likes_count'] ?></span></button>
      </form>
      <form class="reaction-form" data-kind="laugh" action="<?= e(link_to('refus', ['id'=>$story['id']])) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="react"><input type="hidden" name="kind" value="laugh"><input type="hidden" name="id" value="<?= (int)$story['id'] ?>">
        <button class="reaction-button laugh-button <?= $laughed ? 'is-picked' : '' ?>" type="submit" <?= $laughed ? 'disabled':'' ?> aria-label="Ça m’a fait rire"><span class="reaction-icon" aria-hidden="true">😂</span><span class="reaction-words"><strong>Ça m’a fait rire !</strong><small>Rire nerveux accepté</small></span><span class="reaction-count" data-count><?= (int)$story['laughs_count'] ?></span></button>
      </form>
    </div>
    <div id="reactionFeedback" class="reaction-feedback" role="status" aria-live="polite" hidden></div>
    <div id="reactionCelebration" class="reaction-celebration" aria-hidden="true"><span>😂</span><span>HA HA !</span><span>✨</span><span>🤣</span><span>💥</span></div>
  </div>
  <button class="btn btn-soft btn-small share-button" type="button" id="copyButton">Copier le lien</button>
</div></article>
<aside class="detail-sidebar"><div class="sidebox"><h3>À propos de ce refus</h3><div class="keyval"><span>Catégorie</span><strong><?= e(CATEGORIES[$story['category']] ?? 'Autre') ?></strong></div><div class="keyval"><span>Secteur</span><strong><?= e(SECTORS[$story['sector']] ?? 'Autre') ?></strong></div><div class="keyval"><span>Anonymisation</span><strong>Modéré</strong></div></div><div class="sidebox"><h3>Un problème ?</h3><p class="notice">Signalez un contenu identifiable ou inapproprié.</p><form action="<?= e(link_to('refus',['id'=>$story['id']])) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="report"><input type="hidden" name="id" value="<?= (int)$story['id'] ?>"><label for="reason" class="notice">Motif du signalement</label><select name="reason" id="reason" class="control"><option value="privacy">Données personnelles</option><option value="harassment">Harcèlement ou attaques</option><option value="false">Contenu trompeur</option><option value="other">Autre</option></select><button type="submit" class="btn btn-soft btn-small">Signaler</button></form></div></aside>
</div></div></section>
<?php elseif ($page === 'soumettre'): ?>
<section class="page"><div class="container"><div class="page-title"><span class="eyebrow">Votre histoire</span><h1 class="serif">Racontez-nous <span class="coral">ce refus.</span></h1><p>Vous avez reçu un « non » digne d’un sketch ? Déposez la pépite ici. Rire autorisé, noms propres interdits.</p></div>
<div class="submit-layout"><form id="submitForm" class="form-card" method="post" action="<?= e(link_to('soumettre')) ?>" accept-charset="utf-8">
<div id="submitFeedback" class="submit-feedback" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden><span class="feedback-glyph" aria-hidden="true">✦</span><div class="feedback-copy"><strong id="submitFeedbackTitle"></strong><p id="submitFeedbackMessage"></p></div></div>

<?= csrf_field() ?><input type="hidden" name="action" value="submit"><div class="field" aria-hidden="true"><label for="website">Ne pas remplir</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
<div class="form-step"><div class="step-num">01</div><div><h2>Votre anecdote</h2><p>Les champs marqués d’un astérisque sont obligatoires.</p></div></div>
<div class="field"><label for="storyTitle">Titre du témoignage *</label><input id="storyTitle" name="title" class="control" type="text" maxlength="160" minlength="8" value="<?= e($old['title'] ?? '') ?>" placeholder="Ex. Trop compétent pour la photocopieuse…" required></div>
<div class="field"><label for="storyQuote">Message de refus reçu *</label><textarea id="storyQuote" name="quote_text" class="textarea" minlength="20" maxlength="1500" rows="5" required placeholder="Reformulez si nécessaire pour supprimer les informations personnelles."><?= e($old['quote_text'] ?? '') ?></textarea></div>
<div class="field"><label for="storyContext">Contexte *</label><textarea id="storyContext" name="context_text" class="textarea" minlength="20" maxlength="3000" rows="6" required placeholder="Qu’avez-vous vécu ? Sans noms propres ni coordonnées."><?= e($old['context_text'] ?? '') ?></textarea></div>
<div class="form-step"><div class="step-num">02</div><div><h2>Quelques détails</h2><p>Pour retrouver plus facilement les histoires similaires.</p></div></div>
<div class="form-row"><div class="field"><label for="storyCategory">Catégorie *</label><select id="storyCategory" name="category" class="control" required><option value="">Choisissez…</option><?php foreach(CATEGORIES as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($old['category'] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div><div class="field"><label for="storySector">Secteur *</label><select id="storySector" name="sector" class="control" required><option value="">Choisissez…</option><?php foreach(SECTORS as $k=>$v): ?><option value="<?= e($k) ?>" <?= ($old['sector'] ?? '')===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach ?></select></div></div>
<div class="form-step"><div class="step-num">03</div><div><h2>Avant de publier</h2><p>Un modérateur validera votre témoignage avant qu’il soit visible.</p></div></div>
<div class="privacy"><strong>Protégez l’anonymat.</strong><p>Supprimez noms, entreprises, adresses, liens, numéros, captures et tout détail permettant d’identifier les personnes impliquées. Le site ne peut pas garantir l’anonymat d’un texte identifiable.</p></div>
<label class="checkline"><input name="consent" type="checkbox" value="yes" <?= ($old['consent'] ?? '')==='yes'?'checked':'' ?> required> Je confirme avoir anonymisé mon histoire et accepter sa modération avant publication. *</label>
<div class="form-actions"><button type="button" class="btn btn-soft" id="previewButton">Aperçu</button><button type="submit" class="btn btn-primary" id="submitButton">Envoyer cette pépite →</button></div>
</form><aside class="submit-aside"><div class="sidebox"><h3>Quelques conseils</h3><p>Racontez le moment où vous avez relu le message trois fois pour être sûr de ne pas avoir halluciné.</p><p>Évitez les accusations nominatives et les informations permettant de retrouver l’entreprise ou la personne.</p></div><div class="sidebox"><h3>Et ensuite ?</h3><p>Notre comité des sourcils levés vérifie chaque histoire avant de lui ouvrir les portes du musée.</p></div></aside></div></div></section>
<div class="preview-backdrop" id="previewModal" aria-hidden="true"><div class="preview-box" role="dialog" aria-modal="true" aria-labelledby="previewTitle"><button type="button" class="btn btn-small" id="closeModal">Fermer</button><h2 id="previewTitle">Aperçu</h2><p id="previewQuote" class="quote-text"></p><p id="previewContext" class="context-text"></p></div></div>
<?php elseif ($page === 'merci'): ?>
<section class="page"><div class="container page-title"><span class="eyebrow">Merci !</span><h1 class="serif">Témoignage reçu.</h1><p>Votre histoire a été soumise à la modération. Elle n’apparaîtra dans la collection qu’après validation.</p><a class="btn btn-primary" href="<?= e(link_to('explorer')) ?>">Explorer les refus →</a></div></section>
<?php elseif ($page === 'moderation'): ?>
<section class="page"><div class="container"><div class="page-title"><span class="eyebrow">Espace administrateur</span><h1 class="serif">File de <span class="coral">modération.</span></h1><p>Gestion des témoignages en attente et des signalements.</p></div>
<?php if (!admin_logged()): ?><div class="form-card admin-login"><h2>Connexion administrateur</h2><?php if (!admin_ready($config)): ?><p class="site-alert">Cet espace n’est pas encore disponible.</p><?php endif ?><form method="post" action="<?= e(link_to('moderation')) ?>"><?= csrf_field() ?><input name="action" type="hidden" value="login"><div class="field"><label for="adminPassword">Mot de passe</label><input class="control" type="password" id="adminPassword" name="password" autocomplete="current-password" required></div><button class="btn btn-primary" type="submit">Se connecter</button></form></div>
<?php else: ?>
<div class="admin-grid"><div class="stat"><strong><?= $stats['pending'] ?? 0 ?></strong><span>En attente</span></div><div class="stat"><strong><?= $stats['approved'] ?? 0 ?></strong><span>Approuvés</span></div><div class="stat"><strong><?= $stats['rejected'] ?? 0 ?></strong><span>Rejetés</span></div><div class="stat"><strong><?= count($reports) ?></strong><span>Signalements affichés</span></div></div>
<div class="mod-toolbar">
  <div><h2 class="serif">Gérer les témoignages</h2><p><?= $adminTotal ?> témoignage<?= $adminTotal > 1 ? 's' : '' ?> dans cette sélection</p></div>
  <form class="mod-filter" method="get" action="<?= e(link_to('moderation')) ?>"><label for="adminStatus">Afficher</label><select id="adminStatus" class="control" name="statut"><option value="all" <?= $adminStatus==='all'?'selected':'' ?>>Tous les statuts</option><option value="pending" <?= $adminStatus==='pending'?'selected':'' ?>>En attente</option><option value="approved" <?= $adminStatus==='approved'?'selected':'' ?>>Publiés</option><option value="rejected" <?= $adminStatus==='rejected'?'selected':'' ?>>Rejetés</option></select><button type="submit" class="btn btn-soft btn-small">Filtrer</button></form>
  <form method="post" action="<?= e(link_to('moderation')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn btn-soft btn-small" type="submit">Se déconnecter</button></form>
</div>
<?php if (!$adminStories): ?><div class="empty visible">Aucun témoignage ici. Même les recruteurs ont parfois besoin d’une pause.</div><?php endif ?>
<?php foreach($adminStories as $s): ?>
<article class="admin-row mod-entry">
  <div class="mod-entry-top"><span class="pill <?= $s['status']==='pending'?'pill-yellow':($s['status']==='approved'?'pill-green':'pill-gray') ?>"><?= e(['pending'=>'En attente','approved'=>'Publié','rejected'=>'Rejeté'][$s['status']] ?? 'Inconnu') ?></span><span class="notice">#<?= (int)$s['id'] ?> · <?= e(date('d/m/Y', strtotime($s['created_at']))) ?></span></div>
  <h3><?= e($s['title']) ?></h3><blockquote class="mod-quote">« <?= e($s['quote_text']) ?> »</blockquote><p class="mod-context"><?= e($s['context_text']) ?></p>
  <div class="mod-entry-bottom"><span class="notice"><?= e(CATEGORIES[$s['category']] ?? 'Autre') ?> · <?= e(SECTORS[$s['sector']] ?? 'Autre') ?></span>
    <div class="admin-actions">
      <?php if ($s['status']==='pending'): ?><form method="post" action="<?= e(link_to('moderation')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="moderate"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-primary btn-small" name="decision" value="approve" type="submit">✓ Approuver</button><button class="btn btn-soft btn-small" name="decision" value="reject" type="submit">Rejeter</button></form><?php endif ?>
      <details class="delete-expander"><summary class="btn btn-danger btn-small">Supprimer</summary><div class="delete-panel"><strong>Supprimer définitivement ?</strong><p>Ce témoignage, ses réactions et ses signalements seront effacés. Aucun retour en arrière.</p><form method="post" action="<?= e(link_to('moderation')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_story"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="confirmation" value="SUPPRIMER"><button class="btn btn-danger btn-small" type="submit">Oui, supprimer définitivement</button></form></div></details>
    </div>
  </div>
</article>
<?php endforeach ?>
<?php if ($adminTotal > 20): ?><nav class="pager" aria-label="Pagination modération"><?php if ($adminPage > 1): ?><a href="<?= e(link_to('moderation', ['statut'=>$adminStatus,'page'=>$adminPage - 1])) ?>">← Précédent</a><?php endif ?><span>Page <?= $adminPage ?> / <?= (int)ceil($adminTotal / 20) ?></span><?php if ($adminPage < (int)ceil($adminTotal / 20)): ?><a href="<?= e(link_to('moderation', ['statut'=>$adminStatus,'page'=>$adminPage + 1])) ?>">Suivant →</a><?php endif ?></nav><?php endif ?>
<h2 class="serif">Signalements</h2><p>Vérifiez les contenus signalés. « Masquer » retire immédiatement le témoignage public.</p>
<?php if (!$reports): ?><div class="empty visible">Aucun signalement en attente.</div><?php endif ?>
<?php foreach($reports as $r): ?><article class="admin-row"><h3><a href="<?= e(link_to('refus',['id'=>$r['refus_id']])) ?>"><?= e($r['title']) ?> ↗</a></h3><p>Motif : <?= e(['privacy'=>'Données personnelles','harassment'=>'Harcèlement','false'=>'Contenu trompeur','other'=>'Autre'][$r['reason']] ?? 'Autre') ?></p><form method="post" action="<?= e(link_to('moderation')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="review_report"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-primary btn-small" name="decision" value="hide">Masquer</button> <button class="btn btn-soft btn-small" name="decision" value="dismiss">Ignorer</button></form></article><?php endforeach ?>
<?php endif ?></div></section>
<?php else: ?><section class="page"><div class="container page-title"><h1 class="serif">Page introuvable.</h1><p>Ce témoignage n’est pas disponible.</p><a class="btn btn-primary" href="<?= e(link_to('explorer')) ?>">Revenir au catalogue</a></div></section><?php endif ?>
</main>
<footer><div class="container"><div class="footer-main"><a href="<?= e(link_to()) ?>" class="brand"><span class="brand-mark"><span>RI!</span></span>refus<span class="brand-dot">.</span>insolites</a><div class="footlinks"><a href="<?= e(link_to('explorer')) ?>">Explorer</a><a href="<?= e(link_to('soumettre')) ?>">Soumettre</a><a href="<?= e(link_to('moderation')) ?>">Administration</a></div></div><div class="foot-note"><span>Témoignages anonymisés, modération avant publication.</span><span>Les plus beaux « non » méritent un public.</span></div></div></footer>

<!-- Scène de rire plein écran commune aux soumissions et aux réactions. Sans ressources externes. -->
<div class="laugh-stage" id="laughStage" aria-hidden="true" data-kind="submit">
  <div class="laugh-backdrop"></div>
  <div class="laugh-rings"><i></i><i></i><i></i></div>
  <div class="laugh-scene">
    <div class="laugh-comic-words" aria-hidden="true">
      <span class="laugh-word">HA !</span><span class="laugh-word">HA HA !</span><span class="laugh-word">AHAHA !</span>
    </div>
    <svg class="laugh-character" viewBox="0 0 320 340" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
      <ellipse class="laugh-ground-shadow" cx="161" cy="319" rx="97" ry="12" fill="#131d32" opacity=".18"/>
      <g class="laugh-figure">
        <path d="M89 278 Q93 223 117 209 L202 209 Q230 232 235 280 L240 301 Q160 315 80 301Z" fill="#497caa" stroke="#1e3546" stroke-width="7" stroke-linejoin="round"/>
        <path d="M139 216 L160 247 L183 216" fill="none" stroke="#f6d8b2" stroke-width="11" stroke-linecap="round"/>
        <g class="laugh-arm-left">
          <path d="M106 233 Q71 240 62 270 L102 287 Q113 269 127 257Z" fill="#497caa" stroke="#1e3546" stroke-width="6" stroke-linejoin="round"/>
          <ellipse cx="97" cy="288" rx="20" ry="15" fill="#ffcd8f" stroke="#1e3546" stroke-width="5"/>
        </g>
        <g class="laugh-arm-right">
          <path d="M215 231 Q255 236 262 267 L226 287 Q215 267 197 257Z" fill="#497caa" stroke="#1e3546" stroke-width="6" stroke-linejoin="round"/>
          <ellipse cx="225" cy="287" rx="20" ry="15" fill="#ffcd8f" stroke="#1e3546" stroke-width="5"/>
        </g>
        <g class="laugh-head">
          <ellipse cx="160" cy="126" rx="102" ry="99" fill="#ffcd8f" stroke="#1e3546" stroke-width="8"/>
          <path d="M88 82 Q110 28 165 25 Q213 23 242 68 Q212 61 203 39 Q177 69 135 56 Q110 78 88 82Z" fill="#2b344a" stroke="#1e3546" stroke-width="5" stroke-linejoin="round"/>
          <ellipse cx="62" cy="138" rx="13" ry="22" fill="#ffcd8f" stroke="#1e3546" stroke-width="6"/>
          <ellipse cx="258" cy="138" rx="13" ry="22" fill="#ffcd8f" stroke="#1e3546" stroke-width="6"/>
          <ellipse cx="101" cy="164" rx="23" ry="14" fill="#ee8d82" opacity=".52"/>
          <ellipse cx="219" cy="164" rx="23" ry="14" fill="#ee8d82" opacity=".52"/>
          <g class="laugh-eyes" fill="none" stroke="#26374a" stroke-width="8" stroke-linecap="round">
            <path d="M94 117 Q115 95 139 117"/><path d="M182 117 Q205 95 226 117"/>
            <path d="M96 86 L125 82 M196 82 L225 86" stroke-width="6"/>
          </g>
          <g class="laugh-mouth">
            <path d="M118 156 Q160 135 203 156 Q206 216 161 226 Q112 215 118 156Z" fill="#61263d" stroke="#29354b" stroke-width="7" stroke-linejoin="round"/>
            <path d="M121 157 Q158 143 201 157 L196 176 Q159 186 125 174Z" fill="#fff8eb"/>
            <ellipse cx="164" cy="216" rx="27" ry="15" fill="#f17f85"/>
          </g>
          <g class="laugh-tear laugh-tear-left"><path d="M83 123 Q65 149 76 161 Q96 160 83 123Z" fill="#91def6" stroke="#367b9e" stroke-width="3"/></g>
          <g class="laugh-tear laugh-tear-right"><path d="M239 123 Q259 148 247 160 Q226 162 239 123Z" fill="#91def6" stroke="#367b9e" stroke-width="3"/></g>
        </g>
      </g>
      <path class="laugh-shine" d="M36 77 l12 -25 l10 28 l25 9 l-25 10 l-10 27 l-12 -27 l-25 -10Z" fill="#ffe89d"/>
      <path class="laugh-shine laugh-shine-second" d="M280 192 l8 -19 l7 20 l19 7 l-19 8 l-7 19 l-8 -19 l-19 -8Z" fill="#ffe89d"/>
    </svg>
    <div class="laugh-caption" id="laughCaption">ON N'EST PAS PRÊTS !</div>
    <div class="laugh-subcaption" id="laughSubcaption">Le comité des sourcils levés a perdu son sérieux.</div>
  </div>
  <div class="laugh-confetti" aria-hidden="true">
    <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
  </div>
</div>
</body></html>

