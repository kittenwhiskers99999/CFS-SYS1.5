<?php
/**
 * GET /api/cms/list_posts.php   (staff only)
 * Every post, newest edit first, drafts included. Used by the "Website content" tab.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/_lib.php';

requireMethod('GET');
call_user_func('cmsRequireStaff', $pdo);

$stmt = $pdo->query(
    'SELECT id, title, category, status, is_featured, cover_path, event_date, expires_on,
            published_at, updated_at
     FROM content_posts
     ORDER BY updated_at DESC, id DESC
     LIMIT 300'
);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($posts as &$p) {
    $p['is_featured'] = (bool)$p['is_featured'];
}
unset($p);

jsonResponse(200, ['posts' => $posts]);
