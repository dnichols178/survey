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
    die("Access Denied: Results for this survey are restricted.");
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

$charts_payload = [];

include 'header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
.metric-ribbon {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-left: 4px solid #16a34a;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    color: #166534;
    font-size: 0.95rem;
    font-weight: 500;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.chart-container {
    position: relative;
    max-width: 100%;
    height: 250px;
    margin: 20px 0 24px 0;
}
.response-bubble {
    background: var(--slate-50);
    border: 1px solid var(--slate-200);
    border-radius: var(--radius-sm);
    padding: 12px 16px;
    margin-bottom: 10px;
    transition: all 0.15s ease;
}
.response-bubble:hover {
    border-color: var(--slate-300);
    background: #ffffff;
}
</style>

<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 28px;">
    <div>
        <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: var(--primary); letter-spacing: 0.05em; margin-bottom: 4px;">
            Survey Analytics
        </div>
        <h1 style="margin: 0 0 6px 0; font-size: 1.75rem; font-weight: 700; color: var(--slate-900);">
            <?= htmlspecialchars($survey['title']) ?>
        </h1>
        <div style="color: var(--slate-500); font-size: 0.9rem;">
            Public Link: <a href="<?= htmlspecialchars($relative_survey_url) ?>" target="_blank" style="color: var(--primary); text-decoration: underline;"><?= htmlspecialchars($full_survey_url) ?></a>
        </div>
    </div>

    <div style="display: flex; gap: 10px;">
        <?php if ($can_export && $total_responses > 0): ?>
            <a href="<?= $base_path ?>/export.php?slug=<?= urlencode($survey['slug']) ?>" class="btn" style="background: #059669;">
                &darr; Export CSV
            </a>
        <?php endif; ?>
        <a href="<?= $base_path ?>/index.php" class="btn btn-secondary">
            &larr; Dashboard
        </a>
    </div>
</div>

<!-- Overview Stats -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px;">
    <div class="card" style="padding: 18px 20px; margin-bottom: 0;">
        <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--slate-400);">Total Respondents</div>
        <div style="font-size: 2rem; font-weight: 800; color: var(--primary); margin-top: 4px;"><?= $total_responses ?></div>
    </div>
    <div class="card" style="padding: 18px 20px; margin-bottom: 0;">
        <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--slate-400);">Questions</div>
        <div style="font-size: 2rem; font-weight: 800; color: var(--slate-900); margin-top: 4px;"><?= count($questions) ?></div>
    </div>
</div>

<?php if ($total_responses === 0): ?>
    <div style="text-align: center; padding: 60px 20px; background: var(--slate-50); border: 1.5px dashed var(--slate-300); border-radius: var(--radius-md);">
        <div style="font-size: 2rem; margin-bottom: 12px;">⏳</div>
        <h3 style="margin: 0 0 6px 0; color: var(--slate-800);">Awaiting Submissions</h3>
        <p style="margin: 0; color: var(--slate-500); font-size: 0.9rem;">Responses will populate analytics and graphs automatically once submitted.</p>
    </div>
