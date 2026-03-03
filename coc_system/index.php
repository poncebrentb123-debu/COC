<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: /coc_system/login.php');
    exit;
}

require __DIR__ . '/db.php';

$monthlyCocLimit = 40.0;

$editorRoles = ['admin', 'hr_officer', 'hr_staff'];
$userRole = (string) ($_SESSION['user_role'] ?? '');
$canEdit = in_array($userRole, $editorRoles, true);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS coc_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        activity_label VARCHAR(190) NOT NULL,
        activity_date DATE NULL,
        hours_earned DECIMAL(7,2) NOT NULL DEFAULT 0,
        valid_until DATE NULL,
        cto_date DATE NULL,
        cto_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_coc_entries_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

$positionOptions = [
    'Administrative Aide', 'Administrative Officer', 'Municipal Mayor', 'Municipal Vice Mayor',
    'Sangguniang Bayan Member', 'Municipal Administrator', 'Municipal Accountant', 'Municipal Budget Officer',
    'Municipal Treasurer', 'Municipal Assessor', 'Municipal Engineer', 'Municipal Planning and Development Coordinator',
    'Municipal Agriculturist', 'Municipal Health Officer', 'Municipal Social Welfare and Development Officer',
    'Municipal Civil Registrar', 'Human Resource Management Officer', 'Records Officer',
    'Information Technology Officer', 'Disaster Risk Reduction and Management Officer', 'Public Information Officer',
    'Legal Officer',
];

$activeSection = (string) ($_GET['section'] ?? 'dashboard');
$validSections = ['dashboard', 'employee', 'coc', 'settings', 'about'];
if (!in_array($activeSection, $validSections, true)) {
    $activeSection = 'dashboard';
}

$message = '';
$error = '';
$selectedEmployeeId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$formType = (string) ($_POST['form_type'] ?? '');
$userEmployeeId = 0;

