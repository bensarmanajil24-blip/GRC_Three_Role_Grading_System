GLOBAL RECIPROCAL COLLEGES - Professor Grading Portal

The application is implemented in index.php only. PHP handles the server-side
logic and the same file contains the HTML, CSS, and JavaScript. No demo
credentials are created.

XAMPP installation
1. Place the project folder under C:\xampp\htdocs\.
2. In XAMPP Control Panel, start Apache and MySQL.
3. Optional: open http://localhost/phpmyadmin and create a database named
   grc_grading. The application can also create this database on first access
   when its MySQL account has CREATE DATABASE permission.
4. If needed, update DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS near the
   top of index.php. The default XAMPP MySQL username is root and its password
   is often blank on a local installation; change these values to match yours.
5. Open http://localhost/GRC_Three_Role_Grading_System/index.php.
6. On first visit, set up the first professor account. The application
   automatically creates the required tables.

Grading policy
- Midterm class participation itemized weights are 15% attendance, 15%
  recitation, 5% assignment, and 30% quizzes. Exams are 10% prelim and 25%
  midterm exam. The reference also states 75% for Class Participation, which
  conflicts with the itemized 65%; the itemized weights are used until the
  official policy is confirmed.
- Final weights are 15% attendance, 15% recitation, 5% assignment, 30% quizzes,
  and 35% final exam.
- Period grade is 65% lecture plus 35% laboratory. Course grade is the average
  of the midterm and final grades.
- Blank scores remain incomplete. No official transmutation or passing rule
  is applied. Update the weight constants and approved conversion section in
  index.php only after receiving the official policy.

Use HTTPS, strong unique passwords, database backups, and a restricted MySQL
account before using real student information in a production environment.
