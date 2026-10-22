<?php
/**
 * GET /api/cms/get.php?id=123   (staff only)
 * Returns one post with all its fields, drafts included, for the editor.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/_lib.php';

requireMethod('GET');
cmsRequireStaff($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError(400, 'Invalid post.');
}

$stmt = $pdo->prepare(
    'SELECT id, title, body, blocks, category, status, is_featured, cover_path, tag, tag_label,
            expires_on, event_date, event_time, venue, published_at
     FROM content_posts WHERE id = :id'
);
$stmt->execute(['id' => $id]);
$post = $stmt->fetch();
if (!$post) {
    jsonError(404, 'That post no longer exists.');
}

$post['is_featured'] = (bool)$post['is_featured'];
$post['blocks']      = cmsDecodeBlocks($post['blocks']);

jsonResponse(200, ['post' => $post]);
