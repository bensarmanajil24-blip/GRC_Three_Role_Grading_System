<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Portal
 * File: professor-login.php
 * Secure Authentication & Account Setup for Faculty Members
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in as professor, proceed to professor dashboard
if (isLoggedIn() && getUserRole() === 'professor') {
    header('Location: professor-dashboard.php');
    exit;
}

$errorMsg = null;
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

// Process Login or Account Setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Mangyaring mag-refresh at subukan muli.';
    } else {
        $action = (string)($_POST['action'] ?? 'login');

        // 1. PROFESSOR LOGIN
        if ($action === 'login') {
            $identifier = trim((string)($_POST['identifier'] ?? '')); // Email or Username
            $password = (string)($_POST['password'] ?? '');

            if ($identifier === '' || $password === '') {
                $errorMsg = 'Pakisuyong ilagay ang iyong username/email at password.';
            } else {
                $stmt = $pdo->prepare('
                    SELECT * FROM professors 
                    WHERE email = ? OR username = ? 
                    LIMIT 1
                ');
                $stmt->execute([$identifier, $identifier]);
                $prof = $stmt->fetch();

                if ($prof && password_verify($password, $prof['password_hash'])) {
                    loginProfessor($prof);
                    setFlash('success', 'Maligayang pagbabalik, ' . $prof['full_name'] . '!');
                    header('Location: professor-dashboard.php');
                    exit;
                } else {
                    $errorMsg = 'Maling username, email, o password. Pakisuri ang iyong credentials.';
                }
            }
        }

        // 2. PROFESSOR ACCOUNT REGISTRATION / SETUP
        if ($action === 'register') {
            $employeeId = trim((string)($_POST['employee_id'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $department = trim((string)($_POST['department'] ?? 'College of Computer Studies'));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($employeeId === '' || $username === '' || $fullName === '' || $email === '' || $password === '') {
                $errorMsg = 'Lahat ng fields ay kinakailangan punan.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMsg = 'Mangyaring maglagay ng wastong institutional email address.';
            } elseif (strlen($password) < 8) {
                $errorMsg = 'Ang password ay dapat may hindi bababa sa 8 characters.';
            } elseif ($password !== $confirmPassword) {
                $errorMsg = 'Hindi magkatugma ang mga password na inilagay.';
            } else {
                $stmtCheck = $pdo->prepare('SELECT id FROM professors WHERE email = ? OR username = ? OR employee_id = ? LIMIT 1');
                $stmtCheck->execute([$email, $username, $employeeId]);
                if ($stmtCheck->fetch()) {
                    $errorMsg = 'Mayroon nang account na gumagamit ng Employee ID, username, o email na ito.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtIns = $pdo->prepare('
                        INSERT INTO professors (employee_id, username, full_name, email, password_hash, department)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmtIns->execute([$employeeId, $username, $fullName, $email, $hash, $department]);

                    $newId = (int)$pdo->lastInsertId();
                    $newProf = [
                        'id'          => $newId,
                        'employee_id' => $employeeId,
                        'username'    => $username,
                        'full_name'   => $fullName,
                        'email'       => $email,
                        'department'  => $department
                    ];

                    loginProfessor($newProf);
                    setFlash('success', 'Matagumpay na nagawa ang Professor account! Maligayang pagdating sa grading portal.');
                    header('Location: professor-dashboard.php');
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
      <p>Faculty &amp; Professor Grading Portal</p>
    </div>

    <div class="auth-body">
      <div style="margin-bottom: 16px;">
        <a href="index.php" style="font-size: 12px; font-weight: 600; color: var(--blue-accent); text-decoration: none;">
          &larr; Bumalik sa Gateway Homepage
        </a>
      </div>

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
          <p style="margin-top: 4px;"><?= h($dbError) ?></p>
          <small>Pakitiyak na tumatakbo ang MySQL sa XAMPP at na-import ang <code>database/grc_grading.sql</code>.</small>
        </div>
      <?php else: ?>

        <div class="auth-tabs">
          <div id="tabLoginBtn" class="auth-tab <?= !$isSetupMode ? 'active' : '' ?>" onclick="switchAuthTab('login')">
            Professor Sign In
          </div>
          <div id="tabRegisterBtn" class="auth-tab <?= $isSetupMode ? 'active' : '' ?>" onclick="switchAuthTab('register')">
            <?= ($professorCount === 0) ? 'Initial Setup' : 'Register Account' ?>
          </div>
        </div>

        <!-- 1. LOGIN FORM -->
        <div id="loginFormContainer" style="display: <?= !$isSetupMode ? 'block' : 'none' ?>;">
          <form method="POST" action="professor-login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
              <label class="form-label" for="profIdentifier">Username o Institutional Email</label>
              <input type="text" id="profIdentifier" name="identifier" class="form-control" placeholder="Hal. prof.juandc o prof@grc.edu.ph" required autofocus>
            </div>

            <div class="form-group">
              <label class="form-label" for="profPassword">Password</label>
              <input type="password" id="profPassword" name="password" class="form-control" placeholder="Ilagay ang password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
              Mag-login sa Professor Dashboard &rarr;
            </button>
          </form>

          <div style="margin-top: 20px; padding: 12px; background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); font-size: 12px; color: var(--gray-700);">
            <strong>Demo Faculty Account:</strong><br>
            Username: <code>prof.juandc</code> o <code>prof@grc.edu.ph</code><br>
            Password: <code>Password123!</code>
          </div>
        </div>

        <!-- 2. REGISTER / SETUP FORM -->
        <div id="registerFormContainer" style="display: <?= $isSetupMode ? 'block' : 'none' ?>;">
          <form method="POST" action="professor-login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="register">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regEmpId">Employee ID</label>
                <input type="text" id="regEmpId" name="employee_id" class="form-control" placeholder="GRC-FAC-2024-002" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regUsername">Username</label>
                <input type="text" id="regUsername" name="username" class="form-control" placeholder="prof.name" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="regFullName">Buong Pangalan (kasama ang Title)</label>
              <input type="text" id="regFullName" name="full_name" class="form-control" placeholder="Prof. Juan Dela Cruz" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="regEmail">Institutional Email</label>
              <input type="email" id="regEmail" name="email" class="form-control" placeholder="name@grc.edu.ph" required>
            </div>

            <div class="form-group">
              <label class="form-label" for="regDept">Kagawaran / College</label>
              <select id="regDept" name="department" class="form-select">
                <option value="College of Computer Studies">College of Computer Studies</option>
                <option value="College of Business Administration">College of Business Administration</option>
                <option value="College of Education">College of Education</option>
                <option value="College of Engineering">College of Engineering</option>
              </select>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regPass">Password</label>
                <input type="password" id="regPass" name="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regConfirm">Kumpirmahin ang Password</label>
                <input type="password" id="regConfirm" name="confirm_password" class="form-control" placeholder="Ulitin ang password" minlength="8" required>
              </div>
            </div>

            <button type="submit" class="btn btn-navy" style="width: 100%; padding: 12px; margin-top: 10px;">
              Gumawa ng Propesor Account &rarr;
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

