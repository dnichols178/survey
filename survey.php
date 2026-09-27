<?php
// survey.php
require_once 'db.php';

$slug =$_GET['slug'] ?? '';
$stmt =$pdo->prepare("SELECT * FROM surveys WHERE slug = ?");
$stmt->execute([$slug]);
$survey =$stmt->fetch();

if (!$survey) {
    http_response_code(404);
    die("Survey not found.");
}

$q_stmt =$pdo->prepare("SELECT * FROM questions WHERE survey_id = ? ORDER BY sort_order ASC");
$q_stmt->execute([$survey['id']]);
$questions =$q_stmt->fetchAll();

$base_path = get_app_base_path();$survey_action_url = $base_path . '/survey/' . urlencode($survey['slug']);

$client_ip = get_client_ip();$cookie_name = "survey_taken_" . $survey['id'];$device_cookie = $_COOKIE[$cookie_name] ?? null;

$captured_identity = null;
$raw_auth =$_SERVER['REMOTE_USER'] ?? $_SERVER['AUTH_USER'] ?? $_SERVER['LOGON_USER'] ?? null;
if (!empty($raw_auth)) {
    $parts = explode('\\',$raw_auth);
    $captured_identity = strtolower(end($parts));
} elseif (isset($_SESSION['user']['username'])) {
    $captured_identity = strtolower($_SESSION['user']['username']);
}

// Enforce One Survey Per Person policy
$already_completed = false;
if (empty($survey['allow_multiple'])) {
    if (!empty($device_cookie)) {$already_completed = true;
    }
    if (!$already_completed && !empty($captured_identity)) {
        $chk_user =$pdo->prepare("SELECT id FROM responses WHERE survey_id = ? AND respondent_username = ? LIMIT 1");
        $chk_user->execute([$survey['id'],$captured_identity]);
        if ($chk_user->fetch()) {$already_completed = true;
        }
    }
    if (!$already_completed && !empty($client_ip) &&$client_ip !== 'UNKNOWN') {
        $chk_ip =$pdo->prepare("SELECT id FROM responses WHERE survey_id = ? AND ip_address = ? LIMIT 1");
        $chk_ip->execute([$survey['id'],$client_ip]);
        if ($chk_ip->fetch()) {$already_completed = true;
        }
    }
}

$errors = [];$success = false;

function is_question_active($q,$all_answers) {
    if (empty($q['parent_question_id'])) {         return true;     }$parent_val = $all_answers[$q['parent_question_id']] ?? null;
    $target_val = trim($q['condition_value']);

    if (is_array($parent_val)) {
        return in_array($target_val,$parent_val);
    }
    return (string)$parent_val === (string)$target_val;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($already_completed) {$errors[] = "You have already completed this survey. Multiple submissions are not allowed.";
    } else {
        $submitted_answers =$_POST['answers'] ?? [];

        foreach ($questions as $q) {$qid = $q['id'];$val = $submitted_answers[$qid] ?? null;
            $active = is_question_active($q,$submitted_answers);

            if ($active &&$q['is_required']) {
 if ($val === null || (is_string($val) && trim($val) === '') || (is_array($val) && empty($val))) {
    $errors[] = "The question '" . htmlspecialchars($q['question_text']) . "' is required.";
                }
            }
        }

        if (empty($errors)) {$pdo->beginTransaction();
            try {
                $token = bin2hex(random_bytes(16));
                $r_stmt =$pdo->prepare("INSERT INTO responses (survey_id, respondent_username, ip_address, device_token) VALUES (?, ?, ?, ?)");
                $r_stmt->execute([$survey['id'],$captured_identity, $client_ip,$token]);
                $response_id =$pdo->lastInsertId();

                $a_stmt =$pdo->prepare("INSERT INTO answers (response_id, question_id, answer_text) VALUES (?, ?, ?)");
                foreach ($questions as$q) {
                    $qid =$q['id'];
                    if (!is_question_active($q,$submitted_answers)) continue;

                    if (isset($submitted_answers[$qid])) {$ans = $submitted_answers[$qid];
                        if (is_array($ans)) {
                            foreach ($ans as$opt) {
                                $a_stmt->execute([$response_id, $qid, trim($opt)]);
                            }
                        } else {
                            $a_stmt->execute([$response_id, $qid, trim($ans)]);
                        }
                    }
                }
                $pdo->commit();$success = true;

                setcookie($cookie_name,$token, [
                    'expires' => time() + (365 * 24 * 60 * 60),
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } catch (PDOException $e) {
                $pdo->rollBack();$errors[] = "Could not record your response. Please try again.";
            }
        }
    }
}

include 'header.php';
?>

<style>
.survey-header {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--slate-200);
}
.survey-title {
    font-size: 1.85rem;
    font-weight: 700;
    color: var(--slate-900);
    letter-spacing: -0.02em;
    margin: 0 0 8px 0;
}
.survey-desc {
    color: var(--slate-500);
    font-size: 1rem;
    margin: 0;
}

/* Question Cards */
.question-card {
    background: #ffffff;
    border: 1px solid var(--slate-200);
    border-radius: var(--radius-md);
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.question-card:focus-within {
    border-color: #93c5fd;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
}
.question-number {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--primary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 6px;
}
.question-prompt {
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--slate-900);
    margin: 0 0 16px 0;
    line-height: 1.4;
}

/* Slider Controls */
.slider-shell {
    background: var(--slate-50);
    border: 1px solid var(--slate-200);
    padding: 24px 20px;
    border-radius: var(--radius-md);
}
.slider-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}
.score-pill {
    background: var(--primary);
    color: #ffffff;
    font-size: 1.35rem;
    font-weight: 700;
    padding: 2px 18px;
    border-radius: 9999px;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
}
input[type="range"] {
    -webkit-appearance: none;
    width: 100%;
    height: 8px;
    background: var(--slate-300);
    border-radius: 5px;
    outline: none;
    margin: 12px 0;
}
input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--primary);
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    transition: transform 0.1s ease;
}
input[type="range"]::-webkit-slider-thumb:hover {
    transform: scale(1.15);
}

