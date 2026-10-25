<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers.php';

$studentId = trim($_GET['student_id'] ?? '');
$awardDate = trim($_GET['award_date'] ?? '');
$certificateType = trim($_GET['certificate_type'] ?? 'Punctuality');

if ($studentId === '' || $awardDate === '') {
    http_response_code(400);
    echo '<html><body><h2>Invalid certificate request.</h2></body></html>';
    exit;
}

$stmt = $conn->prepare('SELECT full_name, grade_level, section FROM students WHERE student_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo '<html><body><h2>Unable to load learner information.</h2></body></html>';
    exit;
}
$stmt->bind_param('s', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    http_response_code(404);
    echo '<html><body><h2>Learner not found.</h2></body></html>';
    exit;
}

$issuedStmt = $conn->prepare('SELECT notes, award_date FROM attendance_certificates WHERE student_id = ? AND award_date = ? AND certificate_type = ? LIMIT 1');
if ($issuedStmt) {
    $issuedStmt->bind_param('sss', $studentId, $awardDate, $certificateType);
    $issuedStmt->execute();
    $certificate = $issuedStmt->get_result()->fetch_assoc();
}

$displayDate = date('F j, Y', strtotime($awardDate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($certificateType) ?> Certificate</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(900px 420px at 100% 0%, rgba(16,185,129,0.10), transparent),
                #eef7f0;
            margin: 0;
            padding: 32px;
        }
        .certificate {
            max-width: 980px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #0f172a;
            border-radius: 0;
            padding: 32px 42px 24px;
            text-align: center;
            box-shadow: 0 14px 38px rgba(2,44,34,0.14);
        }
        .seal-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 14px;
        }
        .seal {
            width: 96px;
            height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #fff;
            box-shadow: inset 0 0 0 2px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .seal img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .header-block {
            font-family: Georgia, 'Times New Roman', serif;
            color: #111827;
            letter-spacing: 0.03em;
        }
        .header-block .country {
            font-size: 18px;
            font-weight: 700;
            font-style: italic;
            margin-bottom: 4px;
        }
        .header-block .dept {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .header-block .subline {
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
            margin-bottom: 4px;
        }
        .header-block .school-line {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.25;
            margin-top: 8px;
        }
        .header-divider {
            border-top: 3px solid #111827;
            width: 100%;
            margin: 18px auto 0;
        }
        .title {
            font-size: 36px;
            font-weight: 700;
            color: #065f46;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 18px 0 10px;
        }
        .subtitle {
            font-size: 18px;
            color: #334155;
            margin-bottom: 30px;
        }
        h2 {
            font-size: 28px;
            margin: 20px 0 12px;
            color: #0f172a;
        }
        .student {
            font-size: 34px;
            font-weight: 700;
            color: #059669;
            margin: 14px 0 18px;
        }
        .details {
            font-size: 18px;
            color: #475569;
            line-height: 1.8;
        }
        .signature {
            margin-top: 44px;
            border-top: 2px solid #cbd5e1;
            display: inline-block;
            padding-top: 12px;
            min-width: 220px;
            color: #475569;
            font-weight: 600;
        }
        @media print {
            body { background: white; padding: 0; }
            .certificate { box-shadow: none; border: 2px solid #059669; }
            button { display: none; }
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="seal-wrap">
            <div class="seal" aria-label="Department of Education official seal">
                <img src="deped logo.png" alt="Department of Education official seal">
            </div>
        </div>

        <div class="header-block">
            <div class="country">Republic of the Philippines</div>
            <div class="dept">Department of Education</div>
            <div class="subline">Region II – Cagayan Valley</div>
            <div class="subline">Schools Division Office of Cagayan</div>
            <div class="subline">Baggao North District</div>
            <div class="school-line">Baggao National Agricultural School</div>
        </div>
        <div class="header-divider"></div>

        <div class="title">Certificate of Punctuality</div>
        <div class="subtitle">This certifies that</div>
        <div class="student"><?= htmlspecialchars($student['full_name']) ?></div>
        <div class="details">
            <?= htmlspecialchars($student['grade_level'] ?: '-') ?> - Section <?= htmlspecialchars($student['section'] ?: '-') ?><br>
            has demonstrated excellent punctuality and consistent on-time attendance.<br>
            Awarded on <strong><?= htmlspecialchars($displayDate) ?></strong>
        </div>
        <div class="signature">Teacher / Adviser</div>
    </div>
    <div style="text-align:center; margin-top:24px;">
        <button type="button" onclick="window.print()" style="padding:12px 22px; background:linear-gradient(180deg,#10b981,#059669 55%,#047857); color:#fff; border:none; border-radius:10px; cursor:pointer; font-size:15px; font-weight:600; font-family:inherit; box-shadow:0 4px 14px rgba(16,185,129,0.35);">Print Certificate</button>
    </div>
</body>
</html>
