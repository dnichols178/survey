<?php
// results.php
require_once 'db.php';

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE slug = ?");
$stmt->execute([$slug]);
$survey = $stmt->fetch();

if (!$survey) {
    http_response_code(404);
    die("Survey not found.");
}

$u = current_user();

// Permissions matrix for viewing aggregate results
$can_view = false;
if (!empty($survey['results_open'])) {
    $can_view = true;
} elseif ($u) {
    if (!empty($u['can_view_all_results']) || $survey['user_id'] == $u['id']) {
        $can_view = true;
    }
}

if (!$can_view) {
    http_response_code(403);
    die("Access Denied: Results for this survey are restricted. Please sign in with an authorized account.");
}

$can_export = $u && !empty($u['can_export_results']);
$can_see_identities = $u && !empty($u['can_view_respondent_identities']);

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM responses WHERE survey_id = ?");
$count_stmt->execute([$survey['id']]);
$total_responses = (int)$count_stmt->fetchColumn();

$q_stmt = $pdo->prepare("SELECT * FROM questions WHERE survey_id = ? ORDER BY sort_order ASC");
$q_stmt->execute([$survey['id']]);
$questions = $q_stmt->fetchAll();

$base_path = get_app_base_path();
$base_url  = get_base_url();

$relative_survey_url = $base_path . '/survey/' . urlencode($survey['slug']);
$full_survey_url     = $base_url . '/survey/' . urlencode($survey['slug']);

// Storage for client-side Chart.js payload
$charts_payload = [];

include 'header.php';
?>

