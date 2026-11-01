<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Portal
 * File: professor-dashboard.php
 * Comprehensive Professor Management Portal: Classes, Student Roster, and Grade Evaluation
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$professor = requireProfessor();
$pdo = getDbConnection();
$professorId = (int)$professor['id'];

$errorMsg = null;
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'overview';

// Handle All Professor POST Actions (Add/Edit/Delete Subject, Add/Edit/Remove Student, Save Grades)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Pakisubukang muli.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // 1. ADD NEW SUBJECT
        if ($action === 'add_subject') {
            $code = trim((string)($_POST['subject_code'] ?? ''));
            $title = trim((string)($_POST['subject_title'] ?? ''));
            $section = trim((string)($_POST['section'] ?? ''));
            $units = (int)($_POST['units'] ?? 3);
            $sy = trim((string)($_POST['school_year'] ?? '2024-2025'));
            $sem = (string)($_POST['semester'] ?? '1st Semester');

            if ($code === '' || $title === '' || $section === '') {
                $errorMsg = 'Kailangan punan ang Subject Code, Title, at Section.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO subjects (professor_id, subject_code, subject_title, section, units, school_year, semester)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$professorId, $code, $title, $section, $units, $sy, $sem]);
                setFlash('success', 'Matagumpay na naidagdag ang subject na ' . $code . ' (' . $section . ').');
                header('Location: professor-dashboard.php?tab=subjects');
                exit;
            }
        }

        // 2. EDIT SUBJECT
        if ($action === 'edit_subject') {
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $code = trim((string)($_POST['subject_code'] ?? ''));
            $title = trim((string)($_POST['subject_title'] ?? ''));
            $section = trim((string)($_POST['section'] ?? ''));
            $units = (int)($_POST['units'] ?? 3);
            $sy = trim((string)($_POST['school_year'] ?? '2024-2025'));
            $sem = (string)($_POST['semester'] ?? '1st Semester');

            if (!isSubjectOwnedByProfessor($pdo, $subjectId, $professorId)) {
                $errorMsg = 'Wala kang pahintulot na i-edit ang subject na ito.';
            } elseif ($code === '' || $title === '' || $section === '') {
                $errorMsg = 'Hindi maaaring iwang blangko ang subject details.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE subjects 
                    SET subject_code = ?, subject_title = ?, section = ?, units = ?, school_year = ?, semester = ?
                    WHERE id = ? AND professor_id = ?
                ');
                $stmt->execute([$code, $title, $section, $units, $sy, $sem, $subjectId, $professorId]);
                setFlash('success', 'Na-update ang mga impormasyon ng subject.');
                header('Location: professor-dashboard.php?tab=subjects');
                exit;
            }
        }

        // 3. DELETE SUBJECT
        if ($action === 'delete_subject') {
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            if (!isSubjectOwnedByProfessor($pdo, $subjectId, $professorId)) {
                $errorMsg = 'Wala kang pahintulot na burahin ang subject na ito.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ? AND professor_id = ?');
                $stmt->execute([$subjectId, $professorId]);
                setFlash('success', 'Nabura ang subject kasama ang mga kaugnay na enrollments at grades nito.');
                header('Location: professor-dashboard.php?tab=subjects');
                exit;
            }
        }

        // 4. ADD & ENROLL STUDENT
        if ($action === 'add_student') {
            $targetSubId = (int)($_POST['subject_id'] ?? 0);
            $studentNo = trim((string)($_POST['student_no'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $course = trim((string)($_POST['course'] ?? 'BS Information Technology'));
            $yearLevel = trim((string)($_POST['year_level'] ?? '3rd Year'));

            if (!isSubjectOwnedByProfessor($pdo, $targetSubId, $professorId)) {
                $errorMsg = 'Pumili ng wastong subject na iyong itinuturo.';
            } elseif ($studentNo === '' || $firstName === '' || $lastName === '') {
                $errorMsg = 'Student Number, Pangalan, at Apelyido ay kinakailangan.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Check if student exists
                    $stmtCheck = $pdo->prepare('SELECT id FROM students WHERE student_no = ? LIMIT 1');
                    $stmtCheck->execute([$studentNo]);
                    $existingId = $stmtCheck->fetchColumn();

                    if ($existingId) {
                        $studentId = (int)$existingId;
                        $stmtUpdate = $pdo->prepare('UPDATE students SET first_name = ?, last_name = ?, email = ?, course = ?, year_level = ? WHERE id = ?');
                        $stmtUpdate->execute([$firstName, $lastName, $email, $course, $yearLevel, $studentId]);
                    } else {
                        // Generate username and default password hash for new student
                        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . '.' . $lastName));
                        $defaultHash = password_hash('Password123!', PASSWORD_DEFAULT);

                        $stmtInsert = $pdo->prepare('
                            INSERT INTO students (student_no, username, first_name, last_name, email, course, year_level, password_hash)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmtInsert->execute([$studentNo, $username, $firstName, $lastName, $email, $course, $yearLevel, $defaultHash]);
                        $studentId = (int)$pdo->lastInsertId();
                    }

                    // Check enrollment
                    $stmtEnrollCheck = $pdo->prepare('SELECT id FROM enrollments WHERE subject_id = ? AND student_id = ? LIMIT 1');
                    $stmtEnrollCheck->execute([$targetSubId, $studentId]);
                    $enrollId = $stmtEnrollCheck->fetchColumn();

                    if (!$enrollId) {
                        $stmtEnroll = $pdo->prepare('INSERT INTO enrollments (professor_id, subject_id, student_id) VALUES (?, ?, ?)');
                        $stmtEnroll->execute([$professorId, $targetSubId, $studentId]);
                        $enrollId = (int)$pdo->lastInsertId();

                        $stmtInitGrades = $pdo->prepare('INSERT INTO grades (enrollment_id) VALUES (?)');
                        $stmtInitGrades->execute([$enrollId]);
                    }

                    $pdo->commit();
                    setFlash('success', 'Matagumpay na na-enroll ang mag-aaral: ' . $firstName . ' ' . $lastName . ' (' . $studentNo . ').');
                    header('Location: professor-dashboard.php?tab=students&subject_id=' . $targetSubId);
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errorMsg = 'Error sa pagdagdag ng estudyante: ' . $e->getMessage();
                }
            }
        }

        // 5. REMOVE STUDENT ENROLLMENT
        if ($action === 'remove_enrollment') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $stmtVerify = $pdo->prepare('SELECT id FROM enrollments WHERE id = ? AND professor_id = ? LIMIT 1');
            $stmtVerify->execute([$enrollmentId, $professorId]);
            if (!$stmtVerify->fetchColumn()) {
                $errorMsg = 'Wala kang pahintulot na alisin ang enrollment na ito.';
            } else {
                $stmtDel = $pdo->prepare('DELETE FROM enrollments WHERE id = ? AND professor_id = ?');
                $stmtDel->execute([$enrollmentId, $professorId]);
                setFlash('success', 'Inalis ang estudyante sa subject section na ito.');
                header('Location: professor-dashboard.php?tab=students');
                exit;
            }
        }

        // 6. SAVE STUDENT GRADES
        if ($action === 'save_grades') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $targetSubId = (int)($_POST['subject_id'] ?? 0);

            $stmtVerify = $pdo->prepare('
                SELECT e.id FROM enrollments e 
                JOIN subjects s ON e.subject_id = s.id 
                WHERE e.id = ? AND e.professor_id = ? AND s.id = ? 
                LIMIT 1
            ');
            $stmtVerify->execute([$enrollmentId, $professorId, $targetSubId]);
            if (!$stmtVerify->fetchColumn()) {
                $errorMsg = 'Unauthorized: Hindi nahanap ang enrollment record sa iyong klase.';
            } else {
                try {
                    $scoreKeys = [
                        'mid_lec_attendance', 'mid_lec_recitation', 'mid_lec_assignment', 'mid_lec_quizzes', 'mid_lec_prelim_exam', 'mid_lec_midterm_exam',
                        'mid_lab_attendance', 'mid_lab_recitation', 'mid_lab_assignment', 'mid_lab_quizzes', 'mid_lab_prelim_exam', 'mid_lab_midterm_exam',
                        'fnl_lec_attendance', 'fnl_lec_recitation', 'fnl_lec_assignment', 'fnl_lec_quizzes', 'fnl_lec_final_exam',
                        'fnl_lab_attendance', 'fnl_lab_recitation', 'fnl_lab_assignment', 'fnl_lab_quizzes', 'fnl_lab_final_exam'
                    ];

                    $values = [];
                    foreach ($scoreKeys as $k) {
                        $raw = $_POST[$k] ?? null;
                        $values[$k] = validateScore($raw);
                    }

                    $stmtCheck = $pdo->prepare('SELECT id FROM grades WHERE enrollment_id = ? LIMIT 1');
                    $stmtCheck->execute([$enrollmentId]);
                    if ($stmtCheck->fetchColumn()) {
                        $setSql = implode(' = ?, ', $scoreKeys) . ' = ?';
                        $params = array_values($values);
                        $params[] = $enrollmentId;
                        $stmtUpdate = $pdo->prepare("UPDATE grades SET {$setSql} WHERE enrollment_id = ?");
                        $stmtUpdate->execute($params);
                    } else {
                        $cols = implode(', ', $scoreKeys);
                        $placeholders = implode(', ', array_fill(0, count($scoreKeys), '?'));
                        $params = array_merge([$enrollmentId], array_values($values));
                        $stmtInsert = $pdo->prepare("INSERT INTO grades (enrollment_id, {$cols}) VALUES (?, {$placeholders})");
                        $stmtInsert->execute($params);
                    }

                    setFlash('success', 'Matagumpay na nai-save at na-compute ang mga grado ng estudyante.');
                    header('Location: professor-dashboard.php?tab=grades&subject_id=' . $targetSubId);
                    exit;
                } catch (InvalidArgumentException $ex) {
                    $errorMsg = $ex->getMessage();
                } catch (Throwable $ex) {
                    $errorMsg = 'Database error habang nagse-save: ' . $ex->getMessage();
                }
            }
        }
    }
}

