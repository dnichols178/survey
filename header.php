<?php
// header.php
require_once 'db.php';
$u = current_user();
$base_path = get_app_base_path();

// Initials for avatar
$user_display_name = $u ? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : '';
if (!$user_display_name && $u) {
    $user_display_name = $u['username'];
}
$initials = '';
if ($u) {
    $words = explode(' ', $user_display_name);
    foreach ($words as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
    }
    $initials = substr($initials, 0, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SurveyHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-300: #cbd5e1;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.04);
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: var(--slate-800);
            margin: 0;
            padding: 32px 16px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--slate-200);
            padding: 36px 40px;
        }

        /* Top Navigation Bar */
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--slate-200);
            padding-bottom: 20px;
            margin-bottom: 32px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--slate-900);
            text-decoration: none;
            letter-spacing: -0.02em;
        }
        .brand-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.95rem;
            font-weight: 800;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-item {
            color: var(--slate-600);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
        }
        .nav-item:hover {
            color: var(--primary);
            background: var(--primary-light);
        }

        .user-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--slate-50);
            border: 1px solid var(--slate-200);
            padding: 4px 12px 4px 6px;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--slate-700);
        }
        .user-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #4338ca;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Standard Controls & Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary);
            color: #ffffff;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            box-shadow: var(--shadow-sm);
        }
        .btn:hover {
            background: var(--primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn:active { transform: translateY(0); }

        .btn-secondary {
            background: #ffffff;
            color: var(--slate-700);
            border: 1px solid var(--slate-300);
            box-shadow: var(--shadow-sm);
        }
        .btn-secondary:hover {
            background: var(--slate-50);
            border-color: var(--slate-400);
            color: var(--slate-900);
        }

        .btn-danger {
            background: var(--danger);
            color: #ffffff;
        }
        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-mini {
            padding: 4px 10px;
            font-size: 0.78rem;
            border-radius: var(--radius-sm);
        }

        /* Form Inputs */
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--slate-700);
            margin-bottom: 6px;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 10px 14px;
            font-family: inherit;
            font-size: 0.95rem;
            color: var(--slate-800);
            background: #ffffff;
            border: 1.5px solid var(--slate-300);
            border-radius: var(--radius-sm);
            transition: all 0.15s ease;
            outline: none;
        }
        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        textarea:focus,
        select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        /* Alerts & Callouts */
        .alert {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-size: 0.92rem;
            font-weight: 500;
        }
        .alert-success {
            background: var(--success-light);
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: var(--danger-light);
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Cards & Grouping */
        .card {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        /* Modern Tables */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 16px;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        th {
            background: var(--slate-50);
            color: var(--slate-600);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 12px 16px;
            border-bottom: 1px solid var(--slate-200);
            text-align: left;
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--slate-200);
            font-size: 0.9rem;
            color: var(--slate-700);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #fafbfd; }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-red { background: #fee2e2; color: #991b1b; }

        code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85em;
            background: var(--slate-100);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--slate-800);
            border: 1px solid var(--slate-200);
        }

        /* Option Tile Cards (Radio & Checkbox selections) */
        .option-tile {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin-bottom: 8px;
            background: #ffffff;
            border: 1.5px solid var(--slate-200);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        .option-tile:hover {
            border-color: var(--slate-400);
            background: var(--slate-50);
        }
        .option-tile input[type="radio"],
        .option-tile input[type="checkbox"] {
            margin: 0;
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .option-tile.selected {
            border-color: var(--primary);
            background: #f0f7ff;
        }
    </style>
</head>
<body>
<div class="container">
    <nav>
        <a href="<?= $base_path ?>/index.php" class="brand-logo">
            <span class="brand-icon">S</span>
            <span>SurveyHub</span>
        </a>
        <div class="nav-links">
            <?php if ($u): ?>
                <div class="user-pill">
                    <span class="user-avatar"><?= $initials ?></span>
                    <span><?= htmlspecialchars($user_display_name) ?></span>
                </div>
                <?php if (!empty($u['can_create_surveys'])): ?>
                    <a href="<?= $base_path ?>/create_survey.php" class="nav-item">Create Survey</a>
                <?php endif; ?>
                <?php if (!empty($u['can_manage_users'])): ?>
                    <a href="<?= $base_path ?>/users.php" class="nav-item">Manage Users</a>
                <?php endif; ?>
                <a href="<?= $base_path ?>/logout.php" class="nav-item" style="color: var(--danger);">Logout</a>
            <?php else: ?>
                <a href="<?= $base_path ?>/login.php" class="btn btn-secondary btn-mini">Log In</a>
            <?php endif; ?>
        </div>
    </nav>