/* Sortable List */
.rank-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.rank-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1.5px solid var(--slate-200);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    margin-bottom: 10px;
    cursor: grab;
    transition: all 0.15s ease;
}
.rank-item:hover {
    border-color: var(--slate-400);
    background: var(--slate-50);
}
.rank-item.dragging {
    opacity: 0.3;
    border-color: var(--primary);
    background: var(--primary-light);
}
.rank-index {
    width: 28px;
    height: 28px;
    background: var(--slate-100);
    border: 1px solid var(--slate-300);
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--slate-700);
    margin-right: 12px;
}
</style>

<div class="survey-header">
    <h1 class="survey-title"><?= htmlspecialchars($survey['title']) ?></h1>
    <?php if ($survey['description']): ?>
        <p class="survey-desc"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($success): ?>
    <div style="text-align: center; padding: 48px 24px;">
        <div style="width: 64px; height: 64px; background: #d1fae5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px auto; font-size: 2rem;">
            ✓
        </div>
        <h2 style="margin: 0 0 8px 0; color: var(--slate-900);">Response Submitted!</h2>
        <p style="margin: 0 0 24px 0; color: var(--slate-500);">Thank you for taking the time to share your feedback.</p>
        <?php if ($survey['results_open'] || current_user()): ?>
            <a href="<?= $base_path ?>/results.php?slug=<?= urlencode($survey['slug']) ?>" class="btn">View Aggregated Results</a>
        <?php endif; ?>
    </div>

