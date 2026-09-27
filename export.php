<?php
// export.php
require_once 'db.php';
require_login();

$u = current_user();

if (empty($u['can_export_results'])) {
    http_response_code(403);
    die("Access Denied: You do not possess export privileges.");
}

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE slug = ?");
$stmt->execute([$slug]);
$survey = $stmt->fetch();

if (!$survey) {
    http_response_code(404);
    die("Survey not found.");
}

$can_view = $survey['results_open'] || !empty($u['can_view_all_results']) || ($survey['user_id'] == $u['id']);
if (!$can_view) {
    http_response_code(403);
    die("Access Denied: You cannot view results for this survey.");
}

$can_see_identities = !empty($u['can_view_respondent_identities']);

$q_stmt = $pdo->prepare("SELECT id, question_text FROM questions WHERE survey_id = ? ORDER BY sort_order ASC");
$q_stmt->execute([$survey['id']]);
$questions = $q_stmt->fetchAll();

$r_stmt = $pdo->prepare("SELECT id, respondent_username, submitted_at FROM responses WHERE survey_id = ? ORDER BY id ASC");
$r_stmt->execute([$survey['id']]);
$responses = $r_stmt->fetchAll();

$a_stmt = $pdo->prepare("
    SELECT response_id, question_id, GROUP_CONCAT(answer_text, '; ') AS answer_text 
    FROM answers 
    WHERE response_id IN (SELECT id FROM responses WHERE survey_id = ?)
    GROUP BY response_id, question_id
");
$a_stmt->execute([$survey['id']]);
$all_answers = $a_stmt->fetchAll();

$answer_matrix = [];
foreach ($all_answers as $ans) {
    $answer_matrix[$ans['response_id']][$ans['question_id']] = $ans['answer_text'];
}

$filename = "survey_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $survey['slug']) . "_results_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // Excel UTF-8 BOM

// Build Headers
$headers = ['Response ID', 'Submitted At', 'Respondent (Windows SSO)'];
foreach ($questions as $q) {
    $headers[] = $q['question_text'];
}
fputcsv($output, $headers);

// Build Rows
foreach ($responses as $r) {
    $respondent_label = $can_see_identities 
        ? ($r['respondent_username'] ?: 'Unauthenticated Guest') 
        : '[Identity Hidden]';

    $row = [
        $r['id'],
        $r['submitted_at'],
        $respondent_label
    ];

    foreach ($questions as $q) {
        $row[] = $answer_matrix[$r['id']][$q['id']] ?? '';
    }
    fputcsv($output, $row);
}

fclose($output);
exit;