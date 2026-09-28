<?php
// users.php
require_once 'db.php';
require_login();

$u = current_user();
if (empty($u['can_manage_users'])) {
    http_response_code(403);
    die("Access Denied: You lack permissions to manage user accounts.");
}

$msg = '';
$err = '';

// Handle Create / Edit Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username   = strtolower(trim($_POST['username'] ?? ''));
        $password   = $_POST['password'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');

        $can_create     = isset($_POST['can_create_surveys']) ? 1 : 0;
        $can_view_own   = isset($_POST['can_view_own_results']) ? 1 : 0;
        $can_view_all   = isset($_POST['can_view_all_results']) ? 1 : 0;
        $can_export     = isset($_POST['can_export_results']) ? 1 : 0;
        $can_identities = isset($_POST['can_view_respondent_identities']) ? 1 : 0;
        $can_manage     = isset($_POST['can_manage_users']) ? 1 : 0;

        if (!$username || !$password) {
            $err = 'Username and Password are required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        username, password_hash, first_name, last_name, email,
                        can_create_surveys, can_view_own_results, can_view_all_results,
                        can_export_results, can_view_respondent_identities, can_manage_users
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $first_name,
                    $last_name,
                    $email,
                    $can_create,
                    $can_view_own,
                    $can_view_all,
                    $can_export,
                    $can_identities,
                    $can_manage
                ]);
                $msg = "User '{$username}' created successfully.";
            } catch (PDOException $e) {
                $err = 'Error creating user: Username may already exist.';
            }
        }
    } elseif ($action === 'update') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';

        $can_create     = isset($_POST['can_create_surveys']) ? 1 : 0;
        $can_view_own   = isset($_POST['can_view_own_results']) ? 1 : 0;
        $can_view_all   = isset($_POST['can_view_all_results']) ? 1 : 0;
        $can_export     = isset($_POST['can_export_results']) ? 1 : 0;
        $can_identities = isset($_POST['can_view_respondent_identities']) ? 1 : 0;
        $can_manage     = isset($_POST['can_manage_users']) ? 1 : 0;

        // Prevent self-lockout from user management
        if ($user_id === (int)$u['id']) {
            $can_manage = 1;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    first_name = ?,
                    last_name = ?,
                    email = ?,
                    can_create_surveys = ?,
                    can_view_own_results = ?,
                    can_view_all_results = ?,
                    can_export_results = ?,
                    can_view_respondent_identities = ?,
                    can_manage_users = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $first_name,
                $last_name,
                $email,
                $can_create,
                $can_view_own,
                $can_view_all,
                $can_export,
                $can_identities,
                $can_manage,
                $user_id
            ]);

            // Update password if specified
            if (!empty($password)) {
                $pwd_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $pwd_stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]);
            }

            $msg = 'User account updated successfully.';
        } catch (PDOException $e) {
            $err = 'Error updating user: ' . htmlspecialchars($e->getMessage());
        }
    } elseif ($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id === (int)$u['id']) {
            $err = 'You cannot delete your own account.';
        } else {
            $del_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $del_stmt->execute([$user_id]);
            $msg = 'User deleted successfully.';
        }
    }
}

// Fetch all existing users
$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();

$edit_id = (int)($_GET['edit'] ?? 0);
$editing_user = null;
if ($edit_id > 0) {
    foreach ($users as $candidate) {
        if ($candidate['id'] == $edit_id) {
            $editing_user = $candidate;
            break;
        }
    }
}

include 'header.php';
?>

