<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: grades.php
 * Comprehensive Grade Encoding, Live Computation, and Official Grade Report Sheet
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$professor = requireAuth();
$pdo = getDbConnection();
$professorId = (int)$professor['id'];

$errorMsg = null;

// Fetch professor's subjects
$stmtSubjects = $pdo->prepare('SELECT * FROM subjects WHERE professor_id = ? ORDER BY school_year DESC, subject_code ASC, section ASC');
$stmtSubjects->execute([$professorId]);
$subjects = $stmtSubjects->fetchAll();

// Determine active subject
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
if ($selectedSubjectId <= 0 && !empty($subjects)) {
    $selectedSubjectId = (int)$subjects[0]['id'];
}

$activeSubject = null;
if ($selectedSubjectId > 0) {
    foreach ($subjects as $s) {
        if ((int)$s['id'] === $selectedSubjectId) {
            $activeSubject = $s;
            break;
        }
    }
}

// Handle Grade Update POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_grades') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $targetSubId = (int)($_POST['subject_id'] ?? 0);

            // Verify ownership
            $stmtVerify = $pdo->prepare('
                SELECT e.id 
                FROM enrollments e 
                JOIN subjects s ON e.subject_id = s.id 
                WHERE e.id = ? AND e.professor_id = ? AND s.id = ?
                LIMIT 1
            ');
            $stmtVerify->execute([$enrollmentId, $professorId, $targetSubId]);
            if (!$stmtVerify->fetchColumn()) {
                $errorMsg = 'Unauthorized: Enrollment record not found or access denied.';
            } else {
                try {
                    // Extract and validate all 22 component score inputs
                    $scoreKeys = [
                        'mid_lec_attendance', 'mid_lec_recitation', 'mid_lec_assignment', 'mid_lec_quizzes', 'mid_lec_prelim_exam', 'mid_lec_midterm_exam',
                        'mid_lab_attendance', 'mid_lab_recitation', 'mid_lab_assignment', 'mid_lab_quizzes', 'mid_lab_prelim_exam', 'mid_lab_midterm_exam',
                        'fnl_lec_attendance', 'fnl_lec_recitation', 'fnl_lec_assignment', 'fnl_lec_quizzes', 'fnl_lec_final_exam',
                        'fnl_lab_attendance', 'fnl_lab_recitation', 'fnl_lab_assignment', 'fnl_lab_quizzes', 'fnl_lab_final_exam'
                    ];

                    $values = [];
                    foreach ($scoreKeys as $k) {
                        $raw = $_POST[$k] ?? null;
                        $validated = validateScore($raw);
                        $values[$k] = $validated;
                    }

                    // Save or update to `grades` table
                    $stmtGradeCheck = $pdo->prepare('SELECT id FROM grades WHERE enrollment_id = ? LIMIT 1');
                    $stmtGradeCheck->execute([$enrollmentId]);
                    $exists = $stmtGradeCheck->fetchColumn();

                    if ($exists) {
                        $setSql = implode(' = ?, ', $scoreKeys) . ' = ?';
                        $updateParams = array_values($values);
                        $updateParams[] = $enrollmentId;

                        $stmtUpdate = $pdo->prepare("UPDATE grades SET {$setSql} WHERE enrollment_id = ?");
                        $stmtUpdate->execute($updateParams);
                    } else {
                        $cols = implode(', ', $scoreKeys);
                        $placeholders = implode(', ', array_fill(0, count($scoreKeys), '?'));
                        $insertParams = array_merge([$enrollmentId], array_values($values));

                        $stmtInsert = $pdo->prepare("INSERT INTO grades (enrollment_id, {$cols}) VALUES (?, {$placeholders})");
                        $stmtInsert->execute($insertParams);
                    }

                    setFlash('success', 'Grades updated and recalculated successfully.');
                    header('Location: grades.php?subject_id=' . $targetSubId);
                    exit;
                } catch (InvalidArgumentException $ex) {
                    $errorMsg = $ex->getMessage();
                } catch (Throwable $ex) {
                    $errorMsg = 'Database error while saving grades: ' . $ex->getMessage();
                }
            }
        }
    }
}

