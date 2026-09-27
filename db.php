<?php
// db.php
session_start();

$db_path = __DIR__ . '/surveys.db';
$is_new = !file_exists($db_path);

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    if ($is_new) {
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                first_name TEXT DEFAULT '',
                last_name TEXT DEFAULT '',
                email TEXT DEFAULT '',
                can_create_surveys INTEGER NOT NULL DEFAULT 0,
                can_view_all_results INTEGER NOT NULL DEFAULT 0,
                can_export_results INTEGER NOT NULL DEFAULT 0,
                can_view_respondent_identities INTEGER NOT NULL DEFAULT 0,
                can_manage_users INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE surveys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                slug TEXT UNIQUE NOT NULL,
                results_open INTEGER NOT NULL DEFAULT 0,
                allow_multiple INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                survey_id INTEGER NOT NULL,
                question_text TEXT NOT NULL,
                type TEXT NOT NULL,
                is_required INTEGER NOT NULL DEFAULT 0,
                options_json TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                parent_question_id INTEGER DEFAULT NULL,
                condition_value TEXT DEFAULT NULL,
                FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE,
                FOREIGN KEY (parent_question_id) REFERENCES questions(id) ON DELETE SET NULL
            );

            CREATE TABLE responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                survey_id INTEGER NOT NULL,
                respondent_username TEXT DEFAULT NULL,
                ip_address TEXT DEFAULT NULL,
                device_token TEXT DEFAULT NULL,
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
            );

            CREATE TABLE answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                response_id INTEGER NOT NULL,
                question_id INTEGER NOT NULL,
                answer_text TEXT,
                FOREIGN KEY (response_id) REFERENCES responses(id) ON DELETE CASCADE,
                FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
            );
        ");

        $stmt = $pdo->prepare("
            INSERT INTO users (
                username, password_hash, first_name, last_name, email,
                can_create_surveys, can_view_all_results, can_export_results,
                can_view_respondent_identities, can_manage_users
            ) VALUES (?, ?, 'System', 'Administrator', 'admin@local.test', 1, 1, 1, 1, 1)
        ");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    } else {
        // Safe auto-migration for existing databases
        $survey_cols = $pdo->query("PRAGMA table_info(surveys)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('allow_multiple', $survey_cols)) {
            $pdo->exec("ALTER TABLE surveys ADD COLUMN allow_multiple INTEGER NOT NULL DEFAULT 1");
        }

        $response_cols = $pdo->query("PRAGMA table_info(responses)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('ip_address', $response_cols)) {
            $pdo->exec("ALTER TABLE responses ADD COLUMN ip_address TEXT DEFAULT NULL");
        }
        if (!in_array('device_token', $response_cols)) {
            $pdo->exec("ALTER TABLE responses ADD COLUMN device_token TEXT DEFAULT NULL");
        }
        if (!in_array('respondent_username', $response_cols)) {
            $pdo->exec("ALTER TABLE responses ADD COLUMN respondent_username TEXT DEFAULT NULL");
        }

        $user_cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('first_name', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN first_name TEXT DEFAULT ''");
        }
        if (!in_array('last_name', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_name TEXT DEFAULT ''");
        }
        if (!in_array('email', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT DEFAULT ''");
        }
        if (!in_array('can_export_results', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN can_export_results INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('can_view_respondent_identities', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN can_view_respondent_identities INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('can_manage_users', $user_cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN can_manage_users INTEGER NOT NULL DEFAULT 0");
            $pdo->exec("UPDATE users SET can_manage_users = 1 WHERE username = 'admin'");
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }
}

function get_app_base_path() {
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script_name));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

function get_base_url() {
    $is_https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $is_https = true;
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $is_https = true;
    } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $is_https = true;
    }

    $scheme = $is_https ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    return $scheme . $host . get_app_base_path();
}

/**
 * Returns the best available client IP address, handling proxies and IIS headers.
 */
function get_client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}