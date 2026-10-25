<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'db.php';
require_once 'admin_helpers.php';

$message = '';
$messageType = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login_student') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $message = 'Please enter username and password.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare('SELECT student_id, full_name, password_hash FROM students WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if ($row['password_hash'] && password_verify($password, $row['password_hash'])) {
                    $_SESSION['student_id'] = $row['student_id'];
                    $_SESSION['student_name'] = $row['full_name'];
                    $message = 'Logged in successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Invalid credentials.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Student account not found.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'logout_student') {
        session_unset();
        session_destroy();
        session_start();
        $message = 'Logged out.';
        $messageType = 'success';
    }
}

$loggedIn = !empty($_SESSION['student_id']);
$studentDetails = null;

if ($loggedIn) {
    $studentDetailsStmt = $conn->prepare('SELECT student_id, full_name, gender, grade_level, section, grade_section, parent_phone, username FROM students WHERE student_id = ? LIMIT 1');
    $studentDetailsStmt->bind_param('s', $_SESSION['student_id']);
    $studentDetailsStmt->execute();
    $studentDetails = $studentDetailsStmt->get_result()->fetch_assoc();
}

// attendance view data
$amTardyAfterMinutes = getAttendanceSetting($conn, 'am_tardy_after_minutes', 495);
$attendance_cutoff = $_GET['cutoff'] ?? formatMinutesToTime($amTardyAfterMinutes);
$attendance_days = [];
if ($loggedIn) {
    $sid = $_SESSION['student_id'];

    // date-range and pagination parameters (GET)
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';
    $per_page = isset($_GET['per_page']) ? max(5, (int)$_GET['per_page']) : 14;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

    // default range: last 21 days
    if ($start === '' && $end === '') {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-20 days', strtotime($end)));
    }

    // normalize and validate dates
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false) $startTs = strtotime(date('Y-m-d', strtotime('-20 days')));
    if ($endTs === false) $endTs = strtotime(date('Y-m-d'));
    if ($startTs > $endTs) { $tmp = $startTs; $startTs = $endTs; $endTs = $tmp; }

    // build full list of dates in range
    $all_days = [];
    for ($d = $startTs; $d <= $endTs; $d = strtotime('+1 day', $d)) {
        $all_days[] = date('Y-m-d', $d);
    }

    $total = count($all_days);
    $pages = max(1, (int) ceil($total / $per_page));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $per_page;

    $visible_days = array_slice($all_days, $offset, $per_page);

    $attendanceSummary = ['On time' => 0, 'Tardy' => 0, 'Absent' => 0];
    foreach ($all_days as $d) {
        if (!isSchoolDay($conn, $d)) {
            continue;
        }

        $q = $conn->prepare("SELECT log_type, timestamp FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(timestamp) = ? ORDER BY timestamp ASC");
        $q->bind_param('ss', $sid, $d);
        $q->execute();
        $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
        $result = $q->get_result();
        while ($result && ($punch = $result->fetch_assoc())) {
            $punchType = $punch['log_type'];
            if ($punches[$punchType] === null) {
                $punches[$punchType] = $punch['timestamp'];
            }
        }

        $amInTime = $punches['AM_IN'];
        if (! $amInTime) {
            $attendanceSummary['Absent']++;
        } else {
            $timeOnly = date('H:i:s', strtotime($amInTime));
            $status = (strtotime($timeOnly) <= strtotime($attendance_cutoff)) ? 'On time' : 'Tardy';
            $attendanceSummary[$status]++;
        }
    }

    foreach ($visible_days as $d) {
        if (!isSchoolDay($conn, $d)) {
            $attendance_days[$d] = ['status' => 'Weekend', 'time' => null, 'am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => null];
            continue;
        }

        $q = $conn->prepare("SELECT log_type, timestamp FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(timestamp) = ? ORDER BY timestamp ASC");
        $q->bind_param('ss', $sid, $d);
        $q->execute();
        $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
        $result = $q->get_result();
        while ($result && ($punch = $result->fetch_assoc())) {
            $punchType = $punch['log_type'];
            if ($punches[$punchType] === null) {
                $punches[$punchType] = $punch['timestamp'];
            }
        }

        $amInTime = $punches['AM_IN'];
        if (! $amInTime) {
            $attendance_days[$d] = ['status' => 'Absent', 'time' => null, 'am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => null];
        } else {
            $timeOnly = date('H:i:s', strtotime($amInTime));
            $attendance_days[$d] = [
                'status' => (strtotime($timeOnly) <= strtotime($attendance_cutoff)) ? 'On time' : 'Tardy',
                'time' => $amInTime,
                'am_in' => $punches['AM_IN'],
                'am_out' => $punches['AM_OUT'],
                'pm_in' => $punches['PM_IN'],
                'pm_out' => $punches['PM_OUT'],
            ];
        }
    }

    // expose paging vars for template
    $template_pagination = [
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'per_page' => $per_page,
        'start' => date('Y-m-d', $startTs),
        'end' => date('Y-m-d', $endTs),
    ];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Dashboard</title>
    <style>
        body {
            background:
                radial-gradient(900px 420px at 100% 0%, rgba(16,185,129,0.14), transparent),
                radial-gradient(760px 380px at -10% 100%, rgba(6,95,70,0.10), transparent),
                #eef7f0;
            background-attachment: fixed;
            min-height: 100vh;
            margin: 0;
            padding: 28px;
            display: flex;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: #14241b;
        }
        .container {
            max-width: 1160px;
            width: 100%;
            background: #fff;
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 4px 16px rgba(2,44,34,0.1);
            border: 1px solid rgba(16,185,129,0.14);
        }
        h1 { margin: 0 0 6px; color: #065f46; font-size: 26px; font-weight: 800; letter-spacing: -0.3px; }
        h2 { margin: 0 0 10px; color: #064e3b; font-weight: 700; font-size: 20px; }
        .page-sub { color: #6b7280; margin: 0 0 16px; font-size: 14px; }
        .page-actions { margin-bottom: 20px; }
        a.button, button.button, button {
            display: inline-block;
            margin-right: 8px;
            padding: 10px 18px;
            background: linear-gradient(180deg,#10b981,#059669 55%,#047857);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            border: 0;
            cursor: pointer;
            font-weight: 600;
            font-family: inherit;
            font-size: 14px;
            box-shadow: 0 1px 2px rgba(2,44,34,0.06);
            transition: all .2s ease;
        }
        a.button:hover, button.button:hover, button:hover {
            background: linear-gradient(180deg,#059669,#047857 60%,#064e3b);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(16,185,129,0.35);
        }
        a.button.logout, button.button.logout, button.logout {
            background: linear-gradient(180deg,#f43f5e,#e11d48 60%,#be123c);
        }
        a.button.logout:hover, button.button.logout:hover, button.logout:hover {
            box-shadow: 0 8px 24px rgba(225,29,72,0.35);
        }
        .card {
            background: #fff;
            border: 1px solid #e6f0ea;
            border-radius: 12px;
            padding: 20px;
            margin-top: 12px;
        }
        .card h2 { margin-top: 2px; }
        .card::before {
            content: '';
            display: block;
            height: 3px;
            width: 44px;
            border-radius: 3px;
            background: linear-gradient(90deg,#34d399,#059669);
            margin-bottom: 12px;
        }
        .card[style]::before { display: none; }
        .inline { display: flex; gap: 8px; flex-wrap: wrap; }
        .inline input {
            padding: 11px 14px;
            border: 1.5px solid #d1e5d8;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #fbfefc;
            min-width: 200px;
        }
        .inline input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 10px 18px; margin: 0; }
        .details-grid div { padding: 8px 0; border-bottom: 1px solid #e6f0ea; }
        .details-grid dt { color: #6b7280; font-size: 12px; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .details-grid dd { margin: 0; font-weight: 600; color: #14241b; font-size: 15px; }
        .table-wrap { overflow-x: auto; margin-top: 14px; border: 1px solid #e6f0ea; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        table th {
            background: #ecfdf5;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #064e3b;
            border-bottom: 1.5px solid #a7f3d0;
        }
        table th, table td { padding: 12px 14px; border-bottom: 1px solid #e6f0ea; text-align: left; font-size: 14px; }
        table tbody tr:hover { background: #f4fbf7; }
        table tbody tr:last-child td { border-bottom: none; }
        .badge { padding: 5px 12px; border-radius: 999px; color: #fff; font-weight: 600; font-size: 12px; display: inline-block; }
        .badge.on { background: linear-gradient(180deg,#10b981,#059669); border: 1px solid #86efac; box-shadow: 0 2px 10px rgba(16,185,129,0.35); }
        .badge.tardy { background: linear-gradient(180deg,#f59e0b,#d97706); box-shadow: 0 2px 10px rgba(217,119,6,0.35); }
        .badge.absent { background: linear-gradient(180deg,#f43f5e,#e11d48); border: 1px solid #fda4af; box-shadow: 0 2px 10px rgba(225,29,72,0.35); }
        .badge.weekend { background: #94a3b8; }
        .stat-grid { display: grid; grid-template-columns: repeat(3, minmax(90px,1fr)); gap: 12px; margin-top: 12px; }
        .stat { padding: 14px; border-radius: 12px; text-align: center; }
        .stat.on { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .stat.on .num { color: #065f46; }
        .stat.tardy { background: #fffbeb; border: 1px solid #fde68a; }
        .stat.tardy .num { color: #92400e; }
        .stat.absent { background: #fef2f2; border: 1px solid #fecdd3; }
        .stat.absent .num { color: #881337; }
        .stat .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
        .stat .num { font-size: 26px; font-weight: 800; line-height: 1; }
        .filter-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
        .filter-row label { font-size: 13px; color: #3d5649; font-weight: 600; }
        .filter-row input, .filter-row select {
            padding: 8px 10px;
            border: 1.5px solid #d1e5d8;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #fbfefc;
            width: auto;
            min-width: 120px;
        }
        .filter-row select { min-width: 76px; }
        .pagination { margin-top: 14px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .pagination a.button { margin-right: 0; }
        .pagination a.button.current { background: #064e3b; }
        .hello-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .hello-bar p { margin: 0; color: #6b7280; }
        @media (max-width: 700px) {
            body { padding: 14px; }
            .container { padding: 16px; border-radius: 12px; }
            .stat-grid { grid-template-columns: repeat(3, 1fr); }
            .inline input { min-width: 100%; }
        }
        @media (max-width: 460px) {
            .stat-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Student Dashboard</h1>
        <p class="page-sub">View your attendance for the past few weeks.</p>
        <div class="page-actions">
            <a class="button" href="index.php">Back to Kiosk</a>
            <a class="button" href="admin.php">Go to Admin Panel</a>
        </div>

        <?php if ($message !== ''): ?>
            <div class="card" style="background:<?= $messageType === 'success' ? '#ecfdf5' : '#fee2e2' ?>; color:<?= $messageType === 'success' ? '#065f46' : '#881337' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (! $loggedIn): ?>
            <section class="card">
                <h2>Student Login</h2>
                <form method="post" class="inline">
                    <input type="hidden" name="action" value="login_student">
                    <input name="username" placeholder="Username">
                    <input name="password" type="password" placeholder="Password">
                    <button type="submit">Login</button>
                </form>
                <p style="margin-top:8px;color:#666;font-size:13px">If you don't have credentials, ask your administrator to create an account.</p>
            </section>
        <?php else: ?>
            <?php if ($studentDetails): ?>
                <section class="card">
                    <h2>My Details</h2>
                    <dl class="details-grid">
                        <div><dt>Student ID</dt><dd><?= htmlspecialchars($studentDetails['student_id']) ?></dd></div>
                        <div><dt>Gender</dt><dd><?= htmlspecialchars($studentDetails['gender'] ?: '-') ?></dd></div>
                        <div><dt>Full Name</dt><dd><?= htmlspecialchars($studentDetails['full_name']) ?></dd></div>
                        <div><dt>Username</dt><dd><?= htmlspecialchars($studentDetails['username'] ?? '') ?></dd></div>
                        <div><dt>Grade Level</dt><dd><?= htmlspecialchars($studentDetails['grade_level'] ?: '-') ?></dd></div>
                        <div><dt>Section</dt><dd><?= htmlspecialchars($studentDetails['section'] ?: '-') ?></dd></div>
                        <div><dt>Grade and Section</dt><dd><?= htmlspecialchars($studentDetails['grade_section']) ?></dd></div>
                        <div><dt>Parent Phone</dt><dd><?= htmlspecialchars($studentDetails['parent_phone']) ?></dd></div>
                    </dl>
                </section>
            <?php endif; ?>
            <section class="card">
                <div class="hello-bar">
                    <div>
                        <h2><?= htmlspecialchars($_SESSION['student_name']) ?></h2>
                        <p>Cutoff: <?= htmlspecialchars($attendance_cutoff) ?> (change via ?cutoff=HH:MM:SS)</p>
                    </div>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="logout_student">
                        <button class="button logout" type="submit">Logout</button>
                    </form>
                </div>

                <div class="filter-row">
                    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <label>From <input type="date" name="start" value="<?= isset($template_pagination) ? htmlspecialchars($template_pagination['start']) : '' ?>"></label>
                        <label>To <input type="date" name="end" value="<?= isset($template_pagination) ? htmlspecialchars($template_pagination['end']) : '' ?>"></label>
                        <label>Per page
                            <select name="per_page">
                                <?php foreach ([7,14,21,30] as $pp): ?>
                                    <option value="<?= $pp ?>" <?= (isset($template_pagination) && $template_pagination['per_page']==$pp) ? 'selected' : '' ?>><?= $pp ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <input type="hidden" name="cutoff" value="<?= htmlspecialchars($attendance_cutoff) ?>">
                        <button type="submit" class="button">Filter</button>
                    </form>

                    <?php if (isset($template_pagination)): ?>
                        <div style="font-size:13px;color:#6b7280">Showing page <?= $template_pagination['page'] ?> of <?= $template_pagination['pages'] ?> (<?= $template_pagination['total'] ?> days)</div>
                    <?php endif; ?>
                </div>

                <div class="card" style="margin:16px 0 0; padding:18px;">
                    <div style="font-size:14px; font-weight:700; color:#064e3b;">Attendance summary</div>
                    <div class="stat-grid">
                        <div class="stat on">
                            <div class="label">On time</div>
                            <div class="num"><?= htmlspecialchars((string) ($attendanceSummary['On time'] ?? 0)) ?></div>
                        </div>
                        <div class="stat tardy">
                            <div class="label">Tardy</div>
                            <div class="num"><?= htmlspecialchars((string) ($attendanceSummary['Tardy'] ?? 0)) ?></div>
                        </div>
                        <div class="stat absent">
                            <div class="label">Absent</div>
                            <div class="num"><?= htmlspecialchars((string) ($attendanceSummary['Absent'] ?? 0)) ?></div>
                        </div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>AM IN</th><th>AM OUT</th><th>PM IN</th><th>PM OUT</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($attendance_days as $d => $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($d) ?></td>
                                <td><?= $row['am_in'] ? htmlspecialchars(date('H:i', strtotime($row['am_in']))) : '-' ?></td>
                                <td><?= $row['am_out'] ? htmlspecialchars(date('H:i', strtotime($row['am_out']))) : '-' ?></td>
                                <td><?= $row['pm_in'] ? htmlspecialchars(date('H:i', strtotime($row['pm_in']))) : '-' ?></td>
                                <td><?= $row['pm_out'] ? htmlspecialchars(date('H:i', strtotime($row['pm_out']))) : '-' ?></td>
                                <td>
                                    <?php if ($row['status'] === 'On time'): ?>
                                        <span class="badge on">On time</span>
                                    <?php elseif ($row['status'] === 'Tardy'): ?>
                                        <span class="badge tardy">Tardy</span>
                                    <?php elseif ($row['status'] === 'Weekend'): ?>
                                        <span class="badge weekend">Weekend</span>
                                    <?php else: ?>
                                        <span class="badge absent">Absent</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (isset($template_pagination) && $template_pagination['pages'] > 1): ?>
                    <div class="pagination">
                        <?php
                        $baseParams = $_GET;
                        // helper to build page links
                        function pageUrl($p) {
                            $params = $_GET;
                            $params['page'] = $p;
                            return '?' . http_build_query($params);
                        }
                        ?>
                        <?php if ($template_pagination['page'] > 1): ?>
                            <a class="button" href="<?= pageUrl($template_pagination['page'] - 1) ?>">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $template_pagination['page'] - 3);
                        $endPage = min($template_pagination['pages'], $template_pagination['page'] + 3);
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a class="button <?= $p == $template_pagination['page'] ? 'current' : '' ?>" href="<?= pageUrl($p) ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <?php if ($template_pagination['page'] < $template_pagination['pages']): ?>
                            <a class="button" href="<?= pageUrl($template_pagination['page'] + 1) ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
