<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: login.php
 * Secure Professor Authentication & First-Time Setup Portal
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect logged-in users directly to Dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$errorMsg = null;
$successMsg = null;
$dbError = null;
$professorCount = 0;

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query('SELECT COUNT(*) FROM professors');
    $professorCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$isSetupMode = (isset($_GET['setup']) && $_GET['setup'] === '1') || ($professorCount === 0 && !$dbError);

// Process Authentication Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Please refresh and submit again.';
    } else {
        $action = (string)($_POST['action'] ?? 'login');

        // 1. Process Login
        if ($action === 'login') {
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($email === '' || $password === '') {
                $errorMsg = 'Please enter both your email address and password.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM professors WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $prof = $stmt->fetch();

                if ($prof && password_verify($password, $prof['password_hash'])) {
                    loginUser($prof);
                    setFlash('success', 'Welcome back, ' . $prof['full_name'] . '!');
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $errorMsg = 'Invalid email or password. Please verify your credentials.';
                }
            }
        }

        // 2. Process Setup / Register Professor Account
        if ($action === 'register') {
            $employeeId = trim((string)($_POST['employee_id'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $department = trim((string)($_POST['department'] ?? 'College of Computer Studies'));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($fullName === '' || $email === '' || $password === '' || $employeeId === '') {
                $errorMsg = 'All fields are required to create a professor account.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = 'Please provide a valid institutional email address.';
            } elseif (strlen($password) < 8) {
                $errorMsg = 'Password must be at least 8 characters long for security.';
            } elseif ($password !== $confirmPassword) {
                $errorMsg = 'The passwords you entered do not match.';
            } else {
                // Check if email or employee ID already exists
                $checkStmt = $pdo->prepare('SELECT id FROM professors WHERE email = ? OR employee_id = ? LIMIT 1');
                $checkStmt->execute([$email, $employeeId]);
                if ($checkStmt->fetch()) {
                    $errorMsg = 'An account with this email address or Employee ID already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = $pdo->prepare('INSERT INTO professors (employee_id, full_name, email, password_hash, department) VALUES (?, ?, ?, ?, ?)');
                    $insertStmt->execute([$employeeId, $fullName, $email, $hash, $department]);

                    $newId = (int)$pdo->lastInsertId();
                    $newProf = [
                        'id'          => $newId,
                        'employee_id' => $employeeId,
                        'full_name'   => $fullName,
                        'email'       => $email,
                        'department'  => $department
                    ];

                    loginUser($newProf);
                    setFlash('success', 'Professor account created successfully! Welcome to your grading portal.');
                    header('Location: dashboard.php');
                    exit;
                }
            }
        }
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Professor Portal Login | GLOBAL RECIPROCAL COLLEGES</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo-badge">GRC</div>
      <h1>GLOBAL RECIPROCAL COLLEGES</h1>
      <p>Official Professor Grading & Evaluation Portal</p>
    </div>

    <div class="auth-body">
      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>">
          <span><?= h($flash['message']) ?></span>
          <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
      <?php endif; ?>

      <?php if ($errorMsg): ?>
        <div class="alert alert-danger">
          <span><?= h($errorMsg) ?></span>
          <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
      <?php endif; ?>

      <?php if ($dbError): ?>
        <div class="alert alert-warning">
          <strong>Database Notice:</strong>
          <p style="margin-top:4px;"><?= h($dbError) ?></p>
          <small style="display:block; margin-top:8px;">Please check XAMPP MySQL and import <code>database/grc_grading.sql</code> in phpMyAdmin.</small>
        </div>
      <?php else: ?>

        <?php if ($professorCount === 0): ?>
          <div class="alert alert-info">
            <strong>System Setup:</strong> No professor accounts found. Please register the first faculty account below.
          </div>
        <?php endif; ?>

        <div class="auth-tabs">
          <div id="tabLoginBtn" class="auth-tab <?= !$isSetupMode ? 'active' : '' ?>" onclick="switchAuthTab('login')">
            Professor Sign In
          </div>
          <div id="tabRegisterBtn" class="auth-tab <?= $isSetupMode ? 'active' : '' ?>" onclick="switchAuthTab('register')">
            <?= ($professorCount === 0) ? 'Initial Setup' : 'Register Account' ?>
          </div>
        </div>

        <!-- 1. SIGN IN FORM -->
        <div id="loginFormContainer" style="display: <?= !$isSetupMode ? 'block' : 'none' ?>;">
          <form method="POST" action="login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
              <label class="form-label" for="loginEmail">Institutional Email</label>
              <input type="email" id="loginEmail" name="email" class="form-control" placeholder="e.g. prof@grc.edu.ph" required autofocus>
            </div>

            <div class="form-group">
              <label class="form-label" for="loginPassword">Password</label>
              <input type="password" id="loginPassword" name="password" class="form-control" placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
              Sign In to Grading Portal &rarr;
            </button>
          </form>

          <div style="margin-top: 20px; text-align: center; font-size: 12px; color: var(--gray-500);">
            <p>Demo Account: <strong>prof@grc.edu.ph</strong> / <strong>password123</strong></p>
          </div>
        </div>

        <!-- 2. REGISTER / SETUP FORM -->
        <div id="registerFormContainer" style="display: <?= $isSetupMode ? 'block' : 'none' ?>;">
          <form method="POST" action="login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="register">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regEmpId">Employee ID</label>
                <input type="text" id="regEmpId" name="employee_id" class="form-control" placeholder="e.g. GRC-FAC-2024-001" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regFullName">Full Name (with Title)</label>
                <input type="text" id="regFullName" name="full_name" class="form-control" placeholder="e.g. Prof. Juan Dela Cruz" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="regEmail">Institutional Email</label>
              <input type="email" id="regEmail" name="email" class="form-control" placeholder="e.g. jdelacruz@grc.edu.ph" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="regDept">Department / College</label>
              <select id="regDept" name="department" class="form-select">
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Business Administration">College of Business Administration</option>
                <option value="College of Education">College of Education</option>
                <option value="College of Engineering">College of Engineering</option>
                <option value="General Education Department">General Education Department</option>
              </select>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regPassword">Password</label>
                <input type="password" id="regPassword" name="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regConfirm">Confirm Password</label>
                <input type="password" id="regConfirm" name="confirm_password" class="form-control" placeholder="Re-type password" minlength="8" required>
              </div>
            </div>

            <button type="submit" class="btn btn-navy" style="width: 100%; padding: 12px; margin-top: 10px;">
              Create Professor Account &rarr;
            </button>
          </form>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>

