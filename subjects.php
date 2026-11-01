<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: subjects.php
 * Subject and Section Management Portal for Professors
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$professor = requireAuth();
$pdo = getDbConnection();
$professorId = (int)$professor['id'];

$errorMsg = null;

// Handle Form Submissions (Create, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($submittedToken)) {
        $errorMsg = 'Security validation failed (Invalid CSRF token). Please refresh and try again.';
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
                $errorMsg = 'Subject code, title, and section are required.';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO subjects (professor_id, subject_code, subject_title, section, units, school_year, semester)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$professorId, $code, $title, $section, $units, $sy, $sem]);
                setFlash('success', 'Subject class "' . $code . ' - ' . $section . '" created successfully.');
                header('Location: subjects.php');
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
                $errorMsg = 'Unauthorized: You do not have permission to modify this subject.';
            } elseif ($code === '' || $title === '' || $section === '') {
                $errorMsg = 'Subject code, title, and section cannot be blank.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE subjects 
                    SET subject_code = ?, subject_title = ?, section = ?, units = ?, school_year = ?, semester = ?
                    WHERE id = ? AND professor_id = ?
                ');
                $stmt->execute([$code, $title, $section, $units, $sy, $sem, $subjectId, $professorId]);
                setFlash('success', 'Subject details updated successfully.');
                header('Location: subjects.php');
                exit;
            }
        }

        // 3. DELETE SUBJECT
        if ($action === 'delete_subject') {
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            if (!isSubjectOwnedByProfessor($pdo, $subjectId, $professorId)) {
                $errorMsg = 'Unauthorized: You do not have permission to delete this subject.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ? AND professor_id = ?');
                $stmt->execute([$subjectId, $professorId]);
                setFlash('success', 'Subject and its student enrollments were deleted.');
                header('Location: subjects.php');
                exit;
            }
        }
    }
}

// Fetch subjects for this professor with student counts
$stmt = $pdo->prepare('
    SELECT s.*, COUNT(e.id) AS student_count
    FROM subjects s
    LEFT JOIN enrollments e ON s.id = e.subject_id
    WHERE s.professor_id = ?
    GROUP BY s.id
    ORDER BY s.school_year DESC, s.semester ASC, s.subject_code ASC
');
$stmt->execute([$professorId]);
$subjects = $stmt->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Subjects | GLOBAL RECIPROCAL COLLEGES</title>
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
      <a href="subjects.php" class="nav-item active">
        <span class="nav-icon">&#128218;</span>
        <span>My Subjects</span>
      </a>
      <a href="students.php" class="nav-item">
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
        <div class="breadcrumb-title">Subject &amp; Class Management</div>
      </div>
      <div class="topbar-right">
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addSubjectModal')">
          + Add New Class
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
          <h3>Your Assigned Classes &amp; Sections (<?= count($subjects) ?>)</h3>
          <div style="display: flex; gap: 12px; align-items: center;">
            <input type="text" class="form-control" style="width: 250px;" placeholder="Search subject or section..." data-table-search="subjectsTable">
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
                <th style="text-align: right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($subjects)): ?>
                <tr class="no-records-row">
                  <td colspan="8" style="text-align: center; color: var(--gray-500); padding: 32px;">
                    No subjects found. Click the "+ Add New Class" button above to add a subject.
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
                      <a href="students.php?subject_id=<?= (int)$sub['id'] ?>" style="color: var(--blue-accent); font-weight: 600; text-decoration: none;">
                        <?= (int)$sub['student_count'] ?> students &rarr;
                      </a>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                      <a href="grades.php?subject_id=<?= (int)$sub['id'] ?>" class="btn btn-outline-primary btn-sm" title="Open Grade Sheet">
                        Grades
                      </a>
                      <button type="button" class="btn btn-outline-secondary btn-sm" 
                        onclick='openEditSubjectModal(<?= json_encode($sub) ?>)' title="Edit Subject">
                        Edit
                      </button>
                      <form method="POST" action="subjects.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this subject? All associated student enrollments and encoded grades will be permanently deleted.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_subject">
                        <input type="hidden" name="subject_id" value="<?= (int)$sub['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Subject">
                          Delete
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

<!-- MODAL: ADD SUBJECT -->
<div class="modal-backdrop" id="addSubjectModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Add New Subject / Class Section</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('addSubjectModal')">&times;</button>
    </div>
    <form method="POST" action="subjects.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_subject">

      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addCode">Subject Code</label>
            <input type="text" id="addCode" name="subject_code" class="form-control" placeholder="e.g. IT311" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addSection">Section</label>
            <input type="text" id="addSection" name="section" class="form-control" placeholder="e.g. BSIT 3A" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="addTitle">Subject Descriptive Title</label>
          <input type="text" id="addTitle" name="subject_title" class="form-control" placeholder="e.g. Web Systems and Technologies 2" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="addUnits">Units</label>
            <input type="number" id="addUnits" name="units" class="form-control" value="3" min="1" max="12" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="addSY">Academic Year</label>
            <input type="text" id="addSY" name="school_year" class="form-control" value="2024-2025" placeholder="YYYY-YYYY" required>
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
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSubjectModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: EDIT SUBJECT -->
<div class="modal-backdrop" id="editSubjectModal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3>Edit Subject Details</h3>
      <button type="button" class="modal-close-btn" onclick="closeModal('editSubjectModal')">&times;</button>
    </div>
    <form method="POST" action="subjects.php">
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
          <label class="form-label" for="editTitle">Subject Descriptive Title</label>
          <input type="text" id="editTitle" name="subject_title" class="form-control" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="editUnits">Units</label>
            <input type="number" id="editUnits" name="units" class="form-control" min="1" max="12" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editSY">Academic Year</label>
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
        <button type="button" class="btn btn-secondary" onclick="closeModal('editSubjectModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Subject</button>
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
</script>
</body>
</html>

