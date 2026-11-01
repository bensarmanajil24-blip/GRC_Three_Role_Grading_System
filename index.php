<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Dual Portal Grading System
 * File: index.php
 * Official Landing Page & Portal Gateway
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = getCurrentUser();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>GLOBAL RECIPROCAL COLLEGES | Official Grading &amp; Evaluation System</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="landing-body">

<header class="landing-header">
  <div class="landing-container header-inner">
    <div class="landing-brand">
      <div class="landing-logo-badge">GRC</div>
      <div class="landing-brand-text">
        <h1>GLOBAL RECIPROCAL COLLEGES</h1>
        <span>Excellence &bull; Integrity &bull; Service</span>
      </div>
    </div>
    <div class="landing-nav-actions">
      <?php if ($currentUser): ?>
        <a href="<?= ($currentUser['role'] === 'professor') ? 'professor-dashboard.php' : 'student-dashboard.php' ?>" class="btn btn-primary">
          Open Dashboard (<?= h($currentUser['full_name']) ?>) &rarr;
        </a>
        <a href="logout.php" class="btn btn-outline-secondary">Sign Out</a>
      <?php else: ?>
        <a href="professor-login.php" class="btn btn-outline-secondary btn-sm">Faculty Sign In</a>
        <a href="student-login.php" class="btn btn-primary btn-sm">Student Portal</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="landing-main">
  <div class="landing-container">

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>" style="max-width: 860px; margin: 0 auto 24px;">
        <span><?= h($flash['message']) ?></span>
        <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
      </div>
    <?php endif; ?>

    <!-- HERO SECTION -->
    <div class="landing-hero">
      <div class="hero-badge">Official Academic Grading Portal</div>
      <h2 class="hero-title">Automated Evaluation &amp; Grade Reporting System</h2>
      <p class="hero-subtitle">
        Isang secure, automated, at komprehensibong online grading portal na idinisenyo para sa kaguruan at mga mag-aaral ng <strong>Global Reciprocal Colleges</strong>.
      </p>
    </div>

    <!-- PORTAL ACCESS CARDS -->
    <div class="portals-grid">
      <!-- 1. PROFESSOR PORTAL CARD -->
      <div class="portal-card professor-card">
        <div class="portal-card-header">
          <div class="portal-icon">&#128188;</div>
          <span class="portal-pill">Faculty &amp; Instructors</span>
        </div>
        <div class="portal-card-body">
          <h3>Professor Portal</h3>
          <p>
            Ligtas na pamamahala ng mga hawak na klase at subject sections. Mag-encode at mag-edit ng mga score sa Lecture at Laboratory na may awtomatikong computation ng Midterm, Final, at Course Grade.
          </p>
          <ul class="portal-features">
            <li>&#10004; Class &amp; Subject Roster Management</li>
            <li>&#10004; Midterm &amp; Final Grade Encoding (0–100)</li>
            <li>&#10004; Automatic Lecture (65%) + Lab (35%) Formula</li>
            <li>&#10004; Incomplete Grade Indicator &amp; Report Printing</li>
          </ul>
        </div>
        <div class="portal-card-footer">
          <a href="professor-login.php" class="btn btn-primary portal-btn">
            Mag-login sa Professor Portal &rarr;
          </a>
        </div>
      </div>

      <!-- 2. STUDENT PORTAL CARD -->
      <div class="portal-card student-card">
        <div class="portal-card-header">
          <div class="portal-icon">&#127891;</div>
          <span class="portal-pill student">Enrolled Students</span>
        </div>
        <div class="portal-card-body">
          <h3>Student Portal</h3>
          <p>
            Mabilis at secure na pag-check ng iyong mga opisyal na grado. Tingnan ang detalyadong breakdown ng Lecture, Laboratory, Midterm, at Final Grade sa bawat subject class na iyong kinukuha.
          </p>
          <ul class="portal-features">
            <li>&#10004; Read-Only Secure Grade Inquiries</li>
            <li>&#10004; Verified Midterm &amp; Final Grade Status</li>
            <li>&#10004; General Weighted Average (GWA) Overview</li>
            <li>&#10004; Print-Friendly Student Grade Certificate</li>
          </ul>
        </div>
        <div class="portal-card-footer">
          <a href="student-login.php" class="btn btn-navy portal-btn">
            Mag-login sa Student Portal &rarr;
          </a>
        </div>
      </div>
    </div>

    <!-- GRADING POLICY ADVISORY -->
    <div class="policy-banner" style="max-width: 900px; margin: 36px auto 0;">
      <div class="policy-banner-title">
        <span>&#9888;</span>
        <span>Institutional Grading Policy &amp; Formula Notice</span>
      </div>
      <div class="policy-banner-body">
        <p><strong>Itemized Component Weights:</strong> Attendance (15%), Recitation (15%), Assignment (5%), Quizzes (30%), Prelim Exam (10% Midterm only), Midterm Exam (25%), Final Exam (35% Final period).</p>
        <p style="margin-top: 4px;"><strong>Period Formulas:</strong> Midterm / Final Grade = (Lecture × 65%) + (Laboratory × 35%). Course Grade = (Midterm + Final) ÷ 2. Ang mga blangkong score ay may markang <em>Incomplete</em>.</p>
      </div>
    </div>

  </div>
</main>

<footer class="landing-footer">
  <div class="landing-container footer-inner">
    <p>&copy; <?= date('Y') ?> Global Reciprocal Colleges. All Rights Reserved.</p>
    <p>College of Computer Studies &bull; Web Systems &amp; Technologies</p>
  </div>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>
