<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'db.php';
require_once 'admin_helpers.php';
require_once 'config.php';
require_once 'sms_helper.php';

$gradeSectionsResult = $conn->query("SELECT grade_level, section FROM grade_sections ORDER BY FIELD(grade_level, 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'), grade_level ASC, section ASC");
$gradeSections = $gradeSectionsResult ? $gradeSectionsResult->fetch_all(MYSQLI_ASSOC) : [];
if ($gradeSectionsResult) {
    $gradeSectionsResult->free();
}
$teacherGradeLevels = array_values(array_unique(array_column($gradeSections, 'grade_level')));
$message = '';
$messageType = '';

// Handle teacher actions: register, login, logout
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register_teacher') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $gradeLevel = trim($_POST['grade_level'] ?? '');
        $section = trim($_POST['section'] ?? '');
        if ($fullName === '' || $username === '' || $password === '' || $gradeLevel === '' || $section === '') {
            $message = 'Please fill in all registration fields.';
            $messageType = 'error';
        } else {
            $gradeSectionStmt = $conn->prepare('SELECT id FROM grade_sections WHERE grade_level = ? AND section = ? LIMIT 1');
            $gradeSectionStmt->bind_param('ss', $gradeLevel, $section);
            $gradeSectionStmt->execute();
            $gradeSectionResult = $gradeSectionStmt->get_result();
            $validGradeSection = $gradeSectionResult && $gradeSectionResult->num_rows > 0;
            if ($gradeSectionResult) {
                $gradeSectionResult->free();
            }
            if (!$validGradeSection) {
                $message = 'Please select a valid grade level and section.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('SELECT id FROM teachers WHERE username = ? LIMIT 1');
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $message = 'That username is already taken.';
                    $messageType = 'error';
                } else {
                    $pwHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('INSERT INTO teachers (username, password_hash, full_name, grade_level, section, is_approved) VALUES (?, ?, ?, ?, ?, 0)');
                    $stmt->bind_param('sssss', $username, $pwHash, $fullName, $gradeLevel, $section);
                    $stmt->execute();
                    $message = 'Registration submitted. An admin must approve your account before you can log in.';
                    $messageType = 'success';
                }
                if ($res) {
                    $res->free();
                }
            }
        }
    } elseif ($action === 'login_teacher') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $message = 'Please enter username and password.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare('SELECT id, full_name, password_hash, grade_level, section, is_approved FROM teachers WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);$stmt->execute();
            $res =$stmt->get_result();
            if (hash_equals(MASTER_TEACHER_USERNAME, $username) && hash_equals(MASTER_TEACHER_PASSWORD,$password)) {
                session_regenerate_id(true);
                $_SESSION['teacher_id'] = 0;
                $_SESSION['teacher_name'] = 'Master Teacher';$_SESSION['teacher_grade'] = '';
                $_SESSION['teacher_section'] = '';$_SESSION['teacher_is_master'] = true;
                $message = 'Logged in as master teacher.';$messageType = 'success';
            } elseif ($res &&$res->num_rows > 0) {
                $row =$res->fetch_assoc();
                if (!((int)$row['is_approved'])) {
                    $message = 'Your teacher account is awaiting admin approval.';$messageType = 'error';
                } elseif (password_verify($password, $row['password_hash'])) {$_SESSION['teacher_id'] = $row['id'];$_SESSION['teacher_name'] = $row['full_name'];$_SESSION['teacher_grade'] = $row['grade_level'];$_SESSION['teacher_section'] = $row['section'];$_SESSION['teacher_is_master'] = false;
                    $message = 'Logged in successfully.';$messageType = 'success';
                } else {
                    $message = 'Invalid password.';$messageType = 'error';
                }
            } else {
                $message = 'Teacher account not found.';$messageType = 'error';
            }
            if ($res) {$res->free();
            }
        }
    } elseif ($action === 'logout_teacher') {
        session_unset();
        session_destroy();
        session_start();
        $message = 'Logged out.';$messageType = 'success';
    } elseif ($action === 'issue_punctuality_certificate') {
        $message = 'Certificates are no longer available.';$messageType = 'error';
    } elseif ($action === 'clear_issued_certificate') {
        if (empty($_SESSION['teacher_id']) && empty($_SESSION['teacher_is_master'])) {
            $message = 'Please log in to clear a certificate.';$messageType = 'error';
        } else {
            $certificateId = (int) ($_POST['certificate_id'] ?? 0);
            if ($certificateId <= 0) {
                $message = 'Please select a certificate to clear.';$messageType = 'error';
            } else {
                $isMasterTeacher = !empty($_SESSION['teacher_is_master']);
                $teacherGrade =$_SESSION['teacher_grade'] ?? '';
                $teacherSection =$_SESSION['teacher_section'] ?? '';
                $deleteSql = 'DELETE FROM attendance_certificates WHERE id = ?';$types = 'i';
                $params = [$certificateId];
                if (!$isMasterTeacher) {$deleteSql .= ' AND student_id IN (SELECT student_id FROM students WHERE grade_level = ? AND section = ?)';
                    $types .= 'ss';
                    $params[] =$teacherGrade;
                    $params[] =$teacherSection;
                }
                $deleteStmt = $conn->prepare($deleteSql);
                if (!$deleteStmt) {$message = 'Unable to clear the certificate. Database error: ' . $conn->error;
                    $messageType = 'error';
                } else {
                    $deleteStmt->bind_param($types, ...$params);
                    if (!$deleteStmt->execute()) {$message = 'Unable to clear the certificate. Database error: ' . $deleteStmt->error;
                        $messageType = 'error';
                    } else {
                        $message = 'Issued certificate cleared successfully.';$messageType = 'success';
                    }
                }
            }
        }
    } elseif ($action === 'update_student_attendance') {
        if (empty($_SESSION['teacher_id']) && empty($_SESSION['teacher_is_master'])) {
            $message = 'Please log in to update attendance.';$messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $punchType = strtoupper(trim($_POST['punch_type'] ?? 'AM_IN'));
            $status = trim($_POST['attendance_status'] ?? '');
            $timeValue = trim($_POST['attendance_time'] ?? '');
            $attendanceNote = trim($_POST['attendance_note'] ?? '');
            if ($studentId === '' || !in_array($punchType, ['AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT'], true)) {
                $message = 'Please select a student.';$messageType = 'error';
            } else {
                $selectedDate = trim($_POST['attendance_date'] ?? '');
                if ($selectedDate === '') {$selectedDate = date('Y-m-d');
                }
                $isMasterTeacher = !empty($_SESSION['teacher_is_master']);
                $teacherGrade =$_SESSION['teacher_grade'] ?? '';
                $teacherSection =$_SESSION['teacher_section'] ?? '';
                $verifyStudent =$isMasterTeacher
                    ? $conn->prepare('SELECT student_id, full_name, parent_phone FROM students WHERE student_id = ? LIMIT 1')
                    : $conn->prepare('SELECT student_id, full_name, parent_phone FROM students WHERE student_id = ? AND grade_level = ? AND section = ? LIMIT 1');
                if (!$verifyStudent) {$message = 'Unable to verify the selected student. Database error: ' . $conn->error;
                    $messageType = 'error';
                } else {
                    if ($isMasterTeacher) {
                        $verifyStudent->bind_param('s',$studentId);
                    } else {
                        $verifyStudent->bind_param('sss',$studentId, $teacherGrade,$teacherSection);
                    }
                    $verifyStudent->execute();
                }
                $verifyResult = $verifyStudent ? $verifyStudent->get_result() : null;
                $studentIsInClass = $verifyResult &&$verifyResult->num_rows > 0;
                $studentData = $studentIsInClass ? $verifyResult->fetch_assoc() : null;
                if ($verifyResult) {$verifyResult->free();
                }
                if ($verifyStudent && !$isMasterTeacher && !$studentIsInClass) {
                    $message = 'You may only update learners in your advisory class.';$messageType = 'error';
                } elseif ($verifyStudent) {
                    $timestamp =$selectedDate . ' ' . ($timeValue !== '' ?$timeValue : date('H:i')) . ':00';
                    $legacyLogType =$punchType === 'AM_IN' ? 'IN' : ($punchType === 'AM_OUT' ? 'OUT' : '');$deleteQuery = 'DELETE FROM attendance_logs WHERE student_id = ? AND DATE(`timestamp`) = ? AND (log_type = ?' . ($legacyLogType !== '' ? ' OR log_type = ?' : '') . ')';
                    $stmt = $conn->prepare($deleteQuery);
                    if (!$stmt) {
                        $message = 'Unable to update attendance. Database error: ' . $conn->error;
                        $messageType = 'error';
                    } else {
                        if ($legacyLogType !== '') {
                            $stmt->bind_param('ssss', $studentId, $selectedDate, $punchType, $legacyLogType);
                        } else {
                            $stmt->bind_param('sss', $studentId, $selectedDate, $punchType);
                        }
                        if (!$stmt->execute()) {
                            $message = 'Unable to update attendance. Database error: ' . $stmt->error;
                            $messageType = 'error';
                        }
                    }
                    if ($messageType !== 'error') {
                        $overrideStatus = $status === 'on_time' ? 'On time' : ($status === 'tardy' ? 'Tardy' : 'Absent');
                        $overrideStmt = $conn->prepare('INSERT INTO attendance_overrides (student_id, for_date, status, teacher_id, note) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), teacher_id = VALUES(teacher_id), note = VALUES(note)');
                        if (!$overrideStmt) {
                            $message = 'Unable to save attendance status. Database error: ' . $conn->error;
                            $messageType = 'error';
                        } else {
                            $teacherId = $isMasterTeacher ? null : (int) $_SESSION['teacher_id'];
                            $overrideStmt->bind_param('sssis', $studentId, $selectedDate, $overrideStatus, $teacherId, $attendanceNote);
                            if (!$overrideStmt->execute()) {
                                $message = 'Unable to save attendance status. Database error: ' . $overrideStmt->error;
                                $messageType = 'error';
                            }
                        }
                    }
                    if ($messageType !== 'error' && ($status === 'on_time' || $status === 'tardy')) {
                        $insertStmt = $conn->prepare('INSERT INTO attendance_logs (student_id, log_type, timestamp) VALUES (?, ?, ?)');
                        if (!$insertStmt) {
                            $message = 'Unable to record attendance. Database error: ' . $conn->error;
                            $messageType = 'error';
                        } else {
                            $insertStmt->bind_param('sss', $studentId, $punchType, $timestamp);
                            if (!$insertStmt->execute()) {
                                $message = 'Unable to record attendance. Database error: ' . $insertStmt->error;
                                $messageType = 'error';
                            } else {
                                sendAttendanceSMS($studentData['full_name'], $studentData['parent_phone'], $punchType, $timestamp);
                                $message = 'Attendance updated successfully for ' . $selectedDate . '.';
                                $messageType = 'success';
                            }
                        }
                    } elseif ($messageType !== 'error') {
                        $message = 'Student marked as absent for ' . $selectedDate . '.';
                        $messageType = 'success';
                    }
                }
            }
        }
    } elseif ($action === 'save_attendance_note') {
        if (empty($_SESSION['teacher_id']) && empty($_SESSION['teacher_is_master'])) {
            $message = 'Please log in to save a remark.';
            $messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $selectedDate = trim($_POST['attendance_date'] ?? '');
            $attendanceNote = trim($_POST['attendance_note'] ?? '');
            if ($selectedDate === '') {
                $selectedDate = date('Y-m-d');
            }
            $isMasterTeacher = !empty($_SESSION['teacher_is_master']);
            $teacherGrade = $_SESSION['teacher_grade'] ?? '';
            $teacherSection = $_SESSION['teacher_section'] ?? '';
            if ($studentId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
                $message = 'Please select a learner and a valid date.';
                $messageType = 'error';
            } else {
                $verifyStudent = $isMasterTeacher
                    ? $conn->prepare('SELECT student_id FROM students WHERE student_id = ? LIMIT 1')
                    : $conn->prepare('SELECT student_id FROM students WHERE student_id = ? AND grade_level = ? AND section = ? LIMIT 1');
                if (!$verifyStudent) {
                    $message = 'Unable to verify the selected learner. Database error: ' . $conn->error;
                    $messageType = 'error';
                } else {
                    if ($isMasterTeacher) {
                        $verifyStudent->bind_param('s', $studentId);
                    } else {
                        $verifyStudent->bind_param('sss', $studentId, $teacherGrade, $teacherSection);
                    }
                    $verifyStudent->execute();
                    $verifyResult = $verifyStudent->get_result();
                    $studentIsInClass = $verifyResult && $verifyResult->num_rows > 0;
                    if ($verifyResult) {
                        $verifyResult->free();
                    }
                    if (!$isMasterTeacher && !$studentIsInClass) {
                        $message = 'You may only add remarks for learners in your advisory class.';
                        $messageType = 'error';
                    } else {
                        $existingOverride = $conn->prepare('SELECT status FROM attendance_overrides WHERE student_id = ? AND for_date = ? LIMIT 1');
                        $existingOverride->bind_param('ss', $studentId, $selectedDate);
                        $existingOverride->execute();
                        $existingRow = $existingOverride->get_result()->fetch_assoc();
                        $overrideStatus = $existingRow['status'] ?? 'Absent';
                        $teacherId = $isMasterTeacher ? null : (int) $_SESSION['teacher_id'];
                        $overrideStmt = $conn->prepare('INSERT INTO attendance_overrides (student_id, for_date, status, teacher_id, note) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), note = VALUES(note)');
                        if (!$overrideStmt) {
                            $message = 'Unable to save the remark. Database error: ' . $conn->error;
                            $messageType = 'error';
                        } else {
                            $overrideStmt->bind_param('sssis', $studentId, $selectedDate, $overrideStatus, $teacherId, $attendanceNote);
                            if ($overrideStmt->execute()) {
                                $message = 'Remark saved for ' . $selectedDate . '.';
                                $messageType = 'success';
                            } else {
                                $message = 'Unable to save the remark. Database error: ' . $overrideStmt->error;
                                $messageType = 'error';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'request_student_credentials') {
        if (empty($_SESSION['teacher_id']) && empty($_SESSION['teacher_is_master'])) {
            $message = 'Please log in to request student credentials.';
            $messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $isMasterTeacher = !empty($_SESSION['teacher_is_master']);
            $teacherId = $isMasterTeacher ? null : (int) $_SESSION['teacher_id'];
            $teacherGrade = $_SESSION['teacher_grade'] ?? '';
            $teacherSection = $_SESSION['teacher_section'] ?? '';
            $requestedBy = $_SESSION['teacher_name'] ?? '';
            $verifyStudent = $isMasterTeacher
                ? $conn->prepare('SELECT student_id FROM students WHERE student_id = ? LIMIT 1')
                : $conn->prepare('SELECT student_id FROM students WHERE student_id = ? AND grade_level = ? AND section = ? LIMIT 1');
            if (!$verifyStudent) {
                $message = 'Unable to verify the selected learner. Database error: ' . $conn->error;
                $messageType = 'error';
            } else {
                if ($isMasterTeacher) {
                    $verifyStudent->bind_param('s', $studentId);
                } else {
                    $verifyStudent->bind_param('sss', $studentId, $teacherGrade, $teacherSection);
                }
                $verifyStudent->execute();
                $verifyResult = $verifyStudent->get_result();
                $studentIsInClass = $verifyResult && $verifyResult->num_rows > 0;
                if ($verifyResult) {
                    $verifyResult->free();
                }
                if ($studentId === '') {
                    $message = 'Please select a learner.';
                    $messageType = 'error';
                } elseif (!$isMasterTeacher && !$studentIsInClass) {
                    $message = 'You may only request credentials for learners in your advisory class.';
                    $messageType = 'error';
                } else {
                    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $studentId));
                    if ($base === '') {
                        $base = 'student';
                    }
                    $suggestedUsername = $base;
                    $suffix = 0;
                    while (studentUserExists($conn, $suggestedUsername)) {
                        $suffix++;
                        $suggestedUsername = $base . $suffix;
                    }
                    $generatedPassword = 'BNAS' . random_int(1000, 9999);
                    $reqStmt = $conn->prepare(
                        "INSERT INTO credential_requests (student_id, teacher_id, requested_username, requested_password, status, requested_by, created_at, reviewed_at)
                         VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NULL)
                         ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), requested_username = VALUES(requested_username), requested_password = VALUES(requested_password), status = 'pending', requested_by = VALUES(requested_by), created_at = NOW(), reviewed_at = NULL"
                    );
                    if (!$reqStmt) {
                        $message = 'Unable to create the credential request. Database error: ' . $conn->error;
                        $messageType = 'error';
                    } else {
                        $reqStmt->bind_param('ssss', $studentId, $teacherId, $suggestedUsername, $generatedPassword, $requestedBy);
                        if ($reqStmt->execute()) {
                            $message = 'Credential request submitted for admin approval. Suggested username: ' . $suggestedUsername;
                            $messageType = 'success';
                        } else {
                            $message = 'Unable to create the credential request. Database error: ' . $reqStmt->error;
                            $messageType = 'error';
                        }
                    }
                }
            }
        }
    } elseif ($action === 'bulk_update_checkout') {
        if (empty($_SESSION['teacher_id']) && empty($_SESSION['teacher_is_master'])) {
            $message = 'Please log in to update attendance.';
            $messageType = 'error';
        } else {
            $selectedStudentIds = $_POST['selected_student_ids'] ?? [];
            $checkoutType = strtoupper(trim($_POST['checkout_type'] ?? 'AM_OUT'));
            $checkoutTime = trim($_POST['checkout_time'] ?? '');
            $checkoutDate = trim($_POST['attendance_date'] ?? date('Y-m-d'));
            $teacherGrade = $_SESSION['teacher_grade'] ?? '';
            $teacherSection = $_SESSION['teacher_section'] ?? '';
            if (!in_array($checkoutType, ['AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT'], true)) {
                $message = 'Please select a valid punch type.';
                $messageType = 'error';
            } elseif (!is_array($selectedStudentIds) || count($selectedStudentIds) === 0 || $checkoutTime === '') {
                $message = 'Select at least one learner and enter a time.';
                $messageType = 'error';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkoutDate) || !preg_match('/^\d{2}:\d{2}$/', $checkoutTime)) {
                $message = 'Please enter a valid date and time.';
                $messageType = 'error';
            } else {
                $isMasterTeacher = !empty($_SESSION['teacher_is_master']);
                $verifyStudent = $isMasterTeacher
                    ? $conn->prepare('SELECT student_id, full_name, parent_phone FROM students WHERE student_id = ? LIMIT 1')
                    : $conn->prepare('SELECT student_id, full_name, parent_phone FROM students WHERE student_id = ? AND grade_level = ? AND section = ? LIMIT 1');
                $legacyCheckoutType = $checkoutType === 'AM_OUT' ? 'OUT' : '';
                $deleteQuery = 'DELETE FROM attendance_logs WHERE student_id = ? AND DATE(timestamp) = ? AND (log_type = ?' . ($legacyCheckoutType !== '' ? ' OR log_type = ?' : '') . ')';
                $deleteCheckout = $conn->prepare($deleteQuery);
                $insertCheckout = $conn->prepare('INSERT INTO attendance_logs (student_id, log_type, timestamp) VALUES (?, ?, ?)');
                $checkExistingCheckout = $conn->prepare('SELECT id FROM attendance_logs WHERE student_id = ? AND log_type = ? AND timestamp = ? LIMIT 1');
                $validCount = 0;
                foreach ($selectedStudentIds as $selectedStudentId) {
                    $selectedStudentId = trim((string) $selectedStudentId);
                    if ($isMasterTeacher) {
                        $verifyStudent->bind_param('s', $selectedStudentId);
                    } else {
                        $verifyStudent->bind_param('sss', $selectedStudentId, $teacherGrade, $teacherSection);
                    }
                    $verifyStudent->execute();
                    $verifyResult = $verifyStudent->get_result();
                    $studentIsInClass = $verifyResult && $verifyResult->num_rows > 0;
                    $studentData = $studentIsInClass ? $verifyResult->fetch_assoc() : null;
                    if ($verifyResult) {
                        $verifyResult->free();
                    }
                    if (empty($_SESSION['teacher_is_master']) && !$studentIsInClass) {
                        continue;
                    }
                    $checkoutTimestamp = $checkoutDate . ' ' . $checkoutTime . ':00';
                    $checkExistingCheckout->bind_param('sss', $selectedStudentId, $checkoutType, $checkoutTimestamp);
                    $checkExistingCheckout->execute();
                    $existingRecord = $checkExistingCheckout->get_result();
                    $alreadyRecorded = $existingRecord && $existingRecord->num_rows > 0;
                    if ($existingRecord) {
                        $existingRecord->free();
                    }
                    if ($alreadyRecorded) {
                        $validCount++;
                        continue;
                    }
                    if ($legacyCheckoutType !== '') {
                        $deleteCheckout->bind_param('ssss', $selectedStudentId, $checkoutDate, $checkoutType, $legacyCheckoutType);
                    } else {
                        $deleteCheckout->bind_param('sss', $selectedStudentId, $checkoutDate, $checkoutType);
                    }
                    $deleteCheckout->execute();
                    $insertCheckout->bind_param('sss', $selectedStudentId, $checkoutType, $checkoutTimestamp);
                    if ($insertCheckout->execute()) {
                        sendAttendanceSMS($studentData['full_name'], $studentData['parent_phone'], $checkoutType, $checkoutTimestamp);
                        $validCount++;
                    }
                }
                $message = $validCount . ' learner attendance entr' . ($validCount === 1 ? 'y' : 'ies') . ' saved.';
                $messageType = 'success';
            }
        }
    }
}
$loggedIn = !empty($_SESSION['teacher_id']) || !empty($_SESSION['teacher_is_master']);
$isMasterTeacher = !empty($_SESSION['teacher_is_master']);

