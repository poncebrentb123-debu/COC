<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: /coc_system/login.php');
    exit;
}

require __DIR__ . '/db.php';

$allowedRoles = ['admin', 'hr_officer', 'hr_staff'];
$userRole = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($userRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Only HR/Admin users can manage employee records.');
}

$positionOptions = [
    'Administrative Aide',
    'Administrative Officer',
    'Municipal Mayor',
    'Municipal Vice Mayor',
    'Sangguniang Bayan Member',
    'Municipal Administrator',
    'Municipal Accountant',
    'Municipal Budget Officer',
    'Municipal Treasurer',
    'Municipal Assessor',
    'Municipal Engineer',
    'Municipal Planning and Development Coordinator',
    'Municipal Agriculturist',
    'Municipal Health Officer',
    'Municipal Social Welfare and Development Officer',
    'Municipal Civil Registrar',
    'Human Resource Management Officer',
    'Records Officer',
    'Information Technology Officer',
    'Disaster Risk Reduction and Management Officer',
    'Public Information Officer',
    'Legal Officer',
];

$message = '';
$error = '';
$selectedPosition = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $selectedPosition = $position;
    $department = trim($_POST['department'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if ($fullName === '' || $position === '' || $department === '') {
        $error = 'Full name, position, and department are required.';
    } elseif (!in_array($position, $positionOptions, true)) {
        $error = 'Please select a valid municipal position.';
    } elseif (!in_array($status, ['Active', 'On Leave'], true)) {
        $error = 'Invalid status selected.';
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
        $message = 'Employee record saved.';
    }
}

$employees = $pdo->query(
    'SELECT id, full_name, position, department, status, created_at FROM employees ORDER BY full_name ASC'
)->fetchAll();

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
    <title>COC Management | Add Employee</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 24px; color: #0f172a; }
        .container { max-width: 1100px; margin: 0 auto; }
        .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08); margin-bottom: 18px; }
        h1 { margin: 0 0 16px; font-size: 24px; }
        h2 { margin: 0 0 14px; font-size: 20px; }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        label { font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #475569; display: block; margin-bottom: 6px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; }
        .actions { margin-top: 12px; display: flex; gap: 10px; }
        button, .link-btn { border: 0; border-radius: 8px; padding: 10px 14px; cursor: pointer; text-decoration: none; font-weight: 700; }
        button { background: #2563eb; color: #fff; }
        .link-btn { background: #e2e8f0; color: #0f172a; }
        .msg { margin-bottom: 10px; padding: 10px; border-radius: 8px; background: #dcfce7; color: #166534; }
        .err { margin-bottom: 10px; padding: 10px; border-radius: 8px; background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e2e8f0; text-align: left; padding: 10px; font-size: 14px; }
        th { color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Employee Records</h1>
            <?php if ($message !== ''): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>

            <form method="post">
                <div class="grid">
                    <div>
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" required>
                    </div>
                    <div>
                        <label for="position">Position</label>
                        <select id="position" name="position" required>
                            <option value="">Select position</option>
                            <?php foreach ($positionOptions as $positionOption): ?>
                                <option value="<?php echo h($positionOption); ?>" <?php echo $selectedPosition === $positionOption ? 'selected' : ''; ?>>
                                    <?php echo h($positionOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="department">Department</label>
                        <input id="department" name="department" required>
                    </div>
                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Active">Active</option>
                            <option value="On Leave">On Leave</option>
                        </select>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit">Save Employee</button>
                    <a class="link-btn" href="/coc_system/index.php">Back to Dashboard</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Current Municipality Employees</h2>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Position</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><?php echo h((string) $employee['full_name']); ?></td>
                            <td><?php echo h((string) $employee['position']); ?></td>
                            <td><?php echo h((string) $employee['department']); ?></td>
                            <td><?php echo h((string) $employee['status']); ?></td>
                            <td><a href="/coc_system/add_coc.php?employee_id=<?php echo (int) $employee['id']; ?>">Add COC</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($employees === []): ?>
                        <tr><td colspan="5">No employee records yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
