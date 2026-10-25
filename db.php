<?php
mysqli_report(MYSQLI_REPORT_OFF);

date_default_timezone_set('Asia/Manila');

// Updated default database name based on InfinityFree configuration
$dbname = getenv('DB_NAME') ?: 'if0_42733925_school_attendance';

$candidates = [];
if (getenv('DB_HOST') !== false) {
    $candidates[] = [
        'host' => getenv('DB_HOST'),
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'port' => (int) (getenv('DB_PORT') ?: '3306'),
    ];
}

// Added InfinityFree MySQL hosting configuration
$candidates[] = [
    'host' => 'sql312.infinityfree.com',
    'user' => 'if0_42733925',
    'pass' => 'ELoIYoTxpm',
    'port' => 3306
];
// Local dev (Linux / Docker-style setup)
$candidates[] = ['host' => '127.0.0.1', 'user' => 'attendance', 'pass' => 'attendance123', 'port' => 3306];
// XAMPP default (Windows)
$candidates[] = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'port' => 3306];

$conn = null;
$connectErrors = [];

foreach ($candidates as $candidate) {
    $candidateConn = @new mysqli($candidate['host'], $candidate['user'], $candidate['pass'], '', $candidate['port']);
    if ($candidateConn->connect_errno) {
        $connectErrors[] = "{$candidate['host']} (user {$candidate['user']}): {$candidateConn->connect_error}";
        continue;
    }

    @$candidateConn->query("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    if (!$candidateConn->select_db($dbname)) {
        $connectErrors[] = "{$candidate['host']}: cannot select database `{$dbname}`";
        $candidateConn->close();
        continue;
    }

    $conn = $candidateConn;
    break;
}

if ($conn === null) {
    die(json_encode([
        "status" => "error",
        "message" => "Database connection failed. Tried: " . implode(' | ', $connectErrors)
    ]));
}

$conn->query("SET time_zone = '+08:00'");

$conn->query("CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    grade_level VARCHAR(50) NULL,
    section VARCHAR(50) NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$teacherColumns = [];
$teacherColumnResult = $conn->query('SHOW COLUMNS FROM teachers');
if ($teacherColumnResult) {
    while ($row = $teacherColumnResult->fetch_assoc()) {
        $teacherColumns[] = $row['Field'];
    }
}
if (!in_array('is_approved', $teacherColumns, true)) {
    $conn->query('ALTER TABLE teachers ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0');
}

$conn->query("CREATE TABLE IF NOT EXISTS students (
    student_id VARCHAR(50) PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    gender VARCHAR(10) NULL,
    grade_level VARCHAR(50) NULL,
    section VARCHAR(50) NULL,
    grade_section VARCHAR(50) NOT NULL,
    parent_phone VARCHAR(20) NOT NULL,
    username VARCHAR(100) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    qr_payload VARCHAR(255) NOT NULL DEFAULT ''
)");

$conn->query("CREATE TABLE IF NOT EXISTS grade_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_level VARCHAR(50) NOT NULL,
    section VARCHAR(50) NOT NULL,
    UNIQUE KEY unique_grade_section (grade_level, section)
)");

$conn->query("CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    log_type ENUM('IN', 'OUT', 'AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
)");
$conn->query("ALTER TABLE attendance_logs MODIFY log_type ENUM('IN', 'OUT', 'AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') NOT NULL");

$attendanceLogColumns = [];
$attendanceLogColumnResult = $conn->query('SHOW COLUMNS FROM attendance_logs');
if ($attendanceLogColumnResult) {
    while ($row = $attendanceLogColumnResult->fetch_assoc()) {
        $attendanceLogColumns[] = $row['Field'];
    }
}
if (!in_array('latitude', $attendanceLogColumns, true)) {
    $conn->query('ALTER TABLE attendance_logs ADD COLUMN latitude DECIMAL(10,7) NULL');
}
if (!in_array('longitude', $attendanceLogColumns, true)) {
    $conn->query('ALTER TABLE attendance_logs ADD COLUMN longitude DECIMAL(10,7) NULL');
}

$conn->query("CREATE TABLE IF NOT EXISTS attendance_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    for_date DATE NOT NULL,
    status ENUM('On time', 'Tardy', 'Absent') NOT NULL,
    teacher_id INT DEFAULT NULL,
    note TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY student_date (student_id, for_date),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS attendance_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    teacher_id INT NULL,
    certificate_type VARCHAR(100) NOT NULL DEFAULT 'Punctuality',
    award_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_certificate (student_id, certificate_type, award_date),
    KEY idx_teacher_certificate (teacher_id),
    KEY idx_certificate_date (award_date),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS credential_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    teacher_id INT DEFAULT NULL,
    requested_username VARCHAR(100) NOT NULL,
    requested_password VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    requested_by VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    UNIQUE KEY student_request (student_id),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
)");

$studentColumns = [];
$studentColumnResult = $conn->query('SHOW COLUMNS FROM students');
if ($studentColumnResult) {
    while ($row = $studentColumnResult->fetch_assoc()) {
        $studentColumns[] = $row['Field'];
    }
}

$alterStatements = [
    ['parent_phone', 'VARCHAR(20) NOT NULL DEFAULT ""'],
    ['username', 'VARCHAR(100) NULL UNIQUE'],
    ['password_hash', 'VARCHAR(255) NULL'],
    ['qr_payload', 'VARCHAR(255) NOT NULL DEFAULT ""'],
    ['grade_level', 'VARCHAR(50) NULL'],
    ['section', 'VARCHAR(50) NULL'],
];

foreach ($alterStatements as [$column, $definition]) {
    if (!in_array($column, $studentColumns, true)) {
        $conn->query("ALTER TABLE students ADD COLUMN {$column} {$definition}");
    }
}

$conn->query("UPDATE students SET grade_level = COALESCE(NULLIF(TRIM(grade_level), ''), CASE WHEN LOCATE(' - ', grade_section) > 0 THEN TRIM(SUBSTRING_INDEX(grade_section, ' - ', 1)) ELSE '' END) WHERE grade_level IS NULL OR grade_level = ''");
$conn->query("UPDATE students SET section = COALESCE(NULLIF(TRIM(section), ''), CASE WHEN LOCATE(' - ', grade_section) > 0 THEN TRIM(SUBSTRING_INDEX(grade_section, ' - ', -1)) ELSE '' END) WHERE section IS NULL OR section = ''");
$conn->query("UPDATE students SET grade_section = CONCAT(COALESCE(NULLIF(TRIM(grade_level), ''), ''), CASE WHEN COALESCE(NULLIF(TRIM(section), ''), '') <> '' THEN CONCAT(' - ', TRIM(section)) ELSE '' END) WHERE TRIM(COALESCE(grade_section, '')) = ''");

$adminCount = $conn->query("SELECT COUNT(*) AS total FROM admins");
if ($adminCount) {
    $adminCountRow = $adminCount->fetch_assoc();
    if ((int) $adminCountRow['total'] === 0) {
        $adminPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO admins (username, password_hash, full_name) VALUES ('admin', '{$adminPasswordHash}', 'System Admin')");
    }
}
?>