if (!$canEdit) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        exit('Read-only access: you cannot edit records.');
    }

    $userFullName = trim((string) ($_SESSION['user_name'] ?? ''));
    if ($userFullName === '') {
        http_response_code(403);
        exit('Unable to link your account to an employee record.');
    }

    $stmt = $pdo->prepare('SELECT id FROM employees WHERE full_name = :full_name ORDER BY id ASC LIMIT 1');
    $stmt->execute(['full_name' => $userFullName]);
    $userEmployeeId = (int) ($stmt->fetch()['id'] ?? 0);

    if ($userEmployeeId <= 0) {
        http_response_code(403);
        exit('No employee record linked to your account.');
    }

    $selectedEmployeeId = $userEmployeeId;
    $activeSection = 'coc';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $formType === 'employee') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $position = trim((string) ($_POST['position'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'Active');

    if ($fullName === '' || $position === '' || $department === '') {
        $error = 'Full name, position, and department are required to add an employee.';
    } elseif (!in_array($position, $positionOptions, true)) {
        $error = 'Please select a valid municipal position.';
    } elseif (!in_array($status, ['Active', 'On Leave'], true)) {
        $error = 'Invalid employee status selected.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO employees (full_name, position, department, status) VALUES (:full_name, :position, :department, :status)'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'position' => $position,
            'department' => $department,
            'status' => $status,
        ]);
        $selectedEmployeeId = (int) $pdo->lastInsertId();
        header('Location: /coc_system/index.php?section=coc&employee_id=' . $selectedEmployeeId . '&emp_saved=1');
        exit;
    }
    $activeSection = 'coc';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $formType === 'coc') {
    $selectedEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $activityLabel = trim((string) ($_POST['activity_label'] ?? ''));
    $activityDate = trim((string) ($_POST['activity_date'] ?? ''));
    $hoursEarned = (float) ($_POST['hours_earned'] ?? 0);
    $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
    $ctoDate = trim((string) ($_POST['cto_date'] ?? ''));
    $ctoHours = (float) ($_POST['cto_hours'] ?? 0);

    $employeeExists = false;
    if ($selectedEmployeeId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $selectedEmployeeId]);
        $employeeExists = (bool) $stmt->fetch();
    }

    $activityDateValid = $activityDate !== '' && DateTime::createFromFormat('Y-m-d', $activityDate) !== false;
    $validUntilValid = $validUntil !== '' && DateTime::createFromFormat('Y-m-d', $validUntil) !== false;
    $ctoDateValid = $ctoDate !== '' && DateTime::createFromFormat('Y-m-d', $ctoDate) !== false;

    if ($selectedEmployeeId <= 0) {
        $error = 'Please select an employee.';
    } elseif (!$employeeExists) {
        $error = 'Selected employee record does not exist.';
    } elseif ($hoursEarned <= 0 && $ctoHours <= 0) {
        $error = 'Enter earned hours and/or CTO used hours.';
    } elseif ($hoursEarned > 0 && ($activityLabel === '' || $activityDate === '' || $validUntil === '')) {
        $error = 'Activity label, activity date, and valid-until are required when adding earned COC.';
    } elseif ($hoursEarned > 0 && (!$activityDateValid || !$validUntilValid)) {
        $error = 'Please provide valid earned and valid-until dates.';
    } elseif ($ctoHours > 0 && $ctoDate === '') {
        $error = 'CTO date is required when adding used COC.';
    } elseif ($ctoHours > 0 && !$ctoDateValid) {
        $error = 'Please provide a valid CTO date.';
    } elseif ($hoursEarned > 0 && $activityDateValid) {
        $monthKey = date('Y-m', strtotime($activityDate));
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(hours_earned), 0) AS month_total
             FROM coc_entries
             WHERE employee_id = :employee_id
               AND activity_date IS NOT NULL
               AND DATE_FORMAT(activity_date, '%Y-%m') = :month_key"
        );
        $stmt->execute([
            'employee_id' => $selectedEmployeeId,
            'month_key' => $monthKey,
        ]);
        $monthTotal = (float) ($stmt->fetch()['month_total'] ?? 0);

        if (($monthTotal + $hoursEarned) > $monthlyCocLimit) {
            $error = 'Monthly COC earned cannot exceed 40 hours.';
        }
    }

    if ($error === '') {
        $stmt = $pdo->prepare(
            'INSERT INTO coc_entries (employee_id, activity_label, activity_date, hours_earned, valid_until, cto_date, cto_hours)
             VALUES (:employee_id, :activity_label, :activity_date, :hours_earned, :valid_until, :cto_date, :cto_hours)'
        );
        $stmt->execute([
            'employee_id' => $selectedEmployeeId,
            'activity_label' => $activityLabel !== '' ? $activityLabel : 'COC Usage',
            'activity_date' => $activityDate !== '' ? $activityDate : null,
            'hours_earned' => $hoursEarned,
            'valid_until' => $validUntil !== '' ? $validUntil : null,
            'cto_date' => $ctoDate !== '' ? $ctoDate : null,
            'cto_hours' => $ctoHours,
        ]);
        header('Location: /coc_system/index.php?section=coc&employee_id=' . $selectedEmployeeId . '&saved=1');
        exit;
    }
    $activeSection = 'coc';
}

if (isset($_GET['saved'])) {
    $message = 'COC record saved.';
}
if (isset($_GET['emp_saved'])) {
    $message = 'Employee added successfully. You can now save COC records.';
}

$employees = [];
if ($canEdit) {
    $employees = $pdo->query('SELECT id, full_name, position, department, status FROM employees ORDER BY full_name ASC')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT id, full_name, position, department, status FROM employees WHERE id = :id');
    $stmt->execute(['id' => $userEmployeeId]);
    $employees = $stmt->fetchAll();
}

$selectedEmployeeId = $canEdit ? $selectedEmployeeId : $userEmployeeId;
$selectedEmployee = null;
if ($selectedEmployeeId > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, position, department, status FROM employees WHERE id = :id');
    $stmt->execute(['id' => $selectedEmployeeId]);
    $selectedEmployee = $stmt->fetch() ?: null;
}

