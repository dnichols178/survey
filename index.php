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
        (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count 
    FROM surveys s 
    JOIN users u ON s.user_id = u.id 
    ORDER BY s.id DESC
");
$surveys = $stmt->fetchAll();
?>

<h2>Dashboard</h2>

<?php if (isset($_GET['created'])): ?>
    <?php 
    $created_slug = urlencode($_GET['created']);
    $relative_link = $base_path . '/survey/' . $created_slug;
    $full_link     = $base_url . '/survey/' . $created_slug;
    ?>
    <div class="alert alert-success">
        Survey published successfully!<br>
        Public Link: <a href="<?= htmlspecialchars($relative_link) ?>" target="_blank"><?= htmlspecialchars($full_link) ?></a>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; margin-bottom: 12px;">
    <h3 style="margin: 0;">Available Surveys</h3>
    <?php if ($u && !empty($u['can_create_surveys'])): ?>
        <a href="create_survey.php" class="btn">+ Create New Survey</a>
    <?php endif; ?>
</div>

<?php if (empty($surveys)): ?>
    <p style="color: #64748b;">No surveys have been created yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Author</th>
                <th>Submissions</th>
                <th>Survey URL</th>
                <th>Results / Analytics</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($surveys as $s): ?>
                <?php
                $relative_survey_url = $base_path . '/survey/' . urlencode($s['slug']);
                
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
        <strong><?= htmlspecialchars($s['title']) ?></strong>
        <?php if (!empty($s['results_open'])): ?>
            <span style="font-size: 0.75em; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Open Results</span>
        <?php endif; ?>
        <?php if (empty($s['allow_multiple'])): ?>
            <span style="font-size: 0.75em; background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">1 Submission/Person</span>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($s['username']) ?></td>
    <td><?= (int)$s['response_count'] ?></td>
    <td>
        <a href="<?= htmlspecialchars($relative_survey_url) ?>" target="_blank">Take Survey</a>
    </td>
    <td>
        <?php if ($can_view_results): ?>
            <a href="results.php?slug=<?= urlencode($s['slug']) ?>" class="btn" style="padding: 4px 10px; font-size: 0.85em;">View Results</a>
        <?php else: ?>
            <span style="color: #94a3b8; font-size: 0.9em;">Locked (Auth Required)</span>
        <?php endif; ?>
    </td>
</tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include 'footer.php'; ?>