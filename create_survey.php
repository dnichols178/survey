<?php
// create_survey.php
require_once 'db.php';
require_login();

$u = current_user();
if (!$u['can_create_surveys']) {
    die("Access denied: You lack privileges to create surveys.");
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $results_open = isset($_POST['results_open']) ? 1 : 0;
    $allow_multiple = (isset($_POST['allow_multiple']) && $_POST['allow_multiple'] === '0') ? 0 : 1;
    $questions = $_POST['questions'] ?? [];

    if (!$title || !$slug) {
        $error = 'Title and survey slug are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        $error = 'Slug can only contain letters, numbers, hyphens, and underscores.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO surveys (user_id, title, description, slug, results_open, allow_multiple) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$u['id'], $title, $description, $slug, $results_open, $allow_multiple]);
            $survey_id = $pdo->lastInsertId();

            if (!empty($questions) && is_array($questions)) {
                $q_stmt = $pdo->prepare("INSERT INTO questions (survey_id, question_text, type, is_required, options_json, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                
                $inserted_ids = [];
                $order = 1;
                foreach ($questions as $idx => $q) {
                    $text = trim($q['text'] ?? '');
                    if ($text === '') continue;
                    
                    $valid_types = ['text', 'radio', 'checkbox', 'dropdown', 'ranking', 'scale'];
                    $type = in_array($q['type'], $valid_types) ? $q['type'] : 'text';
                    $req = isset($q['required']) ? 1 : 0;
                    
                    $options = null;
                    if (in_array($type, ['radio', 'checkbox', 'dropdown', 'ranking']) && !empty($q['options'])) {
                        $opts_raw = explode("\n", str_replace("\r", "", $q['options']));
                        $clean_opts = array_values(array_filter(array_map('trim', $opts_raw)));
                        $options = json_encode($clean_opts);
                    } elseif ($type === 'scale') {
                        $min = isset($q['scale_min']) ? (int)$q['scale_min'] : 1;
                        $max = isset($q['scale_max']) ? (int)$q['scale_max'] : 10;
                        if ($max <= $min) {
                            $max = $min + 1;
                        }

                        $scale_config = [
                            'min' => $min,
                            'max' => $max,
                            'step' => 1,
                            'min_label' => trim($q['scale_min_label'] ?? ''),
                            'max_label' => trim($q['scale_max_label'] ?? '')
                        ];
                        $options = json_encode($scale_config);
                    }

                    $q_stmt->execute([$survey_id, $text, $type, $req, $options, $order++]);
                    $inserted_ids[$idx] = $pdo->lastInsertId();
                }

                $upd_stmt = $pdo->prepare("UPDATE questions SET parent_question_id = ?, condition_value = ? WHERE id = ?");
                foreach ($questions as $idx => $q) {
                    if (!isset($inserted_ids[$idx])) continue;
                    
                    $parent_idx = $q['parent_idx'] ?? '';
                    $cond_val = trim($q['condition_val'] ?? '');

                    if ($parent_idx !== '' && isset($inserted_ids[$parent_idx]) && $cond_val !== '') {
                        $upd_stmt->execute([$inserted_ids[$parent_idx], $cond_val, $inserted_ids[$idx]]);
                    }
                }
            }
            $pdo->commit();
            header("Location: index.php?created=" . urlencode($slug));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

$base_url = get_base_url();
$base_path = get_app_base_path();

include 'header.php';
?>

<h2>Survey Builder</h2>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="POST">
    <div class="form-group">
        <label>Survey Title</label>
        <input type="text" name="title" id="survey_title" required placeholder="e.g., Annual Employee Engagement">
    </div>
    <div class="form-group">
        <label>Custom URL Path (Slug)</label>
        <input type="text" name="slug" id="survey_slug" required placeholder="employee-engagement">
        <small style="color: #64748b;">
            Public Link: <code id="full-url-preview"><?= htmlspecialchars($base_url) ?>/survey/employee-engagement</code>
        </small>
    </div>
    <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="2"></textarea>
    </div>

    <!-- Survey Policies -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; border-radius: 6px; margin-bottom: 20px;">
        <label style="font-weight: bold; margin-bottom: 8px; display: block;">Survey Configuration &amp; Policies:</label>
        
        <div class="form-group" style="margin-bottom: 12px;">
            <label style="font-weight: normal; margin-bottom: 4px;"><strong>Submission Limits:</strong></label>
            <div>
                <label style="font-weight: normal; margin-right: 16px;">
                    <input type="radio" name="allow_multiple" value="1" checked> 
                    Allow multiple submissions per person
                </label>
                <label style="font-weight: normal;">
                    <input type="radio" name="allow_multiple" value="0"> 
                    <strong>Enforce one survey per person</strong> (tracks cookies, IP, and SSO identity)
                </label>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
            <label style="font-weight: normal;">
                <input type="checkbox" name="results_open" value="1">
                <strong>Open Results:</strong> Allow anyone to view survey responses without an account.
            </label>
        </div>
    </div>

    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <h3>Survey Questions</h3>
    <p style="font-size: 0.9em; color: #64748b; margin-top: -6px;">
        Reorder questions using the <strong>▲ / ▼</strong> buttons or by dragging them.
    </p>

    <div id="questions-container"></div>
    <button type="button" class="btn btn-secondary" onclick="addQuestion()">+ Add Question</button>
    <br><br>
    <button type="submit" class="btn">Save &amp; Publish Survey</button>
</form>

<style>
.q-card { cursor: grab; transition: background-color 0.15s ease; }
.q-card.dragging { opacity: 0.4; border: 2px dashed #2563eb; }
.btn-mini { padding: 4px 8px; font-size: 0.8em; margin-left: 4px; }
</style>

<script>
let qCounter = 0;
const appBaseUrl = <?= json_encode($base_url) ?>;

document.getElementById('survey_title').addEventListener('input', function() {
    const slugInput = document.getElementById('survey_slug');
    if (!slugInput.dataset.manual) {
        const slug = this.value.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-|-$/g, '');
        slugInput.value = slug;
        document.getElementById('full-url-preview').textContent = appBaseUrl + '/survey/' + (slug || '...');
    }
});

document.getElementById('survey_slug').addEventListener('input', function() {
    this.dataset.manual = "1";
    const slug = this.value.trim();
    document.getElementById('full-url-preview').textContent = appBaseUrl + '/survey/' + (slug || '...');
});

function addQuestion() {
    const idx = qCounter++;
    const container = document.getElementById('questions-container');
    const div = document.createElement('div');
    div.className = 'q-card';
    div.id = `q-card-${idx}`;
    div.dataset.index = idx;
    div.draggable = true;
    
    div.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
            <div>
                <span style="cursor:grab; font-size:1.2em; margin-right:6px; color:#64748b;">☰</span>
                <strong class="q-title">Question</strong>
            </div>
            <div>
                <button type="button" class="btn btn-secondary btn-mini" onclick="moveCard(this, -1)">▲ Up</button>
                <button type="button" class="btn btn-secondary btn-mini" onclick="moveCard(this, 1)">▼ Down</button>
                <button type="button" class="btn btn-secondary btn-mini" style="background:#dc2626;" onclick="removeQuestion(${idx})">✕</button>
            </div>
        </div>
        <div class="form-group">
            <label>Question Prompt</label>
            <input type="text" name="questions[${idx}][text]" required oninput="refreshDependencyDropdowns()" placeholder="e.g., What department do you work in?">
        </div>
        <div class="form-group">
            <label>Input Type</label>
            <select name="questions[${idx}][type]" onchange="toggleFieldOptions(this, ${idx})">
                <option value="text">Text Input</option>
                <option value="radio">Radio Buttons (Single Choice)</option>
                <option value="checkbox">Checkboxes (Multiple Choice)</option>
                <option value="dropdown">Dropdown (Single Selection)</option>
                <option value="ranking">Ranking (Order by Preference)</option>
                <option value="scale">Rating Scale / Slider (Custom Range)</option>
            </select>
        </div>

        <div class="form-group options-group" id="opt-group-${idx}" style="display:none;">
            <label id="opt-label-${idx}">Options (one per line)</label>
            <textarea name="questions[${idx}][options]" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
        </div>

        <div class="form-group scale-config-group" id="scale-group-${idx}" style="display:none; background: #e0f2fe; padding: 14px; border-radius: 6px;">
            <label style="color:#0369a1; font-weight:bold; margin-bottom: 8px; display:block;">Slider &amp; Range Settings:</label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                <div>
                    <label style="font-size: 0.85em;">Minimum Number</label>
                    <input type="number" name="questions[${idx}][scale_min]" id="scale_min_${idx}" value="1" style="background:#fff;" onchange="validateMinMax(${idx})">
                </div>
                <div>
                    <label style="font-size: 0.85em;">Maximum Number</label>
                    <input type="number" name="questions[${idx}][scale_max]" id="scale_max_${idx}" value="10" style="background:#fff;" onchange="validateMinMax(${idx})">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label style="font-size: 0.85em;">Min Anchor Label (Optional)</label>
                    <input type="text" name="questions[${idx}][scale_min_label]" placeholder="e.g., Poor" style="background:#fff;">
                </div>
                <div>
                    <label style="font-size: 0.85em;">Max Anchor Label (Optional)</label>
                    <input type="text" name="questions[${idx}][scale_max_label]" placeholder="e.g., Excellent" style="background:#fff;">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label><input type="checkbox" name="questions[${idx}][required]" value="1"> Required field</label>
        </div>

        <div style="background: #e2e8f0; padding: 12px; border-radius: 4px; margin-top: 12px;">
            <label style="margin-bottom: 6px; display: block;"><strong>Display Logic (Conditional):</strong></label>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <span>Show this question only if:</span>
                <select name="questions[${idx}][parent_idx]" id="parent-select-${idx}" style="width: auto; max-width: 250px;">
                    <option value="">-- Always Show (No condition) --</option>
                </select>
                <span>equals</span>
                <input type="text" name="questions[${idx}][condition_val]" placeholder="e.g., Option A or 5" style="width: 200px;">
            </div>
        </div>
    `;

    div.addEventListener('dragstart', () => div.classList.add('dragging'));
    div.addEventListener('dragend', () => {
        div.classList.remove('dragging');
        reindexCards();
    });

    container.appendChild(div);
    reindexCards();
}

function validateMinMax(idx) {
    const minInput = document.getElementById(`scale_min_${idx}`);
    const maxInput = document.getElementById(`scale_max_${idx}`);
    if (minInput && maxInput) {
        const minVal = parseInt(minInput.value, 10);
        const maxVal = parseInt(maxInput.value, 10);
        if (maxVal <= minVal) {
            maxInput.value = minVal + 1;
        }
    }
}

function moveCard(button, direction) {
    const card = button.closest('.q-card');
    if (direction === -1 && card.previousElementSibling) {
        card.parentNode.insertBefore(card, card.previousElementSibling);
    } else if (direction === 1 && card.nextElementSibling) {
        card.parentNode.insertBefore(card, card.nextElementSibling, card);
    }
    reindexCards();
}

function toggleFieldOptions(selectElem, index) {
    const optGroup = document.getElementById(`opt-group-${index}`);
    const optLabel = document.getElementById(`opt-label-${index}`);
    const scaleGroup = document.getElementById(`scale-group-${index}`);
    const val = selectElem.value;

    if (val === 'radio' || val === 'checkbox' || val === 'dropdown') {
        optGroup.style.display = 'block';
        optLabel.textContent = 'Options (one per line)';
        scaleGroup.style.display = 'none';
    } else if (val === 'ranking') {
        optGroup.style.display = 'block';
        optLabel.textContent = 'Items to Rank (one per line)';
        scaleGroup.style.display = 'none';
    } else if (val === 'scale') {
        optGroup.style.display = 'none';
        scaleGroup.style.display = 'block';
    } else {
        optGroup.style.display = 'none';
        scaleGroup.style.display = 'none';
    }
}

function removeQuestion(idx) {
    const el = document.getElementById(`q-card-${idx}`);
    if (el) el.remove();
    reindexCards();
}

function reindexCards() {
    const cards = document.querySelectorAll('.q-card');
    cards.forEach((card, pos) => {
        const title = card.querySelector('.q-title');
        if (title) title.innerText = `Question ${pos + 1}`;
    });
    refreshDependencyDropdowns();
}

function refreshDependencyDropdowns() {
    const cards = Array.from(document.querySelectorAll('.q-card'));
    
    cards.forEach((card, currentIndex) => {
        const idx = card.dataset.index;
        const select = document.getElementById(`parent-select-${idx}`);
        if (!select) return;
        
        const currentValue = select.value;
        select.innerHTML = '<option value="">-- Always Show (No condition) --</option>';

        for (let i = 0; i < currentIndex; i++) {
            const prevCard = cards[i];
            const prevIdx = prevCard.dataset.index;
            const promptInput = prevCard.querySelector(`input[name="questions[${prevIdx}][text]"]`);
            const promptText = promptInput && promptInput.value ? promptInput.value : `Question ${i + 1}`;
            
            const opt = document.createElement('option');
            opt.value = prevIdx;
            opt.textContent = `Q${i + 1}: ${promptText.substring(0, 30)}${promptText.length > 30 ? '...' : ''}`;
            if (currentValue === String(prevIdx)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        }
    });
}

const container = document.getElementById('questions-container');
container.addEventListener('dragover', e => {
    e.preventDefault();
    const draggingCard = document.querySelector('.dragging');
    const afterElement = getDragAfterElement(container, e.clientY);
    if (afterElement == null) {
        container.appendChild(draggingCard);
    } else {
        container.insertBefore(draggingCard, afterElement);
    }
});

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.q-card:not(.dragging)')];
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

addQuestion();
</script>

<?php include 'footer.php'; ?>