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

$employeeId = (int) ($_GET['employee_id'] ?? 0);
if ($employeeId <= 0 && isset($_GET['name'])) {
    $stmt = $pdo->prepare('SELECT id FROM employees WHERE full_name = :name LIMIT 1');
    $stmt->execute(['name' => trim((string) $_GET['name'])]);
    $row = $stmt->fetch();
    if ($row) {
        $employeeId = (int) $row['id'];
    }
}

if (!$canEdit) {
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

    $employeeId = $userEmployeeId;
}

$employee = null;
if ($employeeId > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, position, department FROM employees WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $employeeId]);
    $employee = $stmt->fetch() ?: null;
}

if (!$employee) {
    if (!$canEdit) {
        http_response_code(403);
        exit('Unable to load your certificate.');
    }

    $employees = $pdo->query('SELECT id, full_name, department FROM employees ORDER BY full_name ASC')->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Employee Certificate</title>
        <style>
            body { font-family: Arial, sans-serif; background: #eef2ff; margin: 0; padding: 24px; }
            .card { max-width: 620px; margin: 0 auto; background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 12px 30px rgba(15,23,42,0.1); }
            h1 { margin-top: 0; }
            select, button, a { width: 100%; box-sizing: border-box; padding: 10px; border-radius: 8px; font-size: 14px; }
            select { border: 1px solid #cbd5e1; margin-bottom: 10px; }
            button { border: 0; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; margin-bottom: 10px; }
            a { display: inline-block; text-align: center; text-decoration: none; background: #e2e8f0; color: #0f172a; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Generate Employee Certificate</h1>
            <form method="get">
                <select name="employee_id" required>
                    <option value="">Select employee</option>
                    <?php foreach ($employees as $entry): ?>
                        <option value="<?php echo (int) $entry['id']; ?>">
                            <?php echo htmlspecialchars((string) $entry['full_name'] . ' - ' . (string) $entry['department'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Generate</button>
            </form>
            <a href="/coc_system/add_coc.php">Add COC Records</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$earnedStmt = $pdo->prepare(
    'SELECT activity_label, activity_date, hours_earned, valid_until
     FROM coc_entries
     WHERE employee_id = :employee_id AND hours_earned > 0
     ORDER BY activity_date ASC, id ASC'
);
$earnedStmt->execute(['employee_id' => (int) $employee['id']]);
$earnedRows = $earnedStmt->fetchAll();

$usedStmt = $pdo->prepare(
    'SELECT cto_date, cto_hours
     FROM coc_entries
     WHERE employee_id = :employee_id AND cto_hours > 0 AND cto_date IS NOT NULL
     ORDER BY cto_date ASC, id ASC'
);
$usedStmt->execute(['employee_id' => (int) $employee['id']]);
$usedRows = $usedStmt->fetchAll();

$totalEarned = 0.0;
foreach ($earnedRows as $row) {
    $totalEarned += (float) $row['hours_earned'];
}

$totalUsed = 0.0;
foreach ($usedRows as $row) {
    $totalUsed += (float) $row['cto_hours'];
}

$remainingBalance = $totalEarned - $totalUsed;
if ($remainingBalance < 0) {
    $remainingBalance = 0.0;
}

$usageBalanceRows = [];
$runningBalance = $totalEarned;

foreach ($usedRows as $row) {
    $ctoDate = (string) ($row['cto_date'] ?? '');
    $used = (float) $row['cto_hours'];

    // Beginning Balance + COCs Earned for this row.
    $before = $runningBalance;

    $after = $before - $used;
    if ($after < 0) {
        $after = 0.0;
    }

    $usageBalanceRows[] = [
        'cto_date' => $ctoDate,
        'used' => $used,
        'before' => $before,
        'after' => $after,
    ];

    $runningBalance = $after;
}

if ($usageBalanceRows === []) {
    $usageBalanceRows[] = [
        'cto_date' => '-',
        'used' => 0.0,
        'before' => $totalEarned,
        'after' => $totalEarned,
    ];
}

$asOfDate = date('F Y');
if ($earnedRows !== []) {
    $last = $earnedRows[count($earnedRows) - 1]['activity_date'] ?? null;
    if ($last) {
        $asOfDate = date('F Y', strtotime((string) $last));
    }
}

$preparedBy = (string) ($_SESSION['user_name'] ?? 'HR Staff');
$issuedDate = date('F j, Y');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fmtHours(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function fmtMonthYear(?string $dateValue): string
{
    if ($dateValue === null || trim($dateValue) === '') {
        return '-';
    }

    $ts = strtotime($dateValue);
    if ($ts === false) {
        return (string) $dateValue;
    }

    return date('F Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee COC Certificate</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #d9d9d9; color: #111; }
        .flip-toolbar {
            max-inline-size: 1120px;
            margin: 0 auto 14px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
        }
        .toolbar-btn {
            border: 1px solid #d1d5db;
            color: #0f172a;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-inline-size: 112px;
            transition: transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease;
            box-shadow: 0 4px 10px rgba(2, 6, 23, 0.08);
            background: #fff;
        }
        .toolbar-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(2, 6, 23, 0.14);
        }
        .toolbar-btn:active {
            transform: translateY(0);
            box-shadow: 0 3px 8px rgba(2, 6, 23, 0.1);
        }
        .toolbar-btn:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.28);
            outline-offset: 1px;
        }
        .flip-btn {
            background: linear-gradient(135deg, #111827, #334155);
            border-color: #1f2937;
            color: #fff;
        }
        .print-btn {
            background: linear-gradient(135deg, #0f766e, #0ea5a4);
            border-color: #0f766e;
            color: #fff;
        }
        .nav-btn {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            border-color: #2563eb;
            color: #fff;
        }
        .dash-btn {
            background: linear-gradient(135deg, #475569, #64748b);
            border-color: #475569;
            color: #fff;
        }
        .flip-stage { max-inline-size: 1120px; margin: auto; perspective: 2000px; }
        .flip-card { position: relative; min-block-size: 790px; transform-style: preserve-3d; transition: transform 0.8s ease; }
        .flip-card.is-flipped { transform: rotateY(180deg); }
        .cert-face { position: absolute; inset: 0; backface-visibility: hidden; }
        .cert-face.back { transform: rotateY(180deg); }
        .cert-container { padding: 24px 30px; block-size: 100%; background: #fff; box-shadow: 0 0 20px rgba(0,0,0,0.18); box-sizing: border-box; }
        .front .cert-container { text-align: center; }
        .gov-header { line-height: 1.28; font-weight: 700; font-size: 15px; }
        .gov-header div { margin: 0; }
        .seal { inline-size: 66px; block-size: 66px; object-fit: contain; margin: 8px auto 6px; display: block; }
        .office-title { font-size: 34px; font-weight: 800; margin: 0 0 12px; letter-spacing: 0.2px; }
        .title-box { display: inline-block; border: 2px solid #333; border-radius: 12px; padding: 8px 28px 7px; line-height: 1.1; margin-block-end: 18px; }
        .title-box .line1 { font-size: 39px; }
        .title-box .line2 { font-size: 40px; font-weight: 700; }
        .title-box .line2 span { text-decoration: underline; text-underline-offset: 2px; }
        .entitlement { font-size: 17px; line-height: 1.28; margin: 4px 0 0; }
        .employee-name { text-decoration: underline; text-underline-offset: 2px; font-weight: 700; text-transform: uppercase; }
        .employee-hours { font-weight: 700; }
        .name-note { font-size: 18px; font-weight: 700; margin: 0 0 12px; }
        .coc-table { inline-size: 100%; border-collapse: collapse; margin: 0 auto; border: 1px solid #555; table-layout: fixed; }
        .coc-table th, .coc-table td { border: 1px solid #555; padding: 3px 8px; font-size: 14px; line-height: 1.15; }
        .coc-table th { font-weight: 700; text-align: center; }
        .coc-table td:first-child { text-align: left; }
        .coc-table td:nth-child(2), .coc-table td:nth-child(3) { text-align: center; }
        .note-line { text-align: left; margin: 20px 0 0; font-size: 17px; font-weight: 700; }
        .prepared-signature-space { block-size: 36px; }
        .prepared { text-align: left; margin: 8px 0 0; font-size: 17px; line-height: 1.3; }
        .prepared .label { font-weight: 700; }
        .prepared .name { font-weight: 700; }
        .prepared .indent { margin-inline-start: 80px; }
        .prepared .date-underline { text-decoration: underline; text-underline-offset: 2px; }
        .back .cert-container { text-align: left; padding-top: 24px; }
        .back-table { inline-size: 100%; margin: 0 auto; border-collapse: collapse; border: 1px solid #555; table-layout: fixed; }
        .back-table th, .back-table td { border: 1px solid #555; padding: 4px 6px; text-align: center; vertical-align: middle; font-size: 14px; line-height: 1.15; }
        .back-table thead th { font-weight: 700; font-size: 15px; }
        .back-table td:first-child { text-align: left; padding-inline-start: 8px; }
        .back-foot { inline-size: 100%; margin: 20px auto 0; display: flex; justify-content: space-between; gap: 24px; font-size: 16px; }
        .back-foot .left, .back-foot .right { text-align: left; line-height: 1.24; }
        .back-sign { font-size: 28px; font-style: italic; margin-block-end: -4px; }
        .text-underline { text-decoration: underline; font-weight: 700; }
        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body { background: #fff; padding: 0; margin: 0; }
            .flip-toolbar { display: none; }
            .flip-stage { max-inline-size: none; margin: 0; perspective: none; }
            .flip-card { transform: none !important; min-block-size: auto; position: static; }
            .cert-face {
                position: static;
                inset: auto;
                transform: none !important;
                backface-visibility: visible;
                break-inside: avoid;
                page-break-inside: avoid;
                inline-size: 281mm;
                min-block-size: 194mm;
                max-block-size: 194mm;
                margin: 0 auto;
                overflow: hidden;
            }
            .cert-face.front { break-after: page; page-break-after: always; }
            .cert-face.back { break-before: page; page-break-before: always; break-after: auto; page-break-after: auto; }
            .cert-container { box-shadow: none; block-size: 100%; max-inline-size: none; }
        }
    </style>
</head>
<body>
<div class="flip-toolbar">
    <button id="flipButton" class="toolbar-btn flip-btn" type="button">Show Back</button>
    <button id="printButton" class="toolbar-btn print-btn" type="button">Print Copy</button>
    <?php if ($canEdit): ?><a class="toolbar-btn nav-btn" href="/coc_system/add_coc.php?employee_id=<?php echo (int) $employee['id']; ?>">Edit Records</a><?php endif; ?>
    <a class="toolbar-btn dash-btn" href="/coc_system/index.php?section=<?php echo $canEdit ? 'dashboard' : 'coc'; ?>">Back to Dashboard</a>
</div>

<div class="flip-stage">
    <div id="flipCard" class="flip-card">
        <div class="cert-face front">
            <div class="cert-container">
                <div class="gov-header">
                    <div>Republic of the Philippines</div>
                    <div>Province of Batangas</div>
                    <div>Municipality of Agoncillo</div>
                </div>
                <img src="templates/company_logo.jpg.png" alt="Municipality Seal" class="seal">
                <div class="office-title">OFFICE OF THE HUMAN RESOURCE</div>

                <div class="title-box">
                    <div class="line1">Certificate of COC Earned</div>
                    <div class="line2">As of <span><?php echo h(strtoupper($asOfDate)); ?></span></div>
                </div>

                <p class="entitlement">
                    This certificate entitles Mr./Ms.
                    <span class="employee-name"><?php echo h(strtoupper((string) $employee['full_name'])); ?></span>
                    <span class="employee-hours"><?php echo h(fmtHours($totalEarned)); ?></span> to
                    <span class="employee-hours"><?php echo h(fmtHours($remainingBalance)); ?></span> hrs of Compensatory Overtime Credits
                </p>
                <p class="name-note">(Name of Employee (# of hours))</p>

                <table class="coc-table">
                    <colgroup>
                        <col style="width: 36%;">
                        <col style="width: 34%;">
                        <col style="width: 30%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Date and Kind of Activity</th>
                            <th>COC Earned (hours accrued)</th>
                            <th>Valid Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($earnedRows as $row): ?>
                            <tr>
                                <td><?php echo h((string) $row['activity_label']); ?></td>
                                <td><?php echo h(fmtHours((float) $row['hours_earned'])); ?></td>
                                <td><?php echo h(fmtMonthYear($row['valid_until'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = count($earnedRows); $i < 6; $i++): ?>
                            <tr><td>&nbsp;</td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <p class="note-line">Note: Each employee may accrue not more than forty (40) hours of COC's per month.</p>
                <div class="prepared-signature-space"></div>

                <div class="prepared">
                    <div><span class="label">Prepared by:</span> <span class="name">Angela Marie M. De Castro, CHRA</span></div>
                    <div class="indent">ADMINISTRATIVE AIDE IV/BOOKBINDER II</div>
                    <div><span class="label">Date Issued:</span> <span class="date-underline"><?php echo h($issuedDate); ?></span></div>
                </div>
            </div>
        </div>

        <div class="cert-face back">
            <div class="cert-container">
                <table class="back-table">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 14%;">No. of Hours of Earned COCs<br>(Beginning Balance + COCs Earned)</th>
                            <th rowspan="2" style="width: 12%;">Date of CTO</th>
                            <th rowspan="2" style="width: 11%;">Used COCs<br>(Blocks of 4hrs or 8hrs only)</th>
                            <th rowspan="2" style="width: 14%;">Remaining COCs<br>(Beginning Balance - Used COCs)</th>
                            <th colspan="3" style="width: 49%;">Approved by Authorized Signatories</th>
                        </tr>
                        <tr>
                            <th>Head of Office</th>
                            <th>LCE</th>
                            <th>HR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usageBalanceRows as $row): ?>
                            <tr>
                                <td><?php echo h(fmtHours((float) $row['before'])); ?> hrs</td>
                                <td><?php echo h((string) $row['cto_date']); ?></td>
                                <td><?php echo h(fmtHours((float) $row['used'])); ?></td>
                                <td><?php echo h(fmtHours((float) $row['after'])); ?> hrs</td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = count($usageBalanceRows); $i < 10; $i++): ?>
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <div class="back-foot">
                    <div class="left">
                        <div class="back-sign">&nbsp;</div>
                        <div class="text-underline"><?php echo h(strtoupper((string) $employee['full_name'])); ?></div>
                        <div>Signature over Printed Name</div>
                        <div>Biometric ID No. 126</div>
                    </div>
                    <div class="right">
                        <div>Position: <strong><?php echo h(strtoupper((string) $employee['position'])); ?></strong></div>
                        <div>Department: <strong><?php echo h(strtoupper((string) $employee['department'])); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const flipCard = document.getElementById('flipCard');
        const flipButton = document.getElementById('flipButton');
        const printButton = document.getElementById('printButton');
        flipButton.addEventListener('click', function () {
            flipCard.classList.toggle('is-flipped');
            flipButton.textContent = flipCard.classList.contains('is-flipped') ? 'Show Front' : 'Show Back';
        });
        printButton.addEventListener('click', function () {
            window.print();
        });
    })();
</script>
</body>
</html>
