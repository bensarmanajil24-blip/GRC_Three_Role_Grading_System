<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: dashboard.php
 * Professor Dashboard, System Analytics, and Academic Overview
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$professor = requireAuth();
$pdo = getDbConnection();
$professorId = (int)$professor['id'];

// Fetch Professor's Subjects with enrolled student counts
$stmt = $pdo->prepare('
    SELECT s.*, COUNT(e.id) AS student_count
    FROM subjects s
    LEFT JOIN enrollments e ON s.id = e.subject_id
    WHERE s.professor_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
');
$stmt->execute([$professorId]);
$subjects = $stmt->fetchAll();

// Fetch summary metrics
$totalSubjects = count($subjects);

$stmtEnrolled = $pdo->prepare('
    SELECT COUNT(DISTINCT student_id) 
    FROM enrollments 
    WHERE professor_id = ?
');
$stmtEnrolled->execute([$professorId]);
$totalStudents = (int)$stmtEnrolled->fetchColumn();

// Compute completed vs incomplete grades for professor's classes
$stmtGrades = $pdo->prepare('
    SELECT g.* 
    FROM grades g
    JOIN enrollments e ON g.enrollment_id = e.id
    WHERE e.professor_id = ?
');
$stmtGrades->execute([$professorId]);
$allGrades = $stmtGrades->fetchAll();

$completedGradesCount = 0;
$incompleteGradesCount = 0;

foreach ($allGrades as $gRow) {
    $calculated = computeStudentGrades($gRow);
    if ($calculated['course_status'] === 'Complete') {
        $completedGradesCount++;
    } else {
        $incompleteGradesCount++;
    }
}

// Total total enrollments handled
$stmtTotalEnrollments = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE professor_id = ?');
$stmtTotalEnrollments->execute([$professorId]);
$totalEnrollments = (int)$stmtTotalEnrollments->fetchColumn();
$unencodedCount = $totalEnrollments - count($allGrades);
$incompleteGradesCount += $unencodedCount;

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | GLOBAL RECIPROCAL COLLEGES</title>
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
      <a href="dashboard.php" class="nav-item active">
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
        <div class="user-avatar">
          <?= strtoupper(substr($professor['full_name'], 0, 1)) ?>
        </div>
        <div class="user-info">
          <div class="user-name" title="<?= h($professor['full_name']) ?>"><?= h($professor['full_name']) ?></div>
          <div class="user-role"><?= h($professor['employee_id']) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT AREA -->
  <main class="main-content">
    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggleBtn" aria-label="Toggle Navigation">&#9776;</button>
        <div class="breadcrumb-title">Faculty Dashboard</div>
      </div>
      <div class="topbar-right">
        <div class="academic-badge">
          <span>&#128197;</span>
          <span>A.Y. 2024–2025 | 1st Semester</span>
        </div>
        <a href="logout.php" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Sign out of your session?');">
          Sign Out
        </a>
      </div>
    </header>

    <!-- PAGE CONTAINER -->
    <div class="page-container">
      <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>">
          <span><?= h($flash['message']) ?></span>
          <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
      <?php endif; ?>

      <!-- INSTITUTIONAL GRADING POLICY NOTIFICATION -->
      <div class="policy-banner">
        <div class="policy-banner-title">
          <span>&#9888;</span>
          <span>Official Grading Formula &amp; Institutional Policy Notice</span>
        </div>
        <div class="policy-banner-body">
          <p><strong>Grading Weights:</strong> Midterm and Final periods are calculated with <strong>65% Lecture</strong> and <strong>35% Laboratory</strong>. Course Grade is <strong>(Midterm + Final) ÷ 2</strong>.</p>
          <p style="margin-top: 4px;"><strong>Institutional Clarification:</strong> While the syllabus header states Class Participation at 75%, its itemized sub-components total 65% (Attendance 15% + Recitation 15% + Assignment 5% + Quizzes 30% = 65%). These itemized weights are enforced by default. Unencoded scores are marked <em>Incomplete</em>.</p>
        </div>
      </div>

      <!-- STATS OVERVIEW CARDS -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon blue">&#128218;</div>
          <div class="stat-info">
            <h3><?= number_format($totalSubjects) ?></h3>
            <p>Active Subjects</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon navy">&#127891;</div>
          <div class="stat-info">
            <h3><?= number_format($totalStudents) ?></h3>
            <p>Enrolled Students</p>
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
        <!-- RECENT CLASSES TABLE -->
        <div class="card">
          <div class="card-header">
            <h3>Assigned Subjects &amp; Class Sections</h3>
            <a href="subjects.php" class="btn btn-primary btn-sm">+ Add Subject</a>
          </div>
          <div class="table-responsive">
            <table class="grc-table">
              <thead>
                <tr>
                  <th>Subject Code</th>
                  <th>Title</th>
                  <th>Section</th>
                  <th>Students</th>
                  <th>Term</th>
                  <th style="text-align: right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($subjects)): ?>
                  <tr class="no-records-row">
                    <td colspan="6" style="text-align: center; color: var(--gray-500); padding: 24px;">
                      No subjects registered yet. Click "+ Add Subject" to get started.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($subjects as $s): ?>
                    <tr>
                      <td><strong><?= h($s['subject_code']) ?></strong></td>
                      <td><?= h($s['subject_title']) ?></td>
                      <td><span class="badge badge-navy"><?= h($s['section']) ?></span></td>
                      <td><?= (int)$s['student_count'] ?> students</td>
                      <td><small><?= h($s['school_year']) ?> (<?= h($s['semester']) ?>)</small></td>
                      <td style="text-align: right;">
                        <a href="grades.php?subject_id=<?= (int)$s['id'] ?>" class="btn btn-outline-primary btn-sm">
                          Grade Sheet &rarr;
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card-footer">
            <span style="font-size: 12px; color: var(--gray-500);">Showing <?= count($subjects) ?> subject classes</span>
            <a href="subjects.php" style="font-size: 12px; font-weight: 600; color: var(--blue-accent); text-decoration: none;">
              Manage All Subjects &rarr;
            </a>
          </div>
        </div>

        <!-- PROFESSOR PROFILE CARD -->
        <div class="card">
          <div class="card-header">
            <h3>Faculty Profile</h3>
          </div>
          <div class="card-body">
            <div style="text-align: center; margin-bottom: 20px;">
              <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--navy-primary); color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; border: 3px solid var(--blue-accent); box-shadow: var(--shadow-md);">
                <?= strtoupper(substr($professor['full_name'], 0, 1)) ?>
              </div>
              <h4 style="font-size: 16px; font-weight: 700; margin-top: 10px; color: var(--navy-dark);"><?= h($professor['full_name']) ?></h4>
              <p style="font-size: 12px; color: var(--gray-500);"><?= h($professor['department']) ?></p>
            </div>

            <div style="border-top: 1px solid var(--gray-200); padding-top: 14px; font-size: 13px;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: var(--gray-500);">Employee ID:</span>
                <strong><?= h($professor['employee_id']) ?></strong>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: var(--gray-500);">Institutional Email:</span>
                <strong><?= h($professor['email']) ?></strong>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: var(--gray-500);">Faculty Role:</span>
                <span class="badge badge-complete">Professor</span>
              </div>
              <div style="display: flex; justify-content: space-between;">
                <span style="color: var(--gray-500);">Portal Status:</span>
                <span class="badge badge-info">Active</span>
              </div>
            </div>

            <div style="margin-top: 24px; display: flex; flex-direction: column; gap: 8px;">
              <a href="grades.php" class="btn btn-primary" style="width: 100%;">
                &#9998; Encode Student Grades
              </a>
              <a href="students.php" class="btn btn-outline-secondary" style="width: 100%;">
                &#127891; Manage Student Roster
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>