// If logged in, fetch students in teacher's advisory
$students = [];
$amTardyAfterMinutes = getAttendanceSetting($conn, 'am_tardy_after_minutes', 495);
$pmTardyAfterMinutes = getAttendanceSetting($conn, 'pm_tardy_after_minutes', 780);
$attendance_cutoff = $_GET['cutoff'] ?? formatMinutesToTime($amTardyAfterMinutes);
$selectedAttendanceDate = trim($_POST['attendance_date'] ?? $_GET['attendance_date'] ?? '');
$genderView = trim($_POST['gender_view'] ?? $_GET['gender_view'] ?? 'all');
if (!in_array($genderView, ['all', 'Male', 'Female'], true)) {
    $genderView = 'all';
}
$punchView = trim($_POST['punch_view'] ?? $_GET['punch_view'] ?? 'complete');
if (!in_array($punchView, ['complete', 'am_pm'], true)) {
    $punchView = 'complete';
}
$showAmOut = $punchView === 'complete';
$showPmIn = $punchView === 'complete';
if ($selectedAttendanceDate === '') {
    $selectedAttendanceDate = date('Y-m-d');
}
if ($loggedIn) {
    $g = $_SESSION['teacher_grade'] ?? '';
    $s = $_SESSION['teacher_section'] ?? '';
    if ($isMasterTeacher) {
        $g = trim($_GET['class_grade'] ?? $_POST['class_grade'] ?? '');
        $s = trim($_GET['class_section'] ?? $_POST['class_section'] ?? '');
    }
    $stmt = $conn->prepare('SELECT student_id, full_name, gender, grade_level, section, parent_phone FROM students WHERE grade_level = ? AND section = ? ORDER BY full_name ASC');
    if (!$stmt) {
        $message = 'Unable to load students. Database error: ' . $conn->error;
        $messageType = 'error';
    } else {
        $stmt->bind_param('ss', $g, $s);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) $students[] = $r;
        }
    }
    if ($genderView !== 'all') {
        $students = array_values(array_filter($students, static function ($student) use ($genderView): bool {
            return ($student['gender'] ?? '') === $genderView;
        }));
    }
    $certificateStmt = $conn->prepare('SELECT ac.id, ac.student_id, ac.award_date, ac.certificate_type, s.full_name FROM attendance_certificates ac JOIN students s ON s.student_id = ac.student_id WHERE s.grade_level = ? AND s.section = ? ORDER BY ac.award_date DESC, s.full_name ASC');
    $issuedCertificates = [];
    if ($certificateStmt && $g !== '' && $s !== '') {
        $certificateStmt->bind_param('ss', $g, $s);
        $certificateStmt->execute();
        $certificateResult = $certificateStmt->get_result();
        if ($certificateResult) {
            while ($certificateRow = $certificateResult->fetch_assoc()) {
                $issuedCertificates[] = $certificateRow;
            }
        }
    }
    $monthStart = strtotime(date('Y-m-01', strtotime($selectedAttendanceDate)));
    $monthDays = [];
    $daysInMonth = (int) date('t', $monthStart);
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = date('Y-m-d', mktime(0, 0, 0, date('n', $monthStart), $day, date('Y', $monthStart)));
        if (isSchoolDay($conn, $date)) {
            $monthDays[] = $date;
        }
    }
    $interventionWatchlist = ['On time' => [], 'Tardy' => [], 'Absent' => []];
    foreach ($students as $student) {
        $studentCounts = ['On time' => 0, 'Tardy' => 0, 'Absent' => 0];
        foreach ($monthDays as $date) {
            $overrideQuery = $conn->prepare('SELECT status FROM attendance_overrides WHERE student_id = ? AND for_date = ? LIMIT 1');
            if ($overrideQuery) {
                $overrideQuery->bind_param('ss', $student['student_id'], $date);
                $overrideQuery->execute();
                $override = $overrideQuery->get_result()->fetch_assoc();
                $status = $override['status'] ?? null;
                if ($overrideQuery) {
                    $overrideQuery->close();
                }
            } else {
                $status = null;
            }
            if ($status === null) {
                $punchQuery = $conn->prepare("SELECT log_type, `timestamp` FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(`timestamp`) = ? ORDER BY `timestamp` ASC");
                if ($punchQuery) {
                    $punchQuery->bind_param('ss', $student['student_id'], $date);
                    $punchQuery->execute();
                    $punchResult = $punchQuery->get_result();
                    $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
                    while ($punchResult && ($punch = $punchResult->fetch_assoc())) {
                        $punchType = $punch['log_type'];
                        if ($punches[$punchType] === null) {
                            $punches[$punchType] = $punch['timestamp'];
                        }
                    }
                    $amInTime = $punches['AM_IN'];
                    if (!$amInTime) {
                        $status = 'Absent';
                    } else {
                        $timeOnly = date('H:i:s', strtotime($amInTime));
                        $status = (strtotime($timeOnly) <= strtotime($attendance_cutoff)) ? 'On time' : 'Tardy';
                    }
                    $punchQuery->close();
                } else {
                    $status = 'Absent';
                }
            }
            if (isset($studentCounts[$status])) {
                $studentCounts[$status]++;
            }
        }
        foreach (['On time', 'Tardy', 'Absent'] as $statusType) {
            if (!empty($monthDays) && $studentCounts[$statusType] === count($monthDays)) {
                $interventionWatchlist[$statusType][] = $student['full_name'];
            }
        }
    }
    // For each student, fetch earliest IN for the selected date and compute status
    $studentCount = count($students);
    $ontimeCount = 0;
    $tardyCount = 0;
    $absentCount = 0;
    foreach ($students as &$st) {
        $sid = $st['student_id'];
        $overrideQuery = $conn->prepare('SELECT status, note FROM attendance_overrides WHERE student_id = ? AND for_date = ? LIMIT 1');
        $overrideQuery->bind_param('ss', $sid, $selectedAttendanceDate);
        $overrideQuery->execute();
        $override = $overrideQuery->get_result()->fetch_assoc();
        $st['attendance_note'] = $override['note'] ?? '';
        $credentialStmt = $conn->prepare('SELECT status, requested_username FROM credential_requests WHERE student_id = ? LIMIT 1');
        $st['credential_status'] = '';
        $st['credential_username'] = '';
        if ($credentialStmt) {
            $credentialStmt->bind_param('s', $sid);
            $credentialStmt->execute();
            $credentialRow = $credentialStmt->get_result()->fetch_assoc();
            if ($credentialRow) {
                $st['credential_status'] = $credentialRow['status'];
                $st['credential_username'] = $credentialRow['requested_username'];
            }
            $credentialStmt->close();
        }
        $q = $conn->prepare("SELECT log_type, `timestamp` FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(`timestamp`) = ? ORDER BY `timestamp` ASC");
        $q->bind_param('ss', $sid, $selectedAttendanceDate);
        $q->execute();
        $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
        $punchResult = $q->get_result();
        while ($punchResult && ($punch = $punchResult->fetch_assoc())) {
            $punchType = $punch['log_type'];
            if ($punches[$punchType] === null) $punches[$punchType] = $punch['timestamp'];
        }
        $st['am_in_time'] = $punches['AM_IN'];
        $st['am_out_time'] = $punches['AM_OUT'];
        $st['pm_in_time'] = $punches['PM_IN'];
        $st['pm_out_time'] = $punches['PM_OUT'];
        $st['am_completion'] = $st['am_in_time'] && $st['am_out_time'] ? 'Complete' : 'Incomplete';
        $st['pm_completion'] = $st['pm_in_time'] && $st['pm_out_time'] ? 'Complete' : 'Incomplete';
        $st['attendance_completion'] = ($st['am_in_time'] && $st['pm_out_time']) ? 'Complete' : 'Incomplete';
        $r = $st['am_in_time'] ? ['timestamp' => $st['am_in_time']] : null;
        $st['checkout_time'] = $st['am_out_time'];
        if (!isSchoolDay($conn, $selectedAttendanceDate)) {
            $st['attendance_status'] = 'Weekend';
            $st['attendance_time'] = null;
            continue;
        }
        if ($override) {
            $st['attendance_status'] = $override['status'];
            $st['attendance_time'] = $r['timestamp'] ?? null;
            if ($st['attendance_status'] === 'On time') {
                $ontimeCount++;
            } elseif ($st['attendance_status'] === 'Tardy') {
                $tardyCount++;
            } else {
                $absentCount++;
            }
        } elseif (! $r) {
            $st['attendance_status'] = 'Absent';
            $st['attendance_time'] = null;
            $absentCount++;
        } else {
            $st['attendance_time'] = $r['timestamp'];
            $timeOnly = date('H:i:s', strtotime($r['timestamp']));
            $st['attendance_status'] = (strtotime($timeOnly) <= strtotime($attendance_cutoff)) ? 'On time' : 'Tardy';
            if ($st['attendance_status'] === 'On time') {
                $ontimeCount++;
            } else {
                $tardyCount++;
            }
        }
    }
    unset($st);

    // Handle CSV export for logged-in teacher (day / week / term / year)
    if ($loggedIn && (isset($_GET['export_csv']) || isset($_POST['export_csv']))) {
        $scope = $_GET['export_scope'] ?? $_POST['export_scope'] ?? 'day';
        $safeGrade = $g ?: 'grade';
        $safeSection = $s ?: 'section';
        $refDate = $selectedAttendanceDate ?: date('Y-m-d');
        $startDate = $endDate = $refDate;
        if ($scope === 'week') {
            $ts = strtotime($refDate);
            $dow = (int)date('N', $ts);
            $startDate = date('Y-m-d', strtotime(sprintf('-%d days', $dow - 1), $ts));
            $endDate = date('Y-m-d', strtotime(sprintf('+%d days', 7 - $dow), $ts));
        } elseif ($scope === 'month') {
            $month = trim($_GET['report_month'] ?? $_POST['report_month'] ?? date('Y-m', strtotime($refDate)));
            if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int)substr($month, 5, 2), 1, (int)substr($month, 0, 4))) {
                $month = date('Y-m', strtotime($refDate));
            }
            $monthStart = strtotime($month . '-01');
            $startDate = date('Y-m-d', $monthStart);
            $endDate = date('Y-m-d', strtotime('+1 month -1 day', $monthStart));
        } elseif ($scope === 'term') {
            $startDate = trim($_GET['term_start'] ?? $_POST['term_start'] ?? '');
            $endDate = trim($_GET['term_end'] ?? $_POST['term_end'] ?? '');
            if ($startDate === '' || $endDate === '') {
                $startDate = $endDate = $refDate;
            }
        } elseif ($scope === 'year') {
            $year = trim($_GET['year'] ?? $_POST['year'] ?? date('Y', strtotime($refDate)));
            if (!preg_match('/^\d{4}$/', $year)) $year = date('Y', strtotime($refDate));
            $startDate = $year . '-01-01';
            $endDate = $year . '-12-31';
        }
        $dates = [];
        $sd = strtotime($startDate);
        $ed = strtotime($endDate);
        if ($sd === false || $ed === false || $sd > $ed) {
            $sd = $ed = strtotime($refDate);
        }
        for ($d = $sd; $d <= $ed; $d = strtotime('+1 day', $d)) {
            $dates[] = date('Y-m-d', $d);
        }
        $filename = sprintf('attendance_%s_%s_%s_to_%s.csv', preg_replace('/[^A-Za-z0-9_-]/', '_', $safeGrade), preg_replace('/[^A-Za-z0-9_-]/', '_', $safeSection), $dates[0], end($dates));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        if ($out) {
            $header = ['Student ID', 'Full Name', 'Gender', 'Grade', 'Section', 'Parent Phone'];
            foreach ($dates as $d) $header[] = $d;
            foreach ($dates as $d) $header[] = $d . ' Remarks';
            foreach ($dates as $d) {
                foreach (['AM IN', 'AM OUT', 'PM IN', 'PM OUT'] as $punchLabel) $header[] = $d . ' ' . $punchLabel;
                $header[] = $d . ' AM Attendance';
                $header[] = $d . ' PM Attendance';
            }
            $header = array_merge($header, ['On time', 'Tardy', 'Absent']);
            fputcsv($out, $header);
            $q = $conn->prepare("SELECT log_type, `timestamp` FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(`timestamp`) = ? ORDER BY `timestamp` ASC");
            $reportTotals = ['On time' => 0, 'Tardy' => 0, 'Absent' => 0];
            $dateTotals = array_fill_keys($dates, ['On time' => 0, 'Tardy' => 0, 'Absent' => 0]);
            foreach ($students as $st) {
                $row = [$st['student_id'], $st['full_name'], $st['gender'] ?? '-', $st['grade_level'], $st['section'], $st['parent_phone']];
                $remarks = [];
                $reportPunches = [];
                $ont = $tardy = $abs = 0;
                foreach ($dates as $d) {
                    if (!isSchoolDay($conn, $d)) {
                        $status = 'Weekend';
                        $remarks[] = 'Weekend';
                        $row[] = 'Weekend';
                        continue;
                    }
                    $overrideQuery = $conn->prepare('SELECT status, note FROM attendance_overrides WHERE student_id = ? AND for_date = ? LIMIT 1');
                    $overrideQuery->bind_param('ss', $st['student_id'], $d);
                    $overrideQuery->execute();
                    $override = $overrideQuery->get_result()->fetch_assoc();
                    $q->bind_param('ss', $st['student_id'], $d);
                    $q->execute();
                    $res = $q->get_result();
                    $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
                    while ($res && ($punch = $res->fetch_assoc())) {
                        $punchType = $punch['log_type'];
                        if ($punches[$punchType] === null) $punches[$punchType] = $punch['timestamp'];
                    }
                    $r = $punches['AM_IN'] ? ['timestamp' => $punches['AM_IN']] : null;
                    $reportPunches[] = $punches;
                    if ($override) {
                        $status = $override['status'];
                        $remarks[] = $override['note'] ?? '';
                        if ($status === 'On time') $ont++; elseif ($status === 'Tardy') $tardy++; else $abs++;
                    } elseif (! $r) {
                        $status = 'Absent';
                        $remarks[] = '';
                        $abs++;
                    } else {
                        $remarks[] = '';
                        $timeOnly = date('H:i:s', strtotime($r['timestamp']));
                        $status = (strtotime($timeOnly) <= strtotime($attendance_cutoff)) ? 'On time' : 'Tardy';
                        if ($status === 'On time') $ont++; else $tardy++;
                    }
                    $dateTotals[$d][$status]++;
                    $reportTotals[$status]++;
                    $row[] = $status;
                }
                $row = array_merge($row, $remarks);
                foreach ($reportPunches as $punches) {
                    foreach (['AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT'] as $punchType) {
                        $row[] = $punches[$punchType] ? date('Y-m-d h:i A', strtotime($punches[$punchType])) : '';
                    }
                    $row[] = $punches['AM_IN'] && $punches['AM_OUT'] ? 'Complete' : 'Incomplete';
                    $row[] = $punches['PM_IN'] && $punches['PM_OUT'] ? 'Complete' : 'Incomplete';
                }
                $row[] = $ont;
                $row[] = $tardy;
                $row[] = $abs;
                fputcsv($out, $row);
            }
            $totalRow = ['TOTAL LEARNERS', '', '', '', ''];
            foreach ($dates as $d) {
                $totalRow[] = 'On time: ' . $dateTotals[$d]['On time'] . '; Tardy: ' . $dateTotals[$d]['Tardy'] . '; Absent: ' . $dateTotals[$d]['Absent'];
            }
            foreach ($dates as $d) $totalRow[] = '';
            $totalRow[] = $reportTotals['On time'];
            $totalRow[] = $reportTotals['Tardy'];
            $totalRow[] = $reportTotals['Absent'];
            fputcsv($out, $totalRow);
            fclose($out);
        }
        exit;
    }

    // Handle printable attendance report
    if ($loggedIn && isset($_GET['export_report'])) {
        $scope = $_GET['export_scope'] ?? $_POST['export_scope'] ?? 'day';
        $refDate = $selectedAttendanceDate ?: date('Y-m-d');
        $startDate = $endDate = $refDate;
        if ($scope === 'week') {
            $ts = strtotime($refDate);
            $dow = (int)date('N', $ts);
            $startDate = date('Y-m-d', strtotime(sprintf('-%d days', $dow - 1), $ts));
            $endDate = date('Y-m-d', strtotime(sprintf('+%d days', 7 - $dow), $ts));
        } elseif ($scope === 'month') {
            $month = trim($_GET['report_month'] ?? $_POST['report_month'] ?? date('Y-m', strtotime($refDate)));
            if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int)substr($month, 5, 2), 1, (int)substr($month, 0, 4))) {
                $month = date('Y-m', strtotime($refDate));
            }
            $monthStart = strtotime($month . '-01');
            $startDate = date('Y-m-d', $monthStart);
            $endDate = date('Y-m-d', strtotime('+1 month -1 day', $monthStart));
        } elseif ($scope === 'term') {
            $startDate = trim($_GET['term_start'] ?? $_POST['term_start'] ?? '');
            $endDate = trim($_GET['term_end'] ?? $_POST['term_end'] ?? '');
            if ($startDate === '' || $endDate === '') {
                $startDate = $endDate = $refDate;
            }
        } elseif ($scope === 'year') {
            $year = trim($_GET['year'] ?? $_POST['year'] ?? date('Y', strtotime($refDate)));
            if (!preg_match('/^\d{4}$/', $year)) $year = date('Y', strtotime($refDate));
            $startDate = $year . '-01-01';
            $endDate = $year . '-12-31';
        }
        $schoolDays = [];
        $sd = strtotime($startDate);
        $ed = strtotime($endDate);
        if ($sd === false || $ed === false || $sd > $ed) {
            $sd = $ed = strtotime($refDate);
        }
        for ($d = $sd; $d <= $ed; $d = strtotime('+1 day', $d)) {
            $date = date('Y-m-d', $d);
            if (isSchoolDay($conn, $date)) {
                $schoolDays[] = $date;
            }
        }
        $totalSchoolDays = count($schoolDays);
        $punchQuery = $conn->prepare("SELECT log_type, `timestamp` FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(`timestamp`) = ? ORDER BY `timestamp` ASC");
        $reportRows = [];
        $grandPresent = 0;
        $grandAbsent = 0;
        foreach ($students as $st) {
            $present = 0;
            $absent = 0;
            foreach ($schoolDays as $date) {
                $punchQuery->bind_param('ss', $st['student_id'], $date);
                $punchQuery->execute();
                $res = $punchQuery->get_result();
                $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
                while ($res && ($punch = $res->fetch_assoc())) {
                    if ($punches[$punch['log_type']] === null) $punches[$punch['log_type']] = $punch['timestamp'];
                }
                if ($punches['AM_IN'] && $punches['PM_OUT']) {
                    $present++;
                } else {
                    $absent++;
                }
            }
            $grandPresent += $present;
            $grandAbsent += $absent;
            $reportRows[] = [
                'student_id' => $st['student_id'],
                'full_name' => $st['full_name'],
                'gender' => $st['gender'] ?? '',
                'present' => $present,
                'absent' => $absent,
            ];
        }
        $reportGrade = $g ?: '-';
        $reportSection = $s ?: '-';
        $reportAdviser = $_SESSION['teacher_name'] ?? '';
        if ($isMasterTeacher && $g !== '' && $s !== '') {
            $adviserStmt = $conn->prepare('SELECT full_name FROM teachers WHERE grade_level = ? AND section = ? ORDER BY is_approved DESC, full_name ASC LIMIT 1');
            if ($adviserStmt) {
                $adviserStmt->bind_param('ss', $g, $s);
                $adviserStmt->execute();
                $adviserRow = $adviserStmt->get_result()->fetch_assoc();
                if ($adviserRow && !empty($adviserRow['full_name'])) {
                    $reportAdviser = $adviserRow['full_name'];
                }
            }
        }
        if ($reportAdviser === '') {
            $reportAdviser = 'Not assigned';
        }
        $scopeLabel = ucfirst($scope);
        $rangeLabel = date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate));
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance Report - <?= htmlspecialchars($reportGrade . ' ' . $reportSection) ?></title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; color: #14241b; margin: 24px; }
        h1 { font-size: 20px; color: #065f46; margin: 0 0 4px; }
        .meta { font-size: 13px; color: #475569; margin-bottom: 4px; }
        .note { font-size: 12px; color: #64748b; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #94a3b8; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #ecfdf5; color: #064e3b; text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px; }
        td.num, th.num { text-align: center; }
        tfoot td { font-weight: 700; background: #f8fafc; }
        .actions { margin-bottom: 16px; }
        .actions button { padding: 9px 16px; border: 0; border-radius: 8px; background: linear-gradient(180deg,#10b981,#059669 55%,#047857); color: #fff; font-weight: 600; cursor: pointer; font-family: inherit; }
        .signature { margin-top: 40px; text-align: right; }
        .signature .sig-line { display: inline-block; min-width: 240px; border-top: 1px solid #14241b; padding-top: 4px; font-weight: 700; font-size: 13px; text-align: center; }
        .signature .sig-label { font-size: 12px; color: #475569; text-align: center; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>
    <h1>Attendance Summary</h1>
    <div class="meta"><strong>Grade &amp; Section:</strong> <?= htmlspecialchars($reportGrade . ' - ' . $reportSection) ?></div>
    <div class="meta"><strong>Adviser:</strong> <?= htmlspecialchars($reportAdviser) ?></div>
    <div class="meta"><strong>Scope:</strong> <?= htmlspecialchars($scopeLabel) ?> (<?= htmlspecialchars($rangeLabel) ?>)</div>
    <div class="meta"><strong>Total School Days:</strong> <?= (int) $totalSchoolDays ?></div>
    <div class="note">Present = attendance completed (AM IN and PM OUT both recorded). Absent = incomplete attendance (missing AM IN or PM OUT).</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Student ID</th>
                <th>Name</th>
                <th>Gender</th>
                <th class="num">Total Present</th>
                <th class="num">Total Absent</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($reportRows): ?>
                <?php foreach ($reportRows as $index => $row): ?>
                    <tr>
                        <td class="num"><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($row['student_id']) ?></td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars($row['gender'] ?: '-') ?></td>
                        <td class="num"><?= (int) $row['present'] ?></td>
                        <td class="num"><?= (int) $row['absent'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="num">No learners found for this class.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">TOTAL</td>
                <td class="num"><?= (int) $grandPresent ?></td>
                <td class="num"><?= (int) $grandAbsent ?></td>
            </tr>
        </tfoot>
    </table>
    <div class="signature">
        <div class="sig-line"><?= htmlspecialchars($reportAdviser) ?></div>
        <div class="sig-label">Adviser</div>
    </div>
</body>
</html>
        <?php
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Teacher Dashboard</title>
    <!-- External Stylesheet Link -->
    <link rel="stylesheet" href="shared/layout.css">
    
    <!-- Fallback Inline Design System (Ensures styling loads even if shared/layout.css is missing) -->
    <style>
        :root {
            --surface: #ffffff;
            --surface-muted: #f8fafc;
            --radius: 8px;
            --radius-lg: 16px;
            --radius-pill: 9999px;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --brand-50: #ecfdf5;
            --brand-100: #d1fae5;
            --brand-600: #059669;
            --brand-700: #047857;
            --brand-900: #064e3b;
            --danger-light: #fee2e2;
            --danger-dark: #991b1b;
            --warning-light: #ffedd5;
            --warning-dark: #92400e;
        }
        body { margin: 0; padding: 28px; display: flex; justify-content: center; background: #f0fdf4; color: #14241b; font-family: 'Segoe UI', system-ui, sans-serif; }
        .container {
            max-width: 1180px;
            width: 100%;
            background: var(--surface);
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(16, 185, 129, 0.14);
        }
        .grid { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; }
        form.inline { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .legend { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 700; text-transform: uppercase; box-shadow: none; }
        .badge.on { background: var(--brand-100); color: var(--brand-900); }
        .badge.tardy { background: var(--warning-light); color: var(--warning-dark); }
        .badge.absent { background: var(--danger-light); color: var(--danger-dark); }
        .badge.weekend { background: #e2e8f0; color: #475569; }
        .completion { display: inline-block; padding: 6px 12px; border-radius: var(--radius-pill); font-weight: 600; font-size: 12px; }
        .completion.complete { background: var(--brand-100); color: var(--brand-900); }
        .completion.incomplete { background: var(--danger-light); color: var(--danger-dark); }
        .inline-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .inline-controls select, .inline-controls input { padding: 8px; min-width: 96px; }
        .table-wrap { width: 100%; overflow-x: auto; margin-top: 12px; border-radius: var(--radius); border: 1px solid #e2e8f0; }
        .attendance-table { min-width: 1260px; width: 100%; border-collapse: collapse; }
        .attendance-table th, .attendance-table td { vertical-align: middle; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
        .attendance-table th { background: #f8fafc; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.4px; }
        .attendance-table .manual-update-form {
            display: grid;
            grid-template-columns: 100px 115px 150px minmax(150px, 1fr) auto;
            gap: 6px;
            align-items: center;
            min-width: 520px;
        }
        .attendance-table .manual-update-form input, .attendance-table .manual-update-form select { width: 100%; min-width: 0; margin: 0; box-sizing: border-box; }
        .bulk-checkout-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .bulk-checkout-form input[type="time"] { width: auto; margin: 0; }
        .button { display: inline-block; padding: 8px 16px; background: linear-gradient(180deg,#10b981,#059669 55%,#047857); color: #fff; border: 0; border-radius: var(--radius); font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; font-size: 13px; }
        .button:hover { background: #047857; }
        .message { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 16px; font-weight: 600; font-size: 13px; }
        .message.success { background: var(--brand-50); color: var(--brand-900); border: 1px solid var(--brand-100); }
        .message.error { background: var(--danger-light); color: var(--danger-dark); border: 1px solid #fecaca; }
        .no-print { display: inherit }
        @media print {
            body { display: block; background: #fff; padding: 0 }
            .container { box-shadow: none; padding: 0; max-width: none; border: none }
            .no-print, .topbar, .legend, .button, .message { display: none !important }
            table { font-size: 12px }
        }
        @media (max-width: 700px) {
            body { padding: 14px; }
            .container { padding: 16px; border-radius: var(--radius); }
            form.inline input { min-width: 120px }
            .grid { grid-template-columns: 1fr }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Teacher Dashboard</h1>
        <style>
            .topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
            .topbar .left { display:flex; gap:8px; align-items:center; }
            .topbar .right { display:flex; gap:8px; align-items:center; }
            .button.logout { background: linear-gradient(180deg,#f43f5e,#e11d48 60%,#be123c); }
            .button.logout:hover { box-shadow: 0 6px 16px rgba(225,29,72,0.35); }
        </style>
        <div class="topbar">
            <div class="left">
                <a class="button" href="index.php">Back to Kiosk</a>
                <a class="button" href="admin.php">Go to Admin Panel</a>
            </div>
            <div class="right">
                <?php if ($loggedIn): ?>
                    <form method="get" style="display:inline-block; margin:0;">
                        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedAttendanceDate) ?>">
                        <input type="hidden" name="gender_view" value="<?= htmlspecialchars($genderView) ?>">
                        <input type="hidden" name="punch_view" value="<?= htmlspecialchars($punchView) ?>">
                        <label style="display:inline-block; margin-right:6px; color:#065f46; font-weight:700; font-size:13px">Export:</label>
                        <select name="export_scope" id="export_scope" style="padding:6px; margin-right:6px">
                            <option value="day">Day</option>
                            <option value="week">Week</option>
                            <option value="month">Month</option>
                            <option value="term">Term</option>
                            <option value="year">Year</option>
                        </select>
                        <input type="month" name="report_month" id="report_month" style="display:none; margin-right:6px; padding:6px">
                        <input type="date" name="term_start" id="term_start" style="display:none; margin-right:6px; padding:6px">
                        <input type="date" name="term_end" id="term_end" style="display:none; margin-right:6px; padding:6px">
                        <input type="number" name="year" id="export_year" placeholder="Year" min="2000" max="2100" style="display:none; width:80px; margin-right:6px; padding:6px">
                        <button type="submit" name="export_csv" value="1" class="button">Export CSV</button>
                        <button type="submit" name="export_report" value="1" class="button" formtarget="_blank">Generate PDF Report</button>
                    </form>
                    <button type="button" class="button" onclick="window.print()">Print Attendance</button>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="logout_teacher">
                        <button type="submit" class="button logout">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($message !== ''): ?>
            <div class="message <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if (! $loggedIn): ?>
            <section style="margin-top:16px">
                <div style="display:flex; justify-content:space-between; align-items:center">
                    <h2>Teacher Login</h2>
                    <a href="#" id="openRegister" class="button">Register</a>
                </div>
                <form method="post" class="inline">
                    <input name="action" type="hidden" value="login_teacher">
                    <input name="username" placeholder="Username" style="padding:8px;">
                    <input name="password" type="password" placeholder="Password" style="padding:8px;">
                    <button type="submit">Login</button>
                </form>
            </section>
            <!-- Registration Modal -->
            <div id="registerModal" style="display:none; position:fixed; inset:0; background:rgba(2,44,34,0.55); backdrop-filter:blur(6px); align-items:center; justify-content:center; z-index:9999; padding:16px">
                <div style="background:#fff; padding:26px; border-radius:18px; width:min(720px,95%); box-shadow:0 24px 70px rgba(2,44,34,0.4); position:relative; border:1px solid rgba(16,185,129,0.2)">
                    <button id="closeRegister" style="position:absolute; right:14px; top:12px; background:transparent; border:0; font-size:18px; cursor:pointer; color:#64748b">✕</button>
                    <h3>Teacher Registration</h3>
                    <form method="post" id="registerForm" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;" autocomplete="off">
                        <input type="hidden" name="action" value="register_teacher">
                        <input name="full_name" placeholder="Full name" required style="grid-column:1/3; padding:10px 14px">
                        <input name="username" placeholder="Username" required style="padding:10px 14px">
                        <input name="password" type="password" placeholder="Password" required style="padding:10px 14px">
                        <select name="grade_level" id="teacher-register-grade" required style="padding:10px 14px">
                            <option value="">Select advisory grade</option>
                            <?php foreach ($teacherGradeLevels as $teacherGradeLevel): ?>
                                <option value="<?= htmlspecialchars($teacherGradeLevel) ?>"><?= htmlspecialchars($teacherGradeLevel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="section" id="teacher-register-section" required style="padding:10px 14px" disabled>
                            <option value="">Select advisory section</option>
                        </select>
                        <div style="grid-column:1/3; display:flex; gap:8px; justify-content:flex-end; margin-top:10px">
                            <button type="button" id="cancelRegister" class="button" style="background:#64748b; padding:10px 18px;">Cancel</button>
                            <button type="submit" class="button" style="padding:10px 18px;">Create account</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
                (function(){
                    var open = document.getElementById('openRegister');
                    var modal = document.getElementById('registerModal');
                    var close = document.getElementById('closeRegister');
                    var cancel = document.getElementById('cancelRegister');
                    function show() { modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
                    function hide() { modal.style.display = 'none'; document.body.style.overflow = ''; }
                    if (open) open.addEventListener('click', function(e){ e.preventDefault(); show(); });
                    if (close) close.addEventListener('click', hide);
                    if (cancel) cancel.addEventListener('click', hide);
                    if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) hide(); });
                    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') hide(); });
                })();
            </script>
            <script>
                (function(){
                    var gradeSelect = document.getElementById('teacher-register-grade');
                    var sectionSelect = document.getElementById('teacher-register-section');
                    var gradeSections = <?= json_encode($gradeSections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    if (!gradeSelect || !sectionSelect) return;
                    gradeSelect.addEventListener('change', function () {
                        sectionSelect.innerHTML = '<option value="">Select advisory section</option>';
                        gradeSections.forEach(function (gradeSection) {
                            if (gradeSection.grade_level !== gradeSelect.value) return;
                            var option = document.createElement('option');
                            option.value = gradeSection.section;
                            option.textContent = gradeSection.section;
                            sectionSelect.appendChild(option);
                        });
                        sectionSelect.disabled = sectionSelect.options.length === 1;
                    });
                })();
            </script>
        <?php else: ?>
            <section>
                <h2>Welcome, <?= htmlspecialchars($_SESSION['teacher_name']) ?></h2>
                <p><?= $isMasterTeacher ? 'Master Teacher access' : 'Advisory: ' . htmlspecialchars(($_SESSION['teacher_grade'] ?: '-') . (($_SESSION['teacher_section']) ? ' - ' . $_SESSION['teacher_section'] : '')) ?></p>
                <?php if ($isMasterTeacher): ?>
                    <form method="get" class="inline-controls" style="margin-top:10px;">
                        <label for="class_grade" style="margin:0; font-weight:600;">Class Grade</label>
                        <select id="class_grade" name="class_grade" required>
                            <option value="">Select grade</option>
                            <?php foreach ($teacherGradeLevels as $gradeLevel): ?>
                                <option value="<?= htmlspecialchars($gradeLevel) ?>" <?= $g === $gradeLevel ? 'selected' : '' ?>><?= htmlspecialchars($gradeLevel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="class_section" style="margin:0; font-weight:600;">Section</label>
                        <select id="class_section" name="class_section" required>
                            <option value="">Select section</option>
                            <?php foreach ($gradeSections as $gradeSection): ?>
                                <option value="<?= htmlspecialchars($gradeSection['section']) ?>" data-grade="<?= htmlspecialchars($gradeSection['grade_level']) ?>" <?= $s === $gradeSection['section'] && $gradeSection['grade_level'] === $g ? 'selected' : '' ?>><?= htmlspecialchars($gradeSection['grade_level'] . ' - ' . $gradeSection['section']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button">Open Class</button>
                    </form>
                <?php endif; ?>
            </section>
            <section style="margin-top:16px">
                <div class="grid">
                    <div>
                        <h2>Students in your advisory</h2>
                        <p style="margin:4px 0 0;color:#555">Class size: <strong><?= htmlspecialchars($studentCount) ?></strong></p>
                    </div>
                    <div style="text-align:right">
                        <div class="legend">
                            <div class="badge on">On time</div>
                            <div class="badge tardy">Tardy</div>
                            <div class="badge absent">Absent</div>
                        </div>
                        <div style="margin-top:8px;font-size:13px;color:#666">Late/Tardy after AM: <?= htmlspecialchars(formatMinutesToTime($amTardyAfterMinutes)) ?> | PM: <?= htmlspecialchars(formatMinutesToTime($pmTardyAfterMinutes)) ?></div>
                        <form method="get" class="inline-controls" style="margin-top:8px; justify-content:flex-end;">
                            <?php if ($isMasterTeacher): ?><input type="hidden" name="class_grade" value="<?= htmlspecialchars($g) ?>"><input type="hidden" name="class_section" value="<?= htmlspecialchars($s) ?>"><?php endif; ?>
                            <label for="attendance_date" style="margin:0; font-weight:600;">Date Filter</label>
                            <input type="date" id="attendance_date" name="attendance_date" value="<?= htmlspecialchars($selectedAttendanceDate) ?>">
                            <label for="gender_view" style="margin:0; font-weight:600;">Learner View</label>
                            <select id="gender_view" name="gender_view">
                                <option value="all" <?= $genderView === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="Male" <?= $genderView === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $genderView === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                            <label for="punch_view" style="margin:0; font-weight:600;">Attendance View</label>
                            <select id="punch_view" name="punch_view">
                                <option value="complete" <?= $punchView === 'complete' ? 'selected' : '' ?>>Complete (AM IN, AM OUT, PM IN, PM OUT)</option>
                                <option value="am_pm" <?= $punchView === 'am_pm' ? 'selected' : '' ?>>AM IN and PM OUT only</option>
                            </select>
                            <button type="submit" class="button">Apply Filter</button>
                        </form>
                    </div>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:center; margin:18px 0;">
                    <div style="flex:1; min-width:240px; background:#f7fcf9; border:1px solid #d1fae5; padding:16px; border-radius:12px; box-shadow:0 2px 10px rgba(2,44,34,0.04);">
                        <div style="font-size:14px; color:#047857; font-weight:700;">Advisory counts</div>
                        <div style="display:grid; grid-template-columns:repeat(3, minmax(90px,1fr)); gap:12px; margin-top:12px;">
                            <div style="background:#ecfdf5; padding:12px; border-radius:10px; text-align:center;">
                                <div style="font-size:12px; color:#065f46;">On time</div>
                                <div style="font-size:24px; font-weight:700; color:#065f46;"><?= htmlspecialchars($ontimeCount) ?></div>
                            </div>
                            <div style="background:#ffedd5; padding:12px; border-radius:10px; text-align:center;">
                                <div style="font-size:12px; color:#92400e;">Tardy</div>
                                <div style="font-size:24px; font-weight:700; color:#92400e;"><?= htmlspecialchars($tardyCount) ?></div>
                            </div>
                            <div style="background:#fee2e2; padding:12px; border-radius:10px; text-align:center;">
                                <div style="font-size:12px; color:#991b1b;">Absent</div>
                                <div style="font-size:24px; font-weight:700; color:#991b1b;"><?= htmlspecialchars($absentCount) ?></div>
                            </div>
                        </div>
                    </div>
                    <div style="flex:1 1 220px; min-width:220px;">
                        <canvas id="advisoryPieChart" width="220" height="220" style="width:100%; max-width:220px; height:auto; background:#fff; border-radius:16px; box-shadow:0 2px 10px rgba(15,23,42,0.05);"></canvas>
                    </div>
                </div>
                <?php if (count($students) === 0): ?>
                    <p>No students found for your advisory class.</p>
                <?php else: ?>
                    <?php if (!empty($issuedCertificates)): ?>
                        <div style="margin:18px 0; padding:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                            <div style="font-size:13px; color:#166534; font-weight:700; margin-bottom:8px;">Issued punctuality certificates</div>
                            <ul style="margin:0; padding-left:18px; line-height:1.8;">
                                <?php foreach ($issuedCertificates as $certificate): ?>
                                    <li>
                                        <?= htmlspecialchars($certificate['full_name']) ?> — <?= htmlspecialchars($certificate['certificate_type']) ?> (<?= htmlspecialchars($certificate['award_date']) ?>)
                                        <a href="certificate.php?student_id=<?= urlencode($certificate['student_id']) ?>&award_date=<?= urlencode($certificate['award_date']) ?>&certificate_type=<?= urlencode($certificate['certificate_type']) ?>" target="_blank" style="margin-left:8px; color:#0f766e; font-weight:600;">Print Certificate</a>
                                        <form method="post" style="display:inline; margin-left:8px;">
                                            <input type="hidden" name="action" value="clear_issued_certificate">
                                            <input type="hidden" name="certificate_id" value="<?= (int) $certificate['id'] ?>">
                                            <button type="submit" onclick="return confirm('Clear this issued certificate from the list?')" style="background:#ef4444; color:white; border:none; border-radius:4px; padding:4px 8px; cursor:pointer;">Clear</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div style="margin:18px 0; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                        <?php foreach (['On time' => 'Always on time', 'Tardy' => 'Always tardy', 'Absent' => 'Always absent'] as $statusName => $label): ?>
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px;">
                                <div style="font-size:12px; color:#475569; margin-bottom:8px; font-weight:600;"><?= htmlspecialchars($label) ?></div>
                                <?php if (!empty($interventionWatchlist[$statusName])): ?>
                                    <ul style="margin:0; padding-left:18px; line-height:1.7;">
                                        <?php foreach ($interventionWatchlist[$statusName] as $studentName): ?>
                                            <li><?= htmlspecialchars($studentName) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div style="color:#64748b; font-size:13px;">No learners in this group.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" id="bulk-checkout-form" class="bulk-checkout-form no-print" style="margin:16px 0 10px; padding:12px; background:#f8fafc; border-radius:8px;">
                        <input type="hidden" name="action" value="bulk_update_checkout">
                        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedAttendanceDate) ?>">
                        <input type="hidden" name="gender_view" value="<?= htmlspecialchars($genderView) ?>">
                        <input type="hidden" name="punch_view" value="<?= htmlspecialchars($punchView) ?>">
                        <?php if ($isMasterTeacher): ?><input type="hidden" name="class_grade" value="<?= htmlspecialchars($g) ?>"><?php endif; ?>
                        <?php if ($isMasterTeacher): ?><input type="hidden" name="class_section" value="<?= htmlspecialchars($s) ?>"><?php endif; ?>
                        <strong>Set attendance punch for selected learners</strong>
                        <select name="checkout_type" required aria-label="Punch type" style="padding:6px;">
                            <option value="AM_IN">AM IN</option>
                            <option value="AM_OUT">AM OUT</option>
                            <option value="PM_IN">PM IN</option>
                            <option value="PM_OUT">PM OUT</option>
                        </select>
                        <input type="time" name="checkout_time" required aria-label="Punch time" style="padding:6px;">
                        <button type="submit" class="button" onclick="return confirm('Save this punch time for the selected learners?')">Save Punch</button>
                    </form>
                    <div class="table-wrap">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th class="no-print"><input type="checkbox" id="select-all-learners" aria-label="Select all learners"></th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Grade</th>
                                <th>Section</th>
                                <th>AM IN</th>
                                <?php if ($showAmOut): ?><th>AM OUT</th><?php endif; ?>
                                <?php if ($showPmIn): ?><th>PM IN</th><?php endif; ?>
                                <th>PM OUT</th>
                                <th>Status</th>
                                <th>Attendance Completion</th>
                                <th>Remarks</th>
                                <th>Parent Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $st): ?>
                            <tr>
                                <td class="no-print"><input type="checkbox" name="selected_student_ids[]" value="<?= htmlspecialchars($st['student_id']) ?>" form="bulk-checkout-form" aria-label="Select <?= htmlspecialchars($st['full_name']) ?>"></td>
                                <td><?= htmlspecialchars($st['student_id']) ?></td>
                                <td><?= htmlspecialchars($st['full_name']) ?></td>
                                <td><?= htmlspecialchars($st['gender'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($st['grade_level']) ?></td>
                                <td><?= htmlspecialchars($st['section']) ?></td>
                                <td><?= $st['am_in_time'] ? htmlspecialchars(date('h:i A', strtotime($st['am_in_time']))) : '-' ?></td>
                                <?php if ($showAmOut): ?>
                                <td><?= $st['am_out_time'] ? htmlspecialchars(date('h:i A', strtotime($st['am_out_time']))) : '-' ?></td>
                                <?php endif; ?>
                                <?php if ($showPmIn): ?>
                                <td><?= $st['pm_in_time'] ? htmlspecialchars(date('h:i A', strtotime($st['pm_in_time']))) : '-' ?></td>
                                <?php endif; ?>
                                <td><?= $st['pm_out_time'] ? htmlspecialchars(date('h:i A', strtotime($st['pm_out_time']))) : '-' ?></td>
                                <td>
                                    <?php if ($st['attendance_status'] === 'On time'): ?>
                                        <span class="badge on">On time</span>
                                    <?php elseif ($st['attendance_status'] === 'Tardy'): ?>
                                        <span class="badge tardy">Tardy</span>
                                    <?php elseif ($st['attendance_status'] === 'Weekend'): ?>
                                        <span class="badge weekend">Weekend</span>
                                    <?php else: ?>
                                        <span class="badge absent">Absent</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="completion <?= strtolower($st['attendance_completion']) ?>"><?= htmlspecialchars($st['attendance_completion']) ?></span></td>
                                <td class="no-print">
                                    <form method="post" class="remark-form" style="margin:0; display:flex; gap:6px; align-items:center;">
                                        <input type="hidden" name="action" value="save_attendance_note">
                                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($st['student_id']) ?>">
                                        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedAttendanceDate) ?>">
                                        <input type="hidden" name="gender_view" value="<?= htmlspecialchars($genderView) ?>">
                                        <input type="hidden" name="punch_view" value="<?= htmlspecialchars($punchView) ?>">
                                        <?php if ($isMasterTeacher): ?><input type="hidden" name="class_grade" value="<?= htmlspecialchars($g) ?>"><input type="hidden" name="class_section" value="<?= htmlspecialchars($s) ?>"><?php endif; ?>
                                        <input type="text" name="attendance_note" value="<?= htmlspecialchars($st['attendance_note']) ?>" placeholder="Remark" maxlength="500" style="width:100%; min-width:140px; margin:0; padding:6px;">
                                        <button type="submit" class="button" style="padding:6px 10px;">Save</button>
                                    </form>
                                </td>
                                <td><?= htmlspecialchars($st['parent_phone']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <script>
        (function(){
            var selectAll = document.getElementById('select-all-learners');
            var learnerBoxes = document.querySelectorAll('input[name="selected_student_ids[]"][form="bulk-checkout-form"]');
            if (selectAll && learnerBoxes.length) {
                selectAll.addEventListener('change', function(){
                    learnerBoxes.forEach(function(box){ box.checked = selectAll.checked; });
                });
                learnerBoxes.forEach(function(box){
                    box.addEventListener('change', function(){
                        var allChecked = true;
                        learnerBoxes.forEach(function(candidate){
                            if (!candidate.checked) allChecked = false;
                        });
                        selectAll.checked = allChecked && learnerBoxes.length > 0;
                    });
                });
            }
        })();
    </script>
    <script>
        (function(){
            var grade = document.getElementById('class_grade');
            var section = document.getElementById('class_section');
            if (!grade || !section) return;
            function updateSections() {
                Array.prototype.forEach.call(section.options, function(option) {
                    option.hidden = option.value !== '' && option.getAttribute('data-grade') !== grade.value;
                });
                if (section.selectedOptions.length && section.selectedOptions[0].hidden) section.value = '';
            }
            grade.addEventListener('change', updateSections);
            updateSections();
        })();
    </script>
    <script>
        (function(){
            var canvas = document.getElementById('advisoryPieChart');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var counts = [<?= (int)$ontimeCount ?>, <?= (int)$tardyCount ?>, <?= (int)$absentCount ?>];
            var labels = ['On time', 'Tardy', 'Absent'];
            var colors = ['#10b981', '#f59e0b', '#ef4444'];
            var total = counts.reduce(function(sum, n){ return sum + n; }, 0);
            var startAngle = -0.5 * Math.PI;
            counts.forEach(function(value, index){
                if (value <= 0) return;
                var sliceAngle = (value / total) * 2 * Math.PI;
                ctx.beginPath();
                ctx.moveTo(canvas.width / 2, canvas.height / 2);
                ctx.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width, canvas.height) / 2 - 10, startAngle, startAngle + sliceAngle);
                ctx.closePath();
                ctx.fillStyle = colors[index];
                ctx.fill();
                startAngle += sliceAngle;
            });
            ctx.font = '14px Segoe UI, Tahoma, Geneva, Verdana, sans-serif';
            ctx.fillStyle = '#0f172a';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            if (total === 0) {
                ctx.fillText('No attendance data', canvas.width / 2, canvas.height / 2);
            } else {
                ctx.fillText(labels[0] + ': ' + counts[0], canvas.width / 2, canvas.height / 2 - 16);
                ctx.fillText(labels[1] + ': ' + counts[1], canvas.width / 2, canvas.height / 2);
                ctx.fillText(labels[2] + ': ' + counts[2], canvas.width / 2, canvas.height / 2 + 16);
            }
        })();
    </script>
    <script>
        (function(){
            var scope = document.getElementById('export_scope');
            var reportMonth = document.getElementById('report_month');
            var termStart = document.getElementById('term_start');
            var termEnd = document.getElementById('term_end');
            var yearInput = document.getElementById('export_year');
            if (!scope) return;
            if (reportMonth) reportMonth.value = '<?= date("Y-m", strtotime($selectedAttendanceDate)) ?>';
            yearInput.value = '<?= date("Y", strtotime($selectedAttendanceDate)) ?>';
            function update(){
                var v = scope.value;
                if (reportMonth) reportMonth.style.display = v === 'month' ? 'inline-block' : 'none';
                if (termStart) termStart.style.display = v === 'term' ? 'inline-block' : 'none';
                if (termEnd) termEnd.style.display = v === 'term' ? 'inline-block' : 'none';
                if (yearInput) yearInput.style.display = v === 'year' ? 'inline-block' : 'none';
            }
            scope.addEventListener('change', update);
            update();
        })();
    </script>
</body>
</html>