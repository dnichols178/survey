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

// Detect current visitor's identity and client network data
$client_ip = get_client_ip();$cookie_name = "survey_taken_" . $survey['id'];$device_cookie = $_COOKIE[$cookie_name] ?? null;

$captured_identity = null;
$raw_auth =$_SERVER['REMOTE_USER'] ?? $_SERVER['AUTH_USER'] ?? $_SERVER['LOGON_USER'] ?? null;
if (!empty($raw_auth)) {
    $parts = explode('\\',$raw_auth);
    $captured_identity = strtolower(end($parts));
} elseif (isset($_SESSION['user']['username'])) {
    $captured_identity = strtolower($_SESSION['user']['username']);
}

// -------------------------------------------------------------
// Check "One Survey Per Person" Policy
// -------------------------------------------------------------
$already_completed = false;

if (empty($survey['allow_multiple'])) {
    // 1. Check browser cookie token
    if (!empty($device_cookie)) {$already_completed = true;
    }

    // 2. Check SSO / Account identity if present
    if (!$already_completed && !empty($captured_identity)) {
        $chk_user =$pdo->prepare("SELECT id FROM responses WHERE survey_id = ? AND respondent_username = ? LIMIT 1");
        $chk_user->execute([$survey['id'],$captured_identity]);
        if ($chk_user->fetch()) {$already_completed = true;
        }
    }

    // 3. Check IP address match
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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($already_completed) {$errors[] = "You have already completed this survey. Multiple submissions are not allowed.";
    } else {
        $submitted_answers =$_POST['answers'] ?? [];

        foreach ($questions as $q) {$qid = $q['id'];$val = $submitted_answers[$qid] ?? null;
            $active = is_question_active($q,$submitted_answers);
if ($val === null || (is_string($val) && trim($val) === '') || (is_array($val) && empty($val))) {
    $errors[] = "The question '" . htmlspecialchars($q['question_text']) . "' is required.";
            }
        }

        if (empty($errors)) {$pdo->beginTransaction();
            try {
                // Generate a unique token for the device
                $token = bin2hex(random_bytes(16));

                $r_stmt =$pdo->prepare("
                    INSERT INTO responses (survey_id, respondent_username, ip_address, device_token) 
                    VALUES (?, ?, ?, ?)
                ");
                $r_stmt->execute([$survey['id'],$captured_identity, $client_ip,$token]);
                $response_id =$pdo->lastInsertId();

                $a_stmt =$pdo->prepare("INSERT INTO answers (response_id, question_id, answer_text) VALUES (?, ?, ?)");
                foreach ($questions as$q) {
                    $qid =$q['id'];
                    
                    if (!is_question_active($q,$submitted_answers)) {
                        continue;
                    }

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

                // Stamp client device token cookie for 1 year
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
.slider-container {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 16px;
    border-radius: 8px;
    margin-top: 8px;
}
.slider-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.slider-badge {
    background: #2563eb;
    color: #fff;
    font-size: 1.15em;
    font-weight: bold;
    padding: 2px 14px;
    border-radius: 14px;
    min-width: 28px;
    text-align: center;
    box-shadow: 0 1px 2px rgba(0,0,0,0.15);
}
input[type="range"] {
    width: 100%;
    cursor: pointer;
    margin: 8px 0;
}
.slider-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.85em;
    color: #64748b;
    font-weight: 500;
}
.rank-list {
    list-style: none;
    padding: 0;
    margin: 8px 0 0 0;
}
.rank-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 8px;
    cursor: grab;
    user-select: none;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.rank-item:active { cursor: grabbing; }
.rank-item.dragging { opacity: 0.35; border-color: #2563eb; background: #eff6ff; }
.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    background: #e2e8f0;
    color: #1e293b;
    border-radius: 50%;
    font-weight: bold;
    font-size: 0.85em;
    margin-right: 12px;
}
.rank-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    cursor: pointer;
    padding: 2px 8px;
    font-size: 0.8em;
    margin-left: 4px;
}
.rank-btn:hover { background: #e2e8f0; }
.duplicate-block {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-left: 6px solid #d97706;
    padding: 24px;
    border-radius: 6px;
    margin: 20px 0;
}
</style>

<h2><?= htmlspecialchars($survey['title']) ?></h2>
<?php if ($survey['description']): ?>
    <p style="color: #64748b;"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <h3 style="margin-top:0;">Thank you!</h3>
        Your response has been successfully submitted and recorded.
    </div>
    <?php if ($survey['results_open'] || current_user()): ?>
        <p><a href="<?= $base_path ?>/results.php?slug=<?= urlencode($survey['slug']) ?>" class="btn">View Survey Results</a></p>
    <?php endif; ?>

<?php elseif ($already_completed): ?>
    <!-- Already Completed Block -->
    <div class="duplicate-block">
        <h3 style="color: #b45309; margin: 0 0 8px 0;">Survey Already Completed</h3>
        <p style="margin: 0; color: #78350f; font-size: 1.05em; line-height: 1.5;">
            Our records indicate that you, this computer, or your network have already submitted a response for this survey.
            Multiple submissions have been disabled by the survey author.
        </p>
        <?php if ($survey['results_open'] || current_user()): ?>
            <div style="margin-top: 16px;">
                <a href="<?= $base_path ?>/results.php?slug=<?= urlencode($survey['slug']) ?>" class="btn">View Current Results</a>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin:0; padding-left: 20px;">
                <?php foreach ($errors as$err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($survey_action_url) ?>" id="survey-form">
        <?php foreach ($questions as$q): ?>
            <div class="form-group question-block" 
                 id="q-block-<?= $q['id'] ?>"
                 data-qid="<?= $q['id'] ?>"
                 data-parent-id="<?= htmlspecialchars($q['parent_question_id'] ?? '') ?>"
                 data-condition-val="<?= htmlspecialchars($q['condition_value'] ?? '') ?>"
                 style="margin-bottom: 24px; padding: 12px; border-radius: 6px;">
                
                <label>
                    <?= htmlspecialchars($q['question_text']) ?>
                    <?php if ($q['is_required']): ?>
                        <span style="color: #dc2626;">*</span>
                    <?php endif; ?>
                </label>

                <?php if ($q['type'] === 'text'): ?>
                    <input type="text" name="answers[<?= $q['id'] ?>]" value="<?= htmlspecialchars($_POST['answers'][$q['id']] ?? '') ?>">

                <?php elseif ($q['type'] === 'radio'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <?php foreach ($options as$opt): ?>
                        <div style="margin-top: 4px;">
                            <label style="font-weight: normal; display: inline;">
                                <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= htmlspecialchars($opt) ?>" <?= (isset($_POST['answers'][$q['id']]) &&$_POST['answers'][$q['id']] ===$opt) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'checkbox'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <?php foreach ($options as$opt): ?>
                        <div style="margin-top: 4px;">
                            <label style="font-weight: normal; display: inline;">
                                <input type="checkbox" name="answers[<?= $q['id'] ?>][]" value="<?= htmlspecialchars($opt) ?>" <?= (isset($_POST['answers'][$q['id']]) && is_array($_POST['answers'][$q['id']]) && in_array($opt, $_POST['answers'][$q['id']])) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'dropdown'): ?>
                    <?php $options = json_decode($q['options_json'] ?? '[]', true) ?: []; ?>
                    <select name="answers[<?= $q['id'] ?>]">
                        <option value="">-- Select an option --</option>
                        <?php foreach ($options as$opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= (isset($_POST['answers'][$q['id']]) &&$_POST['answers'][$q['id']] ===$opt) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

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
                    <small style="color: #64748b; display: block; margin-bottom: 6px;">
                        Drag items or use the ▲ / ▼ buttons to arrange in your preferred order:
                    </small>
                    <ul class="rank-list" id="rank-list-<?= $q['id'] ?>">
                        <?php foreach ($items as $rIdx =>$item): ?>
                            <li class="rank-item" draggable="true" data-value="<?= htmlspecialchars($item) ?>">
                                <div style="display: flex; align-items: center;">
                                    <span class="rank-badge"><?= $rIdx + 1 ?></span>
                                    <span class="rank-text"><?= htmlspecialchars($item) ?></span>
                                </div>
                                <div>
                                    <button type="button" class="rank-btn" onclick="moveRankItem(this, -1)">▲</button>
                                    <button type="button" class="rank-btn" onclick="moveRankItem(this, 1)">▼</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" name="answers[<?= $q['id'] ?>]" id="rank-input-<?= $q['id'] ?>">

                <?php elseif ($q['type'] === 'scale'): ?>
                    <?php 
                    $scale = json_decode($q['options_json'] ?? '{}', true) ?: [];$min = isset($scale['min']) ? (int)$scale['min'] : 1;
                    $max = isset($scale['max']) ? (int)$scale['max'] : 10;
                    if ($max <= $min) {$max = $min + 1;                     }$min_lbl = $scale['min_label'] ?? '';$max_lbl = $scale['max_label'] ?? '';$midpoint = (int)round(($min +$max) / 2);
                    $current_val = isset($_POST['answers'][$q['id']]) ? (int)$_POST['answers'][$q['id']] :$midpoint;
                    ?>
                    <div class="slider-container">
                        <div class="slider-header">
                            <span style="font-size: 0.85em; color: #64748b;">Selected score:</span>
                            <span class="slider-badge" id="scale-badge-<?= $q['id'] ?>"><?= $current_val ?></span>
                        </div>
                        <input type="range" 
                               name="answers[<?= $q['id'] ?>]" 
                               id="scale-slider-<?= $q['id'] ?>"
                               min="<?= $min ?>" 
                               max="<?= $max ?>" 
                               step="1" 
                               value="<?= $current_val ?>"
                               oninput="document.getElementById('scale-badge-<?= $q['id'] ?>').textContent = this.value">
                        <div class="slider-labels">
                            <span><?= $min ?><?= $min_lbl ? ' &mdash; ' . htmlspecialchars($min_lbl) : '' ?></span>
                            <span><?= $max_lbl ? htmlspecialchars($max_lbl) . ' &mdash; ' : '' ?><?=$max ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn">Submit Survey</button>
    </form>

    <script>
    function updateRankInputs() {
        document.querySelectorAll('.rank-list').forEach(list => {
            const qid = list.id.replace('rank-list-', '');
            const hiddenInput = document.getElementById(`rank-input-${qid}`);
            const items = list.querySelectorAll('.rank-item');
            const serialized = [];
            
            items.forEach((item, pos) => {
                const badge = item.querySelector('.rank-badge');
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