<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        http_response_code(400);
        exit('Email and password are required.');
    }

    $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        exit('Invalid credentials.');
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];

    header('Location: /coc_system/index.php');
    exit;
}

readfile(__DIR__ . '/templates/login.html');