// Fetch enrolled students and their existing grades for the active subject
$gradeRoster = [];
if ($activeSubject) {
    $stmtRoster = $pdo->prepare('
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
    $stmtRoster->execute([$activeSubject['id'], $professorId]);
    $gradeRoster = $stmtRoster->fetchAll();
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grade Evaluation | GLOBAL RECIPROCAL COLLEGES</title>
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
        <span>COLLEGES &bull; FACULTY</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Academic Portal</div>
      <a href="dashboard.php" class="nav-item">
        <span class="nav-icon">&#9638;</span>
        <span>Dashboard</span>
      </a>
      <a href="subjects.php" class="nav-item">
        <span class="nav-icon">&#128218;</span>
        <span>My Subjects</span>
      </a>
      <a href="students.php" class="nav-item">
        <span class="nav-icon">&#127891;</span>
        <span>Student Roster</span>
      </a>
      <a href="grades.php" class="nav-item active">
        <span class="nav-icon">&#9998;</span>
        <span>Grade Evaluation</span>
      </a>

      <div class="nav-label" style="margin-top: 18px;">Account</div>
      <a href="logout.php" class="nav-item" onclick="return confirm('Are you sure you want to sign out?');">
        <span class="nav-icon">&#128682;</span>
        <span>Sign Out</span>
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

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggleBtn">&#9776;</button>
        <div class="breadcrumb-title">Student Grade Evaluation</div>
      </div>
      <div class="topbar-right">
        <?php if ($activeSubject): ?>
          <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">
            &#128438; Print Official Grade Sheet
          </button>
        <?php endif; ?>
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

      <!-- PRINT-ONLY OFFICIAL HEADER -->
      <?php if ($activeSubject): ?>
        <div class="print-report-header">
          <h1>GLOBAL RECIPROCAL COLLEGES</h1>
          <h2><?= h(strtoupper($professor['department'])) ?></h2>
          <p style="font-weight: bold; margin-bottom: 8px;">OFFICIAL REPORT OF GRADES</p>
          <div class="print-report-meta">
            <div>
              <strong>Course / Section:</strong> <?= h($activeSubject['subject_code']) ?> - <?= h($activeSubject['section']) ?><br>
              <strong>Descriptive Title:</strong> <?= h($activeSubject['subject_title']) ?> (<?= (int)$activeSubject['units'] ?> Units)
            </div>
            <div>
              <strong>Academic Year / Sem:</strong> <?= h($activeSubject['school_year']) ?> (<?= h($activeSubject['semester']) ?>)<br>
              <strong>Faculty:</strong> <?= h($professor['full_name']) ?> (<?= h($professor['employee_id']) ?>)
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- SUBJECT PICKER & TOOLBAR -->
      <div class="card hide-print">
        <div class="card-body" style="padding: 16px 20px;">
          <form method="GET" action="grades.php" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px; flex-grow: 1;">
              <label class="form-label" style="margin-bottom: 0; white-space: nowrap;" for="selectSubject">Select Class:</label>
              <select id="selectSubject" name="subject_id" class="form-select" style="max-width: 450px;" onchange="this.form.submit()">
                <?php if (empty($subjects)): ?>
                  <option value="">No classes found. Please create a subject first.</option>
                <?php else: ?>
                  <?php foreach ($subjects as $sub): ?>
                    <option value="<?= (int)$sub['id'] ?>" <?= ($selectedSubjectId === (int)$sub['id']) ? 'selected' : '' ?>>
                      <?= h($sub['subject_code']) ?> - <?= h($sub['section']) ?>: <?= h($sub['subject_title']) ?> (<?= h($sub['school_year']) ?>)
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <?php if ($activeSubject): ?>
              <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" class="form-control" style="width: 220px;" placeholder="Search student name..." data-table-search="gradeSheetTable">
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- OFFICIAL GRADE SHEET CARD -->
      <?php if (!$activeSubject): ?>
        <div class="card">
          <div class="card-body" style="text-align: center; padding: 40px;">
            <h3>No Subject Selected</h3>
            <p style="color: var(--gray-500); margin-top: 8px;">Please create or select an active subject to view and encode student grades.</p>
            <a href="subjects.php" class="btn btn-primary" style="margin-top: 16px;">Go to Subjects &rarr;</a>
          </div>
        </div>
      <?php else: ?>

        <div class="card">
          <div class="card-header hide-print">
            <div>
              <h3>Class Master Grade Sheet: <?= h($activeSubject['subject_code']) ?> - <?= h($activeSubject['section']) ?></h3>
              <p style="font-size: 12px; color: var(--gray-500); margin-top: 2px;">
                <?= h($activeSubject['subject_title']) ?> &bull; <?= h($activeSubject['school_year']) ?>, <?= h($activeSubject['semester']) ?>
              </p>
            </div>
            <div style="display: flex; gap: 8px;">
              <span class="badge badge-info"><?= count($gradeRoster) ?> Enrolled Students</span>
            </div>
          </div>

          <div class="table-responsive">
            <table class="grc-table" id="gradeSheetTable">
              <thead>
                <!-- Group Headers -->
                <tr>
                  <th rowspan="2" style="vertical-align: bottom;">#</th>
                  <th rowspan="2" style="vertical-align: bottom;">Student No.</th>
                  <th rowspan="2" style="vertical-align: bottom;">Student Name</th>
                  <th colspan="3" class="matrix-th-group midterm">Midterm Period</th>
                  <th colspan="3" class="matrix-th-group final">Final Period</th>
                  <th colspan="3" class="matrix-th-group overall">Evaluation Summary</th>
                  <th rowspan="2" class="hide-print" style="vertical-align: bottom; text-align: right;">Action</th>
                </tr>
                <tr>
                  <!-- Midterm Breakdown -->
                  <th style="font-size: 11px;">Lecture (65%)</th>
                  <th style="font-size: 11px;">Lab (35%)</th>
                  <th style="font-size: 11px; background: #e2e8f0;">Midterm Grade</th>

                  <!-- Final Breakdown -->
                  <th style="font-size: 11px;">Lecture (65%)</th>
                  <th style="font-size: 11px;">Lab (35%)</th>
                  <th style="font-size: 11px; background: #bae6fd;">Final Grade</th>

                  <!-- Overall -->
                  <th style="font-size: 11px; background: #fef3c7;">Course Grade</th>
                  <th style="font-size: 11px;">Transmuted</th>
                  <th style="font-size: 11px;">Remarks</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($gradeRoster)): ?>
                  <tr class="no-records-row">
                    <td colspan="13" style="text-align: center; color: var(--gray-500); padding: 32px;">
                      No students enrolled in this class yet. 
                      <a href="students.php?subject_id=<?= (int)$activeSubject['id'] ?>" style="color: var(--blue-accent); font-weight: 600;">Click here to enroll students.</a>
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

                      <!-- Midterm Lecture & Lab -->
                      <td>
                        <?= $comp['mid_lec_grade'] !== null ? number_format($comp['mid_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                      </td>
                      <td>
                        <?= $comp['mid_lab_grade'] !== null ? number_format($comp['mid_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                      </td>
                      <td style="background: #f8fafc; font-weight: 700;">
                        <?php if ($comp['midterm_grade'] !== null): ?>
                          <span class="badge badge-complete"><?= number_format($comp['midterm_grade'], 2) ?>%</span>
                        <?php else: ?>
                          <span class="badge badge-incomplete">Incomplete</span>
                        <?php endif; ?>
                      </td>

                      <!-- Final Lecture & Lab -->
                      <td>
                        <?= $comp['fnl_lec_grade'] !== null ? number_format($comp['fnl_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                      </td>
                      <td>
                        <?= $comp['fnl_lab_grade'] !== null ? number_format($comp['fnl_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                      </td>
                      <td style="background: #f0f9ff; font-weight: 700;">
                        <?php if ($comp['final_grade'] !== null): ?>
                          <span class="badge badge-complete"><?= number_format($comp['final_grade'], 2) ?>%</span>
                        <?php else: ?>
                          <span class="badge badge-incomplete">Incomplete</span>
                        <?php endif; ?>
                      </td>

                      <!-- Course Grade -->
                      <td style="background: #fffbeb; font-weight: 800; font-size: 14px;">
                        <?php if ($comp['course_grade'] !== null): ?>
                          <?= number_format($comp['course_grade'], 2) ?>%
                        <?php else: ?>
                          <span class="badge badge-incomplete">Incomplete</span>
                        <?php endif; ?>
                      </td>

                      <!-- Transmuted Grade -->
                      <td>
                        <?= h($comp['transmuted']) ?>
                      </td>

                      <!-- Remarks -->
                      <td>
                        <?php if ($comp['remarks'] === 'PASSED'): ?>
                          <span class="badge badge-passed">PASSED</span>
                        <?php elseif ($comp['remarks'] === 'FAILED'): ?>
                          <span class="badge badge-failed">FAILED</span>
                        <?php else: ?>
                          <span class="badge badge-incomplete">INCOMPLETE</span>
                        <?php endif; ?>
                      </td>

                      <!-- Actions -->
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
              <div style="font-size: 10pt; color: #444;">Instructor / Professor</div>
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
    </div>
  </main>
</div>

<!-- MODAL: ENCODE / EDIT GRADES -->
<div class="modal-backdrop" id="gradeEntryModal">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div>
        <h3 id="modalStudentHeader">Encode Student Grades</h3>
        <span id="modalStudentSub" style="font-size: 12px; color: var(--gray-300);"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('gradeEntryModal')">&times;</button>
    </div>

    <form method="POST" action="grades.php" id="gradeEntryForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_grades">
      <input type="hidden" name="subject_id" value="<?= (int)($activeSubject['id'] ?? 0) ?>">
      <input type="hidden" id="modalEnrollmentId" name="enrollment_id" value="">

      <div class="modal-body">
        <!-- Navigation Tabs for Components -->
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

        <!-- 1. TAB: MIDTERM LECTURE -->
        <div id="tabMidLec" class="grade-tab-content" style="display: block;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 14px;">
            Midterm Lecture breakdown: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Prelim Exam (10%), Midterm Exam (25%).
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

        <!-- 2. TAB: MIDTERM LAB -->
        <div id="tabMidLab" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 14px;">
            Midterm Laboratory breakdown: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Prelim Exam (10%), Midterm Exam (25%).
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
              <label class="form-label" for="inp_mid_lab_prelim">Lab Prelim Exam (10%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_prelim" name="mid_lab_prelim_exam" data-component="mid_lab" placeholder="0–100">
            </div>
            <div class="form-group">
              <label class="form-label" for="inp_mid_lab_mid">Lab Midterm Exam (25%)</label>
              <input type="number" step="0.01" min="0" max="100" class="form-control score-input" id="inp_mid_lab_mid" name="mid_lab_midterm_exam" data-component="mid_lab" placeholder="0–100">
            </div>
          </div>
        </div>

        <!-- 3. TAB: FINAL LECTURE -->
        <div id="tabFnlLec" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 14px;">
            Final Lecture breakdown: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Final Exam (35%).
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

        <!-- 4. TAB: FINAL LAB -->
        <div id="tabFnlLab" class="grade-tab-content" style="display: none;">
          <div class="alert alert-info" style="font-size: 12px; margin-bottom: 14px;">
            Final Laboratory breakdown: Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Final Exam (35%).
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

        <!-- LIVE REAL-TIME COMPUTATION PREVIEW BOX -->
        <div class="grade-calc-box">
          <div class="grade-calc-item">
            <span class="grade-calc-label">Mid Lecture (65%)</span>
            <span class="grade-calc-val" id="previewMidLec">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Mid Lab (35%)</span>
            <span class="grade-calc-val" id="previewMidLab">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Midterm Period</span>
            <span class="grade-calc-val" id="previewMidterm">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Final Lecture (65%)</span>
            <span class="grade-calc-val" id="previewFnlLec">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Final Lab (35%)</span>
            <span class="grade-calc-val" id="previewFnlLab">-</span>
          </div>
          <div class="grade-calc-item">
            <span class="grade-calc-label">Final Period</span>
            <span class="grade-calc-val" id="previewFinal">-</span>
          </div>
          <div class="grade-calc-item" style="border-left: 2px solid var(--gray-300); padding-left: 10px;">
            <span class="grade-calc-label" style="color: var(--blue-accent);">Course Grade</span>
            <span class="grade-calc-val" id="previewCourse" style="color: var(--blue-accent);">-</span>
          </div>
        </div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('gradeEntryModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">&#128190; Save &amp; Record Grades</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function openGradeEntryModal(row) {
  document.getElementById('modalStudentHeader').innerText = row.last_name + ', ' + row.first_name;
  document.getElementById('modalStudentSub').innerText = 'Student No: ' + row.student_no + ' | Course: ' + row.course;
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