<?php elseif ($already_completed): ?>
    <div style="text-align: center; padding: 48px 24px; background: var(--warning-light); border: 1.5px solid #fde68a; border-radius: var(--radius-lg);">
        <div style="font-size: 2.2rem; margin-bottom: 12px;">🔒</div>
        <h2 style="margin: 0 0 8px 0; color: #92400e;">Survey Already Completed</h2>
        <p style="margin: 0 auto 24px auto; max-width: 500px; color: #78350f; font-size: 0.95rem; line-height: 1.6;">
            Our records indicate that you, this device, or your local network have already submitted a response for this survey.
        </p>
        <?php if ($survey['results_open'] || current_user()): ?>
            <a href="<?= $base_path ?>/results.php?slug=<?= urlencode($survey['slug']) ?>" class="btn btn-secondary">View Live Results</a>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span style="font-size: 1.2em; margin-right: 10px;">⚠️</span>
            <div>
                <strong>Please complete all required fields:</strong>
                <ul style="margin: 4px 0 0 0; padding-left: 20px;">
                    <?php foreach ($errors as$err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($survey_action_url) ?>" id="survey-form">
        <?php foreach ($questions as $qIdx =>$q): ?>
            <div class="question-card question-block" 
                 id="q-block-<?= $q['id'] ?>"
                 data-qid="<?= $q['id'] ?>"
                 data-parent-id="<?= htmlspecialchars($q['parent_question_id'] ?? '') ?>"
                 data-condition-val="<?= htmlspecialchars($q['condition_value'] ?? '') ?>">
                
                <div class="question-number">Question <?= $qIdx + 1 ?></div>
                <div class="question-prompt">
                    <?= htmlspecialchars($q['question_text']) ?>
                    <?php if ($q['is_required']): ?>
                        <span style="color: var(--danger); font-size: 1.1em;" title="Required">*</span>
                    <?php endif; ?>
                </div>

                <!-- 1. TEXT INPUT -->
                <?php if ($q['type'] === 'text'): ?>
                    <input type="text" name="answers[<?= $q['id'] ?>]" placeholder="Type your answer here..." value="<?= htmlspecialchars($_POST['answers'][$q['id']] ?? '') ?>">

                <!-- 2. RADIO BUTTONS (CARD TILES) -->
                <?php elseif ($q['type'] === 'radio'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <div>
                        <?php foreach ($options as$opt): ?>
                            <?php $checked = (isset($_POST['answers'][$q['id']]) &&$_POST['answers'][$q['id']] ===$opt); ?>
                            <label class="option-tile <?= $checked ? 'selected' : '' ?>">
                                <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= htmlspecialchars($opt) ?>" <?= $checked ? 'checked' : '' ?> onchange="updateTileStyles(this)">
                                <span style="font-weight: 500; font-size: 0.95rem;"><?= htmlspecialchars($opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <!-- 3. CHECKBOXES (CARD TILES) -->
                <?php elseif ($q['type'] === 'checkbox'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <div>
                        <?php foreach ($options as$opt): ?>
                            <?php $checked = (isset($_POST['answers'][$q['id']]) && is_array($_POST['answers'][$q['id']]) && in_array($opt, $_POST['answers'][$q['id']])); ?>
                            <label class="option-tile <?= $checked ? 'selected' : '' ?>">
                                <input type="checkbox" name="answers[<?= $q['id'] ?>][]" value="<?= htmlspecialchars($opt) ?>" <?= $checked ? 'checked' : '' ?> onchange="updateTileStyles(this)">
                                <span style="font-weight: 500; font-size: 0.95rem;"><?= htmlspecialchars($opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <!-- 4. DROPDOWN -->
                <?php elseif ($q['type'] === 'dropdown'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <select name="answers[<?= $q['id'] ?>]">
                        <option value="">-- Choose an option --</option>
                        <?php foreach ($options as$opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= (isset($_POST['answers'][$q['id']]) &&$_POST['answers'][$q['id']] ===$opt) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <!-- 5. RANKING -->
                <?php elseif ($q['type'] === 'ranking'): ?>
                    <?php 
                    $items = json_decode($q['options_json'] ?? '[]', true) ?: [];
                    if (!empty($_POST['answers'][$q['id']])) {
                        $saved_items = array_map('trim', explode('\vert{}',$_POST['answers'][$q['id']]));$clean_saved = [];
                        foreach ($saved_items as$si) {
                            $clean_saved[] = preg_replace('/^\d+\.\s*/', '',$si);
                        }
                        if (count($clean_saved) === count($items)) {
                            $items =$clean_saved;
                        }
                    }
                    ?>
                    <p style="font-size: 0.85rem; color: var(--slate-500); margin: 0 0 10px 0;">
                        Drag items or use the arrows to rank in order of priority:
                    </p>
                    <ul class="rank-list" id="rank-list-<?= $q['id'] ?>">
                        <?php foreach ($items as $rIdx =>$item): ?>
                            <li class="rank-item" draggable="true" data-value="<?= htmlspecialchars($item) ?>">
                                <div style="display: flex; align-items: center;">
                                    <span class="rank-index"><?= $rIdx + 1 ?></span>
                                    <span style="font-weight: 600; font-size: 0.95rem; color: var(--slate-800);"><?= htmlspecialchars($item) ?></span>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-secondary btn-mini" onclick="moveRankItem(this, -1)">▲</button>
                                    <button type="button" class="btn btn-secondary btn-mini" onclick="moveRankItem(this, 1)">▼</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" name="answers[<?= $q['id'] ?>]" id="rank-input-<?= $q['id'] ?>">

                <!-- 6. SLIDER / RATING SCALE -->
                <?php elseif ($q['type'] === 'scale'): ?>
                    <?php 
                    $scale = json_decode($q['options_json'] ?? '{}', true) ?: [];$min = isset($scale['min']) ? (int)$scale['min'] : 1;
                    $max = isset($scale['max']) ? (int)$scale['max'] : 10;
                    if ($max <=$min) $max =$min + 1;
                    $min_lbl = $scale['min_label'] ?? '';$max_lbl = $scale['max_label'] ?? '';$midpoint = (int)round(($min +$max) / 2);
                    $current_val = isset($_POST['answers'][$q['id']]) ? (int)$_POST['answers'][$q['id']] :$midpoint;
                    ?>
                    <div class="slider-shell">
                        <div class="slider-head">
                            <span style="font-size: 0.9rem; font-weight: 600; color: var(--slate-600);">Selected Rating:</span>
                            <span class="score-pill" id="scale-badge-<?= $q['id'] ?>"><?= $current_val ?></span>
                        </div>
                        <input type="range" 
                               name="answers[<?= $q['id'] ?>]" 
                               id="scale-slider-<?= $q['id'] ?>"
                               min="<?= $min ?>" 
                               max="<?= $max ?>" 
                               step="1" 
                               value="<?= $current_val ?>"
                               oninput="document.getElementById('scale-badge-<?= $q['id'] ?>').textContent = this.value">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--slate-500); font-weight: 500; margin-top: 6px;">
                            <span><?= $min ?><?= $min_lbl ? ' &mdash; ' . htmlspecialchars($min_lbl) : '' ?></span>
                            <span><?= $max_lbl ? htmlspecialchars($max_lbl) . ' &mdash; ' : '' ?><?=$max ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 36px;">
            <button type="submit" class="btn" style="padding: 12px 28px; font-size: 1rem;">
                Submit Survey &rarr;
            </button>
        </div>
    </form>

    <script>
    function updateTileStyles(input) {
        if (input.type === 'radio') {
            const name = input.name;
            document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                r.closest('.option-tile').classList.remove('selected');
            });
            input.closest('.option-tile').classList.add('selected');
        } else if (input.type === 'checkbox') {
            input.closest('.option-tile').classList.toggle('selected', input.checked);
        }
    }

    function updateRankInputs() {
        document.querySelectorAll('.rank-list').forEach(list => {
            const qid = list.id.replace('rank-list-', '');
            const hiddenInput = document.getElementById(`rank-input-${qid}`);
            const items = list.querySelectorAll('.rank-item');
            const serialized = [];
            
            items.forEach((item, pos) => {
                const badge = item.querySelector('.rank-index');
                if (badge) badge.textContent = pos + 1;
                serialized.push(`${pos + 1}. ${item.dataset.value}`);
            });

            if (hiddenInput) {
                hiddenInput.value = serialized.join(' | ');
            }
        });
    }

    function moveRankItem(btn, direction) {
        const item = btn.closest('.rank-item');
        const list = item.parentElement;
        if (direction === -1 && item.previousElementSibling) {
            list.insertBefore(item, item.previousElementSibling);
        } else if (direction === 1 && item.nextElementSibling) {
            list.insertBefore(item, item.nextElementSibling);
        }
        updateRankInputs();
    }

    document.querySelectorAll('.rank-list').forEach(list => {
        list.addEventListener('dragstart', e => {
            const item = e.target.closest('.rank-item');
            if (item) item.classList.add('dragging');
        });
        list.addEventListener('dragend', e => {
            const item = e.target.closest('.rank-item');
            if (item) {
                item.classList.remove('dragging');
                updateRankInputs();
            }
        });
        list.addEventListener('dragover', e => {
            e.preventDefault();
            const dragging = list.querySelector('.dragging');
            if (!dragging) return;
            const afterElement = getRankDragAfterElement(list, e.clientY);
            if (afterElement == null) {
                list.appendChild(dragging);
            } else {
                list.insertBefore(dragging, afterElement);
            }
        });
    });

    function getRankDragAfterElement(list, y) {
        const items = [...list.querySelectorAll('.rank-item:not(.dragging)')];
        return items.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    function evaluateConditions() {
        const blocks = document.querySelectorAll('.question-block');
        const values = {};

        blocks.forEach(b => {
            const qid = b.dataset.qid;
            const textInput = b.querySelector('input[type="text"]');
            if (textInput) values[qid] = [textInput.value];

            const selectInput = b.querySelector('select');
            if (selectInput) values[qid] = [selectInput.value];

            const sliderInput = b.querySelector('input[type="range"]');
            if (sliderInput) values[qid] = [sliderInput.value];

            const radioChecked = b.querySelector('input[type="radio"]:checked');
            if (radioChecked) values[qid] = [radioChecked.value];

            const checkedBoxes = Array.from(b.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
            if (checkedBoxes.length > 0) values[qid] = checkedBoxes;
        });

        blocks.forEach(b => {
            const parentId = b.dataset.parentId;
            const conditionVal = b.dataset.conditionVal;

            if (!parentId || parentId === '') {
                b.style.display = 'block';
                return;
            }

            const parentValues = values[parentId] || [];
            const matches = parentValues.includes(conditionVal);
            b.style.display = matches ? 'block' : 'none';
        });
    }

    document.getElementById('survey-form').addEventListener('change', evaluateConditions);
    document.getElementById('survey-form').addEventListener('input', evaluateConditions);
    
    updateRankInputs();
    evaluateConditions();
    </script>
<?php endif; ?>

<?php include 'footer.php'; ?>