<!-- Load Chart.js for data visualization -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
.metric-callout {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-left: 4px solid #16a34a;
    padding: 10px 14px;
    border-radius: 4px;
    color: #166534;
    font-size: 0.95em;
    margin-bottom: 16px;
    display: inline-block;
}
.chart-box {
    position: relative;
    max-width: 100%;
    height: 240px;
    margin: 16px 0 20px 0;
}
.summary-table th { background: #f1f5f9; font-size: 0.85em; text-transform: uppercase; color: #475569; }
.summary-table td { font-size: 0.9em; }
</style>

<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="margin-bottom: 6px;">Results: <?= htmlspecialchars($survey['title']) ?></h2>
        <p style="margin: 0 0 6px 0;">
            <strong>Total Submissions:</strong> <span style="font-size: 1.1em; color: #2563eb; font-weight: bold;"><?= $total_responses ?></span>
        </p>
        <p style="margin: 0 0 16px 0; color: #64748b; font-size: 0.9em;">
            Survey Link: <a href="<?= htmlspecialchars($relative_survey_url) ?>" target="_blank"><?= htmlspecialchars($full_survey_url) ?></a>
        </p>
    </div>

    <?php if ($can_export && $total_responses > 0): ?>
        <div>
            <a href="export.php?slug=<?= urlencode($survey['slug']) ?>" class="btn" style="background-color: #059669;">
                &darr; Export Results (CSV)
            </a>
        </div>
    <?php endif; ?>
</div>

<hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0 24px 0;">

<?php if ($total_responses === 0): ?>
    <div class="alert alert-error">No responses have been submitted for this survey yet.</div>
<?php else: ?>
    <?php foreach ($questions as $idx => $q): ?>
        <div class="q-card" style="background: #ffffff; border: 1px solid #cbd5e1; margin-bottom: 24px; padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                <h3 style="margin: 0; font-size: 1.15em; color: #0f172a;">
                    Q<?= $idx + 1 ?>: <?= htmlspecialchars($q['question_text']) ?>
                    <?php if ($q['is_required']): ?>
                        <span style="color: #dc2626; font-size: 0.9em;">*</span>
                    <?php endif; ?>
                </h3>
                <span style="font-size: 0.8em; color: #64748b; text-transform: uppercase; font-weight: bold;">
                    <?= htmlspecialchars($q['type']) ?>
                    <?php if (!empty($q['parent_question_id'])): ?>
                        | (Conditional)
                    <?php endif; ?>
                </span>
            </div>

            <!-- TYPE: RADIO, CHECKBOX, DROPDOWN -->
            <?php if (in_array($q['type'], ['radio', 'checkbox', 'dropdown'])): ?>
                <?php
                $ans_stmt = $pdo->prepare("
                    SELECT answer_text, COUNT(*) AS count 
                    FROM answers 
                    WHERE question_id = ? 
                    GROUP BY answer_text 
                    ORDER BY count DESC
                ");
                $ans_stmt->execute([$q['id']]);
                $aggregates = $ans_stmt->fetchAll();

                $top_choice = null;
                $top_pct = 0;
                $chart_labels = [];
                $chart_data = [];

                foreach ($aggregates as $row) {
                    $lbl = $row['answer_text'] !== '' ? $row['answer_text'] : '(Blank)';
                    $cnt = (int)$row['count'];
                    $pct = $total_responses > 0 ? round(($cnt / $total_responses) * 100, 1) : 0;
                    
                    if ($top_choice === null && $row['answer_text'] !== '') {
                        $top_choice = $lbl;
                        $top_pct = $pct;
                    }

                    $chart_labels[] = $lbl;
                    $chart_data[] = $cnt;
                }

                $chart_canvas_id = "chart_q_" . $q['id'];
                if (!empty($chart_data)) {
                    $charts_payload[] = [
                        'id' => $chart_canvas_id,
                        'type' => 'bar',
                        'labels' => $chart_labels,
                        'data' => $chart_data,
                        'datasetLabel' => 'Votes'
                    ];
                }
                ?>

                <?php if ($top_choice !== null): ?>
                    <div class="metric-callout">
                        &bull; <strong><?= $top_pct ?>%</strong> of respondents selected <strong>"<?= htmlspecialchars($top_choice) ?>"</strong>
                    </div>
                <?php endif; ?>

                <?php if (!empty($chart_data)): ?>
                    <div class="chart-box">
                        <canvas id="<?= $chart_canvas_id ?>"></canvas>
                    </div>
                <?php endif; ?>

                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Option / Choice</th>
                            <th style="width: 120px; text-align: right;">Raw Responses</th>
                            <th style="width: 120px; text-align: right;">Total Surveyed</th>
                            <th style="width: 110px; text-align: right;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($aggregates)): ?>
                            <tr>
                                <td colspan="4" style="color: #94a3b8; font-style: italic;">No answers recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($aggregates as $row): ?>
                                <?php $pct = round(($row['count'] / $total_responses) * 100, 1); ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['answer_text'] !== '' ? $row['answer_text'] : '(Blank)') ?></strong></td>
                                    <td style="text-align: right;"><?= (int)$row['count'] ?></td>
                                    <td style="text-align: right;"><?= $total_responses ?></td>
                                    <td style="text-align: right;"><strong><?= $pct ?>%</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

            <!-- TYPE: SCALE / SLIDER -->
            <?php elseif ($q['type'] === 'scale'): ?>
                <?php
                $num_stmt = $pdo->prepare("
                    SELECT CAST(answer_text AS INTEGER) AS score, COUNT(*) AS count 
                    FROM answers 
                    WHERE question_id = ? AND answer_text != ''
                    GROUP BY score 
                    ORDER BY score ASC
                ");
                $num_stmt->execute([$q['id']]);
                $score_rows = $num_stmt->fetchAll();

                $scale_cfg = json_decode($q['options_json'] ?? '{}', true) ?: [];
                $min = isset($scale_cfg['min']) ? (int)$scale_cfg['min'] : 1;
                $max = isset($scale_cfg['max']) ? (int)$scale_cfg['max'] : 10;
                if ($max <= $min) { $max = $min + 1; }

                $range_span = $max - $min;
                $total_score_sum = 0;
                $score_responses_count = 0;
                $distribution = [];

                if ($range_span <= 20) {
                    for ($i = $min; $i <= $max; $i++) {
                        $distribution[$i] = 0;
                    }
                }

                foreach ($score_rows as $sr) {
                    $sc = (int)$sr['score'];
                    $cnt = (int)$sr['count'];
                    $distribution[$sc] = $cnt;
                    $total_score_sum += ($sc * $cnt);
                    $score_responses_count += $cnt;
                }
                ksort($distribution);

                $avg = $score_responses_count > 0 ? round($total_score_sum / $score_responses_count, 2) : 0;
                $chart_canvas_id = "chart_q_" . $q['id'];

                $scale_labels = array_map(function($v) { return (string)$v; }, array_keys($distribution));
                $scale_values = array_values($distribution);

                $charts_payload[] = [
                    'id' => $chart_canvas_id,
                    'type' => 'bar',
                    'labels' => $scale_labels,
                    'data' => $scale_values,
                    'datasetLabel' => 'Ratings'
                ];
                ?>

                <div class="metric-callout">
                    &bull; Average Score: <strong><?= $avg ?></strong> / <?= $max ?> 
                    (Based on <strong><?= $score_responses_count ?></strong> responses out of <?= $total_responses ?> total participants)
                </div>

                <div class="chart-box">
                    <canvas id="<?= $chart_canvas_id ?>"></canvas>
                </div>

                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Score Level</th>
                            <th style="width: 120px; text-align: right;">Raw Responses</th>
                            <th style="width: 120px; text-align: right;">Total Surveyed</th>
                            <th style="width: 110px; text-align: right;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distribution as $score_val => $vote_cnt): ?>
                            <?php $pct = $total_responses > 0 ? round(($vote_cnt / $total_responses) * 100, 1) : 0; ?>
                            <tr>
                                <td><strong>Score: <?= $score_val ?></strong></td>
                                <td style="text-align: right;"><?= $vote_cnt ?></td>
                                <td style="text-align: right;"><?= $total_responses ?></td>
                                <td style="text-align: right;"><strong><?= $pct ?>%</strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <!-- TYPE: RANKING -->
            <?php elseif ($q['type'] === 'ranking'): ?>
                <?php
                $rank_stmt = $pdo->prepare("SELECT answer_text FROM answers WHERE question_id = ? AND answer_text != ''");
                $rank_stmt->execute([$q['id']]);
                $rank_answers = $rank_stmt->fetchAll(PDO::FETCH_COLUMN);

                $items_configured = json_decode($q['options_json'] ?? '[]', true) ?: [];
                $total_items = count($items_configured);

                $scores = [];
                $position_counts = [];
                foreach ($items_configured as $it) {
                    $scores[$it] = 0;
                    $position_counts[$it] = array_fill(1, $total_items, 0);
                }

                $total_ranking_submissions = count($rank_answers);

                foreach ($rank_answers as $ans_str) {
                    $slots = explode('|', $ans_str);
                    foreach ($slots as $slot) {
                        $slot = trim($slot);
                        if (preg_match('/^(\d+)\.\s*(.+)$/', $slot, $m)) {
                            $pos = (int)$m[1];
                            $val = trim($m[2]);
                            if (isset($scores[$val])) {
                                $weight = max(1, $total_items - $pos + 1);
                                $scores[$val] += $weight;
                                if (isset($position_counts[$val][$pos])) {
                                    $position_counts[$val][$pos]++;
                                }
                            }
                        }
                    }
                }

                arsort($scores);
                $top_ranked_item = key($scores);

                $chart_canvas_id = "chart_q_" . $q['id'];
                $charts_payload[] = [
                    'id' => $chart_canvas_id,
                    'type' => 'bar',
                    'labels' => array_keys($scores),
                    'data' => array_values($scores),
                    'datasetLabel' => 'Consensus Score (Borda Points)'
                ];
                ?>

                <?php if ($top_ranked_item): ?>
                    <div class="metric-callout">
                        &bull; Top Consensus Preference: <strong>"<?= htmlspecialchars($top_ranked_item) ?>"</strong> (Ranked #1 overall with <?= current($scores) ?> score points)
                    </div>
                <?php endif; ?>

                <div class="chart-box">
                    <canvas id="<?= $chart_canvas_id ?>"></canvas>
                </div>

                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Rank</th>
                            <th>Option Item</th>
                            <th style="width: 130px; text-align: right;">Score Points</th>
                            <th style="width: 140px; text-align: right;">Total Submissions</th>
                            <th style="width: 220px;">Position Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $final_rank = 1;
                        foreach ($scores as $item_name => $score_val): 
                        ?>
                            <tr>
                                <td>
                                    <span style="display:inline-block; width:22px; height:22px; line-height:22px; text-align:center; background:#2563eb; color:#fff; border-radius:50%; font-weight:bold; font-size:0.8em;">
                                        <?= $final_rank++ ?>
                                    </span>
                                </td>
                                <td><strong><?= htmlspecialchars($item_name) ?></strong></td>
                                <td style="text-align: right;"><?= $score_val ?> pts</td>
                                <td style="text-align: right;"><?= $total_ranking_submissions ?></td>
                                <td>
                                    <div style="font-size: 0.8em; color: #475569;">
                                        <?php 
                                        $breakdown_strs = [];
                                        foreach ($position_counts[$item_name] as $p => $c) {
                                            $breakdown_strs[] = "#{$p}: {$c}";
                                        }
                                        echo implode(' &bull; ', $breakdown_strs);
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <!-- TYPE: FREE TEXT -->
            <?php else: ?>
                <?php
                $text_stmt = $pdo->prepare("
                    SELECT a.response_id, a.answer_text, r.respondent_username, r.submitted_at 
                    FROM answers a 
                    JOIN responses r ON a.response_id = r.id 
                    WHERE a.question_id = ? 
                    ORDER BY r.id DESC
                ");
                $text_stmt->execute([$q['id']]);
                $text_rows = $text_stmt->fetchAll();
                $non_empty_count = 0;
                foreach ($text_rows as $row) {
                    if (trim($row['answer_text']) !== '') { $non_empty_count++; }
                }
                $response_rate = $total_responses > 0 ? round(($non_empty_count / $total_responses) * 100, 1) : 0;
                ?>

                <div class="metric-callout">
                    &bull; <strong><?= $non_empty_count ?></strong> out of <strong><?= $total_responses ?></strong> respondents (<?= $response_rate ?>%) answered this question.
                </div>

                <?php if (empty($text_rows)): ?>
                    <p style="color: #94a3b8; font-style: italic; margin: 8px 0;">No text entries recorded.</p>
                <?php else: ?>
                    <div style="max-height: 280px; overflow-y: auto; margin-top: 8px;">
                        <?php foreach ($text_rows as $tr): ?>
                            <?php if (trim($tr['answer_text']) === '') continue; ?>
                            <div style="padding: 10px 14px; margin-bottom: 8px; border-radius: 4px; background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div style="font-size: 0.95em; color: #0f172a; margin-bottom: 4px;">
                                    <?= nl2br(htmlspecialchars($tr['answer_text'])) ?>
                                </div>
                                <div style="font-size: 0.8em; color: #64748b; display: flex; justify-content: space-between;">
                                    <span>
                                        Respondent: 
                                        <?php if ($can_see_identities): ?>
                                            <strong style="color: #0f172a; background: #fef08a; padding: 1px 4px; border-radius: 2px;">
                                                <?= htmlspecialchars($tr['respondent_username'] ?: 'Unauthenticated Guest') ?>
                                            </strong>
                                        <?php else: ?>
                                            <em style="color: #94a3b8;">[Identity Hidden - Admin Privilege Required]</em>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        Ref #<?= (int)$tr['response_id'] ?> &bull; <?= htmlspecialchars($tr['submitted_at']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Initialize Chart.js graphs -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const payload = <?= json_encode($charts_payload) ?>;
        
        payload.forEach(cfg => {
            const ctx = document.getElementById(cfg.id);
            if (!ctx) return;

            new Chart(ctx, {
                type: cfg.type,
                data: {
                    labels: cfg.labels,
                    datasets: [{
                        label: cfg.datasetLabel,
                        data: cfg.data,
                        backgroundColor: 'rgba(37, 99, 235, 0.75)',
                        borderColor: '#1d4ed8',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        });
    });
    </script>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="index.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
</div>

<?php include 'footer.php'; ?>