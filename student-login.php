<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Student Portal
 * File: student-login.php
 * Secure Authentication Portal for Enrolled Students
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in as student, proceed to student dashboard
if (isLoggedIn() && getUserRole() === 'student') {
    header('Location: student-dashboard.php');
    exit;
}

$errorMsg = null;
$dbError = null;

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// Process Student Login or Student Self-Registration (Testing / Activation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbError) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Mangyaring subukan muli.';
    } else {
        $action = (string)($_POST['action'] ?? 'login');

        // 1. STUDENT LOGIN
        if ($action === 'login') {
            $identifier = trim((string)($_POST['identifier'] ?? '')); // Student No or Username
            $password = (string)($_POST['password'] ?? '');

            if ($identifier === '' || $password === '') {
                $errorMsg = 'Pakisuyong ilagay ang iyong Student Number / Username at password.';
            } else {
                $stmt = $pdo->prepare('
                    SELECT * FROM students 
                    WHERE student_no = ? OR username = ? 
                    LIMIT 1
                ');
                $stmt->execute([$identifier, $identifier]);
                $student = $stmt->fetch();

                if ($student && password_verify($password, $student['password_hash'])) {
                    loginStudent($student);
                    setFlash('success', 'Maligayang pagdating, ' . $student['first_name'] . '!');
                    header('Location: student-dashboard.php');
                    exit;
                } else {
                    $errorMsg = 'Maling Student Number, username, o password. Pakisuri ang iyong impormasyon.';
                }
            }
        }

        // 2. STUDENT ACCOUNT ACTIVATION / REGISTRATION (FOR TESTING)
        if ($action === 'register') {
            $studentNo = trim((string)($_POST['student_no'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $course = trim((string)($_POST['course'] ?? 'BS Information Technology'));
            $yearLevel = trim((string)($_POST['year_level'] ?? '3rd Year'));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($studentNo === '' || $username === '' || $firstName === '' || $lastName === '' || $password === '') {
                $errorMsg = 'Lahat ng required fields ay dapat punan.';
            } elseif (strlen($password) < 8) {
                $errorMsg = 'Ang password ay dapat may hindi bababa sa 8 characters.';
            } elseif ($password !== $confirmPassword) {
                $errorMsg = 'Hindi magkatugma ang mga password na inilagay.';
            } else {
                // Check if already registered
                $stmtCheck = $pdo->prepare('SELECT id FROM students WHERE student_no = ? OR username = ? LIMIT 1');
                $stmtCheck->execute([$studentNo, $username]);
                if ($stmtCheck->fetch()) {
                    $errorMsg = 'Ang Student Number o Username na ito ay nakarehistro na sa system.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtIns = $pdo->prepare('
                        INSERT INTO students (student_no, username, first_name, last_name, email, course, year_level, password_hash)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmtIns->execute([$studentNo, $username, $firstName, $lastName, $email, $course, $yearLevel, $hash]);

                    $newStudentId = (int)$pdo->lastInsertId();
                    $newStudent = [
                        'id'         => $newStudentId,
                        'student_no' => $studentNo,
                        'username'   => $username,
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'email'      => $email,
                        'course'     => $course,
                        'year_level' => $yearLevel,
                    ];

                    loginStudent($newStudent);
                    setFlash('success', 'Matagumpay na nairehistro ang iyong student portal account!');
                    header('Location: student-dashboard.php');
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
  <title>Student Portal Login | GLOBAL RECIPROCAL COLLEGES</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo-badge">GRC</div>
      <h1>GLOBAL RECIPROCAL COLLEGES</h1>
      <p>Official Student Grade Evaluation Portal</p>
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
          <div id="tabLoginBtn" class="auth-tab active" onclick="switchAuthTab('login')">
            Student Sign In
          </div>
          <div id="tabRegisterBtn" class="auth-tab" onclick="switchAuthTab('register')">
            Register / Activate
          </div>
        </div>

        <!-- 1. LOGIN FORM -->
        <div id="loginFormContainer" style="display: block;">
          <form method="POST" action="student-login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
              <label class="form-label" for="studentIdentifier">Student Number o Username</label>
              <input type="text" id="studentIdentifier" name="identifier" class="form-control" placeholder="Hal. 2022-00101 o maria.santos" required autofocus>
            </div>

            <div class="form-group">
              <label class="form-label" for="studentPassword">Password</label>
              <input type="password" id="studentPassword" name="password" class="form-control" placeholder="Ilagay ang password" required>
            </div>

            <button type="submit" class="btn btn-navy" style="width: 100%; padding: 12px; margin-top: 10px;">
              Mag-login sa Student Portal &rarr;
            </button>
          </form>

          <div style="margin-top: 20px; padding: 12px; background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); font-size: 12px; color: var(--gray-700);">
            <strong>Demo Student Accounts para sa Testing:</strong><br>
            Student No: <code>2022-00101</code> (Complete Grades)<br>
            Student No: <code>2022-00102</code> (Incomplete Grades)<br>
            Password: <code>Password123!</code>
          </div>
        </div>

        <!-- 2. REGISTER / ACTIVATION FORM -->
        <div id="registerFormContainer" style="display: none;">
          <form method="POST" action="student-login.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="register">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regStudentNo">Student Number</label>
                <input type="text" id="regStudentNo" name="student_no" class="form-control" placeholder="2022-00105" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regUsername">Username</label>
                <input type="text" id="regUsername" name="username" class="form-control" placeholder="firstname.lastname" required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regFirstName">Pangalan (First Name)</label>
                <input type="text" id="regFirstName" name="first_name" class="form-control" placeholder="Maria" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regLastName">Apelyido (Last Name)</label>
                <input type="text" id="regLastName" name="last_name" class="form-control" placeholder="Santos" required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regCourse">Program / Kurso</label>
                <select id="regCourse" name="course" class="form-select">
                  <option value="BS Information Technology">BS Information Technology</option>
                  <option value="BS Computer Science">BS Computer Science</option>
                  <option value="BS Business Administration">BS Business Administration</option>
                  <option value="Bachelor of Elementary Education">Bachelor of Elementary Education</option>
                  <option value="Bachelor of Secondary Education">Bachelor of Secondary Education</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="regYearLevel">Year Level</label>
                <select id="regYearLevel" name="year_level" class="form-select">
                  <option value="1st Year">1st Year</option>
                  <option value="2nd Year">2nd Year</option>
                  <option value="3rd Year" selected>3rd Year</option>
                  <option value="4th Year">4th Year</option>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="regEmail">Email Address</label>
              <input type="email" id="regEmail" name="email" class="form-control" placeholder="student@student.grc.edu.ph">
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="regStudentPass">Password</label>
                <input type="password" id="regStudentPass" name="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="regStudentConfirm">Kumpirmahin ang Password</label>
                <input type="password" id="regStudentConfirm" name="confirm_password" class="form-control" placeholder="Ulitin ang password" minlength="8" required>
              </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
              Rehistruhan ang Student Account &rarr;
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

