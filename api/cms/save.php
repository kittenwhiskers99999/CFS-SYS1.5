<?php
/**
 * POST /api/cms/save.php   (staff only)
 * Body: { id?, category, status: draft|published, title, body, blocks[], cover_path?, is_featured?,
 *         tag?, tag_label?, expires_on?, event_date?, event_time?, venue? }
 * Creates the post when id is missing, otherwise updates it. Returns { id, status, slug }.
 */

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../config/helpers.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/_lib.php';

requireMethod('POST');
$staff = cmsRequireStaff($pdo);
$in    = readJsonBody();

// ---------- common fields ----------
$category = $in['category'] ?? '';
if (!in_array($category, ['news', 'announcement', 'event'], true)) {
    jsonError(400, 'Unknown content type.');
}
$status = $in['status'] ?? 'draft';
if (!in_array($status, ['draft', 'published'], true)) {
    jsonError(400, 'Unknown status.');
}
$title = cmsStr($in['title'] ?? '', 150);
if ($title === '') {
    jsonError(400, 'Please give this a title.');
}
$body = cmsStr($in['body'] ?? '', $category === 'announcement' ? 600 : 300, true);

// ---------- existing post (update) ----------
$id = null;
if (isset($in['id']) && $in['id'] !== null && $in['id'] !== '') {
    $id = filter_var($in['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        jsonError(400, 'Invalid post.');
    }
    $stmt = $pdo->prepare('SELECT id, category, slug FROM content_posts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        jsonError(404, 'That post no longer exists.');
    }
    if ($existing['category'] !== $category) {
        jsonError(400, 'The type of a post cannot be changed.');
    }
}

// ---------- category-specific fields ----------
$blocks = [];
$cover = null; $featured = 0;
$tag = null; $tagLabel = null; $expires = null;
$eventDate = null; $eventTime = null; $venue = null;

if ($category !== 'announcement') {
    $blocks = cmsCleanBlocks($in['blocks'] ?? []);
}

if ($category === 'news') {
    $c = $in['cover_path'] ?? null;
    if ($c !== null && $c !== '') {
        if (!is_string($c) || !cmsImageExists($c)) {
            jsonError(400, 'The cover photo is not valid. Please upload it again.');
        }
        $cover = $c;
    }
    $featured = !empty($in['is_featured']) ? 1 : 0;
}

if ($category === 'announcement') {
    $tag = $in['tag'] ?? 'info';
    if (!in_array($tag, ['urgent', 'info', 'event'], true)) {
        jsonError(400, 'Unknown announcement type.');
    }
    $tagLabel = mb_strtoupper(cmsStr($in['tag_label'] ?? '', 30));
    if ($tagLabel === '') {
        $tagLabel = 'NOTICE';
    }
    $expires = cmsDate($in['expires_on'] ?? null, 'hide-after date');
}

if ($category === 'event') {
    $eventDate = cmsDate($in['event_date'] ?? null, 'event date');
    $eventTime = cmsStr($in['event_time'] ?? '', 50);
    $venue     = cmsStr($in['venue'] ?? '', 100);
}

// ---------- rules for going live ----------
if ($status === 'published') {
    if ($category === 'announcement' && $body === '') {
        jsonError(400, 'Please write the announcement message.');
    }
    if ($category === 'news' && $body === '') {
        jsonError(400, 'Please add a short summary for the homepage card.');
    }
    if ($category === 'event' && $eventDate === null) {
        jsonError(400, 'Please choose the event date.');
    }
}

$blocksJson = $category === 'announcement' ? null : json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$isPub      = $status === 'published' ? 1 : 0;

// ---------- write ----------
$pdo->beginTransaction();
try {
    if ($id === null) {
        $slug = cmsSlug($title);
        $stmt = $pdo->prepare(
            'INSERT INTO content_posts
               (title, slug, body, blocks, category, status, event_date, event_time, venue,
                cover_path, is_featured, tag, tag_label, expires_on, author_id, published_at)
             VALUES
               (:title, :slug, :body, :blocks, :category, :status, :event_date, :event_time, :venue,
                :cover, :featured, :tag, :tag_label, :expires, :author, IF(:is_pub = 1, NOW(), NULL))'
        );
        $stmt->execute([
            'title' => $title, 'slug' => $slug, 'body' => $body, 'blocks' => $blocksJson,
            'category' => $category, 'status' => $status,
            'event_date' => $eventDate, 'event_time' => $eventTime, 'venue' => $venue,
            'cover' => $cover, 'featured' => $featured, 'tag' => $tag, 'tag_label' => $tagLabel,
            'expires' => $expires, 'author' => $staff['id'], 'is_pub' => $isPub,
        ]);
        $id = (int)$pdo->lastInsertId();
        $action = 'content.created';
    } else {
        $slug = $existing['slug'];                       // slug never changes after creation
        $stmt = $pdo->prepare(
            'UPDATE content_posts SET
               title = :title, body = :body, blocks = :blocks, status = :status,
               event_date = :event_date, event_time = :event_time, venue = :venue,
               cover_path = :cover, is_featured = :featured, tag = :tag, tag_label = :tag_label,
               expires_on = :expires,
               published_at = IF(:is_pub = 1, COALESCE(published_at, NOW()), published_at)
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title, 'body' => $body, 'blocks' => $blocksJson, 'status' => $status,
            'event_date' => $eventDate, 'event_time' => $eventTime, 'venue' => $venue,
            'cover' => $cover, 'featured' => $featured, 'tag' => $tag, 'tag_label' => $tagLabel,
            'expires' => $expires, 'is_pub' => $isPub, 'id' => $id,
        ]);
        $action = 'content.updated';
    }

    // Only one headline story at a time.
    if ($category === 'news' && $featured === 1) {
        $stmt = $pdo->prepare("UPDATE content_posts SET is_featured = 0 WHERE category = 'news' AND id <> :id");
        $stmt->execute(['id' => $id]);
    }

    cmsAudit($pdo, (int)$staff['id'], $action, $id, ['title' => $title, 'status' => $status]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('cms/save.php: ' . $e->getMessage());
    jsonError(500, 'Could not save. Please try again.');
}

jsonResponse(200, ['id' => $id, 'status' => $status, 'slug' => $slug]);
