<?php
/**
 * POST /api/cms/delete.php   (staff only)
 * Body: { id }
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/_lib.php';

requireMethod('POST');
$staff = cmsRequireStaff($pdo);
$in    = readJsonBody();

$id = filter_var($in['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    jsonError(400, 'Invalid post.');
}

$stmt = $pdo->prepare('SELECT title FROM content_posts WHERE id = :id');
$stmt->execute(['id' => $id]);
$post = $stmt->fetch();
if (!$post) {
    jsonError(404, 'That post no longer exists.');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('DELETE FROM content_posts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    cmsAudit($pdo, (int)$staff['id'], 'content.deleted', $id, ['title' => $post['title']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('cms/delete.php: ' . $e->getMessage());
    jsonError(500, 'Could not delete. Please try again.');
}

jsonResponse(200, ['deleted' => true]);