$records = [];
if ($selectedEmployee) {
    $stmt = $pdo->prepare(
        'SELECT activity_label, activity_date, hours_earned, valid_until, cto_date, cto_hours
         FROM coc_entries
         WHERE employee_id = :employee_id
         ORDER BY COALESCE(activity_date, cto_date) DESC, id DESC'
    );
    $stmt->execute(['employee_id' => (int) $selectedEmployee['id']]);
    $records = $stmt->fetchAll();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COC Management | <?php echo $canEdit ? 'Admin' : 'Employee'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#2563eb; --primary-light:#3b82f6; --accent:#0ea5e9; --sidebar-bg:linear-gradient(180deg,#0f172a,#1e293b); --main-bg:#eef2ff; --card-bg:rgba(255,255,255,.75); --text-main:#0f172a; --text-muted:#64748b; --success:#16a34a; --danger:#dc2626; --glass-blur:blur(14px); }
        body.dark-theme { --main-bg:#0b1120; --card-bg:rgba(30,41,59,.75); --text-main:#f1f5f9; --text-muted:#94a3b8; --sidebar-bg:linear-gradient(180deg,#020617,#0f172a); }
        body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--main-bg); margin:0; display:flex; min-block-size:100vh; }
        .sidebar { inline-size:280px; background:var(--sidebar-bg); color:#fff; padding:2rem; display:flex; flex-direction:column; box-shadow:6px 0 25px rgba(0,0,0,.25); }
        .logo-container { display:flex; align-items:center; gap:15px; margin-block-end:3rem; }
        .sidebar-logo { inline-size:90px; block-size:90px; border-radius:50%; object-fit:cover; border:3px solid var(--primary-light); box-shadow:0 0 20px rgba(59,130,246,.4); }
        .sidebar h1 { font-size:1.2rem; font-weight:700; margin:0; color:var(--primary-light); }
        .nav-item { display:flex; align-items:center; padding:.9rem 1rem; color:#cbd5e1; text-decoration:none; border-radius:12px; margin-block-end:.6rem; transition:all .3s ease; font-weight:500; }
        .nav-item:hover { background:rgba(255,255,255,.08); transform:translateX(8px); }
        .nav-item.active { background:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(37,99,235,.4); }
        .sidebar-footer { margin-block-start:auto; padding-block-start:1rem; }
        .btn-logout-side { display:inline-flex; align-items:center; padding:.75rem 1rem; border-radius:12px; text-decoration:none; font-weight:700; font-size:.85rem; color:#fff; background:linear-gradient(135deg,#111827,#1f2937); border:1px solid rgba(255,255,255,.12); }
        .content { flex:1; padding:3rem; }
        .table-container,.settings-container { background:var(--card-bg); -webkit-backdrop-filter:var(--glass-blur); backdrop-filter:var(--glass-blur); border-radius:24px; padding:2rem; box-shadow:0 10px 40px rgba(0,0,0,.08); inline-size:100%; box-sizing:border-box; }
        .hidden-section { display:none; }
        .municipality-title { color:var(--primary); margin-block-end:1.2rem; font-weight:700; }
        table { inline-size:100%; border-collapse:collapse; table-layout:fixed; }
        th { text-align:start; padding:.9rem .8rem; color:var(--text-muted); font-size:.75rem; text-transform:uppercase; border-block-end:2px solid rgba(0,0,0,.05); letter-spacing:1px; white-space:nowrap; }
        td { padding:1rem .8rem; border-block-end:1px solid rgba(0,0,0,.04); font-size:.92rem; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .txt-bold { font-weight:600; } .txt-right { text-align:end; } .txt-center { text-align:center; } .txt-num { text-align:end; font-variant-numeric:tabular-nums; } .txt-main { color:var(--text-main); } .txt-muted { color:var(--text-muted); }
        .status-badge { padding:6px 14px; border-radius:50px; font-size:.75rem; font-weight:600; } .status-badge.active { background:rgba(22,163,74,.15); color:var(--success); } .status-badge.on-leave { background:rgba(220,38,38,.15); color:var(--danger); }
        .btn-action { text-decoration:none; padding:.55rem 1rem; border-radius:10px; font-size:.8rem; font-weight:600; display:inline-block; background:linear-gradient(135deg,var(--primary),var(--accent)); color:#fff; }
        .form-grid { display:grid; gap:12px; grid-template-columns:repeat(4,minmax(170px,1fr)); align-items:end; }
        .form-grid .field-wide { grid-column: span 2; }
        .form-grid .field-narrow { max-inline-size: 220px; }
        .field { display:grid; gap:6px; min-inline-size:0; }
        .field label { font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:var(--text-muted); font-weight:700; }
        .field input,.field select { inline-size:100%; min-inline-size:0; block-size:42px; padding:0 12px; border:1px solid rgba(100,116,139,.3); border-radius:10px; font-size:.92rem; color:var(--text-main); background:#fff; }
        .field input::placeholder { color:#94a3b8; opacity:1; }
        .field input:focus,.field select:focus { outline:none; border-color:var(--primary-light); box-shadow:0 0 0 4px rgba(59,130,246,.16); }
        body.dark-theme .field input,body.dark-theme .field select { background:#0f172a; border-color:rgba(148,163,184,.45); color:#f8fafc; }
        body.dark-theme .field input::placeholder,body.dark-theme .field select:invalid { color:#cbd5e1; opacity:1; }
        body.dark-theme .field input[type="date"] { color-scheme:dark; }
        body.dark-theme .field input[type="date"]::-webkit-calendar-picker-indicator { filter:invert(1) brightness(1.2); opacity:.95; cursor:pointer; }
        .coc-table th,.coc-table td { white-space: nowrap; }
        .coc-table td.activity-cell { white-space: normal; line-height: 1.3; }
        .actions { margin-top:14px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .btn,.link-btn { border:0; border-radius:10px; padding:10px 14px; cursor:pointer; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; justify-content:center; min-block-size:42px; }
        .btn { background:linear-gradient(135deg,var(--primary),var(--accent)); color:#fff; } .link-btn { background:#e2e8f0; color:#0f172a; } .link-cert { background:#0f766e; color:#fff; }
        .msg { margin-bottom:10px; padding:10px 12px; border-radius:10px; background:rgba(22,163,74,.12); color:#166534; border:1px solid rgba(22,163,74,.2); }
        .err { margin-bottom:10px; padding:10px 12px; border-radius:10px; background:rgba(220,38,38,.12); color:#991b1b; border:1px solid rgba(220,38,38,.2); }
        .setting-card { display:flex; justify-content:space-between; align-items:center; padding:1.4rem; border-block-end:1px solid rgba(0,0,0,.05); }
        .toggle-switch { position:relative; display:inline-block; inline-size:55px; block-size:26px; }
        .toggle-switch input { opacity:0; inline-size:0; block-size:0; }
        .slider { position:absolute; cursor:pointer; inset:0; background-color:#cbd5e1; transition:.4s; border-radius:50px; }
        .slider:before { position:absolute; content:""; block-size:18px; inline-size:18px; inset-inline-start:4px; inset-block-end:4px; background-color:#fff; transition:.4s; border-radius:50%; }
        input:checked + .slider { background:linear-gradient(135deg,var(--primary),var(--accent)); } input:checked + .slider:before { transform:translateX(28px); }
        .about-card { border-inline-start:6px solid var(--primary); background:var(--card-bg); -webkit-backdrop-filter:var(--glass-blur); backdrop-filter:var(--glass-blur); padding:2rem; margin-block-end:2rem; border-radius:16px; box-shadow:0 8px 30px rgba(0,0,0,.05); }
        .about-text { color:var(--text-muted); line-height:1.8; margin:0; white-space:normal; }
        .contact-info { margin-block-start:3rem; text-align:center; border-block-start:1px solid rgba(0,0,0,.08); padding-block-start:2rem; }
        .contact-info p { margin:.4rem 0; color:var(--text-muted); font-size:.9rem; white-space:normal; }
        .website-link { color:var(--primary); text-decoration:none; font-weight:600; }
        @media (max-width:1200px){ .form-grid{ grid-template-columns:repeat(3,minmax(170px,1fr)); } .form-grid .field-wide{ grid-column:span 3; } }
        @media (max-width:900px){ .form-grid{ grid-template-columns:repeat(2,minmax(170px,1fr)); } .form-grid .field-wide{ grid-column:span 2; } .form-grid .field-narrow{ max-inline-size: none; } }
        @media (max-width:640px){ .form-grid{ grid-template-columns:1fr; } .form-grid .field-wide{ grid-column:span 1; } }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="logo-container">
        <img src="/coc_system/templates/company_logo.jpg.png" alt="Company Logo" class="sidebar-logo">
        <h1>HR COC MANAGEMENT SYSTEM</h1>
    </div>
    <a href="#" onclick="showPage('dashboard-section', this); return false;" id="nav-dash" class="nav-item">Dashboard</a>
    <a href="#" onclick="showPage('employee-section', this); return false;" id="nav-emp" class="nav-item">Employees</a>
    <a href="#" onclick="showPage('coc-section', this); return false;" id="nav-coc" class="nav-item">COC Entry</a>
    <a href="#" onclick="showPage('settings-section', this); return false;" id="nav-settings" class="nav-item">Settings</a>
    <a href="#" onclick="showPage('about-section', this); return false;" id="nav-about" class="nav-item">About</a>
    <div class="sidebar-footer"><a class="btn-logout-side" href="/coc_system/logout.php">Logout</a></div>
</div>

<div class="content">
    <div id="dashboard-section" class="table-container hidden-section">
        <h2 class="municipality-title"><?php echo $canEdit ? 'Admin Dashboard' : 'My Dashboard'; ?></h2>
        <table>
            <thead><tr><th>Full Name</th><th>Position</th><th>Department</th><th>Status</th><th class="txt-right">Operations</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><span class="txt-bold"><?php echo h((string) $employee['full_name']); ?></span></td>
                    <td><?php echo h((string) $employee['position']); ?></td>
                    <td><?php echo h((string) $employee['department']); ?></td>
                    <td><span class="status-badge <?php echo strtolower((string) $employee['status']) === 'active' ? 'active' : 'on-leave'; ?>"><?php echo h((string) $employee['status']); ?></span></td>
                    <td class="txt-right"><a href="/coc_system/index.php?section=coc&employee_id=<?php echo (int) $employee['id']; ?>" class="btn-action"><?php echo $canEdit ? 'Add COC' : 'View COC'; ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($employees === []): ?><tr><td colspan="5">No employee records yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="employee-section" class="table-container hidden-section">
        <h2 class="municipality-title">Employee Directory</h2>
        <table>
            <thead><tr><th>Full Name</th><th>Position</th><th>Department</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><span class="txt-bold"><?php echo h((string) $employee['full_name']); ?></span></td>
                    <td><?php echo h((string) $employee['position']); ?></td>
                    <td><?php echo h((string) $employee['department']); ?></td>
                    <td><span class="status-badge <?php echo strtolower((string) $employee['status']) === 'active' ? 'active' : 'on-leave'; ?>"><?php echo h((string) $employee['status']); ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($employees === []): ?><tr><td colspan="4">No employee records yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="coc-section" class="table-container hidden-section">
        <h2 class="municipality-title"><?php echo $canEdit ? 'COC Record Entry' : 'My COC Records'; ?></h2>
        <?php if ($message !== ''): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
        <?php if ($canEdit): ?>
            <form method="post">
                <input type="hidden" name="form_type" value="coc">
                <div class="form-grid">
                    <div class="field field-wide">
                        <label for="employee_id">Employee</label>
                        <select id="employee_id" name="employee_id" onchange="onEmployeeSelectChange(this.value)" required>
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo (int) $employee['id']; ?>" <?php echo ((int) $employee['id'] === $selectedEmployeeId) ? 'selected' : ''; ?>>
                                    <?php echo h((string) $employee['full_name'] . ' - ' . (string) $employee['department']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field field-wide">
                        <label for="activity_label">Activity Label</label>
                        <input id="activity_label" name="activity_label" placeholder="e.g., November OT">
                    </div>
                    <div class="field">
                        <label for="activity_date">Activity Date</label>
                        <input id="activity_date" name="activity_date" type="date">
                    </div>
                    <div class="field field-narrow">
                        <label for="hours_earned">COC Earned (hrs)</label>
                        <input id="hours_earned" name="hours_earned" type="number" step="0.5" min="0" value="0">
                    </div>
                    <div class="field">
                        <label for="cto_date">CTO Date</label>
                        <input id="cto_date" name="cto_date" type="date">
                    </div>
                    <div class="field field-narrow">
                        <label for="cto_hours">CTO Used (hrs)</label>
                        <input id="cto_hours" name="cto_hours" type="number" step="0.5" min="0" value="0">
                    </div>
                    <div class="field">
                        <label for="valid_until">Valid Until</label>
                        <input id="valid_until" name="valid_until" type="date">
                    </div>
                </div>
                <div class="actions">
                    <button class="btn" type="submit">Save COC Record</button>
                    <?php if ($selectedEmployee): ?><a class="link-btn link-cert" href="/coc_system/certificate.php?employee_id=<?php echo (int) $selectedEmployee['id']; ?>">View Certificate</a><?php endif; ?>
                </div>
            </form>

            <h3 class="municipality-title" style="margin-top:2rem;">Quick Add Employee</h3>
            <form method="post">
                <input type="hidden" name="form_type" value="employee">
                <div class="form-grid">
                    <div class="field field-wide"><label for="emp_full_name">Full Name</label><input id="emp_full_name" name="full_name" required></div>
                    <div class="field"><label for="emp_position">Position</label><select id="emp_position" name="position" required><option value="">Select position</option><?php foreach ($positionOptions as $positionOption): ?><option value="<?php echo h($positionOption); ?>"><?php echo h($positionOption); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="emp_department">Department</label><input id="emp_department" name="department" required></div>
                    <div class="field"><label for="emp_status">Status</label><select id="emp_status" name="status"><option value="Active">Active</option><option value="On Leave">On Leave</option></select></div>
                </div>
                <div class="actions"><button class="btn" type="submit">Add Employee</button></div>
            </form>
        <?php else: ?>
            <div class="msg">Read-only mode: you can only view your own COC records.</div>
            <?php if ($selectedEmployee): ?><div class="actions"><a class="link-btn link-cert" href="/coc_system/certificate.php?employee_id=<?php echo (int) $selectedEmployee['id']; ?>">View Certificate</a></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($selectedEmployee): ?>
            <h3 class="municipality-title" style="margin-top:2rem;"><?php echo h((string) $selectedEmployee['full_name']); ?> - Existing COC Records</h3>
            <table>
                <thead><tr><th style="width:30%;">Activity</th><th style="width:13%;" class="txt-center">Activity Date</th><th style="width:12%;" class="txt-num">Earned</th><th style="width:13%;" class="txt-center">Valid Until</th><th style="width:13%;" class="txt-center">CTO Date</th><th style="width:12%;" class="txt-num">Used</th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td class="activity-cell"><?php echo h((string) $record['activity_label']); ?></td>
                        <td class="txt-center"><?php echo h((string) ($record['activity_date'] ?? '-')); ?></td>
                        <td class="txt-num"><?php echo number_format((float) $record['hours_earned'], 2); ?></td>
                        <td class="txt-center"><?php echo h((string) ($record['valid_until'] ?? '-')); ?></td>
                        <td class="txt-center"><?php echo h((string) ($record['cto_date'] ?? '-')); ?></td>
                        <td class="txt-num"><?php echo number_format((float) $record['cto_hours'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($records === []): ?><tr><td colspan="6">No COC records yet for this employee.</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div id="settings-section" class="settings-container hidden-section">
        <h2 class="municipality-title">System Settings</h2>
        <div class="setting-card"><div><strong class="txt-main">Dark Mode</strong><br><small class="txt-muted">Toggle the high-contrast dark interface.</small></div><label class="toggle-switch"><input type="checkbox" id="themeToggle" onclick="toggleTheme()"><span class="slider"></span></label></div>
        <div class="setting-card"><div><strong class="txt-main">Two-Factor Authentication</strong><br><small class="txt-muted">Add an extra layer of security to admin login.</small></div><label class="toggle-switch"><input type="checkbox"><span class="slider"></span></label></div>
        <div class="setting-card"><div><strong class="txt-main">Confidential Mode</strong><br><small class="txt-muted">Blur sensitive employee data when not hovered.</small></div><label class="toggle-switch"><input type="checkbox"><span class="slider"></span></label></div>
        <div class="setting-card"><div><strong class="txt-main">Email Summary</strong><br><small class="txt-muted">Receive weekly HR status reports via email.</small></div><label class="toggle-switch"><input type="checkbox" checked><span class="slider"></span></label></div>
        <div class="setting-card"><div><strong class="txt-main">Auto-Logout</strong><br><small class="txt-muted">Logout after 15 minutes of inactivity.</small></div><label class="toggle-switch"><input type="checkbox" checked><span class="slider"></span></label></div>
    </div>

    <div id="about-section" class="hidden-section table-container">
        <h2 class="municipality-title">Municipality of Agoncillo</h2>
        <div class="about-card"><h3>Mission</h3><p class="about-text">To establish a community with initiative in pursuing peace, unity and good governance for health, education, agriculture, tourism and livelihood.<br><br>"Itaguyod ang pamayanang may malasakit sa pagsusulong ng kapayapaan, pagkakaisa at mabuting pamamahala sa kalusugan, edukasyon, agrikultura, turismo at kabuhayan".</p></div>
        <div class="about-card"><h3>Vision</h3><p class="about-text">A premier Agri-Ecotourism municipality, home of God-loving, healthy and empowered citizens living in a sustainable environment, safe and resilient community with a progressive economy, driven by honest and competent leaders towards a "Magandang Agoncillo, Magandang Serbisyo Publiko."<br><br>"Nangungunang munisipalidad sa Agri-Ecotourism, tahanan ng mga mamamayang Maka-Diyos, malusog, may kakayahan, naninirahan sa isang ligtas at matatag na komunidad na may maunlad na ekonomiya na pinamumunuan ng matapat at mahusay na mga pinuno tungo sa Magandang Agoncillo, Magandang Serbisyo Publiko".</p></div>
        <div class="contact-info">
            <h4>Contact Us</h4>
            <p><strong>Address:</strong> Poblacion, Agoncillo, Batangas</p>
            <p><strong>Mobile No.:</strong> +63 912 345 6789</p>
            <p><strong>E-Mail Address:</strong> info@agoncillo.gov.ph</p>
            <p><strong>Website Address:</strong> <a href="http://www.agoncillo.gov.ph" target="_blank" rel="noopener" class="website-link">www.agoncillo.gov.ph</a></p>
        </div>
    </div>
</div>

<script>
    function onEmployeeSelectChange(employeeId) {
        const url = new URL(window.location.href);
        url.searchParams.set('section', 'coc');
        if (employeeId) {
            url.searchParams.set('employee_id', employeeId);
        } else {
            url.searchParams.delete('employee_id');
        }
        url.searchParams.delete('saved');
        url.searchParams.delete('emp_saved');
        window.location.href = url.toString();
    }

    function showPage(pageId, navElement) {
        ['dashboard-section', 'employee-section', 'coc-section', 'settings-section', 'about-section'].forEach(function (id) {
            const section = document.getElementById(id);
            if (section) {
                section.style.display = 'none';
            }
        });
        const targetSection = document.getElementById(pageId);
        if (targetSection) {
            targetSection.style.display = 'block';
        }
        document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
        if (navElement) {
            navElement.classList.add('active');
        }
    }
    (function initSection() {
        const map = {dashboard:{id:'dashboard-section',nav:'nav-dash'},employee:{id:'employee-section',nav:'nav-emp'},coc:{id:'coc-section',nav:'nav-coc'},settings:{id:'settings-section',nav:'nav-settings'},about:{id:'about-section',nav:'nav-about'}};
        const section = '<?php echo h($activeSection); ?>';
        const target = map[section] || map.dashboard;
        showPage(target.id, document.getElementById(target.nav));
    })();
    function toggleTheme() { document.body.classList.toggle('dark-theme'); }
</script>
</body>
</html>