<?php else: ?>
    <?php foreach ($questions as $idx => $q): ?>
        <div class="card" style="margin-bottom: 28px; padding: 28px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; border-bottom: 1px solid var(--slate-200); padding-bottom: 12px;">
                <h3 style="margin: 0; font-size: 1.2rem; color: var(--slate-900); font-weight: 700;">
                    Q<?= $idx + 1 ?>: <?= htmlspecialchars($q['question_text']) ?>
                </h3>
                <span class="badge" style="background: var(--slate-100); color: var(--slate-600); text-transform: uppercase;">
                    <?= htmlspecialchars($q['type']) ?>
                </span>
            </div>

            <!-- CHOICE QUESTIONS -->
            <?php if (in_array($q['type'], ['radio', 'checkbox', 'dropdown'])): ?>
                <?php
                $ans_stmt = $pdo->prepare("SELECT answer_text, COUNT(*) AS count FROM answers WHERE question_id = ? GROUP BY answer_text ORDER BY count DESC");
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

                $chart_id = "chart_q_" . $q['id'];
                if (!empty($chart_data)) {
                    $charts_payload[] = [
                        'id' => $chart_id,
                        'type' => 'bar',
                        'labels' => $chart_labels,
                        'data' => $chart_data,
                        'datasetLabel' => 'Votes'
                    ];
                }
                ?>

                <?php if ($top_choice !== null): ?>
                    <div class="metric-ribbon">
                        <span>✨</span>
                        <div><strong><?= $top_pct ?>%</strong> of respondents selected <strong>"<?= htmlspecialchars($top_choice) ?>"</strong></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($chart_data)): ?>
                    <div class="chart-container">
                        <canvas id="<?= $chart_id ?>"></canvas>
                    </div>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>Option / Choice</th>
                            <th style="width: 140px; text-align: right;">Responses</th>
                            <th style="width: 140px; text-align: right;">Total Surveyed</th>
                            <th style="width: 120px; text-align: right;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aggregates as $row): ?>
                            <?php $pct = round(($row['count'] / $total_responses) * 100, 1); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['answer_text'] !== '' ? $row['answer_text'] : '(Blank)') ?></strong></td>
                                <td style="text-align: right; font-weight: 600;"><?= (int)$row['count'] ?></td>
                                <td style="text-align: right; color: var(--slate-400);"><?= $total_responses ?></td>
                                <td style="text-align: right; color: var(--primary); font-weight: 700;"><?= $pct ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <!-- SCALE QUESTIONS -->
            <?php elseif ($q['type'] === 'scale'): ?>
                <?php
                $num_stmt = $pdo->prepare("SELECT CAST(answer_text AS INTEGER) AS score, COUNT(*) AS count FROM answers WHERE question_id = ? AND answer_text != '' GROUP BY score ORDER BY score ASC");
                $num_stmt->execute([$q['id']]);
                $score_rows = $num_stmt->fetchAll();

                $scale_cfg = json_decode($q['options_json'] ?? '{}', true) ?: [];
                $min = isset($scale_cfg['min']) ? (int)$scale_cfg['min'] : 1;
                $max = isset($scale_cfg['max']) ? (int)$scale_cfg['max'] : 10;
                if ($max <= $min) $max = $min + 1;

                $total_score_sum = 0;
                $score_responses_count = 0;
                $distribution = [];

                if (($max - $min) <= 20) {
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
                $chart_id = "chart_q_" . $q['id'];

                $charts_payload[] = [
                    'id' => $chart_id,
                    'type' => 'bar',
                    'labels' => array_map(function($v) { return (string)$v; }, array_keys($distribution)),
                    'data' => array_values($distribution),
                    'datasetLabel' => 'Votes'
                ];
                ?>

                <div class="metric-ribbon">
                    <span>⭐</span>
                    <div>Average Rating: <strong><?= $avg ?></strong> out of <?= $max ?> (<?= $score_responses_count ?> responses)</div>
                </div>

                <div class="chart-container">
                    <canvas id="<?= $chart_id ?>"></canvas>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 120px;">Score Level</th>
                            <th style="width: 140px; text-align: right;">Responses</th>
                            <th style="width: 140px; text-align: right;">Total Surveyed</th>
                            <th style="width: 120px; text-align: right;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distribution as $score_val => $vote_cnt): ?>
                            <?php $pct = $total_responses > 0 ? round(($vote_cnt / $total_responses) * 100, 1) : 0; ?>
                            <tr>
                                <td><strong>Rating <?= $score_val ?></strong></td>
                                <td style="text-align: right; font-weight: 600;"><?= $vote_cnt ?></td>
                                <td style="text-align: right; color: var(--slate-400);"><?= $total_responses ?></td>
                                <td style="text-align: right; color: var(--primary); font-weight: 700;"><?= $pct ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <!-- RANKING QUESTIONS -->
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

                $chart_id = "chart_q_" . $q['id'];
                $charts_payload[] = [
                    'id' => $chart_id,
                    'type' => 'bar',
                    'labels' => array_keys($scores),
                    'data' => array_values($scores),
                    'datasetLabel' => 'Consensus Borda Points'
                ];
                ?>

                <?php if ($top_ranked_item): ?>
                    <div class="metric-ribbon">
                        <span>🏆</span>
                        <div>Top Ranked Choice: <strong>"<?= htmlspecialchars($top_ranked_item) ?>"</strong> (Ranked #1 overall with <?= current($scores) ?> consensus points)</div>
                    </div>
                <?php endif; ?>

                <div class="chart-container">
                    <canvas id="<?= $chart_id ?>"></canvas>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 70px;">Rank</th>
                            <th>Choice Item</th>
                            <th style="width: 140px; text-align: right;">Borda Score</th>
                            <th style="width: 250px;">Position Distribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $final_rank = 1;
                        foreach ($scores as $item_name => $score_val): 
                        ?>
                            <tr>
                                <td>
                                    <span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; background:var(--primary); color:#fff; border-radius:50%; font-weight:700; font-size:0.8rem;">
                                        <?= $final_rank++ ?>
                                    </span>
                                </td>
                                <td><strong><?= htmlspecialchars($item_name) ?></strong></td>
                                <td style="text-align: right; font-weight: 700; color: var(--slate-900);"><?= $score_val ?> pts</td>
                                <td>
                                    <div style="font-size: 0.8rem; color: var(--slate-600);">
                                        <?php 
                                        $breakdown_strs = [];
                                        foreach ($position_counts[$item_name] as $p => $c) {
                                            $breakdown_strs[] = "#{$p}: <strong>{$c}</strong>";
                                        }
                                        echo implode(' &bull; ', $breakdown_strs);
                                        ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <!-- FREE TEXT QUESTIONS -->
            <?php else: ?>
                <?php
                $text_stmt = $pdo->prepare("SELECT a.response_id, a.answer_text, r.respondent_username, r.submitted_at FROM answers a JOIN responses r ON a.response_id = r.id WHERE a.question_id = ? ORDER BY r.id DESC");
                $text_stmt->execute([$q['id']]);
                $text_rows = $text_stmt->fetchAll();

                $non_empty_count = 0;
                foreach ($text_rows as $row) {
                    if (trim($row['answer_text']) !== '') $non_empty_count++;
                }
                $response_rate = $total_responses > 0 ? round(($non_empty_count / $total_responses) * 100, 1) : 0;
                ?>

                <div class="metric-ribbon">
                    <span>💬</span>
                    <div><strong><?= $non_empty_count ?></strong> of <?= $total_responses ?> participants (<?= $response_rate ?>%) responded with comments.</div>
                </div>

                <div style="max-height: 320px; overflow-y: auto; padding-right: 4px;">
                    <?php foreach ($text_rows as $tr): ?>
                        <?php if (trim($tr['answer_text']) === '') continue; ?>
                        <div class="response-bubble">
                            <div style="font-size: 0.95rem; color: var(--slate-900); margin-bottom: 6px;">
                                <?= nl2br(htmlspecialchars($tr['answer_text'])) ?>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--slate-400);">
                                <span>
                                    <?php if ($can_see_identities): ?>
                                        Respondent: <strong style="color: var(--slate-700);"><?= htmlspecialchars($tr['respondent_username'] ?: 'Guest') ?></strong>
                                    <?php else: ?>
                                        <em>[Identity Protected]</em>
                                    <?php endif; ?>
                                </span>
                                <span><?= date('M j, Y g:ia', strtotime($tr['submitted_at'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

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
                        backgroundColor: 'rgba(37, 99, 235, 0.85)',
                        borderColor: '#2563eb',
                        borderWidth: 1.5,
                        borderRadius: 6
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

<?php include 'footer.php'; ?>
