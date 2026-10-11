<?php
session_start();
require_once __DIR__ . '/../config/database.php';
/*
|--------------------------------------------------------------------------
| Authentication and authorization
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'hospital_admin' ||
    !isset($_SESSION['hospital_id'])
) {
    header('Location: ../auth/login.php');
    exit;
}
$hospitalId = (int) $_SESSION['hospital_id'];
function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
/*
|--------------------------------------------------------------------------
| Verify active hospital
|--------------------------------------------------------------------------
*/
$hospitalStmt = $pdo->prepare(
    "SELECT hospital_name
     FROM hospitals
     WHERE hospital_id = ?
       AND status = 'active'
     LIMIT 1"
);
$hospitalStmt->execute([$hospitalId]);
$hospital = $hospitalStmt->fetch(PDO::FETCH_ASSOC);
if (!$hospital) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}
/*
|--------------------------------------------------------------------------
| Validate patient ID
|--------------------------------------------------------------------------
*/
$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('Invalid patient ID.');
}
/*
|--------------------------------------------------------------------------
| Retrieve patient only if associated with this hospital
|--------------------------------------------------------------------------
*/
$patientStmt = $pdo->prepare(
    "SELECT
        p.patient_id,
        p.date_of_birth,
        p.gender,
        u.full_name,
        u.email,
        u.phone,
        MIN(a.appointment_date) AS first_appointment,
        MAX(a.appointment_date) AS latest_appointment,
        COUNT(DISTINCT a.appointment_id) AS total_appointments
     FROM patients p
     INNER JOIN users u
        ON u.user_id = p.user_id
     INNER JOIN appointments a
        ON a.patient_id = p.patient_id
     WHERE p.patient_id = :patient_id
       AND a.hospital_id = :hospital_id
     GROUP BY
        p.patient_id,
        p.date_of_birth,
        p.gender,
        u.full_name,
        u.email,
        u.phone
     LIMIT 1"
);
$patientStmt->execute([
    ':patient_id' => $patientId,
    ':hospital_id' => $hospitalId
]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    http_response_code(404);
    exit('Patient not found at this hospital.');
}
/*
|--------------------------------------------------------------------------
| Retrieve this patient's appointment history at this hospital
|--------------------------------------------------------------------------
*/
$appointmentsStmt = $pdo->prepare(
    "SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.reason_for_visit,
        a.status AS appointment_status,
        d.department_name,
        doc.specialization,
        doctorUser.full_name AS doctor_name,
        q.queue_number,
        q.queue_label,
        q.status AS queue_status
     FROM appointments a
     LEFT JOIN departments d
        ON d.department_id = a.department_id
       AND d.hospital_id = a.hospital_id
     LEFT JOIN doctors doc
        ON doc.doctor_id = a.doctor_id
       AND doc.hospital_id = a.hospital_id
     LEFT JOIN users doctorUser
        ON doctorUser.user_id = doc.user_id
     LEFT JOIN queues q
        ON q.appointment_id = a.appointment_id
       AND q.hospital_id = a.hospital_id
     WHERE a.patient_id = :patient_id
       AND a.hospital_id = :hospital_id
     ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC,
        a.appointment_id DESC"
);
$appointmentsStmt->execute([
    ':patient_id' => $patientId,
    ':hospital_id' => $hospitalId
]);
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile | SmartQueue</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .profile-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .profile-header,
        .profile-card,
        .history-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
        }
        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .profile-header h1 {
            color: #083344;
            margin: 0 0 8px;
        }
        .profile-header p {
            color: #64748b;
            margin: 0;
        }
        .back-link {
            display: inline-block;
            background: #0e7490;
            color: #fff;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 7px;
            font-weight: 600;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }
        .profile-detail {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            overflow-wrap: anywhere;
        }
        .profile-detail .label {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 7px;
        }
        .profile-detail .value {
            color: #164e63;
            font-weight: 600;
        }
        .history-table-wrap {
            overflow-x: auto;
        }
        .history-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
        }
        .history-table th,
        .history-table td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .history-table th {
            background: #f1f5f9;
            font-size: 13px;
        }
        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #e0f2fe;
            color: #075985;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .empty-history {
            color: #64748b;
            padding: 20px 0;
        }
        @media (max-width: 750px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            .profile-page {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body>
<main class="profile-page">
    <header class="profile-header">
        <div>
            <h1>Patient Profile</h1>
            <p><?= escapeHtml($hospital['hospital_name']) ?></p>
        </div>
        <a href="patients.php" class="back-link">
            Back to Patients
        </a>
    </header>
    <section class="profile-card">
        <h2>Personal Information</h2>
        <div class="profile-grid">
            <div class="profile-detail">
                <div class="label">Patient ID</div>
                <div class="value">
                    <?= (int) $patient['patient_id'] ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Full Name</div>
                <div class="value">
                    <?= escapeHtml($patient['full_name']) ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Email Address</div>
                <div class="value">
                    <?= escapeHtml($patient['email']) ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Phone Number</div>
                <div class="value">
                    <?= escapeHtml($patient['phone'] ?: 'Not provided') ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Date of Birth</div>
                <div class="value">
                    <?= escapeHtml($patient['date_of_birth'] ?: 'Not provided') ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Gender</div>
                <div class="value">
                    <?= escapeHtml($patient['gender'] ?: 'Not provided') ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Total Appointments at This Hospital</div>
                <div class="value">
                    <?= (int) $patient['total_appointments'] ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">First Appointment</div>
                <div class="value">
                    <?= escapeHtml($patient['first_appointment'] ?: 'N/A') ?>
                </div>
            </div>
            <div class="profile-detail">
                <div class="label">Latest Appointment</div>
                <div class="value">
                    <?= escapeHtml($patient['latest_appointment'] ?: 'N/A') ?>
                </div>
            </div>
        </div>
    </section>
    <section class="history-card">
        <h2>Appointment and Queue History</h2>
        <?php if (empty($appointments)): ?>
            <p class="empty-history">
                No appointment history is available.
            </p>
        <?php else: ?>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Department</th>
                            <th>Doctor</th>
                            <th>Reason</th>
                            <th>Queue Number</th>
                            <th>Appointment Status</th>
                            <th>Queue Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $appointment): ?>
                            <tr>
                                <td>
                                    <?= escapeHtml($appointment['appointment_date']) ?>
                                </td>
                                <td>
                                    <?= escapeHtml($appointment['appointment_time']) ?>
                                </td>
                                <td>
                                    <?= escapeHtml($appointment['department_name'] ?: 'N/A') ?>
                                </td>
                                <td>
                                    <?= escapeHtml($appointment['doctor_name'] ?: 'N/A') ?>
                                    <?php if (!empty($appointment['specialization'])): ?>
                                        <br>
                                        <small>
                                            <?= escapeHtml($appointment['specialization']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= escapeHtml($appointment['reason_for_visit'] ?: 'Not provided') ?>
                                </td>
                                <td>
                                    <?= escapeHtml(
                                        $appointment['queue_label']
                                        ?: (
                                            $appointment['queue_number'] !== null
                                            ? 'Q' . str_pad(
                                                (string) $appointment['queue_number'],
                                                3,
                                                '0',
                                                STR_PAD_LEFT
                                            )
                                            : 'Not assigned'
                                        )
                                    ) ?>
                                </td>
                                <td>
                                    <span class="status">
                                        <?= escapeHtml(
                                            ucwords(str_replace(
                                                '_',
                                                ' ',
                                                $appointment['appointment_status']
                                            ))
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status">
                                        <?= escapeHtml(
                                            $appointment['queue_status']
                                            ? ucwords(str_replace(
                                                '_',
                                                ' ',
                                                $appointment['queue_status']
                                            ))
                                            : 'Not assigned'
                                        ) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>