<h2>User &amp; Permission Management</h2>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Create / Edit Form -->
<div class="q-card" style="background: #ffffff; border: 1px solid #cbd5e1;">
    <h3><?= $editing_user ? 'Edit User: ' . htmlspecialchars($editing_user['username']) : 'Create New User Account' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editing_user ? 'update' : 'create' ?>">
        <?php if ($editing_user): ?>
            <input type="hidden" name="user_id" value="<?= $editing_user['id'] ?>">
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" value="<?= htmlspecialchars($editing_user['first_name'] ?? '') ?>" placeholder="John">
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($editing_user['last_name'] ?? '') ?>" placeholder="Doe">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>Username <?= $editing_user ? '(Cannot be changed)' : '*' ?></label>
                <input type="text" name="username" value="<?= htmlspecialchars($editing_user['username'] ?? '') ?>" <?= $editing_user ? 'disabled style="background:#f1f5f9;"' : 'required' ?>>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($editing_user['email'] ?? '') ?>" placeholder="john.doe@company.local">
            </div>
        </div>

        <div class="form-group">
            <label><?= $editing_user ? 'Reset Password (leave blank to keep current)' : 'Password *' ?></label>
            <input type="password" name="password" <?= $editing_user ? '' : 'required' ?>>
        </div>

        <div class="form-group" style="margin-top: 16px;">
            <label style="margin-bottom: 8px;">Role Privileges:</label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_create_surveys" value="1" <?= (!empty($editing_user['can_create_surveys'])) ? 'checked' : '' ?>>
                    Create &amp; edit surveys
                </label>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_view_own_results" value="1" <?= (!empty($editing_user['can_view_own_results'])) ? 'checked' : '' ?>>
                    View own survey results
                </label>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_view_all_results" value="1" <?= (!empty($editing_user['can_view_all_results'])) ? 'checked' : '' ?>>
                    View all survey results
                </label>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_export_results" value="1" <?= (!empty($editing_user['can_export_results'])) ? 'checked' : '' ?>>
                    Export results to CSV
                </label>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_view_respondent_identities" value="1" <?= (!empty($editing_user['can_view_respondent_identities'])) ? 'checked' : '' ?>>
                    View SSO respondent identities
                </label>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="can_manage_users" value="1" <?= (!empty($editing_user['can_manage_users'])) ? 'checked' : '' ?> <?= ($editing_user && $editing_user['id'] == $u['id']) ? 'disabled' : '' ?>>
                    Manage user accounts &amp; roles
                </label>
            </div>
        </div>

        <div style="margin-top: 16px;">
            <button type="submit" class="btn"><?= $editing_user ? 'Update User' : 'Create User' ?></button>
            <?php if ($editing_user): ?>
                <a href="users.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Existing Users Table -->
<h3>Existing Users (<?= count($users) ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Email</th>
            <th>Privileges</th>
            <th style="width: 140px;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $usr): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars(trim(($usr['first_name'] ?? '') . ' ' . ($usr['last_name'] ?? '')) ?: '—') ?></strong>
                </td>
                <td><code><?= htmlspecialchars($usr['username']) ?></code></td>
                <td><?= htmlspecialchars($usr['email'] ?: '—') ?></td>
                <td>
                    <?php if (!empty($usr['can_create_surveys'])): ?><span class="badge">Create Surveys</span><?php endif; ?>
                    <?php if (!empty($usr['can_view_own_results'])): ?><span class="badge">View Own Results</span><?php endif; ?>
                    <?php if (!empty($usr['can_view_all_results'])): ?><span class="badge">View All Results</span><?php endif; ?>
                    <?php if (!empty($usr['can_export_results'])): ?><span class="badge">Export CSV</span><?php endif; ?>
                    <?php if (!empty($usr['can_view_respondent_identities'])): ?><span class="badge">View Identities</span><?php endif; ?>
                    <?php if (!empty($usr['can_manage_users'])): ?><span class="badge" style="background:#fee2e2; color:#991b1b;">Admin</span><?php endif; ?>
                </td>
                <td>
                    <a href="users.php?edit=<?= $usr['id'] ?>">Edit</a>
                    <?php if ($usr['id'] != $u['id']): ?>
                        | 
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                            <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; padding:0; text-decoration:underline;">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include 'footer.php'; ?>
