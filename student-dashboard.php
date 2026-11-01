<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Student Portal
 * File: student-dashboard.php
 * Read-Only Official Student Grade Evaluation & Certificate of Grades View
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Enforce student role access control
$student = requireStudent();
$pdo = getDbConnection();
$studentId = (int)$student['id'];

// Fetch the student's enrolled subjects and encoded grades only
$stmt = $pdo->prepare('
    SELECT 
        e.id AS enrollment_id,
        sub.id AS subject_id,
        sub.subject_code,
        sub.subject_title,
        sub.section,
        sub.units,
        sub.school_year,
        sub.semester,
        p.full_name AS professor_name,
        p.department AS professor_department,
        g.*
    FROM enrollments e
    JOIN subjects sub ON e.subject_id = sub.id
    JOIN professors p ON e.professor_id = p.id
    LEFT JOIN grades g ON e.id = g.enrollment_id
    WHERE e.student_id = ?
    ORDER BY sub.school_year DESC, sub.semester ASC, sub.subject_code ASC
');
$stmt->execute([$studentId]);
$enrolledSubjects = $stmt->fetchAll();

// Calculate Student GWA (General Weighted Average) across completed subjects
$totalUnits = 0;
$weightedGradeSum = 0.0;
$completedCount = 0;
$pendingCount = 0;

$processedGrades = [];
foreach ($enrolledSubjects as $row) {
    $comp = computeStudentGrades($row);
    $units = (int)$row['units'];
    $totalUnits += $units;

    if ($comp['course_status'] === 'Complete') {
        $completedCount++;
        $weightedGradeSum += ((float)$comp['course_grade'] * $units);
    } else {
        $pendingCount++;
    }

    $processedGrades[] = [
        'info'       => $row,
        'calculated' => $comp,
    ];
}

$gwa = ($completedCount > 0 && $totalUnits > 0) ? ($weightedGradeSum / $totalUnits) : null;
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Portal | GLOBAL RECIPROCAL COLLEGES</title>
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
        <span>STUDENT PORTAL</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Student Menu</div>
      <a href="student-dashboard.php" class="nav-item active">
        <span class="nav-icon">&#127891;</span>
        <span>My Grades &amp; Classes</span>
      </a>
      <a href="index.php" class="nav-item">
        <span class="nav-icon">&#127968;</span>
        <span>Portal Gateway</span>
      </a>

      <div class="nav-label" style="margin-top: 18px;">Account</div>
      <a href="logout.php" class="nav-item" onclick="return confirm('Nais mo bang mag-sign out sa student portal?');">
        <span class="nav-icon">&#128682;</span>
        <span>Sign Out</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-snippet">
        <div class="user-avatar" style="border-color: #38bdf8;">
          <?= strtoupper(substr($student['first_name'], 0, 1)) ?>
        </div>
        <div class="user-info">
          <div class="user-name"><?= h($student['full_name']) ?></div>
          <div class="user-role"><?= h($student['student_no']) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggleBtn" aria-label="Toggle Menu">&#9776;</button>
        <div class="breadcrumb-title">Opisyal na Talaan ng Grado ng Mag-aaral</div>
      </div>
      <div class="topbar-right">
        <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">
          &#128438; Print Certificate of Grades
        </button>
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

      <!-- PRINT-ONLY OFFICIAL CERTIFICATE HEADER -->
      <div class="print-report-header">
        <h1>GLOBAL RECIPROCAL COLLEGES</h1>
        <h2>OFFICE OF THE REGISTRAR &amp; ACADEMIC AFFAIRS</h2>
        <p style="font-weight: bold; margin-bottom: 6px;">OPISYAL NA SERTIPIKASYON NG MGA GRADO (STUDENT GRADE REPORT)</p>
        <div class="print-report-meta">
          <div>
            <strong>Pangalan ng Estudyante:</strong> <?= h($student['full_name']) ?><br>
            <strong>Student Number:</strong> <?= h($student['student_no']) ?><br>
            <strong>Kurso / Programa:</strong> <?= h($student['course']) ?>
          </div>
          <div>
            <strong>Year Level:</strong> <?= h($student['year_level']) ?><br>
            <strong>Petsa ng Pagkakalikha:</strong> <?= date('F d, Y') ?><br>
            <strong>Status ng Rekord:</strong> Opisyal at Beripikado
          </div>
        </div>
      </div>

      <!-- STUDENT PROFILE SUMMARY BANNER -->
      <div class="card hide-print">
        <div class="card-body" style="padding: 20px 24px;">
          <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 16px;">
              <div style="width: 60px; height: 60px; border-radius: 50%; background: var(--navy-primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; border: 3px solid var(--blue-accent);">
                <?= strtoupper(substr($student['first_name'], 0, 1)) ?>
              </div>
              <div>
                <h3 style="font-size: 18px; font-weight: 800; color: var(--navy-dark);"><?= h($student['full_name']) ?></h3>
                <p style="font-size: 12px; color: var(--gray-600);">
                  Student No: <strong><?= h($student['student_no']) ?></strong> &bull; <?= h($student['course']) ?> (<?= h($student['year_level']) ?>)
                </p>
              </div>
            </div>
            <div style="display: flex; gap: 10px;">
              <span class="badge badge-complete">Active Student</span>
              <span class="badge badge-info">A.Y. 2024–2025</span>
            </div>
          </div>
        </div>
      </div>

      <!-- METRICS OVERVIEW -->
      <div class="stats-grid hide-print">
        <div class="stat-card">
          <div class="stat-icon blue">&#128218;</div>
          <div class="stat-info">
            <h3><?= count($enrolledSubjects) ?></h3>
            <p>Enrolled Subjects</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon navy">&#127915;</div>
          <div class="stat-info">
            <h3><?= $totalUnits ?></h3>
            <p>Total Units</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon green">&#9989;</div>
          <div class="stat-info">
            <h3><?= $completedCount ?></h3>
            <p>Completed Grades</p>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon amber">&#9203;</div>
          <div class="stat-info">
            <h3><?= $pendingCount ?></h3>
            <p>Pending / Incomplete</p>
          </div>
        </div>
      </div>

      <!-- READ-ONLY OFFICIAL GRADE REPORT TABLE -->
      <div class="card">
        <div class="card-header hide-print">
          <div>
            <h3>Opisyal na Talaan ng Grado</h3>
            <p style="font-size: 12px; color: var(--gray-500); margin-top: 2px;">
              Lahat ng grado ay kinakalkula ayon sa opisyal na pormula: Midterm (65% Lec + 35% Lab) at Final (65% Lec + 35% Lab).
            </p>
          </div>
          <div>
            <input type="text" class="form-control" style="width: 220px;" placeholder="Search subject..." data-table-search="studentGradeTable">
          </div>
        </div>

        <div class="table-responsive">
          <table class="grc-table" id="studentGradeTable">
            <thead>
              <tr>
                <th rowspan="2" style="vertical-align: bottom;">Subject Code</th>
                <th rowspan="2" style="vertical-align: bottom;">Descriptive Title</th>
                <th rowspan="2" style="vertical-align: bottom;">Section</th>
                <th rowspan="2" style="vertical-align: bottom;">Units</th>
                <th colspan="3" class="matrix-th-group midterm">Midterm Period</th>
                <th colspan="3" class="matrix-th-group final">Final Period</th>
                <th colspan="3" class="matrix-th-group overall">Evaluation Summary</th>
                <th rowspan="2" class="hide-print" style="vertical-align: bottom; text-align: right;">Detalye</th>
              </tr>
              <tr>
                <!-- Midterm -->
                <th style="font-size: 11px;">Lecture (65%)</th>
                <th style="font-size: 11px;">Lab (35%)</th>
                <th style="font-size: 11px; background: #e2e8f0;">Midterm Grade</th>
                <!-- Final -->
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
              <?php if (empty($processedGrades)): ?>
                <tr class="no-records-row">
                  <td colspan="14" style="text-align: center; color: var(--gray-500); padding: 36px;">
                    Wala ka pang enrolled na subject sa kasalukuyang semester.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($processedGrades as $item): 
                  $info = $item['info'];
                  $comp = $item['calculated'];
                ?>
                  <tr>
                    <td><strong><?= h($info['subject_code']) ?></strong></td>
                    <td>
                      <div><strong><?= h($info['subject_title']) ?></strong></div>
                      <small style="color: var(--gray-500);">Prof. <?= h($info['professor_name']) ?></small>
                    </td>
                    <td><span class="badge badge-navy"><?= h($info['section']) ?></span></td>
                    <td><?= (int)$info['units'] ?></td>

                    <!-- Midterm Breakdown -->
                    <td><?= $comp['mid_lec_grade'] !== null ? number_format($comp['mid_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                    <td><?= $comp['mid_lab_grade'] !== null ? number_format($comp['mid_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                    <td style="background: #f8fafc; font-weight: 700;">
                      <?= $comp['midterm_grade'] !== null ? '<span class="badge badge-complete">' . number_format($comp['midterm_grade'], 2) . '%</span>' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                    </td>

                    <!-- Final Breakdown -->
                    <td><?= $comp['fnl_lec_grade'] !== null ? number_format($comp['fnl_lec_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                    <td><?= $comp['fnl_lab_grade'] !== null ? number_format($comp['fnl_lab_grade'], 2) . '%' : '<span class="badge badge-incomplete">Incomplete</span>' ?></td>
                    <td style="background: #f0f9ff; font-weight: 700;">
                      <?= $comp['final_grade'] !== null ? '<span class="badge badge-complete">' . number_format($comp['final_grade'], 2) . '%</span>' : '<span class="badge badge-incomplete">Incomplete</span>' ?>
                    </td>

                    <!-- Course Grade -->
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
                      <button type="button" class="btn btn-outline-primary btn-sm" onclick='showStudentScoreBreakdown(<?= json_encode($item) ?>)'>
                        &#128065; Breakdown
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- PRINT CERTIFICATE SIGNATURES -->
        <div class="print-signatures">
          <div class="signature-box">
            <div class="signature-line"></div>
            <strong><?= h($student['full_name']) ?></strong>
            <div style="font-size: 10pt; color: #444;">Student Signature</div>
          </div>
          <div class="signature-box">
            <div class="signature-line"></div>
            <strong>Office of Academic Affairs</strong>
            <div style="font-size: 10pt; color: #444;">Global Reciprocal Colleges</div>
          </div>
          <div class="signature-box">
            <div class="signature-line"></div>
            <strong>Office of the Registrar</strong>
            <div style="font-size: 10pt; color: #444;">Certified Official Grade Record</div>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- READ-ONLY STUDENT SCORE BREAKDOWN MODAL -->
<div class="modal-backdrop" id="studentBreakdownModal">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div>
        <h3 id="modalSubjTitle">Subject Grade Breakdown</h3>
        <span id="modalSubjMeta" style="font-size: 12px; color: var(--gray-300);"></span>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeModal('studentBreakdownModal')">&times;</button>
    </div>

    <div class="modal-body">
      <div class="alert alert-info" style="font-size: 12px;">
        Ito ay opisyal na <strong>Read-Only View</strong> ng iyong mga encoded component scores na ipinasok ng iyong propesor.
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
        <!-- Midterm Lecture Breakdown -->
        <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 14px;">
          <h4 style="font-size: 13px; font-weight: 700; color: var(--navy-primary); border-bottom: 2px solid var(--gray-300); padding-bottom: 6px; margin-bottom: 10px;">
            1. Midterm Lecture (65%)
          </h4>
          <div id="midLecDetail" style="font-size: 12px; line-height: 1.8;"></div>
        </div>

        <!-- Midterm Lab Breakdown -->
        <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 14px;">
          <h4 style="font-size: 13px; font-weight: 700; color: var(--navy-primary); border-bottom: 2px solid var(--gray-300); padding-bottom: 6px; margin-bottom: 10px;">
            2. Midterm Laboratory (35%)
          </h4>
          <div id="midLabDetail" style="font-size: 12px; line-height: 1.8;"></div>
        </div>

        <!-- Final Lecture Breakdown -->
        <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 14px;">
          <h4 style="font-size: 13px; font-weight: 700; color: var(--navy-primary); border-bottom: 2px solid var(--gray-300); padding-bottom: 6px; margin-bottom: 10px;">
            3. Final Lecture (65%)
          </h4>
          <div id="fnlLecDetail" style="font-size: 12px; line-height: 1.8;"></div>
        </div>

        <!-- Final Lab Breakdown -->
        <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 14px;">
          <h4 style="font-size: 13px; font-weight: 700; color: var(--navy-primary); border-bottom: 2px solid var(--gray-300); padding-bottom: 6px; margin-bottom: 10px;">
            4. Final Laboratory (35%)
          </h4>
          <div id="fnlLabDetail" style="font-size: 12px; line-height: 1.8;"></div>
        </div>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('studentBreakdownModal')">Isara</button>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function showStudentScoreBreakdown(item) {
  const info = item.info;
  const comp = item.calculated;

  document.getElementById('modalSubjTitle').innerText = info.subject_code + ' - ' + info.subject_title;
  document.getElementById('modalSubjMeta').innerText = 'Instructor: Prof. ' + info.professor_name + ' | Section: ' + info.section + ' | ' + info.school_year + ' (' + info.semester + ')';

  const formatScore = (val) => (val !== null && val !== undefined && val !== '') ? parseFloat(val).toFixed(2) + '%' : '<span class="badge badge-incomplete">Incomplete</span>';

  // Midterm Lec
  document.getElementById('midLecDetail').innerHTML = `
    <div>Attendance (15%): <strong>${formatScore(info.mid_lec_attendance)}</strong></div>
    <div>Recitation (15%): <strong>${formatScore(info.mid_lec_recitation)}</strong></div>
    <div>Assignment (5%): <strong>${formatScore(info.mid_lec_assignment)}</strong></div>
    <div>Quizzes (30%): <strong>${formatScore(info.mid_lec_quizzes)}</strong></div>
    <div>Prelim Exam (10%): <strong>${formatScore(info.mid_lec_prelim_exam)}</strong></div>
    <div>Midterm Exam (25%): <strong>${formatScore(info.mid_lec_midterm_exam)}</strong></div>
    <div style="margin-top: 8px; border-top: 1px solid #ddd; padding-top: 4px; font-weight: bold;">
      Midterm Lec Total: ${formatScore(comp.mid_lec_grade)}
    </div>
  `;

  // Midterm Lab
  document.getElementById('midLabDetail').innerHTML = `
    <div>Lab Attendance (15%): <strong>${formatScore(info.mid_lab_attendance)}</strong></div>
    <div>Lab Recitation (15%): <strong>${formatScore(info.mid_lab_recitation)}</strong></div>
    <div>Lab Assignment (5%): <strong>${formatScore(info.mid_lab_assignment)}</strong></div>
    <div>Lab Quizzes (30%): <strong>${formatScore(info.mid_lab_quizzes)}</strong></div>
    <div>Lab Prelim Exam (10%): <strong>${formatScore(info.mid_lab_prelim_exam)}</strong></div>
    <div>Lab Midterm Exam (25%): <strong>${formatScore(info.mid_lab_midterm_exam)}</strong></div>
    <div style="margin-top: 8px; border-top: 1px solid #ddd; padding-top: 4px; font-weight: bold;">
      Midterm Lab Total: ${formatScore(comp.mid_lab_grade)}
    </div>
  `;

  // Final Lec
  document.getElementById('fnlLecDetail').innerHTML = `
    <div>Attendance (15%): <strong>${formatScore(info.fnl_lec_attendance)}</strong></div>
    <div>Recitation (15%): <strong>${formatScore(info.fnl_lec_recitation)}</strong></div>
    <div>Assignment (5%): <strong>${formatScore(info.fnl_lec_assignment)}</strong></div>
    <div>Quizzes (30%): <strong>${formatScore(info.fnl_lec_quizzes)}</strong></div>
    <div>Final Exam (35%): <strong>${formatScore(info.fnl_lec_final_exam)}</strong></div>
    <div style="margin-top: 8px; border-top: 1px solid #ddd; padding-top: 4px; font-weight: bold;">
      Final Lec Total: ${formatScore(comp.fnl_lec_grade)}
    </div>
  `;

  // Final Lab
  document.getElementById('fnlLabDetail').innerHTML = `
    <div>Lab Attendance (15%): <strong>${formatScore(info.fnl_lab_attendance)}</strong></div>
    <div>Lab Recitation (15%): <strong>${formatScore(info.fnl_lab_recitation)}</strong></div>
    <div>Lab Assignment (5%): <strong>${formatScore(info.fnl_lab_assignment)}</strong></div>
    <div>Lab Quizzes (30%): <strong>${formatScore(info.fnl_lab_quizzes)}</strong></div>
    <div>Lab Final Exam (35%): <strong>${formatScore(info.fnl_lab_final_exam)}</strong></div>
    <div style="margin-top: 8px; border-top: 1px solid #ddd; padding-top: 4px; font-weight: bold;">
      Final Lab Total: ${formatScore(comp.fnl_lab_grade)}
    </div>
  `;

  openModal('studentBreakdownModal');
}
</script>
</body>
</html>

