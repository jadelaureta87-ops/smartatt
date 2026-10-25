<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once 'db.php';
require_once 'admin_helpers.php';
require_once 'sms_helper.php';

ensureBaseSchema($conn);

/**
 * Opens an in-memory CSV stream from raw CSV text.
 *
 * The hosting web server rejects every multipart/form-data POST with a
 * 403 Forbidden page, so browsers cannot upload files to this app. The
 * admin panel therefore reads the CSV in the browser (FileReader) and posts
 * the contents as a plain form field, which is parsed from memory here.
 */
function openCsvTextStream(string $csvText)
{
    $handle = fopen('php://memory', 'r+');
    if ($handle === false) {
        return false;
    }
    if (fwrite($handle, $csvText) === false) {
        fclose($handle);
        return false;
    }
    rewind($handle);
    return $handle;
}

$message = '';
$messageType = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_admin') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');

        if ($username === '' || $password === '' || $fullName === '') {
            $message = 'Please fill in all admin fields.';
            $messageType = 'error';
        } elseif (adminExists($conn, $username)) {
            $message = 'That admin username already exists.';
            $messageType = 'error';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO admins (username, password_hash, full_name) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $username, $passwordHash, $fullName);
            $stmt->execute();

            $_SESSION['admin_id'] = $stmt->insert_id;
            $_SESSION['admin_name'] = $fullName;
            $message = 'Admin account created and you are logged in.';
            $messageType = 'success';
        }
    } elseif ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $message = 'Please enter both username and password.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare('SELECT id, full_name, password_hash FROM admins WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $message = 'Admin account not found.';
                $messageType = 'error';
            } else {
                $admin = $result->fetch_assoc();
                if (password_verify($password, $admin['password_hash'])) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_name'] = $admin['full_name'];
                    $message = 'You are now logged in.';
                    $messageType = 'success';
                } else {
                    $message = 'Invalid password.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'create_user') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to create a user.';
            $messageType = 'error';
        } else {
            $fullName = trim($_POST['full_name'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $parentPhone = normalizePhoneNumber(trim($_POST['parent_phone'] ?? ''));

            if ($fullName === '' || !in_array($gender, ['Male', 'Female'], true) || $username === '' || $password === '' || $gradeLevel === '' || $section === '' || $parentPhone === '') {
                $message = 'Name, gender, username, password, grade level, section, and parent phone are required.';
                $messageType = 'error';
            } elseif (!preg_match('/^\+?[0-9]{10,15}$/', $parentPhone)) {
                $message = 'Please enter a valid parent phone number.';
                $messageType = 'error';
            } elseif (studentUserExists($conn, $username)) {
                $message = 'That username is already used by another user.';
                $messageType = 'error';
            } else {
                $studentId = generateStudentId($conn);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $qrPayload = $studentId;

                $stmt = $conn->prepare(
                    'INSERT INTO students (student_id, full_name, gender, grade_level, section, grade_section, parent_phone, username, password_hash, qr_payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $gradeSection = trim($gradeLevel . ($section !== '' ? ' - ' . $section : ''));
                $stmt->bind_param('ssssssssss', $studentId, $fullName, $gender, $gradeLevel, $section, $gradeSection, $parentPhone, $username, $passwordHash, $qrPayload);
                $stmt->execute();

                $qrUrl = buildQrUrl($qrPayload);
                $message = "User created successfully. QR code is ready.";
                $messageType = 'success';
                $createdUser = [
                    'student_id' => $studentId,
                    'full_name' => $fullName,
                    'gender' => $gender,
                    'grade_level' => $gradeLevel,
                    'section' => $section,
                    'username' => $username,
                    'password' => $password,
                    'parent_phone' => $parentPhone,
                    'qr_url' => $qrUrl,
                    'qr_payload' => $qrPayload,
                ];

                // If request is AJAX, return JSON with created user details
                $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'createdUser' => $createdUser]);
                    exit;
                }
            }
        }
    } elseif ($action === 'edit_user') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage users.';
            $messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $parentPhone = normalizePhoneNumber(trim($_POST['parent_phone'] ?? ''));
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');

            if ($studentId === '' || $fullName === '' || !in_array($gender, ['Male', 'Female'], true) || $gradeLevel === '' || $section === '' || $parentPhone === '' || $username === '') {
                $message = 'Name, gender, grade level, section, parent phone, and username are required.';
                $messageType = 'error';
            } elseif (!preg_match('/^\+?[0-9]{10,15}$/', $parentPhone)) {
                $message = 'Please enter a valid parent phone number.';
                $messageType = 'error';
            } else {
                $gradeSection = trim($gradeLevel . ($section !== '' ? ' - ' . $section : ''));
                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE students SET full_name = ?, gender = ?, grade_level = ?, section = ?, grade_section = ?, parent_phone = ?, username = ?, password_hash = ? WHERE student_id = ?');
                    $stmt->bind_param('sssssssss', $fullName, $gender, $gradeLevel, $section, $gradeSection, $parentPhone, $username, $passwordHash, $studentId);
                } else {
                    $stmt = $conn->prepare('UPDATE students SET full_name = ?, gender = ?, grade_level = ?, section = ?, grade_section = ?, parent_phone = ?, username = ? WHERE student_id = ?');
                    $stmt->bind_param('ssssssss', $fullName, $gender, $gradeLevel, $section, $gradeSection, $parentPhone, $username, $studentId);
                }
                $stmt->execute();
                $message = 'User updated successfully.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'edit_teacher') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage teachers.';
            $messageType = 'error';
        } else {
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $password = trim($_POST['password'] ?? '');

            if ($teacherId <= 0 || $fullName === '' || $username === '') {
                $message = 'Teacher name and username are required.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('SELECT id FROM teachers WHERE username = ? AND id != ? LIMIT 1');
                $stmt->bind_param('si', $username, $teacherId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $message = 'That teacher username is already taken.';
                    $messageType = 'error';
                } else {
                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare('UPDATE teachers SET full_name = ?, username = ?, password_hash = ?, grade_level = ?, section = ? WHERE id = ?');
                        $stmt->bind_param('sssssi', $fullName, $username, $passwordHash, $gradeLevel, $section, $teacherId);
                    } else {
                        $stmt = $conn->prepare('UPDATE teachers SET full_name = ?, username = ?, grade_level = ?, section = ? WHERE id = ?');
                        $stmt->bind_param('ssssi', $fullName, $username, $gradeLevel, $section, $teacherId);
                    }
                    $stmt->execute();
                    $message = 'Teacher updated successfully.';
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'add_grade_section') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage grade levels and sections.';
            $messageType = 'error';
        } else {
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            if ($gradeLevel === '' || $section === '') {
                $message = 'Grade level and section are required.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('INSERT IGNORE INTO grade_sections (grade_level, section) VALUES (?, ?)');
                $stmt->bind_param('ss', $gradeLevel, $section);
                $stmt->execute();
                $message = $stmt->affected_rows > 0 ? 'Grade level and section added.' : 'That grade level and section already exists.';
                $messageType = $stmt->affected_rows > 0 ? 'success' : 'error';
            }
        }
    } elseif ($action === 'edit_grade_section') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage grade levels and sections.';
            $messageType = 'error';
        } else {
            $gradeSectionId = (int) ($_POST['grade_section_id'] ?? 0);
            $gradeLevel = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');
            if ($gradeSectionId <= 0 || $gradeLevel === '' || $section === '') {
                $message = 'Grade level and section are required.';
                $messageType = 'error';
            } else {
                $duplicateStmt = $conn->prepare('SELECT id FROM grade_sections WHERE grade_level = ? AND section = ? AND id != ? LIMIT 1');
                $duplicateStmt->bind_param('ssi', $gradeLevel, $section, $gradeSectionId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->num_rows > 0) {
                    $message = 'That grade level and section already exists.';
                    $messageType = 'error';
                } else {
                    $oldStmt = $conn->prepare('SELECT grade_level, section FROM grade_sections WHERE id = ? LIMIT 1');
                    $oldStmt->bind_param('i', $gradeSectionId);
                    $oldStmt->execute();
                    $oldPair = $oldStmt->get_result()->fetch_assoc();

                    $stmt = $conn->prepare('UPDATE grade_sections SET grade_level = ?, section = ? WHERE id = ?');
                    $stmt->bind_param('ssi', $gradeLevel, $section, $gradeSectionId);
                    $stmt->execute();
                    if ($oldPair) {
                        $updateStudents = $conn->prepare('UPDATE students SET grade_level = ?, section = ?, grade_section = ? WHERE grade_level = ? AND section = ?');
                        $gradeSection = $gradeLevel . ' - ' . $section;
                        $updateStudents->bind_param('sssss', $gradeLevel, $section, $gradeSection, $oldPair['grade_level'], $oldPair['section']);
                        $updateStudents->execute();

                        $updateTeachers = $conn->prepare('UPDATE teachers SET grade_level = ?, section = ? WHERE grade_level = ? AND section = ?');
                        $updateTeachers->bind_param('ssss', $gradeLevel, $section, $oldPair['grade_level'], $oldPair['section']);
                        $updateTeachers->execute();
                    }
                    $message = 'Grade level and section updated.';
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'delete_grade_section') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage grade levels and sections.';
            $messageType = 'error';
        } else {
            $gradeSectionId = (int) ($_POST['grade_section_id'] ?? 0);
            if ($gradeSectionId > 0) {
                $stmt = $conn->prepare('DELETE FROM grade_sections WHERE id = ?');
                $stmt->bind_param('i', $gradeSectionId);
                $stmt->execute();
                $message = 'Grade level and section deleted.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'archive_user') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage users.';
            $messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $stmt = $conn->prepare('UPDATE students SET grade_section = CONCAT(grade_section, " [Archived]") WHERE student_id = ?');
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $message = 'User archived successfully.';
            $messageType = 'success';
        }
    } elseif ($action === 'delete_teacher') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage teachers.';
            $messageType = 'error';
        } else {
            $teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;
            if ($teacherId > 0) {
                $stmt = $conn->prepare('DELETE FROM teachers WHERE id = ?');
                $stmt->bind_param('i', $teacherId);
                $stmt->execute();
                $message = 'Teacher deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Invalid teacher selected.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'approve_teacher') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage teachers.';
            $messageType = 'error';
        } else {
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            if ($teacherId > 0) {
                $stmt = $conn->prepare('UPDATE teachers SET is_approved = 1 WHERE id = ?');
                $stmt->bind_param('i', $teacherId);
                $stmt->execute();
                $message = 'Teacher account approved successfully.';
                $messageType = 'success';
            } else {
                $message = 'Invalid teacher selected.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'approve_credential_request') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage credential requests.';
            $messageType = 'error';
        } else {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            if ($requestId <= 0) {
                $message = 'Invalid credential request selected.';
                $messageType = 'error';
            } else {
                $reqStmt = $conn->prepare("SELECT id, student_id, requested_username, requested_password, status FROM credential_requests WHERE id = ? LIMIT 1");
                $reqStmt->bind_param('i', $requestId);
                $reqStmt->execute();
                $requestRow = $reqStmt->get_result()->fetch_assoc();
                if (!$requestRow) {
                    $message = 'Credential request not found.';
                    $messageType = 'error';
                } elseif ($requestRow['status'] !== 'pending') {
                    $message = 'That credential request has already been reviewed.';
                    $messageType = 'error';
                } elseif (studentUserExists($conn, $requestRow['requested_username'])) {
                    $message = 'The requested username is already used by another user. Ask the teacher to submit a new request.';
                    $messageType = 'error';
                } else {
                    $username = $requestRow['requested_username'];
                    $passwordHash = password_hash($requestRow['requested_password'], PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare('UPDATE students SET username = ?, password_hash = ? WHERE student_id = ?');
                    $updateStmt->bind_param('sss', $username, $passwordHash, $requestRow['student_id']);
                    $updateStmt->execute();
                    $markStmt = $conn->prepare("UPDATE credential_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
                    $markStmt->bind_param('i', $requestId);
                    $markStmt->execute();
                    $message = 'Credential request approved. The student can now log in with username "' . $username . '".';
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'reject_credential_request') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage credential requests.';
            $messageType = 'error';
        } else {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            if ($requestId <= 0) {
                $message = 'Invalid credential request selected.';
                $messageType = 'error';
            } else {
                $rejectStmt = $conn->prepare("UPDATE credential_requests SET status = 'rejected', reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
                $rejectStmt->bind_param('i', $requestId);
                $rejectStmt->execute();
                if ($rejectStmt->affected_rows > 0) {
                    $message = 'Credential request rejected.';
                    $messageType = 'success';
                } else {
                    $message = 'That credential request could not be rejected (already reviewed or missing).';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'delete_user') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage users.';
            $messageType = 'error';
        } else {
            $studentId = trim($_POST['student_id'] ?? '');
            $stmt = $conn->prepare('DELETE FROM students WHERE student_id = ?');
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $message = 'User deleted successfully.';
            $messageType = 'success';
        }
    } elseif ($action === 'delete_attendance_log') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage attendance.';
            $messageType = 'error';
        } else {
            $logId = isset($_POST['log_id']) ? (int) $_POST['log_id'] : (int) ($_POST['attendance_delete_id'] ?? 0);
            if ($logId > 0) {
                $stmt = $conn->prepare('DELETE FROM attendance_logs WHERE id = ?');
                $stmt->bind_param('i', $logId);
                $stmt->execute();
                $message = 'Attendance entry deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Invalid attendance entry selected.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'bulk_delete_users') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage users.';
            $messageType = 'error';
        } else {
            $ids = $_POST['user_ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) {
                $message = 'No users selected for deletion.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('DELETE FROM students WHERE student_id = ?');
                foreach ($ids as $sid) {
                    $sid = trim($sid);
                    if ($sid === '') {
                        continue;
                    }
                    $stmt->bind_param('s', $sid);
                    $stmt->execute();
                }
                $message = 'Selected users deleted successfully.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'save_attendance_thresholds') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to update attendance thresholds.';
            $messageType = 'error';
        } else {
            $amIn = parseTimeToMinutes(trim($_POST['am_in_threshold'] ?? ''));
            $amOut = parseTimeToMinutes(trim($_POST['am_out_threshold'] ?? ''));
            $pmIn = parseTimeToMinutes(trim($_POST['pm_in_threshold'] ?? ''));
            $pmOut = parseTimeToMinutes(trim($_POST['pm_out_threshold'] ?? ''));
            $amTardyAfter = parseTimeToMinutes(trim($_POST['am_tardy_after'] ?? ''));
            $pmTardyAfter = parseTimeToMinutes(trim($_POST['pm_tardy_after'] ?? ''));

            if ($amIn < 0 || $amOut < 0 || $pmIn < 0 || $pmOut < 0 || $amTardyAfter < 0 || $pmTardyAfter < 0) {
                $message = 'Please enter valid time values such as 8:15 AM.';
                $messageType = 'error';
            } elseif ($amIn >= $amOut || $amOut >= $pmIn || $pmIn >= $pmOut) {
                $message = 'Attendance thresholds must be in chronological order: AM IN, AM OUT, PM IN, then PM OUT.';
                $messageType = 'error';
            } else {
                setAttendanceSetting($conn, 'am_in_threshold_minutes', $amIn);
                setAttendanceSetting($conn, 'am_out_threshold_minutes', $amOut);
                setAttendanceSetting($conn, 'pm_in_threshold_minutes', $pmIn);
                setAttendanceSetting($conn, 'pm_out_threshold_minutes', $pmOut);
                setAttendanceSetting($conn, 'am_tardy_after_minutes', $amTardyAfter);
                setAttendanceSetting($conn, 'pm_tardy_after_minutes', $pmTardyAfter);
                $message = 'Attendance thresholds updated successfully.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'save_geotag_settings') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to update geotagging settings.';
            $messageType = 'error';
        } else {
            $geotagChoice = (string) ($_POST['geotag_enabled'] ?? '');
            $scanModeInput = (string) ($_POST['scan_mode'] ?? 'all');
            if (!in_array($geotagChoice, ['0', '1'], true)) {
                $message = 'Please choose Allow or Disallow for geotagging.';
                $messageType = 'error';
            } elseif (!in_array($scanModeInput, ['in_out', 'all'], true)) {
                $message = 'Please choose a valid scanning mode.';
                $messageType = 'error';
            } else {
                $geotagLatInput = trim((string) ($_POST['geotag_latitude'] ?? ''));
                $geotagLngInput = trim((string) ($_POST['geotag_longitude'] ?? ''));
                $geotagRadiusInput = trim((string) ($_POST['geotag_radius_meters'] ?? ''));

                // Accept decimal degrees or Google Earth degrees/minutes/seconds formats.
                $latDecimal = $geotagLatInput !== '' ? parseCoordinateToDecimal($geotagLatInput) : null;
                $lngDecimal = $geotagLngInput !== '' ? parseCoordinateToDecimal($geotagLngInput) : null;
                if ($latDecimal === null || $lngDecimal === null) {
                    $pair = $latDecimal === null ? parseCoordinatePair($geotagLatInput) : null;
                    if ($pair === null && $lngDecimal === null) {
                        $pair = parseCoordinatePair($geotagLngInput);
                    }
                    if ($pair === null && ($geotagLatInput !== '' || $geotagLngInput !== '')) {
                        $pair = parseCoordinatePair(trim($geotagLatInput . ' ' . $geotagLngInput));
                    }
                    if ($pair !== null) {
                        if ($latDecimal === null) {
                            $latDecimal = $pair['latitude'];
                        }
                        if ($lngDecimal === null) {
                            $lngDecimal = $pair['longitude'];
                        }
                    }
                }
                $hasCoordinateInput = $geotagLatInput !== '' || $geotagLngInput !== '';

                if ($hasCoordinateInput && ($latDecimal === null || $lngDecimal === null)) {
                    $message = 'Coordinates could not be read. Use decimal degrees (e.g. 18.358472, 121.820083) or Google Earth formats like 18°21\'30.50"N 121°49\'12.30"E.';
                    $messageType = 'error';
                } elseif ($hasCoordinateInput && ($latDecimal < -90 || $latDecimal > 90)) {
                    $message = 'Latitude must be between -90 and 90.';
                    $messageType = 'error';
                } elseif ($hasCoordinateInput && ($lngDecimal < -180 || $lngDecimal > 180)) {
                    $message = 'Longitude must be between -180 and 180.';
                    $messageType = 'error';
                } elseif ($geotagRadiusInput !== '' && (!ctype_digit($geotagRadiusInput) || (int) $geotagRadiusInput < 1 || (int) $geotagRadiusInput > 1000000)) {
                    $message = 'Radius must be a whole number of meters between 1 and 1000000.';
                    $messageType = 'error';
                } elseif ($geotagChoice === '1' && (!$hasCoordinateInput || $geotagRadiusInput === '')) {
                    $message = 'To allow geotagging, set the scanning latitude and longitude (decimal or Google Earth coordinates) and specify a radius in meters.';
                    $messageType = 'error';
                } else {
                    setGeotaggingEnabled($conn, $geotagChoice === '1');
                    setGeotagSetting($conn, 'geotag_latitude', $hasCoordinateInput ? number_format($latDecimal, 7, '.', '') : '');
                    setGeotagSetting($conn, 'geotag_longitude', $hasCoordinateInput ? number_format($lngDecimal, 7, '.', '') : '');
                    setGeotagSetting($conn, 'geotag_radius_meters', $geotagRadiusInput !== '' ? (string) (int) $geotagRadiusInput : '');
                    setGeotagSetting($conn, 'scan_mode', $scanModeInput);
                    $scanModeLabel = $scanModeInput === 'in_out' ? 'AM IN and PM OUT only' : 'AM IN, AM OUT, PM IN, and PM OUT';
                    $message = $geotagChoice === '1'
                        ? 'Geotagging allowed. Scans must share the device location and stay within ' . (int) $geotagRadiusInput . ' m of the set latitude and longitude. Allowed scans: ' . $scanModeLabel . '.'
                        : 'Geotagging disallowed. Users can now scan their QR code without sharing their location. Allowed scans: ' . $scanModeLabel . '.';
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'save_sms_gateway_settings') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to update SMS gateway settings.';
            $messageType = 'error';
        } else {
            $activeGateway = (string) ($_POST['sms_active_gateway'] ?? '');
            if (!in_array($activeGateway, ['cloud', 'local'], true)) {
                $message = 'Please choose whether the Cloud or Local server is active.';
                $messageType = 'error';
            } else {
                $fields = [
                    'sms_cloud_url', 'sms_cloud_login', 'sms_cloud_password', 'sms_cloud_device_id',
                    'sms_local_url', 'sms_local_login', 'sms_local_password', 'sms_local_device_id',
                ];
                $values = [];
                foreach ($fields as $field) {
                    $values[$field] = trim((string) ($_POST[$field] ?? ''));
                }

                $tooLong = '';
                foreach ($values as $value) {
                    if (strlen($value) > 255) {
                        $tooLong = $value;
                        break;
                    }
                }

                if ($tooLong !== '') {
                    $message = 'SMS gateway values must be 255 characters or fewer.';
                    $messageType = 'error';
                } else {
                    $sendToParents = (string) ($_POST['sms_send_to_parents'] ?? '');
                    $sendToParents = $sendToParents === '1' ? '1' : '0';
                    $dailyLimit = (int) ($_POST['sms_daily_limit'] ?? 0);
                    if ($dailyLimit < 0) {
                        $dailyLimit = 0;
                    }

                    setGeotagSetting($conn, 'sms_active_gateway', $activeGateway);
                    foreach ($values as $field => $value) {
                        setGeotagSetting($conn, $field, $value);
                    }
                    setGeotagSetting($conn, 'sms_send_to_parents', $sendToParents);
                    setGeotagSetting($conn, 'sms_daily_limit', (string) $dailyLimit);
                    $parentStatus = $sendToParents === '1'
                        ? 'Parent SMS notifications are ON.'
                        : 'Parent SMS notifications are OFF; attendance is still recorded.';
                    $limitStatus = $dailyLimit > 0
                        ? 'Daily SMS limit set to ' . $dailyLimit . ' message(s).'
                        : 'Daily SMS limit disabled (unlimited).';
                    $message = 'SMS gateway settings saved. Active server: ' . ($activeGateway === 'local' ? 'Local server' : 'Cloud server') . '. ' . $parentStatus . ' ' . $limitStatus;
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'send_test_sms') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to send a test SMS.';
            $messageType = 'error';
        } else {
            $testNumber = trim((string) ($_POST['test_sms_number'] ?? ''));
            if ($testNumber === '') {
                $message = 'Please enter a phone number for the test SMS.';
                $messageType = 'error';
            } elseif (normalizePhoneNumber($testNumber) === '') {
                $message = 'That phone number does not look valid. Use 09XXXXXXXXX or +639XXXXXXXXX.';
                $messageType = 'error';
            } else {
                $activeKind = getSmsGatewayConfig($conn)['active'];
                $activeLabel = $activeKind === 'local' ? 'Local server' : 'Cloud server';
                $body = 'BNAS test SMS via ' . $activeLabel . ' at ' . date('Y-m-d h:i A') . '.';
                $sent = sendSMS($testNumber, $body);

                if ($sent) {
                    $message = 'Test SMS accepted by the ' . $activeLabel . ' for ' . $testNumber . '. Check the phone to confirm delivery. If it fails there, the SMS Gate app on the phone may be missing the Android SMS permission.';
                    $messageType = 'success';
                } else {
                    $message = 'Test SMS could not be sent through the ' . $activeLabel . '. Verify the server address, credentials, and that the SMS Gate device is online.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'bulk_delete_attendance') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to manage attendance.';
            $messageType = 'error';
        } else {
            $ids = $_POST['attendance_ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) {
                $message = 'No attendance entries selected for deletion.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('DELETE FROM attendance_logs WHERE id = ?');
                foreach ($ids as $id) {
                    $id = (int) $id;
                    if ($id <= 0) continue;
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                }
                $message = 'Selected attendance entries deleted successfully.';
                $messageType = 'success';
            }
        }
    }
    elseif ($action === 'update_admin') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to update admin settings.';
            $messageType = 'error';
        } else {
            $adminId = (int) $_SESSION['admin_id'];
            $currentPassword = trim($_POST['current_password'] ?? '');
            $newUsername = trim($_POST['new_username'] ?? '');
            $newFullName = trim($_POST['new_full_name'] ?? '');
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

            if ($currentPassword === '') {
                $message = 'Current password is required to update settings.';
                $messageType = 'error';
            } else {
                $stmt = $conn->prepare('SELECT username, full_name, password_hash FROM admins WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $adminId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) {
                    $message = 'Admin account not found.';
                    $messageType = 'error';
                } else {
                    $adminRow = $res->fetch_assoc();
                    if (!password_verify($currentPassword, $adminRow['password_hash'])) {
                        $message = 'Current password is incorrect.';
                        $messageType = 'error';
                    } else {
                        // validate new username uniqueness if changed
                        if ($newUsername !== '' && $newUsername !== $adminRow['username'] && adminExists($conn, $newUsername)) {
                            $message = 'That username is already taken.';
                            $messageType = 'error';
                        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
                            $message = 'New password and confirmation do not match.';
                            $messageType = 'error';
                        } else {
                                $updateUsername = $newUsername !== '' ? $newUsername : $adminRow['username'];
                                $updateFullName = $newFullName !== '' ? $newFullName : $adminRow['full_name'];
                                $passwordChanged = false;
                                $usernameChanged = $updateUsername !== $adminRow['username'];
                                if ($newPassword !== '') {
                                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                                    $stmt = $conn->prepare('UPDATE admins SET username = ?, full_name = ?, password_hash = ? WHERE id = ?');
                                    $stmt->bind_param('sssi', $updateUsername, $updateFullName, $newHash, $adminId);
                                    $passwordChanged = true;
                                } else {
                                    $stmt = $conn->prepare('UPDATE admins SET username = ?, full_name = ? WHERE id = ?');
                                    $stmt->bind_param('ssi', $updateUsername, $updateFullName, $adminId);
                                }
                                $stmt->execute();

                                // If username or password changed, require re-login
                                $needReLogin = $usernameChanged || $passwordChanged;
                                if (!$needReLogin) {
                                    $_SESSION['admin_name'] = $updateFullName;
                                }

                                if ($isAjax) {
                                    header('Content-Type: application/json');
                                    echo json_encode(['ok' => true, 'need_relogin' => $needReLogin, 'message' => 'Admin settings updated successfully.']);
                                    exit;
                                }

                                if ($needReLogin) {
                                    session_destroy();
                                    session_start();
                                    header('Location: admin.php?message=' . urlencode('Admin settings updated. Please log in with your new credentials.'));
                                    exit;
                                }

                                $message = 'Admin settings updated successfully.';
                                $messageType = 'success';
                        }
                    }
                }
            }
        }
    }
    elseif ($action === 'import_users') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to import users.';
            $messageType = 'error';
        } else {
            $csvText = trim((string) ($_POST['csv_text'] ?? ''));
            if ($csvText === '') {
                $message = 'Please load or paste the CSV content to import.';
                $messageType = 'error';
            } else {
                $fh = openCsvTextStream($csvText);
                if ($fh === false) {
                    $message = 'Failed to read the CSV content.';
                    $messageType = 'error';
                } else {
                    $row = 0;
                    $imported = 0;
                    while (($data = fgetcsv($fh)) !== false) {
                        $row++;
                        if ($row === 1) continue; // skip header
                        // Supports exports with gender and older six-column CSV files.
                        $studentId = trim($data[0] ?? '');
                        $fullName = trim($data[1] ?? '');
                        $hasGenderColumn = isset($data[6]) || (isset($data[2]) && in_array(trim($data[2]), ['Male', 'Female'], true));
                        if ($hasGenderColumn) {
                            $gender = trim($data[2] ?? '');
                            $username = trim($data[3] ?? '');
                            $parentPhone = normalizePhoneNumber(trim($data[4] ?? ''));
                            $gradeLevel = trim($data[5] ?? '');
                            $section = trim($data[6] ?? '');
                        } else {
                            $gender = '';
                            $username = trim($data[2] ?? '');
                            $parentPhone = normalizePhoneNumber(trim($data[3] ?? ''));
                            $gradeLevel = trim($data[4] ?? '');
                            $section = trim($data[5] ?? '');
                        }

                        if ($fullName === '' || $username === '' || !in_array($gender, ['Male', 'Female'], true)) continue;

                        // if student_id provided and exists -> update, else insert
                        if ($studentId !== '') {
                            $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM students WHERE student_id = ?');
                            $stmt->bind_param('s', $studentId);
                            $stmt->execute();
                            $res = $stmt->get_result()->fetch_assoc();
                            if ($res && (int)$res['c'] > 0) {
                                $gradeSection = trim($gradeLevel . ($section !== '' ? ' - ' . $section : ''));
                                $stmt = $conn->prepare('UPDATE students SET full_name = ?, gender = ?, grade_level = ?, section = ?, grade_section = ?, parent_phone = ?, username = ? WHERE student_id = ?');
                                $stmt->bind_param('ssssssss', $fullName, $gender, $gradeLevel, $section, $gradeSection, $parentPhone, $username, $studentId);
                                $stmt->execute();
                                $imported++;
                                continue;
                            }
                        }

                        // insert new user
                        $newId = generateStudentId($conn);
                        $gradeSection = trim($gradeLevel . ($section !== '' ? ' - ' . $section : ''));
                        $pwHash = password_hash('changeme', PASSWORD_DEFAULT);
                        $qrPayload = $newId;
                        $stmt = $conn->prepare('INSERT INTO students (student_id, full_name, gender, grade_level, section, grade_section, parent_phone, username, password_hash, qr_payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('ssssssssss', $newId, $fullName, $gender, $gradeLevel, $section, $gradeSection, $parentPhone, $username, $pwHash, $qrPayload);
                        $stmt->execute();
                        $imported++;
                    }
                    fclose($fh);
                    $message = "Imported $imported users from CSV.";
                    $messageType = 'success';
                }
            }
        }
    } elseif ($action === 'import_attendance') {
        if (empty($_SESSION['admin_id'])) {
            $message = 'Please log in to import attendance.';
            $messageType = 'error';
        } else {
            $csvText = trim((string) ($_POST['csv_text'] ?? ''));
            if ($csvText === '') {
                $message = 'Please load or paste the CSV content to import.';
                $messageType = 'error';
            } else {
                $fh = openCsvTextStream($csvText);
                if ($fh === false) {
                    $message = 'Failed to read the CSV content.';
                    $messageType = 'error';
                } else {
                    $row = 0;
                    $imported = 0;
                    while (($data = fgetcsv($fh)) !== false) {
                        $row++;
                        if ($row === 1) continue; // skip header
                        // expected columns: student_id, log_type, timestamp
                        $studentId = trim($data[0] ?? '');
                        $logType = trim($data[1] ?? '');
                        $timestamp = trim($data[2] ?? '');
                        if ($studentId === '' || $logType === '' || $timestamp === '') continue;
                        $stmt = $conn->prepare('INSERT INTO attendance_logs (student_id, log_type, timestamp) VALUES (?, ?, ?)');
                        $stmt->bind_param('sss', $studentId, $logType, $timestamp);
                        $stmt->execute();
                        $imported++;
                    }
                    fclose($fh);
                    $message = "Imported $imported attendance entries from CSV.";
                    $messageType = 'success';
                }
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    session_start();
    header('Location: index.php');
    exit;
}

// Export handlers (CSV) - require admin
if (isset($_GET['action']) && !empty($_GET['action'])) {
    $gaction = $_GET['action'];
    if ($gaction === 'export_users') {
        if (empty($_SESSION['admin_id'])) { header('HTTP/1.1 403 Forbidden'); exit; }
        $filename = 'users_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['student_id','full_name','gender','username','parent_phone','grade_level','section']);
        $r = $conn->query('SELECT student_id, full_name, gender, username, parent_phone, grade_level, section FROM students ORDER BY full_name ASC');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                fputcsv($out, [$row['student_id'], $row['full_name'], $row['gender'], $row['username'], $row['parent_phone'], $row['grade_level'], $row['section']]);
            }
        }
        fclose($out);
        exit;
    }
    if ($gaction === 'export_attendance') {
        if (empty($_SESSION['admin_id'])) { header('HTTP/1.1 403 Forbidden'); exit; }
        $filename = 'attendance_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','student_id','full_name','log_type','timestamp','latitude','longitude']);
        $r = $conn->query('SELECT a.id, a.student_id, a.log_type, a.timestamp, a.latitude, a.longitude, s.full_name FROM attendance_logs a LEFT JOIN students s ON s.student_id = a.student_id ORDER BY a.timestamp DESC');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                fputcsv($out, [$row['id'], $row['student_id'], $row['full_name'], $row['log_type'], $row['timestamp'], $row['latitude'], $row['longitude']]);
            }
        }
        fclose($out);
        exit;
    }
    if ($gaction === 'print_attendance') {
        if (empty($_SESSION['admin_id'])) { header('HTTP/1.1 403 Forbidden'); exit; }
        $selectedDate = trim($_GET['attendance_date_filter'] ?? '');
        if ($selectedDate === '') {
            $selectedDate = date('Y-m-d');
        }
        $stmt = $conn->prepare('SELECT a.id, a.student_id, a.log_type, a.timestamp, a.latitude, a.longitude, s.full_name FROM attendance_logs a JOIN students s ON s.student_id = a.student_id WHERE DATE(a.timestamp) = ? ORDER BY a.timestamp DESC, a.id DESC');
        $stmt->bind_param('s', $selectedDate);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Attendance Report</title><style>body{font-family:Segoe UI,system-ui,sans-serif;background:#eef7f0;padding:20px;}table{width:100%;border-collapse:collapse;}th{background:#d1fae5;color:#065f46;}th,td{border:1px solid #a7f3d0;padding:8px;text-align:left;}h1{margin-bottom:6px;color:#065f46;}p{color:#047857;}</style></head><body><h1>Attendance Report</h1><p>Date: ' . htmlspecialchars($selectedDate) . '</p><table><thead><tr><th>Student</th><th>Type</th><th>Timestamp</th><th>Geotag</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $geotag = ($row['latitude'] !== null && $row['longitude'] !== null) ? number_format((float) $row['latitude'], 6) . ', ' . number_format((float) $row['longitude'], 6) : '-';
            echo '<tr><td>' . htmlspecialchars($row['full_name'] ?? $row['student_id']) . '</td><td>' . htmlspecialchars($row['log_type']) . '</td><td>' . htmlspecialchars($row['timestamp']) . '</td><td>' . htmlspecialchars($geotag) . '</td></tr>';
        }
        echo '</tbody></table><script>window.print(); setTimeout(function(){window.close();}, 1000);</script></body></html>';
        exit;
    }
    if ($gaction === 'print_students') {
        if (empty($_SESSION['admin_id'])) { header('HTTP/1.1 403 Forbidden'); exit; }
        $pGrade = trim($_GET['grade_filter'] ?? '');
        $pSection = trim($_GET['section_filter'] ?? '');
        $pUserFilter = trim($_GET['user_filter'] ?? '');
        $pGender = trim($_GET['gender_filter'] ?? '');
        if (!in_array($pGender, ['', 'Male', 'Female'], true)) {
            $pGender = '';
        }
        $sql = 'SELECT student_id, full_name, gender, username, parent_phone, grade_level, section FROM students WHERE 1=1';
        $types = '';
        $params = [];
        if ($pGrade !== '') {
            $sql .= ' AND grade_level = ?';
            $types .= 's';
            $params[] = $pGrade;
        }
        if ($pSection !== '') {
            $sql .= ' AND section = ?';
            $types .= 's';
            $params[] = $pSection;
        }
        if ($pGender !== '') {
            $sql .= ' AND gender = ?';
            $types .= 's';
            $params[] = $pGender;
        }
        $sql .= ' ORDER BY full_name ASC';
        $stmt = $conn->prepare($sql);
        if ($stmt && $types !== '') {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } elseif ($stmt) {
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $rows = $conn->query('SELECT student_id, full_name, gender, username, parent_phone, grade_level, section FROM students ORDER BY full_name ASC')->fetch_all(MYSQLI_ASSOC);
        }
        if ($pUserFilter !== '') {
            $pUserFilterLower = strtolower($pUserFilter);
            $rows = array_values(array_filter($rows, static function ($row) use ($pUserFilterLower): bool {
                $searchableText = strtolower(implode(' ', [
                    $row['full_name'] ?? '',
                    $row['gender'] ?? '',
                    $row['username'] ?? '',
                    $row['parent_phone'] ?? '',
                    $row['grade_level'] ?? '',
                    $row['section'] ?? '',
                ]));
                return strpos($searchableText, $pUserFilterLower) !== false;
            }));
        }
        $titleParts = ['Registered Students'];
        if ($pGrade !== '') { $titleParts[] = $pGrade; }
        if ($pSection !== '') { $titleParts[] = $pSection; }
        $title = implode(' - ', $titleParts);
        $filterInfo = [];
        if ($pGrade !== '') { $filterInfo[] = 'Grade: ' . htmlspecialchars($pGrade); }
        if ($pSection !== '') { $filterInfo[] = 'Section: ' . htmlspecialchars($pSection); }
        if ($pGender !== '') { $filterInfo[] = 'Gender: ' . htmlspecialchars($pGender); }
        if ($pUserFilter !== '') { $filterInfo[] = 'Filter: ' . htmlspecialchars($pUserFilter); }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><style>body{font-family:Segoe UI,system-ui,sans-serif;background:#eef7f0;padding:20px;}table{width:100%;border-collapse:collapse;}th{background:#d1fae5;color:#065f46;}th,td{border:1px solid #a7f3d0;padding:8px;text-align:left;}h1{margin-bottom:6px;color:#065f46;}p{color:#047857;}</style></head><body><h1>' . htmlspecialchars($title) . '</h1><p>Total: ' . count($rows) . (!empty($filterInfo) ? ' | ' . implode(' | ', $filterInfo) : '') . '</p><table><thead><tr><th>Name</th><th>Gender</th><th>Username</th><th>Phone</th><th>Grade</th><th>Section</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . htmlspecialchars($row['full_name']) . '</td><td>' . htmlspecialchars($row['gender'] ?? '-') . '</td><td>' . htmlspecialchars($row['username']) . '</td><td>' . htmlspecialchars($row['parent_phone']) . '</td><td>' . htmlspecialchars($row['grade_level']) . '</td><td>' . htmlspecialchars($row['section']) . '</td></tr>';
        }
        echo '</tbody></table><script>window.print(); setTimeout(function(){window.close();}, 1000);</script></body></html>';
        exit;
    }
    if ($gaction === 'print_teachers') {
        if (empty($_SESSION['admin_id'])) { header('HTTP/1.1 403 Forbidden'); exit; }
        $rows = $conn->query('SELECT id, full_name, username, grade_level, section FROM teachers ORDER BY full_name ASC')->fetch_all(MYSQLI_ASSOC);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Registered Teachers</title><style>body{font-family:Segoe UI,system-ui,sans-serif;background:#eef7f0;padding:20px;}table{width:100%;border-collapse:collapse;}th{background:#d1fae5;color:#065f46;}th,td{border:1px solid #a7f3d0;padding:8px;text-align:left;}h1{margin-bottom:6px;color:#065f46;}p{color:#047857;}</style></head><body><h1>Registered Teachers</h1><p>Total: ' . count($rows) . '</p><table><thead><tr><th>Name</th><th>Username</th><th>Grade</th><th>Section</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . htmlspecialchars($row['full_name']) . '</td><td>' . htmlspecialchars($row['username']) . '</td><td>' . htmlspecialchars($row['grade_level']) . '</td><td>' . htmlspecialchars($row['section']) . '</td></tr>';
        }
        echo '</tbody></table><script>window.print(); setTimeout(function(){window.close();}, 1000);</script></body></html>';
        exit;
    }
}

$loggedIn = !empty($_SESSION['admin_id']);
$adminName = $_SESSION['admin_name'] ?? '';
$adminCountResult = $conn->query('SELECT COUNT(*) AS total FROM admins');
$adminCount = $adminCountResult ? (int) $adminCountResult->fetch_assoc()['total'] : 0;
$userFilter = trim($_GET['user_filter'] ?? '');
$genderFilter = trim($_GET['gender_filter'] ?? '');
$gradeFilter = trim($_GET['grade_filter'] ?? '');
$sectionFilter = trim($_GET['section_filter'] ?? '');
if (!in_array($genderFilter, ['', 'Male', 'Female'], true)) {
    $genderFilter = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$dashboardDate = trim($_GET['dashboard_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dashboardDate)) {
    $dashboardDate = date('Y-m-d');
}
$dashboardName = trim($_GET['dashboard_name'] ?? '');
$dashboardGrade = trim($_GET['dashboard_grade'] ?? '');
$dashboardSection = trim($_GET['dashboard_section'] ?? '');
$dashboardStatus = trim($_GET['dashboard_status'] ?? '');
if (!in_array($dashboardStatus, ['', 'Present', 'Absent'], true)) {
    $dashboardStatus = '';
}
$dashboardGradeLevels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$dashboardCounts = array_fill_keys($dashboardGradeLevels, 0);
$dashboardResult = $conn->query('SELECT grade_level, COUNT(*) AS total FROM students GROUP BY grade_level');
if ($dashboardResult) {
    while ($dashboardRow = $dashboardResult->fetch_assoc()) {
        if (array_key_exists($dashboardRow['grade_level'], $dashboardCounts)) {
            $dashboardCounts[$dashboardRow['grade_level']] = (int) $dashboardRow['total'];
        }
    }
}
$dashboardTotalUsers = array_sum($dashboardCounts);
$dashboardAmInCount = 0;
$dashboardPmOutCount = 0;
$dashboardPunchCountStmt = $conn->prepare("SELECT COUNT(DISTINCT CASE WHEN log_type = 'AM_IN' THEN student_id END) AS am_in_total, COUNT(DISTINCT CASE WHEN log_type = 'PM_OUT' THEN student_id END) AS pm_out_total FROM attendance_logs WHERE DATE(timestamp) = ?");
if ($dashboardPunchCountStmt) {
    $dashboardPunchCountStmt->bind_param('s', $dashboardDate);
    $dashboardPunchCountStmt->execute();
    $dashboardPunchCountResult = $dashboardPunchCountStmt->get_result();
    if ($dashboardPunchCountResult && ($dashboardPunchCountRow = $dashboardPunchCountResult->fetch_assoc())) {
        $dashboardAmInCount = (int) $dashboardPunchCountRow['am_in_total'];
        $dashboardPmOutCount = (int) $dashboardPunchCountRow['pm_out_total'];
    }
    $dashboardPunchCountStmt->close();
}
$dashboardLearners = [];
$dashboardPresentCount = 0;
$dashboardAbsentCount = 0;
$dashboardLearnersStmt = $conn->prepare("SELECT s.student_id, s.full_name, s.grade_level, s.section, CASE WHEN a.student_id IS NULL THEN 'Absent' ELSE 'Present' END AS attendance_status, COALESCE(GROUP_CONCAT(DISTINCT t.full_name ORDER BY t.full_name SEPARATOR ', '), '') AS adviser FROM students s LEFT JOIN (SELECT DISTINCT student_id FROM attendance_logs WHERE log_type IN ('AM_IN', 'PM_IN') AND DATE(timestamp) = ?) a ON a.student_id = s.student_id LEFT JOIN teachers t ON t.grade_level = s.grade_level AND t.section = s.section GROUP BY s.student_id, s.full_name, s.grade_level, s.section, a.student_id ORDER BY FIELD(s.grade_level, 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'), s.grade_level ASC, s.section ASC, s.full_name ASC");
$dashboardLearnersStmt->bind_param('s', $dashboardDate);
$dashboardLearnersStmt->execute();
$dashboardLearnersResult = $dashboardLearnersStmt->get_result();
if ($dashboardLearnersResult) {
    while ($dashboardLearner = $dashboardLearnersResult->fetch_assoc()) {
        if (!isSchoolDay($conn, $dashboardDate)) {
            $dashboardLearner['attendance_status'] = 'Weekend';
        }
        $dashboardLearners[] = $dashboardLearner;
        if ($dashboardLearner['attendance_status'] === 'Present') {
            $dashboardPresentCount++;
        } elseif ($dashboardLearner['attendance_status'] === 'Absent') {
            $dashboardAbsentCount++;
        }
    }
}
$dashboardGradeOptions = array_values(array_unique(array_filter(array_column($dashboardLearners, 'grade_level'))));
sort($dashboardGradeOptions, SORT_NATURAL | SORT_FLAG_CASE);
if (!in_array($dashboardGrade, $dashboardGradeOptions, true)) {
    $dashboardGrade = '';
}
$dashboardSectionMap = [];
foreach ($dashboardLearners as $dashboardLearner) {
    $dashboardLearnerSection = $dashboardLearner['section'] ?? '';
    $dashboardLearnerGrade = $dashboardLearner['grade_level'] ?? '';
    if ($dashboardLearnerSection === '') {
        continue;
    }
    if (!isset($dashboardSectionMap[$dashboardLearnerSection])) {
        $dashboardSectionMap[$dashboardLearnerSection] = [];
    }
    if ($dashboardLearnerGrade !== '' && !in_array($dashboardLearnerGrade, $dashboardSectionMap[$dashboardLearnerSection], true)) {
        $dashboardSectionMap[$dashboardLearnerSection][] = $dashboardLearnerGrade;
    }
}
ksort($dashboardSectionMap, SORT_NATURAL | SORT_FLAG_CASE);
$dashboardSectionOptions = $dashboardSectionMap;
if (!isset($dashboardSectionOptions[$dashboardSection])
    || ($dashboardGrade !== '' && !in_array($dashboardGrade, $dashboardSectionOptions[$dashboardSection], true))) {
    $dashboardSection = '';
}
if ($dashboardName !== '' || $dashboardGrade !== '' || $dashboardSection !== '' || $dashboardStatus !== '') {
    $dashboardLearners = array_values(array_filter($dashboardLearners, static function ($dashboardLearner) use ($dashboardName, $dashboardGrade, $dashboardSection, $dashboardStatus): bool {
        if ($dashboardName !== '' && stripos($dashboardLearner['full_name'] ?? '', $dashboardName) === false) {
            return false;
        }
        if ($dashboardGrade !== '' && ($dashboardLearner['grade_level'] ?? '') !== $dashboardGrade) {
            return false;
        }
        if ($dashboardSection !== '' && ($dashboardLearner['section'] ?? '') !== $dashboardSection) {
            return false;
        }
        if ($dashboardStatus !== '' && ($dashboardLearner['attendance_status'] ?? '') !== $dashboardStatus) {
            return false;
        }

        return true;
    }));
}
$usersResult = $conn->query('SELECT student_id, full_name, gender, grade_level, section, grade_section, parent_phone, username FROM students ORDER BY full_name ASC');
$users = $usersResult ? $usersResult->fetch_all(MYSQLI_ASSOC) : [];

if ($userFilter !== '') {
    $userFilterLower = strtolower($userFilter);
    $users = array_values(array_filter($users, static function ($user) use ($userFilterLower): bool {
        $searchableText = strtolower(implode(' ', [
            $user['full_name'] ?? '',
            $user['gender'] ?? '',
            $user['username'] ?? '',
            $user['parent_phone'] ?? '',
            $user['grade_level'] ?? '',
            $user['section'] ?? '',
            $user['grade_section'] ?? '',
        ]));

        return strpos($searchableText, $userFilterLower) !== false;
    }));
}

$users = array_values(array_filter($users, static function ($user) use ($genderFilter, $gradeFilter, $sectionFilter): bool {
    if ($genderFilter !== '' && ($user['gender'] ?? '') !== $genderFilter) {
        return false;
    }
    if ($gradeFilter !== '' && ($user['grade_level'] ?? '') !== $gradeFilter) {
        return false;
    }
    if ($sectionFilter !== '' && ($user['section'] ?? '') !== $sectionFilter) {
        return false;
    }
    return true;
}));
$totalUsers = count($users);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$users = array_slice($users, ($page - 1) * $perPage, $perPage);
$teachersResult = $conn->query('SELECT id, username, full_name, grade_level, section, is_approved FROM teachers ORDER BY full_name ASC');
$registeredTeachers = $teachersResult ? $teachersResult->fetch_all(MYSQLI_ASSOC) : [];

$teacherNameFilter = trim($_GET['teacher_name_filter'] ?? $_GET['teacher_filter'] ?? '');
$teacherGradeFilter = trim($_GET['teacher_grade_filter'] ?? '');
$teacherSectionFilter = trim($_GET['teacher_section_filter'] ?? '');
if ($teacherNameFilter !== '') {
    $teacherNameFilterLower = strtolower($teacherNameFilter);
    $registeredTeachers = array_values(array_filter($registeredTeachers, static function ($teacher) use ($teacherNameFilterLower): bool {
        $searchable = strtolower($teacher['full_name'] ?? '');
        return strpos($searchable, $teacherNameFilterLower) !== false;
    }));
}
if ($teacherGradeFilter !== '') {
    $registeredTeachers = array_values(array_filter($registeredTeachers, static function ($teacher) use ($teacherGradeFilter): bool {
        return ($teacher['grade_level'] ?? '') === $teacherGradeFilter;
    }));
}
if ($teacherSectionFilter !== '') {
    $registeredTeachers = array_values(array_filter($registeredTeachers, static function ($teacher) use ($teacherSectionFilter): bool {
        return ($teacher['section'] ?? '') === $teacherSectionFilter;
    }));
}
$credentialRequestsResult = $conn->query("SELECT cr.id, cr.student_id, cr.requested_username, cr.status, cr.requested_by, cr.created_at, s.full_name AS student_name, s.grade_level, s.section FROM credential_requests cr JOIN students s ON s.student_id = cr.student_id ORDER BY FIELD(cr.status, 'pending', 'approved', 'rejected'), cr.created_at DESC");
$credentialRequests = $credentialRequestsResult ? $credentialRequestsResult->fetch_all(MYSQLI_ASSOC) : [];
$pendingCredentialRequests = 0;
foreach ($credentialRequests as $credentialRequest) {
    if ($credentialRequest['status'] === 'pending') {
        $pendingCredentialRequests++;
    }
}
$gradeSectionsResult = $conn->query("SELECT id, grade_level, section FROM grade_sections ORDER BY FIELD(grade_level, 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'), grade_level ASC, section ASC");
$gradeSections = $gradeSectionsResult ? $gradeSectionsResult->fetch_all(MYSQLI_ASSOC) : [];
$dashboardSections = array_fill_keys($dashboardGradeLevels, []);
foreach ($gradeSections as $gradeSection) {
    if (isset($dashboardSections[$gradeSection['grade_level']])) {
        $dashboardSections[$gradeSection['grade_level']][] = $gradeSection['section'];
    }
}
$availableGradeLevels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
$gradeLevels = array_values(array_unique(array_column($gradeSections, 'grade_level')));
$sections = array_values(array_unique(array_column($gradeSections, 'section')));
foreach ($users as $user) {
    if (!empty($user['grade_level'])) $gradeLevels[] = $user['grade_level'];
    if (!empty($user['section'])) $sections[] = $user['section'];
}
foreach ($registeredTeachers as $teacher) {
    if (!empty($teacher['grade_level'])) $gradeLevels[] = $teacher['grade_level'];
    if (!empty($teacher['section'])) $sections[] = $teacher['section'];
}
$gradeLevels = array_values(array_unique($gradeLevels));
$sections = array_values(array_unique($sections));
sort($gradeLevels, SORT_NATURAL | SORT_FLAG_CASE);
sort($sections, SORT_NATURAL | SORT_FLAG_CASE);

$attendanceFilter = trim($_GET['attendance_filter'] ?? '');
$attendanceDateFilter = trim($_GET['attendance_date_filter'] ?? '');
if ($attendanceDateFilter === '') {
    $attendanceDateFilter = date('Y-m-d');
}
$attendanceLogsResult = $conn->query('SELECT a.id, a.student_id, a.log_type, a.timestamp, a.latitude, a.longitude, s.full_name, s.grade_level, s.section FROM attendance_logs a JOIN students s ON s.student_id = a.student_id ORDER BY a.timestamp DESC, a.id DESC');
$attendanceLogs = $attendanceLogsResult ? $attendanceLogsResult->fetch_all(MYSQLI_ASSOC) : [];

$amInThresholdMinutes = getAttendanceSetting($conn, 'am_in_threshold_minutes', 300);
$amOutThresholdMinutes = getAttendanceSetting($conn, 'am_out_threshold_minutes', 420);
$pmInThresholdMinutes = getAttendanceSetting($conn, 'pm_in_threshold_minutes', 780);
$pmOutThresholdMinutes = getAttendanceSetting($conn, 'pm_out_threshold_minutes', 960);
$amTardyAfterMinutes = getAttendanceSetting($conn, 'am_tardy_after_minutes', 495);
$pmTardyAfterMinutes = getAttendanceSetting($conn, 'pm_tardy_after_minutes', 780);
$geotagEnabled = isGeotaggingEnabled($conn);
$geotagConfig = getGeotagConfig($conn);
$scanMode = getScanMode($conn);
$smsGateway = getSmsGatewayConfig($conn);
$smsSendToParents = isSmsEnabledForParents($conn);
$smsDailyLimit = getSmsDailyLimit($conn);
$smsSentToday = countSmsSentToday($conn);
$amInThresholdLabel = formatMinutesToTime($amInThresholdMinutes);
$amOutThresholdLabel = formatMinutesToTime($amOutThresholdMinutes);
$pmInThresholdLabel = formatMinutesToTime($pmInThresholdMinutes);
$pmOutThresholdLabel = formatMinutesToTime($pmOutThresholdMinutes);
$amTardyAfterLabel = formatMinutesToTime($amTardyAfterMinutes);
$pmTardyAfterLabel = formatMinutesToTime($pmTardyAfterMinutes);

if ($attendanceDateFilter !== '') {
    $attendanceLogs = array_values(array_filter($attendanceLogs, static function ($log) use ($attendanceDateFilter): bool {
        return substr($log['timestamp'], 0, 10) === $attendanceDateFilter;
    }));
}

if ($attendanceFilter !== '') {
    $attendanceFilterLower = strtolower($attendanceFilter);
    $attendanceLogs = array_values(array_filter($attendanceLogs, static function ($log) use ($attendanceFilterLower): bool {
        $searchable = strtolower(implode(' ', [
            $log['full_name'] ?? '',
            $log['log_type'] ?? '',
            $log['timestamp'] ?? '',
            $log['student_id'] ?? '',
        ]));

        return strpos($searchable, $attendanceFilterLower) !== false;
    }));
}
$attendanceGradeFilter = trim($_GET['attendance_grade_filter'] ?? '');
$attendanceSectionFilter = trim($_GET['attendance_section_filter'] ?? '');
if (!in_array($attendanceGradeFilter, $gradeLevels, true)) {
    $attendanceGradeFilter = '';
}
if (!in_array($attendanceSectionFilter, $sections, true)) {
    $attendanceSectionFilter = '';
}
$attendanceGradeOptions = $gradeLevels;
$attendanceSectionOptions = $sections;
$attendanceSectionMap = [];
foreach ($attendanceGradeOptions as $attendanceGradeOption) {
    foreach ($gradeSections as $gradeSection) {
        if ($gradeSection['grade_level'] !== $attendanceGradeOption) {
            continue;
        }
        if (!isset($attendanceSectionMap[$gradeSection['section']])) {
            $attendanceSectionMap[$gradeSection['section']] = [];
        }
        if (!in_array($attendanceGradeOption, $attendanceSectionMap[$gradeSection['section']], true)) {
            $attendanceSectionMap[$gradeSection['section']][] = $attendanceGradeOption;
        }
    }
}
foreach ($attendanceLogs as $attendanceLog) {
    $attendanceLogGrade = $attendanceLog['grade_level'] ?? '';
    $attendanceLogSection = $attendanceLog['section'] ?? '';
    if ($attendanceLogSection === '') {
        continue;
    }
    if (!isset($attendanceSectionMap[$attendanceLogSection])) {
        $attendanceSectionMap[$attendanceLogSection] = [];
    }
    if ($attendanceLogGrade !== '' && !in_array($attendanceLogGrade, $attendanceSectionMap[$attendanceLogSection], true)) {
        $attendanceSectionMap[$attendanceLogSection][] = $attendanceLogGrade;
    }
}
if ($attendanceGradeFilter !== '' || $attendanceSectionFilter !== '') {
    $attendanceLogs = array_values(array_filter($attendanceLogs, static function ($attendanceLog) use ($attendanceGradeFilter, $attendanceSectionFilter): bool {
        if ($attendanceGradeFilter !== '' && ($attendanceLog['grade_level'] ?? '') !== $attendanceGradeFilter) {
            return false;
        }
        if ($attendanceSectionFilter !== '' && ($attendanceLog['section'] ?? '') !== $attendanceSectionFilter) {
            return false;
        }

        return true;
    }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - School Attendance</title>
    <link rel="stylesheet" href="shared/layout.css?v=2">
    <style>
        /* Admin-specific refinements on top of shared layout.css */
        .header { margin-bottom: 22px; }
        .card::before {
            content: '';
            display: block;
            height: 3px;
            width: 44px;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--brand-400), var(--brand-600));
            margin-bottom: 14px;
        }
        .card > h2 { margin-top: 2px; }
        .table-wrap { margin-top: 12px; }
        .user-fullname { font-weight: 600; color: var(--text-primary); }

        /* ---- CSV import (file is read in the browser, the host blocks uploads) ---- */
        .csv-textarea {
            width: 100%;
            min-height: 96px;
            padding: 10px 12px;
            box-sizing: border-box;
            border: 1.5px solid #d1e5d8;
            border-radius: 10px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
            color: #14241b;
            background: #fbfefc;
            margin-bottom: 10px;
        }
        .csv-textarea:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }

        /* ---- Dashboard stat boxes ---- */
        .dash-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin: 6px 0 24px;
        }
        .dash-stat {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #ffffff;
            border: 1px solid #e0efe6;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 4px 14px rgba(2,44,34,0.06);
            transition: all .25s ease;
            position: relative;
            overflow: hidden;
            min-height: 96px;
        }
        .dash-stat::after {
            content: '';
            position: absolute;
            right: -24px;
            top: -24px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16,185,129,0.10), transparent 70%);
            pointer-events: none;
        }
        .dash-stat:hover { transform: translateY(-3px); box-shadow: 0 10px 26px rgba(2,44,34,0.12); }
        .dash-stat .dash-stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(16,185,129,0.3);
        }
        .dash-stat .dash-stat-icon svg { width: 26px; height: 26px; fill: currentColor; }
        .dash-stat.dark .dash-stat-icon { background: linear-gradient(180deg,#34d399,#065f46); }
        .dash-stat.green .dash-stat-icon { background: linear-gradient(180deg,#10b981,#047857); }
        .dash-stat.red .dash-stat-icon { background: linear-gradient(180deg,#fb7185,#e11d48); box-shadow: 0 6px 14px rgba(225,29,72,0.3); }
        .dash-stat.blue .dash-stat-icon { background: linear-gradient(180deg,#38bdf8,#0369a1); box-shadow: 0 6px 14px rgba(3,105,161,0.3); }
        .dash-stat.amber .dash-stat-icon { background: linear-gradient(180deg,#fbbf24,#b45309); box-shadow: 0 6px 14px rgba(180,83,9,0.3); }
        .dash-stat .dash-stat-value { font-size: 30px; font-weight: 800; color: #064e3b; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .dash-stat .dash-stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280; font-weight: 700; margin-top: 4px; }
        .dash-stat .dash-stat-sub { font-size: 12px; color: #94a3b8; margin-top: 2px; }

        .dash-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 800;
            color: #064e3b;
            margin: 0 0 14px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .dash-section-title::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(180deg,#34d399,#047857);
            box-shadow: 0 0 0 4px rgba(16,185,129,0.15);
        }

        .grade-card {
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e0efe6;
            border-radius: 16px;
            padding: 18px;
            background: #ffffff;
            box-shadow: 0 4px 14px rgba(2,44,34,0.06);
            transition: all .25s ease;
            position: relative;
            overflow: hidden;
            min-height: 96px;
        }
        .grade-card::after {
            content: '';
            position: absolute;
            right: -24px;
            top: -24px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16,185,129,0.08), transparent 70%);
            pointer-events: none;
        }
        .grade-card:hover { transform: translateY(-3px); box-shadow: 0 10px 26px rgba(2,44,34,0.12); }
        .grade-card .grade-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            background: linear-gradient(180deg,#34d399,#065f46);
            box-shadow: 0 6px 14px rgba(16,185,129,0.3);
        }
        .grade-card .grade-icon svg { width: 26px; height: 26px; fill: currentColor; }
        .grade-card .grade-count { font-size: 30px; font-weight: 800; color: #064e3b; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .grade-card h3 { margin: 2px 0 0; font-size: 15px; font-weight: 800; color: #064e3b; letter-spacing: 0.2px; }
        .grade-card .grade-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #6b7280;
            font-weight: 700;
            margin-top: 2px;
        }
        .grade-card .grade-sections-label { font-size: 12px; color: #94a3b8; margin-top: 2px; }

        .dash-table-box {
            margin-top: 24px;
            border: 1px solid #e0efe6;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(2,44,34,0.06);
            overflow: hidden;
        }
        .dash-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 16px 18px;
            border-bottom: 1px solid #e6f0ea;
            background: linear-gradient(180deg,#f7fcf9,#eefbf4);
        }
        .dash-table-header h3 { margin: 0; color: #064e3b; }
        .dash-table-box .dashboard-table-wrap { border: 0; border-radius: 0; background: #fff; }
        .dash-table-box .dashboard-table-wrap table tbody tr:last-child td { border-bottom: none; }
        .lrn-present { color: #059669; font-weight: 700; }
        .lrn-absent { color: #e11d48; font-weight: 700; }
        @media (max-width: 640px) {
            .dash-header-actions { width: 100%; }
            .dash-header-actions form { flex: 1; }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php if ($loggedIn): ?>
        <button class="sidebar-toggle" type="button" aria-label="Toggle sidebar" aria-controls="siteSidebar" aria-expanded="false" onclick="toggleSidebar()">&#9776;</button>
        <aside class="sidebar" id="siteSidebar">
            <div class="brand"><img src="bnas_logo.png" alt="BNAS Logo" class="brand-logo"> PROJECT SMART</div>
            <div class="muted">Baggao National Agricultural School</div>
            <nav>
                <a href="#dashboard">Dashboard</a>
                <a href="#create-user">Create User</a>
                <a href="#grade-sections">Grade Levels & Sections</a>
                <a href="#attendance-thresholds">Attendance Thresholds</a>
                <a href="#geotagging">Geotagging</a>
                <a href="#sms-gateway">SMS Gateway</a>
                <a href="#attendance-records">Attendance Records</a>
                <a href="#registered-teachers">Registered Teachers</a>
                <a href="#registered-users">Registered Users</a>
            </nav>
            <div class="sidebar-logout">
                <a href="admin.php?logout=1">Logout</a>
            </div>
        </aside>
        <?php endif; ?>
        <main class="main">
            <div class="panel">
        <div class="header">
            <div>
                <h1>Admin Panel</h1>
                <p class="muted">Register an admin, create a user account, and generate a QR code.</p>
            </div>
            <?php if ($loggedIn): ?>
                <div class="header-actions">
                    <button id="open-account-btn" class="btn-secondary icon-btn" type="button" onclick="openAccountModal()" title="Account Settings" aria-label="Account Settings">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M19.14 12.936a7.003 7.003 0 000-1.873l2.037-1.582a.5.5 0 00.12-.638l-1.928-3.337a.5.5 0 00-.607-.22l-2.396.96a6.992 6.992 0 00-1.62-.94l-.36-2.54A.5.5 0 0013.88 2h-3.76a.5.5 0 00-.495.427l-.36 2.54a6.99 6.99 0 00-1.62.94l-2.396-.96a.5.5 0 00-.607.22L2.703 8.85a.5.5 0 00.12.638L4.86 11.07a6.98 6.98 0 000 1.873L2.823 14.525a.5.5 0 00-.12.638l1.928 3.337a.5.5 0 00.607.22l2.396-.96c.5.38 1.04.69 1.62.94l.36 2.54a.5.5 0 00.495.427h3.76a.5.5 0 00.495-.427l.36-2.54c.58-.25 1.12-.56 1.62-.94l2.396.96a.5.5 0 00.607-.22l1.928-3.337a.5.5 0 00-.12-.638l-2.037-1.582zM12 15.5A3.5 3.5 0 1112 8.5a3.5 3.5 0 010 7z"/></svg>
                    </button>
                    <button id="open-create-user-btn" class="btn-secondary icon-btn" type="button" onclick="(window.showAdminSection||function(id){})('create-user')" title="Create User" aria-label="Create User">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M19 11h-6V5a1 1 0 10-2 0v6H5a1 1 0 100 2h6v6a1 1 0 102 0v-6h6a1 1 0 100-2z"/></svg>
                    </button>
                    <a href="admin.php?logout=1"><button class="btn-secondary" type="button">Logout</button></a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!$loggedIn): ?>
            <div class="grid">
                <div class="card">
                    <h2><?= $adminCount > 0 ? 'Admin Login' : 'Create Admin Account' ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="<?= $adminCount > 0 ? 'login' : 'register_admin' ?>">
                        <?php if ($adminCount === 0): ?>
                            <label>Full Name</label>
                            <input type="text" name="full_name" required>
                        <?php endif; ?>
                        <label>Username</label>
                        <input type="text" name="username" required>
                        <label>Password</label>
                        <input type="password" name="password" required>
                        <button type="submit"><?= $adminCount > 0 ? 'Login' : 'Create Admin' ?></button>
                    </form>
                </div>

                <?php if ($adminCount > 0): ?>
                    <div class="card">
                        <h2>Need an account?</h2>
                        <p class="muted">Ask an existing administrator to create a new admin account.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card" id="welcome">
                <h2>Welcome, <?= htmlspecialchars($adminName) ?></h2>
                <p class="muted">Create a new user, generate a QR code, and manage registered users.</p>
            </div>
            <section class="card admin-section" id="dashboard">
                <div class="dashboard-heading">
                    <div>
                        <h2>Dashboard</h2>
                        <p class="muted" style="margin:0;">Overview of learners and daily attendance monitoring</p>
                    </div>
                </div>

                <div class="dash-stat-grid">
                    <div class="dash-stat dark">
                        <div class="dash-stat-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        </div>
                        <div>
                            <div class="dash-stat-value"><?= (int) $dashboardTotalUsers ?></div>
                            <div class="dash-stat-label">Registered Users</div>
                            <div class="dash-stat-sub">Learners in the system</div>
                        </div>
                    </div>
                    <div class="dash-stat green">
                        <div class="dash-stat-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        </div>
                        <div>
                            <div class="dash-stat-value"><?= (int) $dashboardPresentCount ?></div>
                            <div class="dash-stat-label">Present Today</div>
                            <div class="dash-stat-sub"><?= htmlspecialchars(date('F j, Y', strtotime($dashboardDate))) ?></div>
                        </div>
                    </div>
                    <div class="dash-stat red">
                        <div class="dash-stat-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M11.5 2C6.26 2 2 6.26 2 11.5S6.26 21 11.5 21s9.5-4.26 9.5-9.5S16.74 2 11.5 2zm3.99 12.22c.2.2.2.53 0 .73l-.51.51c-.2.2-.53.2-.73 0L11.5 11.9l-2.75 2.56c-.2.2-.53.2-.73 0l-.51-.51c-.2-.2-.2-.53 0-.73l2.74-2.56-2.74-2.56c-.2-.2-.2-.53 0-.73l.51-.51c.2-.2.53-.2.73 0l2.75 2.56 2.75-2.56c.2-.2.53-.2.73 0l.51.51c.2.2.2.53 0 .73l-2.75 2.56 2.75 2.56z"/></svg>
                        </div>
                        <div>
                            <div class="dash-stat-value"><?= (int) $dashboardAbsentCount ?></div>
                            <div class="dash-stat-label">Absent Today</div>
                            <div class="dash-stat-sub"><?= htmlspecialchars(date('F j, Y', strtotime($dashboardDate))) ?></div>
                        </div>
                    </div>
                    <div class="dash-stat blue">
                        <div class="dash-stat-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M11 7l-1.41 1.41L12.17 11H3v2h9.17l-2.58 2.59L11 17l5-5-5-5zM20 3h-8v2h8v14h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                        </div>
                        <div>
                            <div class="dash-stat-value"><?= (int) $dashboardAmInCount ?></div>
                            <div class="dash-stat-label">AM In</div>
                            <div class="dash-stat-sub">AM arrivals recorded</div>
                        </div>
                    </div>
                    <div class="dash-stat amber">
                        <div class="dash-stat-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M17 7l-1.41 1.41L18.17 11H9v2h9.17l-2.58 2.59L17 17l5-5-5-5zM7 5H5c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h2v-2H5V7h2V5z"/></svg>
                        </div>
                        <div>
                            <div class="dash-stat-value"><?= (int) $dashboardPmOutCount ?></div>
                            <div class="dash-stat-label">PM Out</div>
                            <div class="dash-stat-sub">PM departures recorded</div>
                        </div>
                    </div>
                </div>

                <div class="dash-section-title">Registered Users by Grade Level</div>
                <div class="grade-dashboard">
                    <?php foreach ($dashboardGradeLevels as $dashboardGradeLevel): ?>
                        <div class="grade-card">
                            <div class="grade-icon">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>
                            </div>
                            <div style="min-width:0;">
                                <div class="grade-count"><?= $dashboardCounts[$dashboardGradeLevel] ?></div>
                                <h3><?= htmlspecialchars($dashboardGradeLevel) ?></h3>
                                <div class="grade-label">Registered users</div>
                                <div class="grade-sections-label">Sections: <?= $dashboardSections[$dashboardGradeLevel] ? htmlspecialchars(implode(', ', $dashboardSections[$dashboardGradeLevel])) : 'None added' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dash-table-box">
                    <div class="dash-table-header">
                        <h3>Learner Attendance for <?= htmlspecialchars(date('F j, Y', strtotime($dashboardDate))) ?></h3>
                        <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
                            <label for="dashboard-date" style="font-size:13px; font-weight:600;">Date Filter</label>
                            <input type="date" name="dashboard_date" id="dashboard-date" value="<?= htmlspecialchars($dashboardDate) ?>" style="width:auto; margin:0;">
                            <input type="text" name="dashboard_name" value="<?= htmlspecialchars($dashboardName) ?>" placeholder="Filter by name" style="width:auto; margin:0;">
                            <select name="dashboard_grade" id="dashboard-grade" style="width:auto; margin:0;">
                                <option value="">All grade levels</option>
                                <?php foreach ($dashboardGradeOptions as $dashboardGradeOption): ?>
                                    <option value="<?= htmlspecialchars($dashboardGradeOption) ?>" <?= $dashboardGrade === $dashboardGradeOption ? 'selected' : '' ?>><?= htmlspecialchars($dashboardGradeOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="dashboard_section" id="dashboard-section" style="width:auto; margin:0;">
                                <option value="">All sections</option>
                                <?php foreach ($dashboardSectionOptions as $dashboardSectionName => $dashboardSectionGrades): ?>
                                    <option value="<?= htmlspecialchars($dashboardSectionName) ?>" data-grades="<?= htmlspecialchars(implode('|', $dashboardSectionGrades)) ?>" <?= $dashboardSection === $dashboardSectionName ? 'selected' : '' ?>><?= htmlspecialchars($dashboardSectionName) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="dashboard_status" style="width:auto; margin:0;">
                                <option value="">All statuses</option>
                                <option value="Present" <?= $dashboardStatus === 'Present' ? 'selected' : '' ?>>Present</option>
                                <option value="Absent" <?= $dashboardStatus === 'Absent' ? 'selected' : '' ?>>Absent</option>
                            </select>
                            <button type="submit">Apply</button>
                            <?php if ($dashboardName !== '' || $dashboardGrade !== '' || $dashboardSection !== '' || $dashboardStatus !== '' || $dashboardDate !== date('Y-m-d')): ?>
                                <a href="admin.php"><button type="button" class="btn-secondary">Clear</button></a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="dashboard-table-wrap">
                        <?php if ($dashboardLearners): ?>
                            <table class="dashboard-table">
                                <thead><tr><th>Name</th><th>Grade Level</th><th>Section</th><th>Adviser</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($dashboardLearners as $dashboardLearner): ?>
                                        <tr class="<?= $dashboardLearner['attendance_status'] === 'Present' ? 'row-present' : 'row-absent' ?>">
                                            <td><span class="<?= $dashboardLearner['attendance_status'] === 'Present' ? 'lrn-present' : 'lrn-absent' ?>"><?= htmlspecialchars($dashboardLearner['full_name']) ?></span></td>
                                            <td><?= htmlspecialchars($dashboardLearner['grade_level'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($dashboardLearner['section'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($dashboardLearner['adviser'] ?: '-') ?></td>
                                            <td><span class="<?= $dashboardLearner['attendance_status'] === 'Present' ? 'status-present' : 'status-absent' ?>"><?= htmlspecialchars($dashboardLearner['attendance_status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="muted" style="padding:18px; margin:0;"><?= ($dashboardName !== '' || $dashboardGrade !== '' || $dashboardSection !== '' || $dashboardStatus !== '') ? 'No learners match the selected filters.' : 'No registered learners found.' ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Account Settings moved to modal; opened via header button -->

                <div class="grid">
                    <div class="card admin-section" id="create-user">
                    <h2>Create User</h2>
                    <p class="muted">Create a new user account and generate a QR code.</p>
                    <div style="margin-top:8px;">
                        <button type="button" onclick="openCreateUser()">Open Create User</button>
                    </div>
                </div>

                <?php if (!empty($createdUser)): ?>
                    <div class="card qr-preview">
                        <h2>QR Code Generated</h2>
                        <img src="<?= htmlspecialchars($createdUser['qr_url']) ?>" alt="QR code">
                        <p><strong>Student ID:</strong> <?= htmlspecialchars($createdUser['student_id']) ?></p>
                        <p><strong>Username:</strong> <?= htmlspecialchars($createdUser['username']) ?></p>
                        <p><strong>Grade Level:</strong> <?= htmlspecialchars($createdUser['grade_level']) ?></p>
                        <p><strong>Section:</strong> <?= htmlspecialchars($createdUser['section']) ?></p>
                        <p><strong>Password:</strong> <?= htmlspecialchars($createdUser['password']) ?></p>
                        <p class="muted">Scan this QR code at the attendance kiosk.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card admin-section" id="grade-sections">
                <h2>Grade Levels and Sections</h2>
                <p class="muted">Add the options that should appear in user and teacher forms.</p>
                <form method="post" class="grid" style="margin-top:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <input type="hidden" name="action" value="add_grade_section">
                    <div>
                        <label for="new-grade-level">Grade Level</label>
                        <select name="grade_level" id="new-grade-level" required>
                            <option value="">Select grade level</option>
                            <?php foreach ($availableGradeLevels as $availableGradeLevel): ?>
                                <option value="<?= htmlspecialchars($availableGradeLevel) ?>"><?= htmlspecialchars($availableGradeLevel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="new-section">Section</label>
                        <input type="text" name="section" id="new-section" placeholder="e.g. Gold" required>
                    </div>
                    <div style="align-self:end;"><button type="submit">Add Grade Level and Section</button></div>
                </form>
                <?php if ($gradeSections): ?>
                    <div class="table-wrap" style="margin-top:12px; overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse;">
                            <thead><tr><th style="padding:8px; text-align:left;">Grade Level</th><th style="padding:8px; text-align:left;">Section</th><th style="padding:8px; text-align:left;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($gradeSections as $gradeSection): ?>
                                    <tr data-grade-section-id="<?= (int) $gradeSection['id'] ?>"><td style="padding:8px; border-top:1px solid #e2e8f0;"><?= htmlspecialchars($gradeSection['grade_level']) ?></td><td style="padding:8px; border-top:1px solid #e2e8f0;"><?= htmlspecialchars($gradeSection['section']) ?></td><td style="padding:8px; border-top:1px solid #e2e8f0;"><button type="button" onclick="openGradeSectionEdit(<?= (int) $gradeSection['id'] ?>, '<?= htmlspecialchars($gradeSection['grade_level'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($gradeSection['section'], ENT_QUOTES, 'UTF-8') ?>')">Edit</button><form method="post" style="display:inline-block; margin-left:6px;"><input type="hidden" name="action" value="delete_grade_section"><input type="hidden" name="grade_section_id" value="<?= (int) $gradeSection['id'] ?>"><button type="submit" class="btn-secondary" onclick="return confirm('Delete this grade level and section? Existing users will keep their saved details.')">Delete</button></form></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card admin-section" id="attendance-thresholds">
                <h2>Attendance Thresholds</h2>
                <p class="muted">Set the earliest allowed time for each attendance button.</p>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="save_attendance_thresholds">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>AM IN Threshold</label>
                            <input type="text" name="am_in_threshold" value="<?= htmlspecialchars($amInThresholdLabel) ?>" placeholder="e.g. 5:00 AM" required>
                        </div>
                        <div>
                            <label>AM OUT Threshold</label>
                            <input type="text" name="am_out_threshold" value="<?= htmlspecialchars($amOutThresholdLabel) ?>" placeholder="e.g. 12:00 PM" required>
                        </div>
                        <div>
                            <label>PM IN Threshold</label>
                            <input type="text" name="pm_in_threshold" value="<?= htmlspecialchars($pmInThresholdLabel) ?>" placeholder="e.g. 1:00 PM" required>
                        </div>
                        <div>
                            <label>PM OUT Threshold</label>
                            <input type="text" name="pm_out_threshold" value="<?= htmlspecialchars($pmOutThresholdLabel) ?>" placeholder="e.g. 4:00 PM" required>
                        </div>
                        <div>
                            <label>Late/Tardy After AM</label>
                            <input type="text" name="am_tardy_after" value="<?= htmlspecialchars($amTardyAfterLabel) ?>" placeholder="e.g. 8:15 AM" required>
                        </div>
                        <div>
                            <label>Late/Tardy After PM</label>
                            <input type="text" name="pm_tardy_after" value="<?= htmlspecialchars($pmTardyAfterLabel) ?>" placeholder="e.g. 1:15 PM" required>
                        </div>
                    </div>
                    <div class="actions actions-bottom">
                        <button type="submit">Save Thresholds</button>
                    </div>
                </form>
            </div>

            <div class="card admin-section" id="geotagging">
                <h2>Geotagging</h2>
                <p class="muted">Allow or disallow users from scanning their QR code. When allowed, the scanner must capture the device location before every scan, the scan is rejected if the device is outside the allowed radius around the map coordinates, and the location is saved with the attendance entry. When disallowed, scans work normally without requesting any location.</p>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="save_geotag_settings">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>QR Code Scanning Geotagging</label>
                            <select name="geotag_enabled" required>
                                <option value="1" <?= $geotagEnabled ? 'selected' : '' ?>>Allow - require location to scan</option>
                                <option value="0" <?= $geotagEnabled ? '' : 'selected' ?>>Disallow - scan without location</option>
                            </select>
                        </div>
                        <div>
                            <label>Current Status</label>
                            <input type="text" value="<?= $geotagEnabled ? 'Allowed - location required for every scan' : 'Disallowed - location not required' ?>" readonly>
                        </div>
                        <div>
                            <label>Scanning Mode</label>
                            <select name="scan_mode" required>
                                <option value="all" <?= $scanMode === 'all' ? 'selected' : '' ?>>AM IN/OUT and PM IN/OUT (all four)</option>
                                <option value="in_out" <?= $scanMode === 'in_out' ? 'selected' : '' ?>>AM IN and PM OUT only</option>
                            </select>
                        </div>
                        <div>
                            <label>Allowed Scanning Radius (meters)</label>
                            <input type="number" name="geotag_radius_meters" id="geotag-radius" min="1" max="1000000" step="1" value="<?= htmlspecialchars($geotagConfig['radius_meters'] > 0 ? (string) $geotagConfig['radius_meters'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 100">
                        </div>
                        <div>
                            <label>Latitude</label>
                            <input type="text" inputmode="decimal" name="geotag_latitude" id="geotag-lat" value="<?= htmlspecialchars($geotagConfig['latitude'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 18.358472 or 18°21'30.50&quot;N">
                        </div>
                        <div>
                            <label>Longitude</label>
                            <input type="text" inputmode="decimal" name="geotag_longitude" id="geotag-lng" value="<?= htmlspecialchars($geotagConfig['longitude'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 121.820083 or 121°49'12.30&quot;E">
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <label>Paste Google Earth Coordinates</label>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <input type="text" id="geotag-paste" placeholder="e.g. 18°21'30.50&quot;N 121°49'12.30&quot;E or 18.358472, 121.820083" style="flex:1; min-width:260px; margin-bottom:0;">
                            <button type="button" id="geotag-use-paste" style="white-space:nowrap;">Use Coordinates</button>
                        </div>
                        <span id="geotag-paste-msg" style="display:block; margin-top:6px; font-size:13px; color:#64748b;">Copy the coordinates from Google Earth (Show ruler, or right-click a point and read the lat/long) and paste them above.</span>
                    </div>
                    <div style="margin-top:12px;">
                        <label>Scanning Area Preview</label>
                        <div id="geotag-preview-placeholder" style="height:360px; border:1.5px solid #d1e5d8; border-radius:12px; display:flex; align-items:center; justify-content:center; background:#f8fafc; color:#64748b; font-size:14px; text-align:center; padding:16px; box-sizing:border-box; <?= $geotagConfig['latitude'] !== '' && $geotagConfig['longitude'] !== '' ? 'display:none;' : '' ?>">
                            Enter or paste the latitude and longitude above to preview the scanning area.
                        </div>
                        <iframe id="geotag-preview" title="Scanning area preview" style="width:100%; height:360px; border:1.5px solid #d1e5d8; border-radius:12px; box-sizing:border-box; <?= $geotagConfig['latitude'] !== '' && $geotagConfig['longitude'] !== '' ? '' : 'display:none;' ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="about:blank"></iframe>
                        <p class="muted" style="margin-top:6px; font-size:13px;" id="geotag-radius-note"></p>
                    </div>
                    <div class="actions actions-bottom">
                        <button type="submit">Save Geotagging Setting</button>
                    </div>
                </form>
            </div>
            <script>
                (function () {
                    var latInput = document.getElementById('geotag-lat');
                    var lngInput = document.getElementById('geotag-lng');
                    var radiusInput = document.getElementById('geotag-radius');
                    var pasteInput = document.getElementById('geotag-paste');
                    var pasteBtn = document.getElementById('geotag-use-paste');
                    var pasteMsg = document.getElementById('geotag-paste-msg');
                    var preview = document.getElementById('geotag-preview');
                    var previewPlaceholder = document.getElementById('geotag-preview-placeholder');
                    var radiusNote = document.getElementById('geotag-radius-note');
                    var previewTimer = null;

                    function parseCoordinateValue(str) {
                        var v = String(str || '').trim()
                            .replace(/\u00A0/g, ' ')
                            .replace(/[\u2032\u02BA]/g, "'")
                            .replace(/[\u2033]/g, '"')
                            .replace(/[\u2018\u2019]/g, "'")
                            .replace(/[\u201C\u201D]/g, '"');
                        if (!v) return null;
                        if (/^[+-]?\d+(\.\d+)?\s*°?$/.test(v)) return parseFloat(v);
                        var m = v.match(/^([nsew])?\s*(-?\d{1,3})\s*°?\s*(\d{1,2}(?:\.\d+)?)\s*'?\s*(?:(\d+(?:\.\d+)?)\s*"?)?\s*([nsew])?$/i);
                        if (!m) return null;
                        var minutes = parseFloat(m[3]);
                        var seconds = m[4] ? parseFloat(m[4]) : 0;
                        if (minutes >= 60 || seconds >= 60) return null;
                        var hemi = (m[5] || m[1] || '').toUpperCase();
                        var dec = Math.abs(parseFloat(m[2])) + minutes / 60 + seconds / 3600;
                        if (m[2].charAt(0) === '-' || hemi === 'S' || hemi === 'W') dec = -dec;
                        return dec;
                    }

                    function dmsToDecimalJS(deg, min, sec, hemi) {
                        var dec = Math.abs(parseFloat(deg)) + (parseFloat(min) / 60) + ((parseFloat(sec) || 0) / 3600);
                        if (String(deg).charAt(0) === '-' || /[sw]/i.test(hemi)) dec = -dec;
                        return dec;
                    }

                    function parseCoordinatePair(str) {
                        var v = String(str || '').trim()
                            .replace(/\u00A0/g, ' ')
                            .replace(/[\u2032\u02BA]/g, "'")
                            .replace(/[\u2033]/g, '"')
                            .replace(/[\u2018\u2019]/g, "'")
                            .replace(/[\u201C\u201D]/g, '"');
                        if (!v) return null;

                        var decPair = v.match(/^([+-]?\d+(?:\.\d+)?)\s*°?\s*[,;\s]\s*([+-]?\d+(?:\.\d+)?)\s*°?$/);
                        if (decPair) {
                            var a = parseFloat(decPair[1]), b = parseFloat(decPair[2]);
                            if (Math.abs(a) <= 90 && Math.abs(b) <= 180) return { latitude: a, longitude: b };
                            if (Math.abs(b) <= 90 && Math.abs(a) <= 180) return { latitude: b, longitude: a };
                            return null;
                        }

                        var dms = "([-+]?\\d{1,3})\\s*°?\\s*(\\d{1,2})\\s*'?\\s*(?:(\\d+(?:\\.\\d+)?)\\s*\"?)?";
                        var m;

                        m = v.match(new RegExp('^' + dms + '\\s*([ns])[,;\\s]*' + dms + '\\s*([ew])$', 'i'));
                        if (m) return { latitude: dmsToDecimalJS(m[1], m[2], m[3], m[4]), longitude: dmsToDecimalJS(m[5], m[6], m[7], m[8]) };

                        m = v.match(new RegExp('^' + dms + '\\s*([ew])[,;\\s]*' + dms + '\\s*([ns])$', 'i'));
                        if (m) return { latitude: dmsToDecimalJS(m[5], m[6], m[7], m[8]), longitude: dmsToDecimalJS(m[1], m[2], m[3], m[4]) };

                        m = v.match(new RegExp('^([ns])\\s*' + dms + '[,;\\s]*([ew])\\s*' + dms + '$', 'i'));
                        if (m) return { latitude: dmsToDecimalJS(m[2], m[3], m[4], m[1]), longitude: dmsToDecimalJS(m[6], m[7], m[8], m[5]) };

                        m = v.match(new RegExp('^([ew])\\s*' + dms + '[,;\\s]*([ns])\\s*' + dms + '$', 'i'));
                        if (m) return { latitude: dmsToDecimalJS(m[6], m[7], m[8], m[5]), longitude: dmsToDecimalJS(m[2], m[3], m[4], m[1]) };

                        return null;
                    }

                    function zoomForRadius(radius) {
                        if (radius <= 100) return 16;
                        if (radius <= 250) return 15;
                        if (radius <= 500) return 14;
                        if (radius <= 1500) return 13;
                        return 12;
                    }

                    function updatePreview() {
                        var lat = parseCoordinateValue(latInput.value);
                        var lng = parseCoordinateValue(lngInput.value);
                        var radius = parseInt(radiusInput.value, 10);
                        if (isNaN(radius) || radius < 1) radius = 100;

                        if (lat === null || lng === null || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
                            preview.style.display = 'none';
                            previewPlaceholder.style.display = 'flex';
                            radiusNote.textContent = '';
                            return;
                        }

                        preview.style.display = 'block';
                        previewPlaceholder.style.display = 'none';
                        var src = 'https://maps.google.com/maps?q=' + encodeURIComponent(lat + ',' + lng)
                            + '&z=' + zoomForRadius(radius) + '&output=embed&hl=en';
                        if (preview.getAttribute('src') !== src) {
                            preview.setAttribute('src', src);
                        }
                        radiusNote.textContent = 'Scans are accepted only within ' + radius + ' m of ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '. The preview marker shows the center point (radius is enforced when scanning).';
                    }

                    function schedulePreview() {
                        clearTimeout(previewTimer);
                        previewTimer = setTimeout(updatePreview, 400);
                    }

                    pasteBtn.addEventListener('click', function () {
                        var pair = parseCoordinatePair(pasteInput.value) || (function () {
                            var single = parseCoordinateValue(pasteInput.value);
                            return single === null ? null : { latitude: single, longitude: null };
                        })();
                        if (!pair || pair.longitude === null) {
                            pasteMsg.textContent = 'Could not read those coordinates. Paste them the way Google Earth shows them (e.g. 18°21\'30.50"N 121°49\'12.30"E).';
                            pasteMsg.style.color = '#b91c1c';
                            return;
                        }
                        latInput.value = pair.latitude.toFixed(7);
                        lngInput.value = pair.longitude.toFixed(7);
                        pasteMsg.textContent = 'Coordinates set. Click Save Geotagging Setting to apply them.';
                        pasteMsg.style.color = '#047857';
                        updatePreview();
                    });

                    latInput.addEventListener('input', schedulePreview);
                    lngInput.addEventListener('input', schedulePreview);
                    radiusInput.addEventListener('input', schedulePreview);

                    updatePreview();
                })();
            </script>

            <div class="card admin-section" id="sms-gateway">
                <h2>SMS Gateway</h2>
                <p class="muted">Choose which SMS gateway the system uses to send parent notifications. Cloud uses the hosted api.sms-gate.app service; Local reaches an Android SMS Gate device on the school network. Set the details for each server and pick which one is active.</p>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="save_sms_gateway_settings">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>Active Server</label>
                            <select name="sms_active_gateway" required>
                                <option value="cloud" <?= $smsGateway['active'] === 'cloud' ? 'selected' : '' ?>>Cloud server (api.sms-gate.app)</option>
                                <option value="local" <?= $smsGateway['active'] === 'local' ? 'selected' : '' ?>>Local server (school network)</option>
                            </select>
                        </div>
                        <div>
                            <label>Send SMS to Parent Numbers</label>
                            <select name="sms_send_to_parents" required>
                                <option value="1" <?= $smsSendToParents ? 'selected' : '' ?>>Yes - notify parents on every scan</option>
                                <option value="0" <?= $smsSendToParents ? '' : 'selected' ?>>No - record attendance without sending SMS</option>
                            </select>
                        </div>
                        <div>
                            <label>Current Parent SMS Status</label>
                            <input type="text" value="<?= $smsSendToParents ? 'ON - parents receive SMS on every scan' : 'OFF - no SMS sent to parents' ?>" readonly>
                        </div>
                    </div>

                    <h3 style="margin:18px 0 8px; font-size:16px; color:#065f46;">Daily SMS Limit</h3>
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>Maximum SMS to Send Per Day</label>
                            <input type="number" name="sms_daily_limit" min="0" step="1" value="<?= (int) $smsDailyLimit ?>" placeholder="0">
                            <p class="muted" style="font-size:12px; margin:6px 0 0;">Set to 0 for unlimited. Once this many SMS are sent in a day, the system stops sending more so the telco does not flag the SIM card for exceeding its daily limit.</p>
                        </div>
                        <div>
                            <label>Sent Today</label>
                            <input type="text" value="<?= (int) $smsSentToday ?> message(s)<?= $smsDailyLimit > 0 ? ' of ' . (int) $smsDailyLimit : ' (unlimited)' ?>" readonly>
                        </div>
                        <div>
                            <label>Limit Status</label>
                            <input type="text" value="<?= ($smsDailyLimit > 0 && $smsSentToday >= $smsDailyLimit) ? 'Limit reached - SMS paused until tomorrow' : 'Below limit - SMS sending active' ?>" readonly>
                        </div>
                    </div>

                    <h3 style="margin:18px 0 8px; font-size:16px; color:#065f46;">Cloud Server</h3>
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>Server Address</label>
                            <input type="text" name="sms_cloud_url" value="<?= htmlspecialchars($smsGateway['cloud']['url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="api.sms-gate.app:443">
                        </div>
                        <div>
                            <label>Username</label>
                            <input type="text" name="sms_cloud_login" value="<?= htmlspecialchars($smsGateway['cloud']['login'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cloud username">
                        </div>
                        <div>
                            <label>Password</label>
                            <input type="text" name="sms_cloud_password" value="<?= htmlspecialchars($smsGateway['cloud']['password'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cloud password">
                        </div>
                        <div>
                            <label>Device ID</label>
                            <input type="text" name="sms_cloud_device_id" value="<?= htmlspecialchars($smsGateway['cloud']['device_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Cloud device ID">
                        </div>
                    </div>

                    <h3 style="margin:18px 0 8px; font-size:16px; color:#065f46;">Local Server</h3>
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>Server Address</label>
                            <input type="text" name="sms_local_url" value="<?= htmlspecialchars($smsGateway['local']['url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="192.168.254.165:8080">
                        </div>
                        <div>
                            <label>Username</label>
                            <input type="text" name="sms_local_login" value="<?= htmlspecialchars($smsGateway['local']['login'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Local username">
                        </div>
                        <div>
                            <label>Password</label>
                            <input type="text" name="sms_local_password" value="<?= htmlspecialchars($smsGateway['local']['password'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Local password">
                        </div>
                        <div>
                            <label>Device ID</label>
                            <input type="text" name="sms_local_device_id" value="<?= htmlspecialchars($smsGateway['local']['device_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Local device ID">
                        </div>
                    </div>

                    <div class="actions actions-bottom">
                        <button type="submit">Save SMS Gateway Settings</button>
                    </div>
                </form>

                <hr style="margin:20px 0; border:none; border-top:1px solid #e2e8f0;">
                <h3 style="margin:0 0 8px; font-size:16px; color:#065f46;">Send Test SMS</h3>
                <p class="muted">Send a one-off test message through the currently active server to verify delivery. This uses the saved settings above.</p>
                <form method="post" style="margin-top:12px;">
                    <input type="hidden" name="action" value="send_test_sms">
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                        <div>
                            <label>Test Phone Number</label>
                            <input type="text" name="test_sms_number" value="<?= htmlspecialchars($smsTestNumber ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required>
                        </div>
                        <div>
                            <label>Active Server</label>
                            <input type="text" value="<?= $smsGateway['active'] === 'local' ? 'Local server' : 'Cloud server' ?>" readonly>
                        </div>
                    </div>
                    <div class="actions actions-bottom">
                        <button type="submit">Send Test SMS</button>
                    </div>
                </form>
            </div>

            <div class="card admin-section" id="attendance-records">
                <h2>Attendance Records</h2>
                <p class="muted">Remove a time-in or time-out entry when an admin needs to correct a mistake.</p>
                <div class="card-actions">
                    <a href="admin.php?action=export_attendance"><button type="button">Export Attendance (CSV)</button></a>
                    <input type="file" id="import-attendance-file" accept="text/csv" style="margin-bottom:8px;">
                    <button type="button" class="btn-secondary" data-csv-load="import-attendance-file" data-csv-target="import-attendance-csv">Load CSV file</button>
                    <form method="post" style="display:block; margin-top:10px;">
                        <input type="hidden" name="action" value="import_attendance">
                        <label>CSV content</label>
                        <textarea id="import-attendance-csv" name="csv_text" rows="4" class="csv-textarea" placeholder="Paste the CSV rows here, or load a file with the button above. The first row is treated as the header."></textarea>
                        <button type="submit" class="btn-secondary">Import Attendance (CSV)</button>
                    </form>
                </div>
                <form method="get" style="margin-bottom:12px;">
                    <input type="hidden" name="page" value="1">
                    <input type="text" name="attendance_filter" value="<?= htmlspecialchars($attendanceFilter ?? '') ?>" placeholder="Filter attendance by student, type, or timestamp" style="margin-bottom:8px;">
                    <label>Date</label>
                    <input type="date" name="attendance_date_filter" value="<?= htmlspecialchars($attendanceDateFilter ?? '') ?>" style="margin-bottom:8px;">
                    <label>Grade Level</label>
                    <select name="attendance_grade_filter" id="attendance-grade-filter" style="width:auto; margin-bottom:8px;">
                        <option value="">All grade levels</option>
                        <?php foreach ($attendanceGradeOptions as $attendanceGradeOption): ?>
                            <option value="<?= htmlspecialchars($attendanceGradeOption) ?>" <?= $attendanceGradeFilter === $attendanceGradeOption ? 'selected' : '' ?>><?= htmlspecialchars($attendanceGradeOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Section</label>
                    <select name="attendance_section_filter" id="attendance-section-filter" style="width:auto; margin-bottom:8px;">
                        <option value="">All sections</option>
                        <?php foreach ($attendanceSectionMap as $attendanceSectionName => $attendanceSectionGrades): ?>
                            <option value="<?= htmlspecialchars($attendanceSectionName) ?>" data-grades="<?= htmlspecialchars(implode('|', $attendanceSectionGrades)) ?>" <?= $attendanceSectionFilter === $attendanceSectionName ? 'selected' : '' ?>><?= htmlspecialchars($attendanceSectionName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="actions">
                        <button type="submit">Filter</button>
                        <?php if (!empty($attendanceFilter) || !empty($attendanceGradeFilter) || !empty($attendanceSectionFilter) || !empty($attendanceDateFilter) && $attendanceDateFilter !== date('Y-m-d')): ?>
                            <a href="admin.php?page=1"><button type="button" class="btn-secondary">Clear</button></a>
                        <?php endif; ?>
                        <a href="admin.php?action=print_attendance&attendance_date_filter=<?= urlencode($attendanceDateFilter) ?>" target="_blank"><button type="button" class="btn-secondary">Print Attendance</button></a>
                    </div>
                </form>
                <?php if (empty($attendanceLogs)): ?>
                    <p class="muted">No attendance entries have been recorded yet.</p>
                <?php else: ?>
                    <form method="post" id="bulk-attendance-form">
                        <input type="hidden" name="action" value="bulk_delete_attendance">
                        <table style="width:100%; border-collapse: collapse;">
                            <thead>
                                <tr style="text-align:left; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:8px;"><input type="checkbox" id="attendance-select-all"></th>
                                    <th style="padding:8px;">Student</th>
                                    <th style="padding:8px;">Grade Level</th>
                                    <th style="padding:8px;">Section</th>
                                    <th style="padding:8px;">Type</th>
                                    <th style="padding:8px;">Timestamp</th>
                                    <th style="padding:8px;">Geotag</th>
                                    <th style="padding:8px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceLogs as $log): ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:8px;"><input type="checkbox" name="attendance_ids[]" value="<?= htmlspecialchars((string) $log['id']) ?>"></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($log['full_name']) ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($log['grade_level'] ?: '-') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($log['section'] ?: '-') ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($log['log_type']) ?></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($log['timestamp']) ?></td>
                                        <td style="padding:8px;"><?= isset($log['latitude'], $log['longitude']) && $log['latitude'] !== null && $log['longitude'] !== null ? htmlspecialchars(number_format((float) $log['latitude'], 6) . ', ' . number_format((float) $log['longitude'], 6)) : '&mdash;' ?></td>
                                        <td style="padding:8px;"><button type="submit" class="btn-secondary" formaction="admin.php?page=1" name="attendance_delete_id" value="<?= htmlspecialchars((string) $log['id']) ?>" onclick="return confirm('Delete this attendance entry? This cannot be undone.')">Delete</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="actions actions-bottom">
                            <button type="submit" class="btn-secondary" onclick="return confirm('Delete selected attendance entries? This cannot be undone.')">Delete Selected</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card admin-section" id="registered-teachers">
                <h2>Registered Teachers</h2>
                <p class="muted">Review teacher accounts and update their advisory details.</p>
                <div class="actions actions-bottom" style="margin-bottom:12px;">
                    <a href="admin.php?action=print_teachers" target="_blank"><button type="button" class="btn-secondary">Print Teachers</button></a>
                </div>
                <form method="get" style="margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="1">
                    <input type="text" name="teacher_name_filter" value="<?= htmlspecialchars($teacherNameFilter) ?>" placeholder="Filter by name" style="margin-bottom:0;">
                    <select name="teacher_grade_filter" id="teacher-grade-filter" style="width:auto; margin-bottom:0;">
                        <option value="">All grade levels</option>
                        <?php foreach ($gradeLevels as $gopt): ?>
                            <option value="<?= htmlspecialchars($gopt) ?>" <?= $teacherGradeFilter === $gopt ? 'selected' : '' ?>><?= htmlspecialchars($gopt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="teacher_section_filter" id="teacher-section-filter" style="width:auto; margin-bottom:0;">
                        <option value="">All sections</option>
                        <?php foreach ($sections as $sopt): ?>
                            <option value="<?= htmlspecialchars($sopt) ?>" <?= $teacherSectionFilter === $sopt ? 'selected' : '' ?>><?= htmlspecialchars($sopt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Filter</button>
                    <?php if ($teacherNameFilter !== '' || $teacherGradeFilter !== '' || $teacherSectionFilter !== ''): ?>
                        <a href="admin.php"><button type="button" class="btn-secondary">Clear</button></a>
                    <?php endif; ?>
                </form>
                <?php if (empty($registeredTeachers)): ?>
                    <p class="muted">No teachers have been registered yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #e2e8f0;">
                                <th style="padding:8px;">Name</th>
                                <th style="padding:8px;">Username</th>
                                <th style="padding:8px;">Grade</th>
                                <th style="padding:8px;">Section</th>
                                <th style="padding:8px;">Status</th>
                                <th style="padding:8px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registeredTeachers as $teacher): ?>
                                <tr style="border-bottom:1px solid #f1f5f9;" data-teacher-id="<?= (int) $teacher['id'] ?>" data-full-name="<?= htmlspecialchars($teacher['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-username="<?= htmlspecialchars($teacher['username'], ENT_QUOTES, 'UTF-8') ?>" data-grade-level="<?= htmlspecialchars($teacher['grade_level'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($teacher['section'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <td style="padding:8px;"><?= htmlspecialchars($teacher['full_name']) ?></td>
                                    <td style="padding:8px;"><?= htmlspecialchars($teacher['username']) ?></td>
                                    <td style="padding:8px;"><?= htmlspecialchars($teacher['grade_level'] ?? '-') ?></td>
                                    <td style="padding:8px;"><?= htmlspecialchars($teacher['section'] ?? '-') ?></td>
                                    <td style="padding:8px;"><?= (int) $teacher['is_approved'] === 1 ? 'Approved' : 'Pending approval' ?></td>
                                    <td style="padding:8px;">
                                        <?php if ((int) $teacher['is_approved'] !== 1): ?>
                                            <form method="post" style="display:inline-block;">
                                                <input type="hidden" name="action" value="approve_teacher">
                                                <input type="hidden" name="teacher_id" value="<?= (int) $teacher['id'] ?>">
                                                <button type="submit">Approve</button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" onclick="openEditTeacherModal(<?= (int) $teacher['id'] ?>)">Edit</button>
                                        <form method="post" style="display:inline-block; margin-left:6px;">
                                            <input type="hidden" name="action" value="delete_teacher">
                                            <input type="hidden" name="teacher_id" value="<?= (int) $teacher['id'] ?>">
                                            <button type="submit" class="btn-secondary" onclick="return confirm('Delete this teacher account? This cannot be undone.')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card admin-section" id="credential-requests">
                <h2>Student Credential Requests <?php if ($pendingCredentialRequests > 0): ?><span class="badge tardy" style="margin-left:8px;"><?= (int) $pendingCredentialRequests ?> pending</span><?php endif; ?></h2>
                <p class="muted">Teachers request student usernames and passwords. Approve to activate the student's login, or reject to dismiss the request.</p>
                <?php if (empty($credentialRequests)): ?>
                    <p class="muted">No credential requests have been submitted yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #e2e8f0;">
                                <th style="padding:8px;">Student</th>
                                <th style="padding:8px;">Grade &amp; Section</th>
                                <th style="padding:8px;">Requested Username</th>
                                <th style="padding:8px;">Requested By</th>
                                <th style="padding:8px;">Status</th>
                                <th style="padding:8px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credentialRequests as $credentialRequest): ?>
                                <tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:8px;"><?= htmlspecialchars($credentialRequest['student_name']) ?><div class="muted" style="font-size:11px;"><?= htmlspecialchars($credentialRequest['student_id']) ?></div></td>
                                    <td style="padding:8px;"><?= htmlspecialchars(trim(($credentialRequest['grade_level'] ?? '') . ' - ' . ($credentialRequest['section'] ?? ''), ' -')) ?></td>
                                    <td style="padding:8px;"><?= htmlspecialchars($credentialRequest['requested_username']) ?></td>
                                    <td style="padding:8px;"><?= htmlspecialchars($credentialRequest['requested_by'] ?: '-') ?></td>
                                    <td style="padding:8px;">
                                        <?php if ($credentialRequest['status'] === 'approved'): ?>
                                            <span class="badge on">Approved</span>
                                        <?php elseif ($credentialRequest['status'] === 'rejected'): ?>
                                            <span class="badge absent">Rejected</span>
                                        <?php else: ?>
                                            <span class="badge tardy">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:8px;">
                                        <?php if ($credentialRequest['status'] === 'pending'): ?>
                                            <form method="post" style="display:inline-block;">
                                                <input type="hidden" name="action" value="approve_credential_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $credentialRequest['id'] ?>">
                                                <button type="submit">Approve</button>
                                            </form>
                                            <form method="post" style="display:inline-block; margin-left:6px;">
                                                <input type="hidden" name="action" value="reject_credential_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $credentialRequest['id'] ?>">
                                                <button type="submit" class="btn-secondary" onclick="return confirm('Reject this credential request?')">Reject</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">Reviewed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card admin-section" id="registered-users">
                <h2>Registered Users</h2>
                <p class="muted">Manage existing users with edit, archive, or delete actions.</p>
                <div class="card-actions">
                    <a href="admin.php?action=print_students<?= $userFilter !== '' ? '&user_filter=' . urlencode($userFilter) : '' ?><?= $genderFilter !== '' ? '&gender_filter=' . urlencode($genderFilter) : '' ?><?= $gradeFilter !== '' ? '&grade_filter=' . urlencode($gradeFilter) : '' ?><?= $sectionFilter !== '' ? '&section_filter=' . urlencode($sectionFilter) : '' ?>" target="_blank"><button type="button" class="btn-secondary">Print Students</button></a>
                    <a href="admin.php?action=export_users"><button type="button">Export Users (CSV)</button></a>
                    <input type="file" id="import-users-file" accept="text/csv" style="margin-bottom:8px;">
                    <button type="button" class="btn-secondary" data-csv-load="import-users-file" data-csv-target="import-users-csv">Load CSV file</button>
                    <form method="post" style="display:block; margin-top:10px;">
                        <input type="hidden" name="action" value="import_users">
                        <label>CSV content</label>
                        <textarea id="import-users-csv" name="csv_text" rows="4" class="csv-textarea" placeholder="Paste the CSV rows here, or load a file with the button above. The first row is treated as the header."></textarea>
                        <button type="submit" class="btn-secondary">Import Users (CSV)</button>
                    </form>
                </div>
                <form method="get" style="margin-bottom: 12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="1">
                    <input type="text" name="user_filter" value="<?= htmlspecialchars($userFilter) ?>" placeholder="Filter by name, username, phone, or role" style="margin-bottom: 0;">
                    <select name="grade_filter" id="user-grade-filter" style="width:auto; margin-bottom:0;">
                        <option value="">All grades</option>
                        <?php foreach ($gradeLevels as $gopt): ?>
                            <option value="<?= htmlspecialchars($gopt) ?>" <?= $gradeFilter === $gopt ? 'selected' : '' ?>><?= htmlspecialchars($gopt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="section_filter" id="user-section-filter" style="width:auto; margin-bottom:0;">
                        <option value="">All sections</option>
                        <?php
                        $userSectionOptions = [];
                        if ($gradeFilter !== '') {
                            if (isset($dashboardSections[$gradeFilter]) && is_array($dashboardSections[$gradeFilter])) {
                                $userSectionOptions = array_values($dashboardSections[$gradeFilter]);
                            }
                            $userSectionOptions = array_values(array_unique(array_filter($userSectionOptions)));
                            sort($userSectionOptions, SORT_NATURAL | SORT_FLAG_CASE);
                        } else {
                            $userSectionOptions = array_values(array_unique(array_filter($sections)));
                            sort($userSectionOptions, SORT_NATURAL | SORT_FLAG_CASE);
                        }
                        if ($sectionFilter !== '' && !in_array($sectionFilter, $userSectionOptions, true)) {
                            $sectionFilter = '';
                        }
                        foreach ($userSectionOptions as $sopt): ?>
                            <option value="<?= htmlspecialchars($sopt) ?>" <?= $sectionFilter === $sopt ? 'selected' : '' ?>><?= htmlspecialchars($sopt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="gender_filter" style="width:auto; margin-bottom:0;">
                        <option value="">All genders</option>
                        <option value="Male" <?= $genderFilter === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $genderFilter === 'Female' ? 'selected' : '' ?>>Female</option>
                    </select>
                    <button type="submit">Filter</button>
                    <?php if ($userFilter !== '' || $genderFilter !== '' || $gradeFilter !== '' || $sectionFilter !== ''): ?>
                        <a href="admin.php?page=1"><button type="button" class="btn-secondary">Clear</button></a>
                    <?php endif; ?>
                </form>
                <p class="muted">Showing <?= count($users) ?> of <?= $totalUsers ?> matching users (up to 50 per page).</p>
                <?php if (empty($users)): ?>
                    <p class="muted"><?= $userFilter !== '' ? 'No users match the current filter.' : 'No users have been registered yet.' ?></p>
                <?php else: ?>
                    <div id="users-table-container">
                        <table style="width:100%; border-collapse: collapse;">
                            <thead>
                                <tr style="text-align:left; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:8px;"><input type="checkbox" id="users-select-all"></th>
                                    <th style="padding:8px;">Name</th>
                                    <th style="padding:8px;">Gender</th>
                                    <th style="padding:8px;">Username</th>
                                    <th style="padding:8px;">Phone</th>
                                    <th style="padding:8px;">Grade Level</th>
                                    <th style="padding:8px;">Section</th>
                                    <th style="padding:8px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;" data-student-id="<?= htmlspecialchars($user['student_id']) ?>" data-full-name="<?= htmlspecialchars($user['full_name']) ?>" data-gender="<?= htmlspecialchars($user['gender'] ?? '') ?>" data-username="<?= htmlspecialchars($user['username']) ?>" data-parent-phone="<?= htmlspecialchars($user['parent_phone']) ?>" data-grade-level="<?= htmlspecialchars($user['grade_level'] ?? '') ?>" data-section="<?= htmlspecialchars($user['section'] ?? '') ?>">
                                        <td style="padding:8px;"><input type="checkbox" name="user_ids[]" value="<?= htmlspecialchars($user['student_id']) ?>"></td>
                                        <td style="padding:8px;"><span class="user-fullname"><?= htmlspecialchars($user['full_name']) ?></span></td>
                                        <td style="padding:8px;"><?= htmlspecialchars($user['gender'] ?? '-') ?></td>
                                        <td style="padding:8px;"><span class="user-username"><?= htmlspecialchars($user['username']) ?></span></td>
                                        <td style="padding:8px;"><span class="user-phone"><?= htmlspecialchars($user['parent_phone']) ?></span></td>
                                        <td style="padding:8px;"><span class="user-grade"><?= htmlspecialchars($user['grade_level'] ?? '') ?></span></td>
                                        <td style="padding:8px;"><span class="user-section"><?= htmlspecialchars($user['section'] ?? '') ?></span></td>
                                        <td style="padding:8px;">
                                            <button type="button" onclick="openUserPreview('<?= htmlspecialchars($user['student_id'], ENT_QUOTES, 'UTF-8') ?>')">Preview</button>
                                            <button type="button" onclick="openEditModal('<?= htmlspecialchars($user['student_id']) ?>')">Edit</button>
                                            <div class="row-actions">
                                                <form method="post" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="archive_user">
                                                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($user['student_id']) ?>">
                                                    <button type="submit" class="btn-secondary">Archive</button>
                                                </form>
                                                <form method="post" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($user['student_id']) ?>">
                                                    <button type="submit" class="btn-secondary">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <form method="post" id="bulk-users-form">
                            <input type="hidden" name="action" value="bulk_delete_users">
                            <div class="actions actions-bottom">
                                <button type="submit" class="btn-secondary" id="bulk-users-delete-btn">Delete Selected</button>
                            </div>
                        </form>
                    <div class="pagination">
                        <a href="admin.php?page=1<?= $userFilter !== '' ? '&user_filter=' . urlencode($userFilter) : '' ?><?= $genderFilter !== '' ? '&gender_filter=' . urlencode($genderFilter) : '' ?><?= $gradeFilter !== '' ? '&grade_filter=' . urlencode($gradeFilter) : '' ?><?= $sectionFilter !== '' ? '&section_filter=' . urlencode($sectionFilter) : '' ?>"><button type="button" class="btn-secondary">First</button></a>
                        <a href="admin.php?page=<?= max(1, $page - 1) ?><?= $userFilter !== '' ? '&user_filter=' . urlencode($userFilter) : '' ?><?= $genderFilter !== '' ? '&gender_filter=' . urlencode($genderFilter) : '' ?><?= $gradeFilter !== '' ? '&grade_filter=' . urlencode($gradeFilter) : '' ?><?= $sectionFilter !== '' ? '&section_filter=' . urlencode($sectionFilter) : '' ?>"><button type="button" class="btn-secondary">Previous</button></a>
                        <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
                        <a href="admin.php?page=<?= min($totalPages, $page + 1) ?><?= $userFilter !== '' ? '&user_filter=' . urlencode($userFilter) : '' ?><?= $genderFilter !== '' ? '&gender_filter=' . urlencode($genderFilter) : '' ?><?= $gradeFilter !== '' ? '&grade_filter=' . urlencode($gradeFilter) : '' ?><?= $sectionFilter !== '' ? '&section_filter=' . urlencode($sectionFilter) : '' ?>"><button type="button" class="btn-secondary">Next</button></a>
                        <a href="admin.php?page=<?= $totalPages ?><?= $userFilter !== '' ? '&user_filter=' . urlencode($userFilter) : '' ?><?= $genderFilter !== '' ? '&gender_filter=' . urlencode($genderFilter) : '' ?><?= $gradeFilter !== '' ? '&grade_filter=' . urlencode($gradeFilter) : '' ?><?= $sectionFilter !== '' ? '&section_filter=' . urlencode($sectionFilter) : '' ?>"><button type="button" class="btn-secondary">Last</button></a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var attAll = document.getElementById('attendance-select-all');
            if (attAll) {
                attAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('input[name="attendance_ids[]"]');
                    checkboxes.forEach(function (cb) { cb.checked = attAll.checked; });
                });
            }
            var usersAll = document.getElementById('users-select-all');
            if (usersAll) {
                usersAll.addEventListener('change', function () {
                    var checkboxes = document.querySelectorAll('input[name="user_ids[]"]');
                    checkboxes.forEach(function (cb) { cb.checked = usersAll.checked; });
                });
            }
            var bulkUsersForm = document.getElementById('bulk-users-form');
            if (bulkUsersForm) {
                bulkUsersForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var checked = Array.from(document.querySelectorAll('input[name="user_ids[]"]:checked')).map(function (cb) { return cb.value; });
                    if (checked.length === 0) {
                        alert('Please select at least one user to delete.');
                        return;
                    }
                    if (!confirm('Delete selected users? This cannot be undone.')) return;
                    // remove previous generated inputs
                    Array.from(bulkUsersForm.querySelectorAll('input[name="user_ids[]"]')).forEach(function (n) { n.remove(); });
                    checked.forEach(function (val) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'user_ids[]';
                        inp.value = val;
                        bulkUsersForm.appendChild(inp);
                    });
                    bulkUsersForm.submit();
                });
            }
                // Preserve scroll position across form submissions
                function saveScrollY() {
                    try {
                        sessionStorage.setItem('attsys_scrollY', String(window.scrollY || 0));
                    } catch (e) {
                        // ignore storage errors
                    }
                }

                // Save scroll position on any form submit
                document.addEventListener('submit', function () {
                    saveScrollY();
                }, true);

                // Restore scroll position after load if available
                try {
                    var saved = sessionStorage.getItem('attsys_scrollY');
                    if (saved !== null) {
                        setTimeout(function () {
                            window.scrollTo(0, parseInt(saved, 10) || 0);
                            try { sessionStorage.removeItem('attsys_scrollY'); } catch (e) {}
                        }, 30);
                    }
                } catch (e) {}
        });
    </script>
    <script>
        (function(){
            var links = document.querySelectorAll('.sidebar nav a');
            var sections = document.querySelectorAll('.admin-section');
            function showSection(id) {
                sections.forEach(function(s){
                    if (s.id === id) {
                        s.classList.add('active');
                        s.scrollIntoView({behavior:'smooth', block:'start'});
                    } else {
                        s.classList.remove('active');
                    }
                });
                links.forEach(function(a){
                    a.classList.toggle('active', a.getAttribute('href') === '#' + id);
                });
            }

            links.forEach(function(a){
                a.addEventListener('click', function(e){
                    e.preventDefault();
                    var target = (a.getAttribute('href') || '').replace('#','');
                    if (!target) return;
                    history.replaceState(null, '', '#' + target);
                    showSection(target);
                });
            });

            // If hash present on load, show that section
            var initial = window.location.hash ? window.location.hash.replace('#','') : '';
            if (initial) {
                showSection(initial);
            } else {
                showSection('dashboard');
            }

            // Also expose a helper to show from other buttons
            window.showAdminSection = function(id){
                history.replaceState(null, '', '#' + id);
                showSection(id);
            };
        })();
    </script>
    <div id="grade-section-edit-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:480px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Edit Grade Level and Section</h3>
            <form method="post">
                <input type="hidden" name="action" value="edit_grade_section">
                <input type="hidden" name="grade_section_id" id="edit-grade-section-id">
                <label for="edit-grade-level">Grade Level</label>
                <input type="text" name="grade_level" id="edit-grade-level" required>
                <label for="edit-section">Section</label>
                <input type="text" name="section" id="edit-section" required>
                <div class="actions actions-bottom" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeGradeSectionEdit()">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openGradeSectionEdit(id, gradeLevel, section) {
            document.getElementById('edit-grade-section-id').value = id;
            document.getElementById('edit-grade-level').value = gradeLevel;
            document.getElementById('edit-section').value = section;
            document.getElementById('grade-section-edit-modal').style.display = 'flex';
        }

        function closeGradeSectionEdit() {
            document.getElementById('grade-section-edit-modal').style.display = 'none';
        }

        document.addEventListener('click', function (e) {
            var modal = document.getElementById('grade-section-edit-modal');
            if (modal && e.target === modal) closeGradeSectionEdit();
        });
    </script>
    <!-- Edit User Modal -->
            <div id="edit-user-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:480px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Edit User</h3>
            <form id="edit-user-form" method="post">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="student_id" id="modal-student-id">
                <label>Full Name</label>
                <input type="text" name="full_name" id="modal-full-name" required>
                <label>Username</label>
                <input type="text" name="username" id="modal-username" required>
                <label>Password</label>
                <input type="password" name="password" id="modal-password" placeholder="Leave blank to keep current password">
                <label>Gender</label>
                <select name="gender" id="modal-gender" required>
                    <option value="">Select gender</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
                <label>Parent Phone</label>
                <input type="text" name="parent_phone" id="modal-parent-phone" required>
                <label>Grade Level</label>
                <select name="grade_level" id="modal-grade-level" required>
                    <option value="">Select grade level</option>
                    <?php foreach ($gradeLevels as $gradeLevelOption): ?>
                        <option value="<?= htmlspecialchars($gradeLevelOption) ?>"><?= htmlspecialchars($gradeLevelOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Section</label>
                <select name="section" id="modal-section" required>
                    <option value="">Select section</option>
                    <?php foreach ($sections as $sectionOption): ?>
                        <option value="<?= htmlspecialchars($sectionOption) ?>"><?= htmlspecialchars($sectionOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="actions actions-bottom" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openEditModal(studentId) {
            var row = document.querySelector('tr[data-student-id="' + studentId + '"]');
            if (!row) return alert('User row not found');
            var fullName = row.dataset.fullName || '';
            var gender = row.dataset.gender || '';
            var username = row.dataset.username || '';
            var parentPhone = row.dataset.parentPhone || '';
            var gradeLevel = row.dataset.gradeLevel || '';
            var section = row.dataset.section || '';

            document.getElementById('modal-student-id').value = studentId;
            document.getElementById('modal-full-name').value = fullName;
            document.getElementById('modal-gender').value = gender;
            document.getElementById('modal-username').value = username;
            document.getElementById('modal-password').value = '';
            document.getElementById('modal-parent-phone').value = parentPhone;
            document.getElementById('modal-grade-level').value = gradeLevel;
            updateEditUserSections(section);

            var modal = document.getElementById('edit-user-modal');
            modal.style.display = 'flex';
        }

        function closeEditModal() {
            var modal = document.getElementById('edit-user-modal');
            modal.style.display = 'none';
        }

        // Close modal when clicking backdrop
        document.addEventListener('click', function (e) {
            var modal = document.getElementById('edit-user-modal');
            if (!modal) return;
            if (e.target === modal) closeEditModal();
        });

        // Update visible table row after successful form submit (simple approach: submit normally and rely on refresh)
        // Optionally could implement AJAX here.
    </script>
    <!-- Edit Teacher Modal -->
    <div id="edit-teacher-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:480px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Edit Teacher</h3>
            <form id="edit-teacher-form" method="post">
                <input type="hidden" name="action" value="edit_teacher">
                <input type="hidden" name="teacher_id" id="modal-teacher-id">
                <label>Full Name</label>
                <input type="text" name="full_name" id="modal-teacher-full-name" required>
                <label>Username</label>
                <input type="text" name="username" id="modal-teacher-username" required>
                <label>Password</label>
                <input type="password" name="password" id="modal-teacher-password" placeholder="Leave blank to keep current password">
                <label>Grade Level</label>
                <select name="grade_level" id="modal-teacher-grade-level">
                    <option value="">Select grade level</option>
                    <?php foreach ($gradeLevels as $gradeLevelOption): ?>
                        <option value="<?= htmlspecialchars($gradeLevelOption) ?>"><?= htmlspecialchars($gradeLevelOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Section</label>
                <select name="section" id="modal-teacher-section">
                    <option value="">Select section</option>
                    <?php foreach ($sections as $sectionOption): ?>
                        <option value="<?= htmlspecialchars($sectionOption) ?>"><?= htmlspecialchars($sectionOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="actions actions-bottom" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeEditTeacherModal()">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openEditTeacherModal(teacherId) {
            var row = document.querySelector('tr[data-teacher-id="' + teacherId + '"]');
            if (!row) return alert('Teacher row not found');

            document.getElementById('modal-teacher-id').value = teacherId;
            document.getElementById('modal-teacher-full-name').value = row.getAttribute('data-full-name') || '';
            document.getElementById('modal-teacher-username').value = row.getAttribute('data-username') || '';
            document.getElementById('modal-teacher-password').value = '';
            document.getElementById('modal-teacher-grade-level').value = row.getAttribute('data-grade-level') || '';
            document.getElementById('modal-teacher-section').value = row.getAttribute('data-section') || '';

            var modal = document.getElementById('edit-teacher-modal');
            if (modal) modal.style.display = 'flex';
        }

        function closeEditTeacherModal() {
            var modal = document.getElementById('edit-teacher-modal');
            if (modal) modal.style.display = 'none';
        }

        document.addEventListener('click', function (e) {
            var modal = document.getElementById('edit-teacher-modal');
            if (!modal) return;
            if (e.target === modal) closeEditTeacherModal();
        });
    </script>

    <!-- Account Settings Modal -->
    <div id="account-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:520px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Account Settings</h3>
            <form id="account-form" method="post">
                <input type="hidden" name="action" value="update_admin">
                <label>Current Password (required)</label>
                <input type="password" name="current_password" required>

                <label>New Full Name (optional)</label>
                <input type="text" name="new_full_name" placeholder="Your display name">

                <label>New Username (optional)</label>
                <input type="text" name="new_username" placeholder="Change username">

                <label>New Password (optional)</label>
                <input type="password" name="new_password" placeholder="New password">

                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" placeholder="Confirm new password">

                <div class="actions actions-bottom" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeAccountModal()">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openAccountModal() {
            var modal = document.getElementById('account-modal');
            if (!modal) return;
            modal.style.display = 'flex';
        }

        function closeAccountModal() {
            var modal = document.getElementById('account-modal');
            if (!modal) return;
            modal.style.display = 'none';
        }

        document.addEventListener('click', function (e) {
            var modal = document.getElementById('account-modal');
            if (!modal) return;
            if (e.target === modal) closeAccountModal();
        });
    </script>
    <!-- Create User Modal -->
    <div id="create-user-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:520px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">Create User</h3>
            <form id="create-user-form" method="post">
                <input type="hidden" name="action" value="create_user">
                <label>Full Name</label>
                <input type="text" name="full_name" required>
                <label>Username</label>
                <input type="text" name="username" required>
                <label>Password</label>
                <input type="password" name="password" required>
                <div class="learner-fields-row">
                    <div>
                        <label>Gender</label>
                        <select name="gender" required>
                            <option value="">Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label>Grade Level</label>
                        <select name="grade_level" id="create-grade-level" required>
                            <option value="">Select grade level</option>
                            <?php foreach ($gradeLevels as $gradeLevelOption): ?>
                                <option value="<?= htmlspecialchars($gradeLevelOption) ?>"><?= htmlspecialchars($gradeLevelOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Section</label>
                        <select name="section" id="create-section" required>
                            <option value="">Select section</option>
                        </select>
                    </div>
                </div>
                <label>Parent Phone</label>
                <input type="text" name="parent_phone" placeholder="Required" required>
                <div class="actions actions-bottom" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeCreateUser()">Cancel</button>
                    <button type="submit">Create User & Generate QR</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Create Result Modal (shows QR and details) -->
    <div id="create-result-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:560px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">User Created</h3>
            <div id="create-result-content" class="create-result-content">
                <div id="create-result-qr"></div>
                <div id="create-result-info" class="create-result-info"></div>
            </div>
            <div class="actions actions-bottom" style="justify-content:flex-end;">
                <button type="button" onclick="saveCreatedUserAsPicture()">Save as Picture</button>
                <button type="button" onclick="saveCreatedUserDetails()">Save QR and Details</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('create-result-modal').style.display='none'; window.location.reload();">Done</button>
            </div>
        </div>
    </div>
    <div id="user-preview-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center;">
        <div style="background:#fff; padding:18px; border-radius:10px; max-width:560px; width:100%; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
            <h3 style="margin-top:0;">User Preview</h3>
            <div class="create-result-content">
                <div id="user-preview-qr"></div>
                <div id="user-preview-info" class="create-result-info"></div>
            </div>
            <div class="actions actions-bottom" style="justify-content:flex-end;">
                <button type="button" onclick="savePreviewUserAsPicture()">Save as Picture</button>
                <button type="button" class="btn-secondary" onclick="closeUserPreview()">Close</button>
            </div>
        </div>
    </div>
    <script>
        function openUserPreview(studentId) {
            var row = Array.prototype.find.call(document.querySelectorAll('tr[data-student-id]'), function (candidate) {
                return candidate.dataset.studentId === studentId;
            });
            if (!row) return alert('User row not found');

            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(studentId);
            previewUserForSaving = {
                student_id: studentId,
                full_name: row.dataset.fullName,
                username: row.dataset.username,
                parent_phone: row.dataset.parentPhone,
                grade_level: row.dataset.gradeLevel,
                section: row.dataset.section,
                qr_url: qrUrl
            };
            var previewQr = document.getElementById('user-preview-qr');
            var previewInfo = document.getElementById('user-preview-info');
            previewQr.innerHTML = '<img src="' + qrUrl + '" alt="QR code" style="max-width:180px; border:1px solid #e2e8f0; padding:8px; border-radius:6px;">';
            previewInfo.textContent = '';
            [
                ['Student ID', studentId],
                ['Full Name', row.dataset.fullName],
                ['Username', row.dataset.username],
                ['Parent Phone', row.dataset.parentPhone],
                ['Grade Level', row.dataset.gradeLevel || '-'],
                ['Section', row.dataset.section || '-']
            ].forEach(function (detail) {
                var paragraph = document.createElement('p');
                var label = document.createElement('strong');
                label.textContent = detail[0] + ': ';
                paragraph.appendChild(label);
                paragraph.appendChild(document.createTextNode(detail[1] || ''));
                previewInfo.appendChild(paragraph);
            });
            document.getElementById('user-preview-modal').style.display = 'flex';
        }

        function closeUserPreview() {
            document.getElementById('user-preview-modal').style.display = 'none';
        }

        document.addEventListener('click', function (e) {
            var modal = document.getElementById('user-preview-modal');
            if (modal && e.target === modal) closeUserPreview();
        });
    </script>
    <script>
        var gradeSectionOptions = <?= json_encode($gradeSections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function updateCreateUserSections() {
            var gradeSelect = document.getElementById('create-grade-level');
            var sectionSelect = document.getElementById('create-section');
            if (!gradeSelect || !sectionSelect) return;

            var selectedGrade = gradeSelect.value;
            sectionSelect.innerHTML = '<option value="">Select section</option>';
            gradeSectionOptions.forEach(function (gradeSection) {
                if (gradeSection.grade_level !== selectedGrade) return;
                var option = document.createElement('option');
                option.value = gradeSection.section;
                option.textContent = gradeSection.section;
                sectionSelect.appendChild(option);
            });
            sectionSelect.disabled = selectedGrade === '' || sectionSelect.options.length === 1;
        }

        function updateEditUserSections(selectedSection) {
            var gradeSelect = document.getElementById('modal-grade-level');
            var sectionSelect = document.getElementById('modal-section');
            if (!gradeSelect || !sectionSelect) return;

            var selectedGrade = gradeSelect.value;
            sectionSelect.innerHTML = '<option value="">Select section</option>';
            gradeSectionOptions.forEach(function (gradeSection) {
                if (gradeSection.grade_level !== selectedGrade) return;
                var option = document.createElement('option');
                option.value = gradeSection.section;
                option.textContent = gradeSection.section;
                option.selected = gradeSection.section === selectedSection;
                sectionSelect.appendChild(option);
            });
            sectionSelect.disabled = selectedGrade === '' || sectionSelect.options.length === 1;
        }

        document.getElementById('create-grade-level').addEventListener('change', updateCreateUserSections);
        document.getElementById('modal-grade-level').addEventListener('change', function () {
            updateEditUserSections('');
        });

        (function () {
            var dashboardGradeSelect = document.getElementById('dashboard-grade');
            var dashboardSectionSelect = document.getElementById('dashboard-section');
            if (!dashboardGradeSelect || !dashboardSectionSelect) return;

            function updateDashboardSections() {
                var selectedGrade = dashboardGradeSelect.value;
                Array.prototype.forEach.call(dashboardSectionSelect.options, function (option) {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }
                    var grades = (option.getAttribute('data-grades') || '').split('|');
                    option.hidden = selectedGrade !== '' && grades.indexOf(selectedGrade) === -1;
                });
                if (dashboardSectionSelect.selectedOptions.length && dashboardSectionSelect.selectedOptions[0].hidden) {
                    dashboardSectionSelect.value = '';
                }
            }

            dashboardGradeSelect.addEventListener('change', updateDashboardSections);
            updateDashboardSections();
        })();

        (function () {
            var attendanceGradeSelect = document.getElementById('attendance-grade-filter');
            var attendanceSectionSelect = document.getElementById('attendance-section-filter');
            if (!attendanceGradeSelect || !attendanceSectionSelect) return;

            function updateAttendanceSections() {
                var selectedGrade = attendanceGradeSelect.value;
                Array.prototype.forEach.call(attendanceSectionSelect.options, function (option) {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }
                    var grades = (option.getAttribute('data-grades') || '').split('|');
                    option.hidden = selectedGrade !== '' && grades.indexOf(selectedGrade) === -1;
                });
                if (attendanceSectionSelect.selectedOptions.length && attendanceSectionSelect.selectedOptions[0].hidden) {
                    attendanceSectionSelect.value = '';
                }
            }

            attendanceGradeSelect.addEventListener('change', updateAttendanceSections);
            updateAttendanceSections();
        })();

        var createdUserForSaving = null;
        var previewUserForSaving = null;

        function escapePrintValue(value) {
            return String(value || '').replace(/[&<>\"']/g, function (character) {
                return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[character];
            });
        }

        function saveCreatedUserDetails() {
            if (!createdUserForSaving) return alert('No created user details are available.');
            var created = createdUserForSaving;
            var printWindow = window.open('', '_blank');
            if (!printWindow) return alert('Please allow pop-ups to save the QR and details.');
            printWindow.document.write('<!DOCTYPE html><html><head><title>User QR and Details</title><style>body{font-family:Segoe UI,system-ui,sans-serif;padding:24px;color:#14241b}h1{font-size:22px;color:#065f46}img{width:240px;height:240px;border:1px solid #a7f3d0;padding:8px}p{margin:8px 0}</style></head><body>' +
                '<h1>User QR and Details</h1>' +
                '<img src="' + escapePrintValue(created.qr_url) + '" alt="QR code">' +
                '<p><strong>Student ID:</strong> ' + escapePrintValue(created.student_id) + '</p>' +
                '<p><strong>Full Name:</strong> ' + escapePrintValue(created.full_name) + '</p>' +
                '<p><strong>Username:</strong> ' + escapePrintValue(created.username) + '</p>' +
                '<p><strong>Grade Level:</strong> ' + escapePrintValue(created.grade_level) + '</p>' +
                '<p><strong>Section:</strong> ' + escapePrintValue(created.section) + '</p>' +
                '<p><strong>Parent Phone:</strong> ' + escapePrintValue(created.parent_phone) + '</p>' +
                '</body></html>');
            printWindow.document.close();
            printWindow.focus();
            printWindow.onload = function () { printWindow.print(); };
        }

        function saveUserAsPicture(user) {
            var qrImage = new Image();
            qrImage.crossOrigin = 'anonymous';
            qrImage.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = 900;
                canvas.height = 760;
                var context = canvas.getContext('2d');
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#1f2937';
                context.font = 'bold 32px Arial';
                context.fillText('User QR and Details', 48, 58);
                context.drawImage(qrImage, 48, 92, 300, 300);
                context.font = '22px Arial';
                var details = [
                    ['Student ID', user.student_id],
                    ['Full Name', user.full_name],
                    ['Username', user.username],
                    ['Grade Level', user.grade_level],
                    ['Section', user.section],
                    ['Parent Phone', user.parent_phone]
                ];
                details.forEach(function (detail, index) {
                    context.font = 'bold 22px Arial';
                    context.fillText(detail[0] + ':', 400, 130 + (index * 52));
                    context.font = '22px Arial';
                    context.fillText(String(detail[1] || '-'), 400, 158 + (index * 52));
                });
                var link = document.createElement('a');
                link.download = (user.student_id || 'student') + '-qr-details.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            };
            qrImage.onerror = function () {
                alert('The QR image could not be loaded. Check your internet connection and try again.');
            };
            qrImage.src = user.qr_url;
        }

        function saveCreatedUserAsPicture() {
            if (!createdUserForSaving) return alert('No created user details are available.');
            saveUserAsPicture(createdUserForSaving);
        }

        function savePreviewUserAsPicture() {
            if (!previewUserForSaving) return alert('No preview user details are available.');
            saveUserAsPicture(previewUserForSaving);
        }

        function openCreateUser() {
            var modal = document.getElementById('create-user-modal');
            if (!modal) return;
            modal.style.display = 'flex';
        }

        function closeCreateUser() {
            var modal = document.getElementById('create-user-modal');
            if (!modal) return;
            modal.style.display = 'none';
        }

        document.addEventListener('click', function (e) {
            var modal = document.getElementById('create-user-modal');
            if (!modal) return;
            if (e.target === modal) closeCreateUser();
        });
    </script>
    <script>
        // AJAX submit for create user form
        (function () {
            var form = document.getElementById('create-user-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fullName = form.querySelector('input[name="full_name"]').value.trim();
                var username = form.querySelector('input[name="username"]').value.trim();
                var password = form.querySelector('input[name="password"]').value.trim();
                var grade = form.querySelector('[name="grade_level"]').value.trim();
                var section = form.querySelector('[name="section"]').value.trim();
                var phone = form.querySelector('input[name="parent_phone"]').value.trim();
                if (!fullName || !username || !password || !grade || !section || !phone) {
                    alert('Please fill in all required fields.');
                    return;
                }
                var fd = new FormData(form);
                // Send as urlencoded: the host returns 403 Forbidden for multipart/form-data.
                var params = new URLSearchParams();
                fd.forEach(function (value, key) { params.append(key, value); });
                fetch('admin.php', {
                    method: 'POST',
                    body: params.toString(),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (!json || !json.ok) {
                            alert((json && json.message) ? json.message : 'Failed to create user');
                            return;
                        }
                        // show result modal with QR and details
                        var created = json.createdUser;
                        createdUserForSaving = created;
                        var qrDiv = document.getElementById('create-result-qr');
                        var infoDiv = document.getElementById('create-result-info');
                        qrDiv.innerHTML = '<img src="' + (created.qr_url || '') + '" style="max-width:180px; border:1px solid #e2e8f0; padding:8px; border-radius:6px;">';
                        infoDiv.innerHTML = '<p><strong>Student ID:</strong> ' + (created.student_id || '') + '</p>' +
                                            '<p><strong>Full Name:</strong> ' + (created.full_name || '') + '</p>' +
                                            '<p><strong>Username:</strong> ' + (created.username || '') + '</p>' +
                                            '<p><strong>Password:</strong> ' + (created.password || '') + '</p>';
                        document.getElementById('create-user-modal').style.display = 'none';
                        document.getElementById('create-result-modal').style.display = 'flex';
                    }).catch(function (err) { console.error(err); alert('Request failed'); });
            });
        })();
    </script>
    <script>
        // Client-side validation and AJAX submit for account form
        (function () {
            var form = document.getElementById('account-form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var current = form.querySelector('input[name="current_password"]').value.trim();
                var newPass = form.querySelector('input[name="new_password"]').value.trim();
                var confirm = form.querySelector('input[name="confirm_password"]').value.trim();
                var newUser = form.querySelector('input[name="new_username"]').value.trim();
                if (current === '') { alert('Current password is required.'); return; }
                if (newPass !== '' && newPass !== confirm) { alert('New password and confirmation do not match.'); return; }

                var formData = new FormData(form);
                // Send as urlencoded: the host returns 403 Forbidden for multipart/form-data.
                var params = new URLSearchParams();
                formData.forEach(function (value, key) { params.append(key, value); });

                fetch('admin.php', {
                    method: 'POST',
                    body: params.toString(),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    }
                }).then(function (res) {
                    return res.json();
                }).then(function (json) {
                    if (!json) { alert('Unexpected server response'); return; }
                    if (!json.ok) {
                        alert(json.message || 'Update failed');
                        return;
                    }
                    if (json.need_relogin) {
                        alert(json.message || 'Settings updated. Please log in again.');
                        window.location.href = 'admin.php';
                        return;
                    }
                    // success without re-login: update displayed admin name
                    var nameElem = document.querySelector('.card h2');
                    if (nameElem && form.querySelector('input[name="new_full_name"]').value.trim() !== '') {
                        nameElem.textContent = 'Welcome, ' + form.querySelector('input[name="new_full_name"]').value.trim();
                    }
                    // show temporary message
                    var msg = document.createElement('div');
                    msg.className = 'message success';
                    msg.textContent = json.message || 'Account updated';
                    var panel = document.querySelector('.panel');
                    if (panel) panel.insertBefore(msg, panel.firstChild.nextSibling);
                    setTimeout(function () { try { msg.remove(); } catch (e) {} }, 4000);
                }).catch(function (err) {
                    console.error(err);
                    alert('Request failed');
                });
            });
        })();
    </script>
    <script>
        // CSV import helper.
        // The hosting web server answers every multipart/form-data POST with a
        // 403 Forbidden page, so the selected file is read here in the browser
        // and the CSV text is posted as a normal form field instead.
        (function () {
            document.querySelectorAll('[data-csv-load]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var fileInput = document.getElementById(button.getAttribute('data-csv-load'));
                    var target = document.getElementById(button.getAttribute('data-csv-target'));
                    if (!fileInput || !target) return;
                    if (!fileInput.files || !fileInput.files[0]) {
                        alert('Choose a CSV file first.');
                        return;
                    }
                    var reader = new FileReader();
                    reader.onload = function (event) {
                        target.value = event.target.result;
                        target.focus();
                    };
                    reader.onerror = function () {
                        alert('Could not read the selected file.');
                    };
                    reader.readAsText(fileInput.files[0]);
                });
            });
        })();
    </script>
    <script>
        function toggleSidebar() {
            var sidebar = document.getElementById('siteSidebar');
            var toggle = document.querySelector('.sidebar-toggle');
            if (!sidebar) return;
            var isOpen = sidebar.classList.toggle('open');
            if (toggle) toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        document.addEventListener('click', function (event) {
            var sidebar = document.getElementById('siteSidebar');
            var toggle = document.querySelector('.sidebar-toggle');
            if (!sidebar || window.innerWidth > 900 || !sidebar.classList.contains('open')) return;
            if (!sidebar.contains(event.target) && event.target !== toggle) {
                sidebar.classList.remove('open');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>
</html>
`