// Fetch all subjects owned by this professor
$stmtSubjects = $pdo->prepare('
    SELECT s.*, COUNT(e.id) AS student_count
    FROM subjects s
    LEFT JOIN enrollments e ON s.id = e.subject_id
    WHERE s.professor_id = ?
    GROUP BY s.id
    ORDER BY s.school_year DESC, s.subject_code ASC, s.section ASC
');
$stmtSubjects->execute([$professorId]);
$subjects = $stmtSubjects->fetchAll();

// Determine selected subject for grade sheet or student roster
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
if ($selectedSubjectId <= 0 && !empty($subjects)) {
    $selectedSubjectId = (int)$subjects[0]['id'];
}

$activeSubject = null;
foreach ($subjects as $s) {
    if ((int)$s['id'] === $selectedSubjectId) {
        $activeSubject = $s;
        break;
    }
}

// Fetch students for Roster Tab
$studentQuery = '
    SELECT 
        e.id AS enrollment_id,
        s.id AS student_id,
        s.student_no,
        s.username,
        s.first_name,
        s.last_name,
        s.email AS student_email,
        s.course,
        s.year_level,
        sub.id AS subject_id,
        sub.subject_code,
        sub.subject_title,
        sub.section,
        sub.school_year,
        sub.semester
    FROM enrollments e
    JOIN students s ON e.student_id = s.id
    JOIN subjects sub ON e.subject_id = sub.id
    WHERE e.professor_id = ?
';
$studentParams = [$professorId];
if ($selectedSubjectId > 0 && $activeTab === 'students') {
    $studentQuery .= ' AND sub.id = ? ';
    $studentParams[] = $selectedSubjectId;
}
$studentQuery .= ' ORDER BY sub.subject_code ASC, sub.section ASC, s.last_name ASC';
$stmtRoster = $pdo->prepare($studentQuery);
$stmtRoster->execute($studentParams);
$rosterStudents = $stmtRoster->fetchAll();

// Fetch grades for Grade Evaluation Tab
$gradeRoster = [];
if ($activeSubject) {
    $stmtGrades = $pdo->prepare('
        SELECT 
            e.id AS enrollment_id,
            s.id AS student_id,
            s.student_no,
            s.first_name,
            s.last_name,
            s.course,
            s.year_level,
            g.*
        FROM enrollments e
        JOIN students s ON e.student_id = s.id
        LEFT JOIN grades g ON e.id = g.enrollment_id
        WHERE e.subject_id = ? AND e.professor_id = ?
        ORDER BY s.last_name ASC, s.first_name ASC
    ');
    $stmtGrades->execute([$activeSubject['id'], $professorId]);
    $gradeRoster = $stmtGrades->fetchAll();
}

// Calculate summary stats
$totalSubjectsCount = count($subjects);
$stmtDist = $pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE professor_id = ?');
$stmtDist->execute([$professorId]);
$totalStudentsCount = (int)$stmtDist->fetchColumn();

$stmtAllGrades = $pdo->prepare('
    SELECT g.* FROM grades g 
    JOIN enrollments e ON g.enrollment_id = e.id 
    WHERE e.professor_id = ?
');
$stmtAllGrades->execute([$professorId]);
$allProfessorGrades = $stmtAllGrades->fetchAll();

$completedGradesCount = 0;
$incompleteGradesCount = 0;
foreach ($allProfessorGrades as $gRow) {
    $comp = computeStudentGrades($gRow);
    if ($comp['course_status'] === 'Complete') {
        $completedGradesCount++;
    } else {
        $incompleteGradesCount++;
    }
}

$stmtTotEnr = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE professor_id = ?');
$stmtTotEnr->execute([$professorId]);
$totEnrollments = (int)$stmtTotEnr->fetchColumn();
$incompleteGradesCount += ($totEnrollments - count($allProfessorGrades));

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Professor Dashboard | GLOBAL RECIPROCAL COLLEGES</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-wrapper">
  <!-- SIDEBAR NAVIGATION -->
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo-badge">GRC</div>
      <div class="sidebar-brand">
        <h1>GLOBAL RECIPROCAL</h1>
        <span>FACULTY PORTAL</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Academic Center</div>
      <a href="professor-dashboard.php?tab=overview" class="nav-item <?= ($activeTab === 'overview') ? 'active' : '' ?>">
        <span class="nav-icon">&#9638;</span>
        <span>Overview</span>
      </a>
      <a href="professor-dashboard.php?tab=subjects" class="nav-item <?= ($activeTab === 'subjects') ? 'active' : '' ?>">
        <span class="nav-icon">&#128218;</span>
        <span>My Classes &amp; Subjects</span>
      </a>
      <a href="professor-dashboard.php?tab=students" class="nav-item <?= ($activeTab === 'students') ? 'active' : '' ?>">
        <span class="nav-icon">&#127891;</span>
        <span>Student Roster</span>
      </a>
      <a href="professor-dashboard.php?tab=grades" class="nav-item <?= ($activeTab === 'grades') ? 'active' : '' ?>">
        <span class="nav-icon">&#9998;</span>
        <span>Grade Evaluation</span>
      </a>

      <div class="nav-label" style="margin-top: 18px;">Account</div>
      <a href="logout.php" class="nav-item" onclick="return confirm('Nais mo bang mag-sign out sa portal?');">
        <span class="nav-icon">&#128682;</span>
        <span>Sign Out</span>
      </a>
      <a href="index.php" class="nav-item">
        <span class="nav-icon">&#127968;</span>
        <span>Gateway Home</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-snippet">
        <div class="user-avatar"><?= strtoupper(substr($professor['full_name'], 0, 1)) ?></div>
        <div class="user-info">
          <div class="user-name"><?= h($professor['full_name']) ?></div>
          <div class="user-role"><?= h($professor['employee_id']) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN VIEW -->
  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggleBtn" aria-label="Toggle Menu">&#9776;</button>
        <div class="breadcrumb-title">
          <?php
            if ($activeTab === 'subjects') echo 'Class &amp; Subject Management';
            elseif ($activeTab === 'students') echo 'Student Roster &amp; Enrollments';
            elseif ($activeTab === 'grades') echo 'Student Grade Evaluation Sheet';
            else echo 'Professor Faculty Dashboard';
          ?>
        </div>
      </div>
      <div class="topbar-right">
        <div class="academic-badge">
          <span>&#128197;</span>
          <span>A.Y. 2024–2025 | 1st Sem</span>
        </div>
        <a href="logout.php" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Sign out of your session?');">
          Sign Out
        </a>
      </div>
    </header>

    <div class="page-container">
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

      <!-- INSTITUTIONAL GRADING ADVISORY NOTICE -->
      <div class="policy-banner">
        <div class="policy-banner-title">
          <span>&#9888;</span>
          <span>GRC Institutional Grading Formula Advisory</span>
        </div>
        <div class="policy-banner-body">
          <p>
            <strong>Formulas:</strong> Midterm Grade = (Lecture × 65%) + (Laboratory × 35%). Final Grade = (Lecture × 65%) + (Laboratory × 35%). Course Grade = (Midterm + Final) ÷ 2.
          </p>
          <p style="margin-top: 4px;">
            <strong>Policy Clarification:</strong> Bagaman nakasulat ang "Class Participation 75%" sa heading ng reference syllabus, ang kabuuang itemized components nito ay 65% (Attendance 15% + Recitation 15% + Assignment 5% + Quizzes 30% = 65%). Ang itemized component weights ang ipinapatupad. Ang mga blangkong score ay may markang <em>Incomplete</em>.
          </p>
        </div>
      </div>

      <!-- ===================================================================
           TAB 1: OVERVIEW DASHBOARD
           =================================================================== -->
      <?php if ($activeTab === 'overview'): ?>
        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-icon blue">&#128218;</div>
            <div class="stat-info">
              <h3><?= number_format($totalSubjectsCount) ?></h3>
              <p>Hawak na Classes</p>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon navy">&#127891;</div>
            <div class="stat-info">
              <h3><?= number_format($totalStudentsCount) ?></h3>
              <p>Naka-enroll na Estudyante</p>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon green">&#9989;</div>
            <div class="stat-info">
              <h3><?= number_format($completedGradesCount) ?></h3>
              <p>Completed Grades</p>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon amber">&#9203;</div>
            <div class="stat-info">
              <h3><?= number_format($incompleteGradesCount) ?></h3>
              <p>Pending / Incomplete</p>
            </div>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
          <!-- Active Classes Summary -->
          <div class="card">
            <div class="card-header">
              <h3>Aking mga Klase at Seksyon</h3>
              <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addSubjectModal')">+ Magdagdag ng Klase</button>
            </div>
            <div class="table-responsive">
              <table class="grc-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Descriptive Title</th>
                    <th>Section</th>
                    <th>Students</th>
                    <th style="text-align: right;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($subjects)): ?>
                    <tr class="no-records-row">
                      <td colspan="5" style="text-align: center; color: var(--gray-500); padding: 24px;">
                        Wala pang subject na nakarehistro. I-click ang "+ Magdagdag ng Klase".
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($subjects as $s): ?>
                      <tr>
                        <td><strong><?= h($s['subject_code']) ?></strong></td>
                        <td><?= h($s['subject_title']) ?></td>
                        <td><span class="badge badge-navy"><?= h($s['section']) ?></span></td>
                        <td><?= (int)$s['student_count'] ?> students</td>
                        <td style="text-align: right;">
                          <a href="professor-dashboard.php?tab=grades&subject_id=<?= (int)$s['id'] ?>" class="btn btn-outline-primary btn-sm">
                            Grade Sheet &rarr;
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Professor Profile Card -->
          <div class="card">
            <div class="card-header">
              <h3>Faculty Profile</h3>
            </div>
            <div class="card-body">
              <div style="text-align: center; margin-bottom: 18px;">
                <div style="width: 68px; height: 68px; border-radius: 50%; background: var(--navy-primary); color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 800; border: 3px solid var(--blue-accent);">
                  <?= strtoupper(substr($professor['full_name'], 0, 1)) ?>
                </div>
                <h4 style="font-size: 15px; font-weight: 700; margin-top: 8px;"><?= h($professor['full_name']) ?></h4>
                <p style="font-size: 12px; color: var(--gray-500);"><?= h($professor['department']) ?></p>
              </div>

              <div style="border-top: 1px solid var(--gray-200); padding-top: 12px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                  <span style="color: var(--gray-500);">Employee ID:</span>
                  <strong><?= h($professor['employee_id']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                  <span style="color: var(--gray-500);">Institutional Email:</span>
                  <strong><?= h($professor['email']) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: var(--gray-500);">Access Level:</span>
                  <span class="badge badge-complete">Faculty Professor</span>
                </div>
              </div>

              <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 8px;">
                <a href="professor-dashboard.php?tab=grades" class="btn btn-primary" style="width: 100%;">
                  &#9998; Encode Student Grades
                </a>
                <a href="professor-dashboard.php?tab=students" class="btn btn-outline-secondary" style="width: 100%;">
                  &#127891; Pamahalaan ang Roster
                </a>
              </div>
            </div>
          </div>
        </div>

      <!-- ===================================================================
           TAB 2: SUBJECTS MANAGEMENT
           =================================================================== -->
      <?php elseif ($activeTab === 'subjects'): ?>
        <div class="card">
          <div class="card-header">
            <h3>Aking mga Hawak na Classes &amp; Subjects (<?= count($subjects) ?>)</h3>
            <div style="display: flex; gap: 10px;">
              <input type="text" class="form-control" style="width: 220px;" placeholder="Search subject code o title..." data-table-search="subjectsTable">
              <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addSubjectModal')">+ Magdagdag ng Subject</button>
            </div>
          </div>
          <div class="table-responsive">
            <table class="grc-table" id="subjectsTable">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Descriptive Title</th>
                  <th>Section</th>
                  <th>Units</th>
                  <th>School Year</th>
                  <th>Semester</th>
                  <th>Enrolled</th>
                  <th style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($subjects)): ?>
                  <tr class="no-records-row">
                    <td colspan="8" style="text-align: center; color: var(--gray-500); padding: 32px;">
                      Walang nakitang subjects. I-click ang "+ Magdagdag ng Subject" upang magsimula.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($subjects as $sub): ?>
                    <tr>
                      <td><strong><?= h($sub['subject_code']) ?></strong></td>
                      <td><?= h($sub['subject_title']) ?></td>
                      <td><span class="badge badge-navy"><?= h($sub['section']) ?></span></td>
                      <td><?= (int)$sub['units'] ?></td>
                      <td><?= h($sub['school_year']) ?></td>
                      <td><?= h($sub['semester']) ?></td>
                      <td>
                        <a href="professor-dashboard.php?tab=students&subject_id=<?= (int)$sub['id'] ?>" style="color: var(--blue-accent); font-weight: 600; text-decoration: none;">
                          <?= (int)$sub['student_count'] ?> mag-aaral &rarr;
                        </a>
                      </td>
                      <td style="text-align: right; white-space: nowrap;">
                        <a href="professor-dashboard.php?tab=grades&subject_id=<?= (int)$sub['id'] ?>" class="btn btn-outline-primary btn-sm">
                          Grades
                        </a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick='openEditSubjectModal(<?= json_encode($sub) ?>)'>
                          Edit
                        </button>
                        <form method="POST" action="professor-dashboard.php?tab=subjects" style="display: inline-block;" onsubmit="return confirm('Sigurado ka bang nais mong burahin ang klase na <?= h($sub['subject_code']) ?>? Mabubura rin ang lahat ng kaugnay na student grades.');">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="delete_subject">
                          <input type="hidden" name="subject_id" value="<?= (int)$sub['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <!-- ===================================================================
           TAB 3: STUDENT ROSTER
           =================================================================== -->
      <?php elseif ($activeTab === 'students'): ?>
        <div class="card">
          <div class="card-header">
            <h3>Student Class Roster (<?= count($rosterStudents) ?>)</h3>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
              <form method="GET" action="professor-dashboard.php" style="display: flex; gap: 8px;">
                <input type="hidden" name="tab" value="students">
                <select name="subject_id" class="form-select" style="min-width: 220px;" onchange="this.form.submit()">
                  <option value="0">Lahat ng Classes</option>
                  <?php foreach ($subjects as $pSub): ?>
                    <option value="<?= (int)$pSub['id'] ?>" <?= ($selectedSubjectId === (int)$pSub['id']) ? 'selected' : '' ?>>
                      <?= h($pSub['subject_code']) ?> - <?= h($pSub['section']) ?> (<?= h($pSub['subject_title']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
              <input type="text" class="form-control" style="width: 200px;" placeholder="Search student name o no..." data-table-search="rosterTable">
              <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addStudentModal')">+ Add &amp; Enroll Student</button>
            </div>
          </div>
          <div class="table-responsive">
            <table class="grc-table" id="rosterTable">
              <thead>
                <tr>
                  <th>Student No.</th>
                  <th>Student Full Name</th>
                  <th>Subject &amp; Section</th>
                  <th>School Year / Sem</th>
                  <th>Course &amp; Year</th>
                  <th style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rosterStudents)): ?>
                  <tr class="no-records-row">
                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: 32px;">
                      Walang estudyanteng nakatala. Gamitin ang "+ Add &amp; Enroll Student" upang magdagdag.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rosterStudents as $row): ?>
                    <tr>
                      <td><strong><?= h($row['student_no']) ?></strong></td>
                      <td>
                        <strong><?= h($row['last_name']) ?>, <?= h($row['first_name']) ?></strong>
                        <small style="display: block; color: var(--gray-500);"><?= h($row['student_email'] ?? 'No email') ?></small>
                      </td>
                      <td>
                        <strong><?= h($row['subject_code']) ?></strong> - <?= h($row['section']) ?>
                        <small style="display: block; color: var(--gray-500);"><?= h($row['subject_title']) ?></small>
                      </td>
                      <td>
                        <span class="badge badge-navy"><?= h($row['school_year']) ?></span>
                        <small style="display: block; color: var(--gray-500);"><?= h($row['semester']) ?></small>
                      </td>
                      <td>
                        <?= h($row['course']) ?>
                        <small style="display: block; color: var(--gray-500);"><?= h($row['year_level']) ?></small>
                      </td>
                      <td style="text-align: right; white-space: nowrap;">
                        <a href="professor-dashboard.php?tab=grades&subject_id=<?= (int)$row['subject_id'] ?>" class="btn btn-outline-primary btn-sm">
                          Grades &rarr;
                        </a>
                        <form method="POST" action="professor-dashboard.php?tab=students" style="display: inline-block;" onsubmit="return confirm('Alisin si <?= h($row['first_name'] . ' ' . $row['last_name']) ?> mula sa klase na ito?');">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="remove_enrollment">
                          <input type="hidden" name="enrollment_id" value="<?= (int)$row['enrollment_id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Alisin</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <!-- ===================================================================
           TAB 4: GRADE EVALUATION & REPORT SHEET
           =================================================================== -->
      <?php elseif ($activeTab === 'grades'): ?>
        <?php if ($activeSubject): ?>
          <!-- PRINT-ONLY OFFICIAL HEADER -->
          <div class="print-report-header">
            <h1>GLOBAL RECIPROCAL COLLEGES</h1>
            <h2><?= h(strtoupper($professor['department'])) ?></h2>
            <p style="font-weight: bold; margin-bottom: 6px;">OPISYAL NA ULAT NG MGA GRADO (OFFICIAL GRADE REPORT)</p>
            <div class="print-report-meta">
              <div>
                <strong>Subject Code &amp; Section:</strong> <?= h($activeSubject['subject_code']) ?> - <?= h($activeSubject['section']) ?><br>
                <strong>Descriptive Title:</strong> <?= h($activeSubject['subject_title']) ?> (<?= (int)$activeSubject['units'] ?> Units)
              </div>
              <div>
                <strong>Academic Year &amp; Term:</strong> <?= h($activeSubject['school_year']) ?> (<?= h($activeSubject['semester']) ?>)<br>
                <strong>Faculty Instructor:</strong> <?= h($professor['full_name']) ?> (<?= h($professor['employee_id']) ?>)
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="card hide-print">
          <div class="card-body" style="padding: 16px 20px;">
            <form method="GET" action="professor-dashboard.php" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
              <input type="hidden" name="tab" value="grades">
              <label class="form-label" style="margin-bottom: 0;" for="selectGradeClass">Pumili ng Klase:</label>
              <select id="selectGradeClass" name="subject_id" class="form-select" style="max-width: 440px;" onchange="this.form.submit()">
                <?php foreach ($subjects as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= ($selectedSubjectId === (int)$s['id']) ? 'selected' : '' ?>>
                    <?= h($s['subject_code']) ?> - <?= h($s['section']) ?>: <?= h($s['subject_title']) ?> (<?= h($s['school_year']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($activeSubject): ?>
                <input type="text" class="form-control" style="width: 220px;" placeholder="Search student name..." data-table-search="gradeMatrixTable">
                <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">
                  &#128438; Print Grade Report
                </button>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <?php if (!$activeSubject): ?>
          <div class="card">
            <div class="card-body" style="text-align: center; padding: 40px;">
              <h3>Walang Aktibong Klase</h3>
              <p style="color: var(--gray-500); margin-top: 8px;">Mangyaring gumawa ng klase sa tab ng Subjects bago mag-encode ng grado.</p>
            </div>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="card-header hide-print">
              <div>
                <h3>Master Grade Sheet: <?= h($activeSubject['subject_code']) ?> (<?= h($activeSubject['section']) ?>)</h3>
                <small style="color: var(--gray-500);"><?= h($activeSubject['subject_title']) ?> &bull; <?= h($activeSubject['school_year']) ?>, <?= h($activeSubject['semester']) ?></small>
              </div>
              <span class="badge badge-info"><?= count($gradeRoster) ?> Naka-enroll</span>
            </div>

            <div class="table-responsive">
              <table class="grc-table" id="gradeMatrixTable">
                <thead>
                  <tr>
                    <th rowspan="2" style="vertical-align: bottom;">#</th>
                    <th rowspan="2" style="vertical-align: bottom;">Student No.</th>
                    <th rowspan="2" style="vertical-align: bottom;">Pangalan ng Estudyante</th>
                    <th colspan="3" class="matrix-th-group midterm">Midterm Period</th>
                    <th colspan="3" class="matrix-th-group final">Final Period</th>
                    <th colspan="3" class="matrix-th-group overall">Evaluation Summary</th>
                    <th rowspan="2" class="hide-print" style="vertical-align: bottom; text-align: right;">Action</th>
                  </tr>
                  <tr>
                    <th style="font-size: 11px;">Lecture (65%)</th>
                    <th style="font-size: 11px;">Lab (35%)</th>
                    <th style="font-size: 11px; background: #e2e8f0;">Midterm Grade</th>
                    <th style="font-size: 11px;">Lecture (65%)</th>
                    <th style="font-size: 11px;">Lab (35%)</th>
                    <th style="font-size: 11px; background: #bae6fd;">Final Grade</th>
                    <th style="font-size: 11px; background: #fef3c7;">Course Grade</th>
                    <th style="font-size: 11px;">Transmuted</th>
                    <th style="font-size: 11px;">Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($gradeRoster)): ?>
                    <tr class="no-records-row">
                      <td colspan="13" style="text-align: center; color: var(--gray-500); padding: 32px;">
                        Wala pang naka-enroll na estudyante sa klase na ito.
                        <a href="professor-dashboard.php?tab=students&subject_id=<?= (int)$activeSubject['id'] ?>" style="color: var(--blue-accent); font-weight: 600;">Mag-enroll ng estudyante dito.</a>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php $idx = 1; foreach ($gradeRoster as $row): 
                      $comp = computeStudentGrades($row);
                    ?>
                      <tr>
                        <td><?= $idx++ ?></td>
                        <td><strong><?= h($row['student_no']) ?></strong></td>
                        <td>
                          <strong><?= h($row['last_name']) ?>, <?= h($row['first_name']) ?></strong>
                          <small style="display: block; color: var(--gray-500);"><?= h($row['course']) ?></small>
                        </td>

                        <!-- Midterm -->
                        <td><?= $comp['mid_lec_grade'] !== null ? number_format($comp['mid_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                        <td><?= $comp['mid_lab_grade'] !== null ? number_format($comp['mid_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                        <td style="background: #f8fafc; font-weight: 700;">
                          <?= $comp['midterm_grade'] !== null ? '<span class="badge badge-complete">' . number_format($comp['midterm_grade'], 2) . '%</span>' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                        </td>

                        <!-- Final -->
                        <td><?= $comp['fnl_lec_grade'] !== null ? number_format($comp['fnl_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                        <td><?= $comp['fnl_lab_grade'] !== null ? number_format($comp['fnl_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                        <td style="background: #f0f9ff; font-weight: 700;">
                          <?= $comp['final_grade'] !== null ? '<span class="badge badge-complete">' . number_format($comp['final_grade'], 2) . '%</span>' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                        </td>

                        <!-- Course -->
                        <td style="background: #fffbeb; font-weight: 800; font-size: 14px;">
                          <?= $comp['course_grade'] !== null ? number_format($comp['course_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                        </td>
                        <td><?= h($comp['transmuted']) ?></td>
                        <td>
                          <?php if ($comp['remarks'] === 'PASSED'): ?>
                            <span class="badge badge-passed">PASSED</span>
                          <?php elseif ($comp['remarks'] === 'FAILED'): ?>
                            <span class="badge badge-failed">FAILED</span>
                          <?php else: ?>
                            <span class="badge badge-incomplete">INCOMPLETE</span>
                          <?php endif; ?>
                        </td>
                        <td class="hide-print" style="text-align: right; white-space: nowrap;">
                          <button type="button" class="btn btn-primary btn-sm" onclick='openGradeEntryModal(<?= json_encode($row) ?>)'>
                            &#9998; Encode
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <!-- PRINT SIGNATURE SECTION -->
            <div class="print-signatures">
              <div class="signature-box">
                <div class="signature-line"></div>
                <strong><?= h($professor['full_name']) ?></strong>
                <div style="font-size: 10pt; color: #444;">Faculty Professor / Instructor</div>
              </div>
              <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Dean / Department Head</strong>
                <div style="font-size: 10pt; color: #444;"><?= h($professor['department']) ?></div>
              </div>
              <div class="signature-box">
                <div class="signature-line"></div>
                <strong>College Registrar</strong>
                <div style="font-size: 10pt; color: #444;">Global Reciprocal Colleges</div>
              </div>
            </div>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </main>
</div>

<!-- =====================================================================
     MODALS FOR PROFESSOR ACTIONS
     ===================================================================== -->

<!-- 1. MODAL: ADD SUBJECT -->
<div class="modal-backdrop" id="addSubjectModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Magdagdag ng Bagong Subject / Klase</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('addSubjectModal')">&times;</button>
    </div>
    <form method="POST" action="professor-dashboard.php?tab=subjects">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_subject">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addCode">Subject Code</label>
            <input type="text" id="addCode" name="subject_code" class="form-control" placeholder="IT311" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addSection">Section</label>
            <input type="text" id="addSection" name="section" class="form-control" placeholder="BSIT 3A" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="addTitle">Subject Descriptive Title</label>
          <input type="text" id="addTitle" name="subject_title" class="form-control" placeholder="Web Systems and Technologies 2" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addUnits">Units</label>
            <input type="number" id="addUnits" name="units" class="form-control" value="3" min="1" max="12" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addSY">School Year</label>
            <input type="text" id="addSY" name="school_year" class="form-control" value="2024-2025" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addSem">Semester</label>
            <select id="addSem" name="semester" class="form-select">
              <option value="1st Semester">1st Semester</option>
              <option value="2nd Semester">2nd Semester</option>
              <option value="Summer">Summer</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSubjectModal')">Kanselahin</button>
        <button type="submit" class="btn btn-primary">I-save ang Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- 2. MODAL: EDIT SUBJECT -->
<div class="modal-backdrop" id="editSubjectModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>I-edit ang Subject</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('editSubjectModal')">&times;</button>
    </div>
    <form method="POST" action="professor-dashboard.php?tab=subjects">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_subject">
      <input type="hidden" id="editSubjectId" name="subject_id" value="">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editCode">Subject Code</label>
            <input type="text" id="editCode" name="subject_code" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editSection">Section</label>
            <input type="text" id="editSection" name="section" class="form-control" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="editTitle">Descriptive Title</label>
          <input type="text" id="editTitle" name="subject_title" class="form-control" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editUnits">Units</label>
            <input type="number" id="editUnits" name="units" class="form-control" min="1" max="12" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editSY">School Year</label>
            <input type="text" id="editSY" name="school_year" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editSem">Semester</label>
            <select id="editSem" name="semester" class="form-select">
              <option value="1st Semester">1st Semester</option>
              <option value="2nd Semester">2nd Semester</option>
              <option value="Summer">Summer</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editSubjectModal')">Kanselahin</button>
        <button type="submit" class="btn btn-primary">I-update ang Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- 3. MODAL: ADD STUDENT -->
<div class="modal-backdrop" id="addStudentModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Magdagdag at Mag-enroll ng Estudyante</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('addStudentModal')">&times;</button>
    </div>
    <form method="POST" action="professor-dashboard.php?tab=students">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_student">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="enrollSubId">Subject Class na Pag-eenrollan</label>
          <select id="enrollSubId" name="subject_id" class="form-select" required>
            <option value="">-- Pumili ng Subject Class --</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ($selectedSubjectId === (int)$s['id']) ? 'selected' : '' ?>>
                <?= h($s['subject_code']) ?> - <?= h($s['section']) ?> (<?= h($s['subject_title']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="stuNo">Student Number</label>
            <input type="text" id="stuNo" name="student_no" class="form-control" placeholder="2022-00105" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="stuEmail">Email Address</label>
            <input type="email" id="stuEmail" name="email" class="form-control" placeholder="student@student.grc.edu.ph">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="stuFirst">Pangalan (First Name)</label>
            <input type="text" id="stuFirst" name="first_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="stuLast">Apelyido (Last Name)</label>
            <input type="text" id="stuLast" name="last_name" class="form-control" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="stuCourse">Kurso / Program</label>
            <select id="stuCourse" name="course" class="form-select">
              <option value="BS Information Technology">BS Information Technology</option>
              <option value="BS Computer Science">BS Computer Science</option>
              <option value="BS Business Administration">BS Business Administration</option>
              <option value="Bachelor of Elementary Education">Bachelor of Elementary Education</option>
              <option value="Bachelor of Secondary Education">Bachelor of Secondary Education</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="stuYear">Year Level</label>
            <select id="stuYear" name="year_level" class="form-select">
              <option value="1st Year">1st Year</option>
              <option value="2nd Year">2nd Year</option>
              <option value="3rd Year" selected>3rd Year</option>
              <option value="4th Year">4th Year</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addStudentModal')">Kanselahin</button>
        <button type="submit" class="btn btn-primary">I-enroll ang Estudyante</button>
      </div>
    </form>
  </div>
</div>

<!-- 4. MODAL: ENCODE GRADES -->
<div class="modal-backdrop" id="gradeEntryModal">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div>
        <h3 id="modalStudentHeader">Encode Student Grades</h3>
        <span id="modalStudentSub" style="font-size: 12px; color: var(--gray-300);"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('gradeEntryModal')">&times;</button>
    </div>

    <form method="POST" action="professor-dashboard.php?tab=grades" id="gradeEntryForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_grades">
      <input type="hidden" name="subject_id" value="<?= (int)($activeSubject['id'] ?? 0) ?>">
      <input type="hidden" id="modalEnrollmentId" name="enrollment_id" value="">

      <div class="modal-body">
        <div class="grade-breakdown-tabs">
          <button type="button" class="grade-tab-btn active" onclick="switchGradeTab(event, 'tabMidLec')">
            1. Midterm Lecture (65%)
          </button>
          <button type="button" class="grade-tab-btn" onclick="switchGradeTab(event, 'tabMidLab')">
            2. Midterm Lab (35%)
          </button>
          <button type="button" class="grade-tab-btn" onclick="switchGradeTab(event, 'tabFnlLec')">
            3. Final Lecture (65%)
          </button>
          <button type="button" class="grade-tab-btn" onclick="switchGradeTab(event, 'tabFnlLab')">
            4. Final Lab (35%)
          </button>
        </div>

        <!-- 1. MIDTERM LECTURE -->
        <div id="tabMidLec" class="grade-tab-content" style="display: block;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 12px;">
            Midterm Lecture: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Prelim (10%), Midterm Exam (25%).
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_att">Attendance (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_att" name="mid_lec_attendance" data-component="mid_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_rec">Recitation (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_rec" name="mid_lec_recitation" data-component="mid_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_ass">Assignment (5%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_ass" name="mid_lec_assignment" data-component="mid_lec" placeholder="0–100">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_quiz">Quizzes (30%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_quiz" name="mid_lec_quizzes" data-component="mid_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_prelim">Prelim Exam (10%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_prelim" name="mid_lec_prelim_exam" data-component="mid_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lec_mid">Midterm Exam (25%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lec_mid" name="mid_lec_midterm_exam" data-component="mid_lec" placeholder="0–100">
            </div>
          </div>
        </div>

        <!-- 2. MIDTERM LAB -->
        <div id="tabMidLab" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 12px;">
            Midterm Lab: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Prelim (10%), Midterm Exam (25%).
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_att">Lab Attendance (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_att" name="mid_lab_attendance" data-component="mid_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_rec">Lab Recitation (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_rec" name="mid_lab_recitation" data-component="mid_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_ass">Lab Assignment (5%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_ass" name="mid_lab_assignment" data-component="mid_lab" placeholder="0–100">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_quiz">Lab Quizzes (30%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_quiz" name="mid_lab_quizzes" data-component="mid_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_prelim">Lab Prelim (10%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_prelim" name="mid_lab_prelim_exam" data-component="mid_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_mid">Lab Midterm Exam (25%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_mid" name="mid_lab_midterm_exam" data-component="mid_lab" placeholder="0–100">
            </div>
          </div>
        </div>

        <!-- 3. FINAL LECTURE -->
        <div id="tabFnlLec" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 12px;">
            Final Lecture: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Final Exam (35%).
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lec_att">Attendance (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lec_att" name="fnl_lec_attendance" data-component="fnl_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lec_rec">Recitation (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lec_rec" name="fnl_lec_recitation" data-component="fnl_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lec_ass">Assignment (5%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lec_ass" name="fnl_lec_assignment" data-component="fnl_lec" placeholder="0–100">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lec_quiz">Quizzes (30%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lec_quiz" name="fnl_lec_quizzes" data-component="fnl_lec" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lec_fnl">Final Exam (35%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lec_fnl" name="fnl_lec_final_exam" data-component="fnl_lec" placeholder="0–100">
            </div>
          </div>
        </div>

        <!-- 4. FINAL LAB -->
        <div id="tabFnlLab" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 12px;">
            Final Lab: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Final Exam (35%).
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lab_att">Lab Attendance (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lab_att" name="fnl_lab_attendance" data-component="fnl_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lab_rec">Lab Recitation (15%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lab_rec" name="fnl_lab_recitation" data-component="fnl_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lab_ass">Lab Assignment (5%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lab_ass" name="fnl_lab_assignment" data-component="fnl_lab" placeholder="0–100">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lab_quiz">Lab Quizzes (30%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lab_quiz" name="fnl_lab_quizzes" data-component="fnl_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_fnl_lab_fnl">Lab Final Exam (35%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_fnl_lab_fnl" name="fnl_lab_final_exam" data-component="fnl_lab" placeholder="0–100">
            </div>
          </div>
        </div>

        <!-- REAL-TIME PREVIEW COMPUTATION -->
        <div class="grade-calc-box">
          <div class="grade-calc-item">
            <span class="grade-calc-label">Mid Lec (65%)</span>
            <span class="grade-calc-val" id="previewMidLec">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Mid Lab (35%)</span>
            <span class="grade-calc-val" id="previewMidLab">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Midterm Grade</span>
            <span class="grade-calc-val" id="previewMidterm">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Fnl Lec (65%)</span>
            <span class="grade-calc-val" id="previewFnlLec">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Fnl Lab (35%)</span>
            <span class="grade-calc-val" id="previewFnlLab">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Final Grade</span>
            <span class="grade-calc-val" id="previewFinal">-</span>
          </div>
          <div class="grade-calc-item" style="border-left: 2px solid var(--gray-300); padding-left: 8px;">
            <span class="grade-calc-label" style="color: var(--blue-accent);">Course Grade</span>
            <span class="grade-calc-val" id="previewCourse" style="color: var(--blue-accent);">-</span>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('gradeEntryModal')">Kanselahin</button>
        <button type="submit" class="btn btn-primary">&#128190; I-save at I-record ang Grado</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function openEditSubjectModal(sub) {
  document.getElementById('editSubjectId').value = sub.id;
  document.getElementById('editCode').value = sub.subject_code;
  document.getElementById('editTitle').value = sub.subject_title;
  document.getElementById('editSection').value = sub.section;
  document.getElementById('editUnits').value = sub.units;
  document.getElementById('editSY').value = sub.school_year;
  document.getElementById('editSem').value = sub.semester;
  openModal('editSubjectModal');
}

function openGradeEntryModal(row) {
  document.getElementById('modalStudentHeader').innerText = row.last_name + ', ' + row.first_name;
  document.getElementById('modalStudentSub').innerText = 'Student No: ' + row.student_no + ' | Kurso: ' + row.course;
  document.getElementById('modalEnrollmentId').value = row.enrollment_id;

  const fields = [
    'mid_lec_attendance', 'mid_lec_recitation', 'mid_lec_assignment', 'mid_lec_quizzes', 'mid_lec_prelim_exam', 'mid_lec_midterm_exam',
    'mid_lab_attendance', 'mid_lab_recitation', 'mid_lab_assignment', 'mid_lab_quizzes', 'mid_lab_prelim_exam', 'mid_lab_midterm_exam',
    'fnl_lec_attendance', 'fnl_lec_recitation', 'fnl_lec_assignment', 'fnl_lec_quizzes', 'fnl_lec_final_exam',
    'fnl_lab_attendance', 'fnl_lab_recitation', 'fnl_lab_assignment', 'fnl_lab_quizzes', 'fnl_lab_final_exam'
  ];

  fields.forEach(f => {
    const el = document.querySelector(`[name="${f}"]`);
    if (el) {
      el.value = (row[f] !== null && row[f] !== undefined) ? row[f] : '';
    }
  });

  recalculateModalGrades();
  openModal('gradeEntryModal');
}
</script>
</body>
</html>

