<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

$message = '';
$error = '';
$availableRoles = [
    'admin' => 'Administrator',
    'hr_officer' => 'HR Officer',
    'hr_staff' => 'HR Staff',
    'department_head' => 'Department Head',
    'mayor' => 'Municipal Mayor',
    'vice_mayor' => 'Vice Mayor',
    'sb_member' => 'Sangguniang Bayan Member',
    'accounting_staff' => 'Accounting Staff',
    'budget_officer' => 'Budget Officer',
    'records_officer' => 'Records Officer',
    'employee' => 'Employee',
];
$roleDepartmentMap = [
    'admin' => 'Human Resource',
    'hr_officer' => 'Human Resource',
    'hr_staff' => 'Human Resource',
    'department_head' => 'Department Office',
    'mayor' => 'Office of the Mayor',
    'vice_mayor' => 'Office of the Vice Mayor',
    'sb_member' => 'Sangguniang Bayan',
    'accounting_staff' => 'Accounting',
    'budget_officer' => 'Budget',
    'records_officer' => 'Records Management',
    'employee' => 'General Services',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'employee';

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!isset($availableRoles[$role])) {
        $error = 'Invalid role selected.';
    } else {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role) VALUES (:email, :hash, :name, :role)');
            $stmt->execute([
                'email' => $email,
                'hash' => $hash,
                'name' => $fullName,
                'role' => $role,
            ]);

            $position = $availableRoles[$role];
            $department = $roleDepartmentMap[$role] ?? 'General Services';

            $existsStmt = $pdo->prepare(
                'SELECT id FROM employees WHERE full_name = :full_name AND position = :position AND department = :department LIMIT 1'
            );
            $existsStmt->execute([
                'full_name' => $fullName,
                'position' => $position,
                'department' => $department,
            ]);
            $employeeExists = (bool) $existsStmt->fetch();

            if (!$employeeExists) {
                $employeeStmt = $pdo->prepare(
                    'INSERT INTO employees (full_name, position, department, status) VALUES (:full_name, :position, :department, :status)'
                );
                $employeeStmt->execute([
                    'full_name' => $fullName,
                    'position' => $position,
                    'department' => $department,
                    'status' => 'Active',
                ]);
            }

            $pdo->commit();
            $message = 'User created successfully and added to COC records.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) $e->getCode() === 23000) {
                $error = 'That email already exists.';
            } else {
                $error = 'Failed to create user.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COC Management | Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #101114;
            --paper: #f6f2e9;
            --accent: #f97316;
            --accent-deep: #c2410c;
            --mint: #84cc16;
            --shadow: rgba(16, 17, 20, 0.15);
            --card: rgba(255, 255, 255, 0.7);
            --ring: rgba(249, 115, 22, 0.35);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-block-size: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            padding-block: 2rem;
            position: relative;
            overflow: hidden;
            background: radial-gradient(1200px 600px at 10% 10%, #ffe8d1, transparent),
                        radial-gradient(900px 500px at 90% 20%, #e6f7d9, transparent),
                        linear-gradient(180deg, #f6f2e9, #efe7d8);
            color: var(--ink);
            font-family: "Space Grotesk", system-ui, sans-serif;
        }

        body::before,
        body::after {
            content: "";
            position: absolute;
            inset: auto;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            inset-inline-start: -120px;
            inset-block-start: -160px;
            inline-size: 360px;
            block-size: 360px;
            border-radius: 48% 52% 45% 55%;
            background: radial-gradient(circle at 30% 30%, rgba(249, 115, 22, 0.35), transparent 65%);
            filter: blur(2px);
        }

        body::after {
            inset-inline-end: -140px;
            inset-block-end: -180px;
            inline-size: 420px;
            block-size: 420px;
            border-radius: 55% 45% 52% 48%;
            background: radial-gradient(circle at 60% 60%, rgba(132, 204, 22, 0.35), transparent 65%);
            filter: blur(2px);
        }

        .topbar {
            inline-size: min(980px, 92vw);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.2rem;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.65);
            border: 1px solid rgba(16,17,20,0.08);
            box-shadow: 0 12px 30px var(--shadow);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            position: relative;
            z-index: 1;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .brand img {
            inline-size: 44px;
            block-size: 44px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 8px 18px rgba(16,17,20,0.15);
            border: 2px solid rgba(16,17,20,0.08);
        }

        .brand-text {
            display: grid;
            gap: 0.1rem;
        }

        .brand-title {
            font-size: 0.95rem;
        }

        .brand-subtitle {
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(16, 17, 20, 0.55);
        }

        .page {
            inline-size: min(980px, 92vw);
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 2rem;
            align-items: stretch;
            position: relative;
            z-index: 1;
        }

        .hero {
            background: #101114;
            color: #f8f7f4;
            border-radius: 28px;
            padding: 2.5rem;
            box-shadow: 0 30px 80px var(--shadow);
            position: relative;
            overflow: hidden;
            display: grid;
            gap: 2rem;
            align-content: space-between;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset-block-end: -120px;
            inset-inline-end: -120px;
            inline-size: 320px;
            block-size: 320px;
            border-radius: 50%;
            background: radial-gradient(circle at 40% 40%, rgba(249,115,22,0.85), transparent 60%);
            opacity: 0.9;
        }

        .hero h1 {
            font-family: "Instrument Serif", serif;
            font-size: clamp(2rem, 3.2vw, 3rem);
            margin: 0;
            letter-spacing: -0.02em;
        }

        .hero p {
            margin: 0;
            line-height: 1.7;
            max-inline-size: 40ch;
            color: rgba(248, 247, 244, 0.75);
        }

        .hero .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            font-size: 0.85rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .hero .badge span {
            inline-size: 10px;
            block-size: 10px;
            border-radius: 50%;
            background: var(--mint);
            box-shadow: 0 0 10px rgba(132,204,22,0.6);
        }

        .register-card {
            background: var(--card);
            border-radius: 28px;
            padding: 2.2rem;
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px var(--shadow);
            border: 1px solid rgba(16,17,20,0.08);
            display: grid;
            gap: 1.2rem;
        }

        .register-card h2 {
            margin: 0;
            font-size: 1.6rem;
        }

        .register-card small {
            color: rgba(16, 17, 20, 0.6);
        }

        .field {
            display: grid;
            gap: 0.5rem;
        }

        .field label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
        }

        .field input,
        .field select {
            padding: 0.9rem 1rem;
            border-radius: 14px;
            border: 1px solid rgba(16,17,20,0.15);
            background: rgba(255,255,255,0.85);
            font-family: inherit;
            font-size: 1rem;
        }

        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--ring);
        }

        .actions {
            display: grid;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.95rem 1rem;
            border-radius: 14px;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }

        .btn.primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-deep));
            color: #fff;
            box-shadow: 0 10px 30px rgba(249,115,22,0.35);
        }

        .btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 35px rgba(249,115,22,0.45);
        }

        .btn.ghost {
            background: transparent;
            border: 1px solid rgba(16,17,20,0.2);
            color: var(--ink);
        }

        .msg {
            padding: 0.8rem 1rem;
            border-radius: 12px;
            background: rgba(22, 163, 74, 0.12);
            color: #166534;
            border: 1px solid rgba(22, 163, 74, 0.25);
        }

        .err {
            padding: 0.8rem 1rem;
            border-radius: 12px;
            background: rgba(185, 28, 28, 0.1);
            color: #991b1b;
            border: 1px solid rgba(185, 28, 28, 0.25);
        }

        .note {
            font-size: 0.9rem;
            color: rgba(16, 17, 20, 0.75);
            margin: 0;
        }

        @media (max-inline-size: 860px) {
            .page { grid-template-columns: 1fr; }
            .hero { order: 2; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="/coc_system/login.php">
            <img src="/coc_system/templates/company_logo.jpg.png" alt="COC Management logo">
            <span class="brand-text">
                <span class="brand-title">COC Management</span>
                <span class="brand-subtitle">HR Command Center</span>
            </span>
        </a>
    </header>

    <main class="page">
        <section class="hero">
            <div>
                <div class="badge"><span></span> Account Provisioning</div>
                <h1>Create a new COC user account.</h1>
                <p>Register administrators and staff who need access to employee records and certificate workflows.</p>
            </div>
            <div>
                <p>Use official municipality email addresses and assign the proper role before account activation.</p>
            </div>
        </section>

        <section class="register-card" aria-labelledby="register-title">
            <div>
                <h2 id="register-title">Create User</h2>
                <small>Fill in account details to register.</small>
            </div>

            <form method="post">
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" required>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>

                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <?php foreach ($availableRoles as $roleValue => $roleLabel): ?>
                            <option value="<?php echo htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $roleValue === 'employee' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="actions">
                    <button class="btn primary" type="submit">Create User</button>
                    <a class="btn ghost" href="/coc_system/login.php">Back to Login</a>
                </div>
            </form>

            <?php if ($message): ?>
                <div class="msg"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <p class="note">After creating the first admin, restrict or remove this page for security.</p>
        </section>
    </main>
</body>
</html>
