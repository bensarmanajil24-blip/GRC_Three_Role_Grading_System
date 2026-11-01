<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: students.php
 * Student Roster and Class Enrollment Management
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$professor = requireAuth();
$pdo = getDbConnection();
$professorId = (int)$professor['id'];

$errorMsg = null;
$selectedSubjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

// Fetch all subjects taught by this professor for dropdowns
$stmtSubjects = $pdo->prepare('SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC, section ASC');
$stmtSubjects->execute([$professorId]);
$professorSubjects = $stmtSubjects->fetchAll();

// Handle Form Submissions (Add Student & Enroll, Edit Student, Remove Enrollment)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // 1. ADD NEW STUDENT & ENROLL IN SUBJECT
        if ($action === 'add_student') {
            $studentNo = trim((string)($_POST['student_no'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $course = trim((string)($_POST['course'] ?? 'BS Information Technology'));
            $yearLevel = trim((string)($_POST['year_level'] ?? '3rd Year'));
            $targetSubjectId = (int)($_POST['subject_id'] ?? 0);

            if ($studentNo === '' || $firstName === '' || $lastName === '') {
                $errorMsg = 'Student number, first name, and last name are required.';
            } elseif ($targetSubjectId <= 0 || !isSubjectOwnedByProfessor($pdo, $targetSubjectId, $professorId)) {
                $errorMsg = 'Please select a valid subject class that you teach.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Check if student already exists by student_no
                    $stmtCheck = $pdo->prepare('SELECT id FROM students WHERE student_no = ? LIMIT 1');
                    $stmtCheck->execute([$studentNo]);
                    $existingStudentId = $stmtCheck->fetchColumn();

                    if ($existingStudentId) {
                        $studentId = (int)$existingStudentId;
                        // Update details
                        $stmtUp = $pdo->prepare('UPDATE students SET first_name = ?, last_name = ?, email = ?, course = ?, year_level = ? WHERE id = ?');
                        $stmtUp->execute([$firstName, $lastName, $email, $course, $yearLevel, $studentId]);
                    } else {
                        // Insert new student
                        $stmtIns = $pdo->prepare('INSERT INTO students (student_no, first_name, last_name, email, course, year_level) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmtIns->execute([$studentNo, $firstName, $lastName, $email, $course, $yearLevel]);
                        $studentId = (int)$pdo->lastInsertId();
                    }

                    // Check if already enrolled in this subject
                    $stmtEnrollCheck = $pdo->prepare('SELECT id FROM enrollments WHERE subject_id = ? AND student_id = ? LIMIT 1');
                    $stmtEnrollCheck->execute([$targetSubjectId, $studentId]);
                    $enrollId = $stmtEnrollCheck->fetchColumn();

                    if (!$enrollId) {
                        $stmtEnroll = $pdo->prepare('INSERT INTO enrollments (professor_id, subject_id, student_id) VALUES (?, ?, ?)');
                        $stmtEnroll->execute([$professorId, $targetSubjectId, $studentId]);
                        $enrollId = (int)$pdo->lastInsertId();

                        // Initialize empty grades record
                        $stmtInitGrades = $pdo->prepare('INSERT INTO grades (enrollment_id) VALUES (?)');
                        $stmtInitGrades->execute([$enrollId]);
                    }

                    $pdo->commit();
                    setFlash('success', 'Student ' . $firstName . ' ' . $lastName . ' (' . $studentNo . ') enrolled successfully.');
                    header('Location: students.php' . ($selectedSubjectId > 0 ? '?subject_id=' . $selectedSubjectId : ''));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMsg = 'Failed to add student: ' . $e->getMessage();
                }
            }
        }

        // 2. EDIT STUDENT DETAILS
        if ($action === 'edit_student') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $studentNo = trim((string)($_POST['student_no'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $course = trim((string)($_POST['course'] ?? ''));
            $yearLevel = trim((string)($_POST['year_level'] ?? ''));

            if ($studentId <= 0 || $studentNo === '' || $firstName === '' || $lastName === '') {
                $errorMsg = 'Student ID, student number, and names are required.';
            } else {
                // Verify student is enrolled in at least one of this professor's classes
                $stmtVerify = $pdo->prepare('
                    SELECT 1 FROM enrollments WHERE student_id = ? AND professor_id = ? LIMIT 1
                ');
                $stmtVerify->execute([$studentId, $professorId]);
                if (!$stmtVerify->fetchColumn()) {
                    $errorMsg = 'Unauthorized: Student is not enrolled in any of your classes.';
                } else {
                    $stmtUpdate = $pdo->prepare('
                        UPDATE students 
                        SET student_no = ?, first_name = ?, last_name = ?, email = ?, course = ?, year_level = ?
                        WHERE id = ?
                    ');
                    $stmtUpdate->execute([$studentNo, $firstName, $lastName, $email, $course, $yearLevel, $studentId]);
                    setFlash('success', 'Student information updated successfully.');
                    header('Location: students.php' . ($selectedSubjectId > 0 ? '?subject_id=' . $selectedSubjectId : ''));
                    exit;
                }
            }
        }

        // 3. REMOVE STUDENT FROM SUBJECT ENROLLMENT
        if ($action === 'remove_enrollment') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);

            // Verify enrollment belongs to this professor
            $stmtVerify = $pdo->prepare('SELECT id FROM enrollments WHERE id = ? AND professor_id = ? LIMIT 1');
            $stmtVerify->execute([$enrollmentId, $professorId]);
            if (!$stmtVerify->fetchColumn()) {
                $errorMsg = 'Unauthorized: Enrollment not found or access denied.';
            } else {
                $stmtDelete = $pdo->prepare('DELETE FROM enrollments WHERE id = ? AND professor_id = ?');
                $stmtDelete->execute([$enrollmentId, $professorId]);
                setFlash('success', 'Student removed from this class section.');
                header('Location: students.php' . ($selectedSubjectId > 0 ? '?subject_id=' . $selectedSubjectId : ''));
                exit;
            }
        }
    }
}

// Fetch enrolled students according to filter
$query = '
    SELECT 
        e.id AS enrollment_id,
        s.id AS student_id,
        s.student_no,
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
$params = [$professorId];

if ($selectedSubjectId > 0) {
    $query .= ' AND sub.id = ? ';
    $params[] = $selectedSubjectId;
}

$query .= ' ORDER BY sub.subject_code ASC, sub.section ASC, s.last_name ASC, s.first_name ASC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$enrolledStudents = $stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Roster | GLOBAL RECIPROCAL COLLEGES</title>
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
      <a href="students.php" class="nav-item active">
        <span class="nav-icon">&#127891;</span>
        <span>Student Roster</span>
      </a>
      <a href="grades.php" class="nav-item">
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
        <div class="breadcrumb-title">Student Class Roster</div>
      </div>
      <div class="topbar-right">
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addStudentModal')">
          + Add &amp; Enroll Student
        </button>
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

      <div class="card">
        <div class="card-header">
          <h3>Enrolled Students Roster (<?= count($enrolledStudents) ?>)</h3>

          <!-- FILTER BY SUBJECT & SEARCH -->
          <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <form method="GET" action="students.php" style="display: flex; gap: 8px;">
              <select name="subject_id" class="form-select" style="width: auto; min-width: 220px;" onchange="this.form.submit()">
                <option value="0">All Subjects / Classes</option>
                <?php foreach ($professorSubjects as $pSub): ?>
                  <option value="<?= (int)$pSub['id'] ?>" <?= ($selectedSubjectId === (int)$pSub['id']) ? 'selected' : '' ?>>
                    <?= h($pSub['subject_code']) ?> - <?= h($pSub['section']) ?> (<?= h($pSub['subject_title']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </form>

            <input type="text" class="form-control" style="width: 230px;" placeholder="Search student name or no..." data-table-search="rosterTable">
          </div>
        </div>

        <div class="table-responsive">
          <table class="grc-table" id="rosterTable">
            <thead>
              <tr>
                <th>Student No.</th>
                <th>Full Name</th>
                <th>Subject &amp; Section</th>
                <th>Academic Year &amp; Sem</th>
                <th>Program &amp; Year</th>
                <th style="text-align: right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($enrolledStudents)): ?>
                <tr class="no-records-row">
                  <td colspan="6" style="text-align: center; color: var(--gray-500); padding: 32px;">
                    No enrolled students found. Use "+ Add &amp; Enroll Student" to register students to your classes.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($enrolledStudents as $row): ?>
                  <tr>
                    <td><strong><?= h($row['student_no']) ?></strong></td>
                    <td>
                      <div style="font-weight: 600; color: var(--navy-dark);">
                        <?= h($row['last_name']) ?>, <?= h($row['first_name']) ?>
                      </div>
                      <small style="color: var(--gray-500);"><?= h($row['student_email'] ?? 'No email') ?></small>
                    </td>
                    <td>
                      <div><strong><?= h($row['subject_code']) ?></strong> - <?= h($row['section']) ?></div>
                      <small style="color: var(--gray-500);"><?= h($row['subject_title']) ?></small>
                    </td>
                    <td>
                      <span class="badge badge-navy"><?= h($row['school_year']) ?></span>
                      <small style="display: block; color: var(--gray-500);"><?= h($row['semester']) ?></small>
                    </td>
                    <td>
                      <div><?= h($row['course']) ?></div>
                      <small style="color: var(--gray-500);"><?= h($row['year_level']) ?></small>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                      <a href="grades.php?subject_id=<?= (int)$row['subject_id'] ?>" class="btn btn-outline-primary btn-sm" title="View Grades">
                        Grades &rarr;
                      </a>
                      <button type="button" class="btn btn-outline-secondary btn-sm" 
                        onclick='openEditStudentModal(<?= json_encode($row) ?>)' title="Edit Student Details">
                        Edit
                      </button>
                      <form method="POST" action="students.php" style="display: inline-block;" onsubmit="return confirm('Remove student <?= h($row['first_name'] . ' ' . $row['last_name']) ?> from class <?= h($row['subject_code']) ?>? Enrolled grades will also be removed.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remove_enrollment">
                        <input type="hidden" name="enrollment_id" value="<?= (int)$row['enrollment_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Remove Enrollment">
                          Remove
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- MODAL: ADD & ENROLL STUDENT -->
<div class="modal-backdrop" id="addStudentModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Add &amp; Enroll Student to Class</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('addStudentModal')">&times;</button>
    </div>
    <form method="POST" action="students.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_student">

      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="addStudentSubject">Select Subject Class to Enroll in</label>
          <select id="addStudentSubject" name="subject_id" class="form-select" required>
            <option value="">-- Choose Subject Class --</option>
            <?php foreach ($professorSubjects as $pSub): ?>
              <option value="<?= (int)$pSub['id'] ?>" <?= ($selectedSubjectId === (int)$pSub['id']) ? 'selected' : '' ?>>
                <?= h($pSub['subject_code']) ?> - <?= h($pSub['section']) ?> (<?= h($pSub['subject_title']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addStudentNo">Student Number</label>
            <input type="text" id="addStudentNo" name="student_no" class="form-control" placeholder="e.g. 2022-00105" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addStudentEmail">Email Address</label>
            <input type="email" id="addStudentEmail" name="email" class="form-control" placeholder="e.g. student@grc.edu.ph">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addFirstName">First Name</label>
            <input type="text" id="addFirstName" name="first_name" class="form-control" placeholder="First Name" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addLastName">Last Name</label>
            <input type="text" id="addLastName" name="last_name" class="form-control" placeholder="Last Name" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addCourse">Program / Degree</label>
            <select id="addCourse" name="course" class="form-select">
              <option value="BS Information Technology">BS Information Technology</option>
              <option value="BS Computer Science">BS Computer Science</option>
              <option value="BS Business Administration">BS Business Administration</option>
              <option value="Bachelor of Elementary Education">Bachelor of Elementary Education</option>
              <option value="Bachelor of Secondary Education">Bachelor of Secondary Education</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="addYearLevel">Year Level</label>
            <select id="addYearLevel" name="year_level" class="form-select">
              <option value="1st Year">1st Year</option>
              <option value="2nd Year">2nd Year</option>
              <option value="3rd Year" selected>3rd Year</option>
              <option value="4th Year">4th Year</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Enroll Student</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: EDIT STUDENT -->
<div class="modal-backdrop" id="editStudentModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Edit Student Details</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('editStudentModal')">&times;</button>
    </div>
    <form method="POST" action="students.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_student">
      <input type="hidden" id="editStudentId" name="student_id" value="">

      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editStudentNo">Student Number</label>
            <input type="text" id="editStudentNo" name="student_no" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editStudentEmail">Email Address</label>
            <input type="email" id="editStudentEmail" name="email" class="form-control">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editFirstName">First Name</label>
            <input type="text" id="editFirstName" name="first_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editLastName">Last Name</label>
            <input type="text" id="editLastName" name="last_name" class="form-control" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editCourse">Program / Degree</label>
            <input type="text" id="editCourse" name="course" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editYearLevel">Year Level</label>
            <input type="text" id="editYearLevel" name="year_level" class="form-control" required>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Details</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function openEditStudentModal(student) {
  document.getElementById('editStudentId').value = student.student_id;
  document.getElementById('editStudentNo').value = student.student_no;
  document.getElementById('editFirstName').value = student.first_name;
  document.getElementById('editLastName').value = student.last_name;
  document.getElementById('editStudentEmail').value = student.student_email || '';
  document.getElementById('editCourse').value = student.course;
  document.getElementById('editYearLevel').value = student.year_level;
  openModal('editStudentModal');
}
</script>
</body>
</html>

