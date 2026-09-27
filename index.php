<?php
// index.php
require_once 'db.php';
include 'header.php';

$u = current_user();
$base_path = get_app_base_path();
$base_url  = get_base_url();

$stmt = $pdo->query("
    SELECT 
        s.*, 
        u.username,
        u.first_name,
        u.last_name,
        (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count 
    FROM surveys s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY s.id DESC
");
$surveys = $stmt->fetchAll();

$total_surveys = count($surveys);
$total_all_responses = 0;
foreach ($surveys as $s) {
    $total_all_responses += (int)$s['response_count'];
}
?>

<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 28px;">
    <div>
        <h1 style="margin: 0 0 6px 0; font-size: 1.75rem; font-weight: 700; color: var(--slate-900); letter-spacing: -0.02em;">Surveys &amp; Feedback</h1>
        <p style="margin: 0; color: var(--slate-500); font-size: 0.95rem;">Manage active surveys, review submissions, and view analytical breakdowns.</p>
    </div>
    <?php if ($u && !empty($u['can_create_surveys'])): ?>
        <a href="<?= $base_path ?>/create_survey.php" class="btn">
            <span style="font-size: 1.1em; line-height: 0;">+</span> Create Survey
        </a>
    <?php endif; ?>
</div>

<!-- Quick Stat Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
    <div class="card" style="padding: 18px 20px; margin-bottom: 0;">
        <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--slate-400); letter-spacing: 0.05em;">Total Surveys</div>
        <div style="font-size: 1.85rem; font-weight: 700; color: var(--slate-900); margin-top: 4px;"><?= $total_surveys ?></div>
    </div>
    <div class="card" style="padding: 18px 20px; margin-bottom: 0;">
        <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--slate-400); letter-spacing: 0.05em;">Total Submissions</div>
        <div style="font-size: 1.85rem; font-weight: 700; color: var(--primary); margin-top: 4px;"><?= $total_all_responses ?></div>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <?php 
    $created_slug = urlencode($_GET['created']);
    $relative_link = $base_path . '/survey/' . $created_slug;
    $full_link     = $base_url . '/survey/' . $created_slug;
    ?>
    <div class="alert alert-success">
        <span style="margin-right: 8px;">🎉</span>
        <div>
            <strong>Survey published successfully!</strong> Share this link: 
            <a href="<?= htmlspecialchars($relative_link) ?>" target="_blank" style="color: #065f46; font-weight: 600; text-decoration: underline;">
                <?= htmlspecialchars($full_link) ?>
            </a>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($surveys)): ?>
    <div style="text-align: center; padding: 60px 20px; background: var(--slate-50); border: 1.5px dashed var(--slate-300); border-radius: var(--radius-md);">
        <div style="font-size: 2.2rem; margin-bottom: 12px;">📋</div>
        <h3 style="margin: 0 0 6px 0; color: var(--slate-800);">No surveys yet</h3>
        <p style="margin: 0 0 18px 0; color: var(--slate-500); font-size: 0.9rem;">Get started by launching your first question flow.</p>
        <?php if ($u && !empty($u['can_create_surveys'])): ?>
            <a href="<?= $base_path ?>/create_survey.php" class="btn">Create Your First Survey</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Survey Name</th>
                <th>Author</th>
                <th style="text-align: center;">Responses</th>
                <th>Share Link</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($surveys as $s): ?>
                <?php
                $relative_survey_url = $base_path . '/survey/' . urlencode($s['slug']);
                $author_name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                if (!$author_name) $author_name = $s['username'];

                $can_view_results = false;
                if (!empty($s['results_open'])) {
                    $can_view_results = true;
                } elseif ($u) {
                    if (!empty($u['can_view_all_results']) || $s['user_id'] == $u['id']) {
                        $can_view_results = true;
                    }
                }
                ?>
                <tr>
                    <td>
                        <div style="font-weight: 600; color: var(--slate-900); font-size: 0.95rem;">
                            <?= htmlspecialchars($s['title']) ?>
                        </div>
                        <div style="margin-top: 4px; display: flex; gap: 6px;">
                            <?php if (!empty($s['results_open'])): ?>
                                <span class="badge badge-blue">Open Results</span>
                            <?php endif; ?>
                            <?php if (empty($s['allow_multiple'])): ?>
                                <span class="badge badge-amber">1 per person</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 0.88rem; font-weight: 500; color: var(--slate-700);"><?= htmlspecialchars($author_name) ?></div>
                        <div style="font-size: 0.78rem; color: var(--slate-400);"><?= date('M j, Y', strtotime($s['created_at'])) ?></div>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-weight: 700; color: var(--slate-800); font-size: 1rem;">
                            <?= (int)$s['response_count'] ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($relative_survey_url) ?>" target="_blank" class="btn btn-secondary btn-mini" style="font-weight: 500;">
                            Open Form &nearr;
                        </a>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($can_view_results): ?>
                            <a href="<?= $base_path ?>/results.php?slug=<?= urlencode($s['slug']) ?>" class="btn btn-mini" style="font-weight: 600;">
                                View Analytics &rarr;
                            </a>
                        <?php else: ?>
                            <span class="badge" style="background: var(--slate-100); color: var(--slate-400);">Locked</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include 'footer.php'; ?>
