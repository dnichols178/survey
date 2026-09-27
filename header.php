<?php
// header.php
require_once 'db.php';
$u = current_user();
$base_path = get_app_base_path();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Survey Engine</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f8fafc; color: #1e293b; }
        .container { max-width: 950px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        nav { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 24px; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .btn { display: inline-block; background: #2563eb; color: #fff; padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; font-size: 0.9em; }
        .btn:hover { background: #1d4ed8; text-decoration: none; }
        .btn-secondary { background: #64748b; }
        .btn-secondary:hover { background: #475569; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input[type="text"], input[type="password"], input[type="email"], textarea, select { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; }
        .q-card { border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 16px; background: #f1f5f9; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 16px; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #dcfce7; color: #166534; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        th { background: #f8fafc; font-size: 0.9em; }
        .badge { display: inline-block; padding: 2px 6px; font-size: 0.75em; border-radius: 4px; background: #e2e8f0; color: #475569; margin: 1px; }
    </style>
</head>
<body>
<div class="container">
    <nav>
        <div>
            <a href="<?= $base_path ?>/index.php" style="font-size: 1.25rem; font-weight: bold; color: #0f172a;">SurveyApp</a>
        </div>
        <div>
            <?php if ($u): ?>
                <span>Signed in as <strong><?= htmlspecialchars($u['first_name'] ? ($u['first_name'] . ' ' . $u['last_name']) : $u['username']) ?></strong></span>
                <?php if (!empty($u['can_create_surveys'])): ?>
                    | <a href="<?= $base_path ?>/create_survey.php">Create Survey</a>
                <?php endif; ?>
                <?php if (!empty($u['can_manage_users'])): ?>
                    | <a href="<?= $base_path ?>/users.php">Manage Users</a>
                <?php endif; ?>
                | <a href="<?= $base_path ?>/logout.php">Logout</a>
            <?php else: ?>
                <a href="<?= $base_path ?>/login.php">Login</a>
            <?php endif; ?>
        </div>
    </nav>