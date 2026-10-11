<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// --------------------------------------------------
// 1. ACCESS CONTROL
// --------------------------------------------------

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'hospital_admin' ||
    empty($_SESSION['hospital_id'])
) {
    header('Location: ../auth/login.php');
    exit;
}

$hospitalId = (int) $_SESSION['hospital_id'];

// Verify that the administrator and hospital are still active.

$stmt = $pdo->prepare(
    "SELECT h.hospital_name
     FROM hospitals h
     INNER JOIN users u
        ON u.hospital_id = h.hospital_id
     WHERE h.hospital_id = ?
       AND u.user_id = ?
       AND u.role = 'hospital_admin'
       AND h.status = 'active'
       AND u.account_status = 'active'
     LIMIT 1"
);

$stmt->execute([
    $hospitalId,
    (int) $_SESSION['user_id']
]);

$hospital = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hospital) {
    $_SESSION = [];
    session_regenerate_id(true);

    header('Location: ../auth/login.php');
    exit;
}

$hospitalName = $hospital['hospital_name'];

// --------------------------------------------------
// 2. DATE FILTERS
// --------------------------------------------------

$today = date('Y-m-d');
$defaultStartDate = date('Y-m-d', strtotime('-29 days'));

$startDate = $_GET['start_date'] ?? $defaultStartDate;
$endDate = $_GET['end_date'] ?? $today;

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function validDate($date)
{
    if (!is_string($date)) {
        return false;
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);

    return $dateObject !== false
        && $dateObject->format('Y-m-d') === $date;
}

$errors = [];

if (!validDate($startDate) || !validDate($endDate)) {
    $errors[] = 'Please select valid start and end dates.';
} elseif ($startDate > $endDate) {
    $errors[] = 'The start date cannot be after the end date.';
} elseif ($endDate > $today) {
    $errors[] = 'The end date cannot be in the future.';
}

if (!empty($errors)) {
    $startDate = $defaultStartDate;
    $endDate = $today;
}

// --------------------------------------------------
// 3. APPOINTMENT STATISTICS
// --------------------------------------------------

$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_appointments,

        COALESCE(SUM(
            CASE
                WHEN status = 'completed' THEN 1
                ELSE 0
            END
        ), 0) AS completed_appointments,

        COALESCE(SUM(
            CASE
                WHEN status IN (
                    'booked',
                    'checked_in',
                    'waiting',
                    'in_progress',
                    'almost_over'
                ) THEN 1
                ELSE 0
            END
        ), 0) AS active_appointments,

        COALESCE(SUM(
            CASE
                WHEN status = 'cancelled' THEN 1
                ELSE 0
            END
        ), 0) AS cancelled_appointments,

        COALESCE(SUM(
            CASE
                WHEN status = 'no_show' THEN 1
                ELSE 0
            END
        ), 0) AS no_show_appointments

     FROM appointments

     WHERE hospital_id = ?
       AND appointment_date BETWEEN ? AND ?"
);

$stmt->execute([
    $hospitalId,
    $startDate,
    $endDate
]);

$appointmentStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 4. QUEUE STATISTICS
// --------------------------------------------------

$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_queue_entries,

        COALESCE(SUM(
            CASE
                WHEN status = 'waiting' THEN 1
                ELSE 0
            END
        ), 0) AS waiting_count,

        COALESCE(SUM(
            CASE
                WHEN status = 'called' THEN 1
                ELSE 0
            END
        ), 0) AS called_count,

        COALESCE(SUM(
            CASE
                WHEN status IN ('in_progress', 'almost_over') THEN 1
                ELSE 0
            END
        ), 0) AS in_progress_count,

        COALESCE(SUM(
            CASE
                WHEN status = 'completed' THEN 1
                ELSE 0
            END
        ), 0) AS completed_count,

        COALESCE(SUM(
            CASE
                WHEN status = 'absent' THEN 1
                ELSE 0
            END
        ), 0) AS absent_count,

        COALESCE(SUM(
            CASE
                WHEN status = 'cancelled' THEN 1
                ELSE 0
            END
        ), 0) AS cancelled_count

     FROM queues

     WHERE hospital_id = ?
       AND queue_date BETWEEN ? AND ?"
);

$stmt->execute([
    $hospitalId,
    $startDate,
    $endDate
]);

$queueStats = $stmt->fetch(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 5. DEPARTMENT PERFORMANCE
// --------------------------------------------------

$stmt = $pdo->prepare(
    "SELECT
        d.department_id,
        d.department_name,

        COUNT(a.appointment_id) AS total_appointments,

        COALESCE(SUM(
            CASE
                WHEN a.status = 'completed' THEN 1
                ELSE 0
            END
        ), 0) AS completed_appointments,

        COALESCE(SUM(
            CASE
                WHEN a.status IN (
                    'booked',
                    'checked_in',
                    'waiting',
                    'in_progress',
                    'almost_over'
                ) THEN 1
                ELSE 0
            END
        ), 0) AS active_appointments,

        COALESCE(SUM(
            CASE
                WHEN a.status = 'cancelled' THEN 1
                ELSE 0
            END
        ), 0) AS cancelled_appointments

     FROM departments d

     LEFT JOIN appointments a
        ON a.department_id = d.department_id
        AND a.hospital_id = d.hospital_id
        AND a.appointment_date BETWEEN ? AND ?

     WHERE d.hospital_id = ?

     GROUP BY
        d.department_id,
        d.department_name

     ORDER BY total_appointments DESC, d.department_name ASC"
);

$stmt->execute([
    $startDate,
    $endDate,
    $hospitalId
]);

$departmentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 6. DAILY APPOINTMENT ACTIVITY
// --------------------------------------------------

$stmt = $pdo->prepare(
    "SELECT
        appointment_date,

        COUNT(*) AS total_appointments,

        SUM(
            CASE
                WHEN status = 'completed' THEN 1
                ELSE 0
            END
        ) AS completed_appointments,

        SUM(
            CASE
                WHEN status = 'cancelled' THEN 1
                ELSE 0
            END
        ) AS cancelled_appointments,

        SUM(
            CASE
                WHEN status = 'no_show' THEN 1
                ELSE 0
            END
        ) AS no_show_appointments

     FROM appointments

     WHERE hospital_id = ?
       AND appointment_date BETWEEN ? AND ?

     GROUP BY appointment_date

     ORDER BY appointment_date DESC"
);

$stmt->execute([
    $hospitalId,
    $startDate,
    $endDate
]);

$dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 7. CSV EXPORT
// --------------------------------------------------

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'hospital_report_'
        . $startDate
        . '_to_'
        . $endDate
        . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    $output = fopen('php://output', 'w');

    // Help spreadsheet applications recognize UTF-8.

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['Hospital Report']);
    fputcsv($output, ['Hospital', $hospitalName]);
    fputcsv($output, ['Start Date', $startDate]);
    fputcsv($output, ['End Date', $endDate]);
    fputcsv($output, []);

    fputcsv($output, ['APPOINTMENT SUMMARY']);

    fputcsv($output, [
        'Total Appointments',
        'Completed',
        'Active',
        'Cancelled',
        'No Shows'
    ]);

    fputcsv($output, [
        $appointmentStats['total_appointments'],
        $appointmentStats['completed_appointments'],
        $appointmentStats['active_appointments'],
        $appointmentStats['cancelled_appointments'],
        $appointmentStats['no_show_appointments']
    ]);

    fputcsv($output, []);

    fputcsv($output, ['QUEUE SUMMARY']);

    fputcsv($output, [
        'Total Queue Entries',
        'Waiting',
        'Called',
        'In Progress',
        'Completed',
        'Absent',
        'Cancelled'
    ]);

    fputcsv($output, [
        $queueStats['total_queue_entries'],
        $queueStats['waiting_count'],
        $queueStats['called_count'],
        $queueStats['in_progress_count'],
        $queueStats['completed_count'],
        $queueStats['absent_count'],
        $queueStats['cancelled_count']
    ]);

    fputcsv($output, []);

    fputcsv($output, ['DEPARTMENT PERFORMANCE']);

    fputcsv($output, [
        'Department',
        'Total Appointments',
        'Completed',
        'Active',
        'Cancelled'
    ]);

    foreach ($departmentStats as $department) {
        fputcsv($output, [
            $department['department_name'],
            $department['total_appointments'],
            $department['completed_appointments'],
            $department['active_appointments'],
            $department['cancelled_appointments']
        ]);
    }

    fputcsv($output, []);

    fputcsv($output, ['DAILY APPOINTMENT ACTIVITY']);

    fputcsv($output, [
        'Date',
        'Total Appointments',
        'Completed',
        'Cancelled',
        'No Shows'
    ]);

    foreach ($dailyStats as $day) {
        fputcsv($output, [
            $day['appointment_date'],
            $day['total_appointments'],
            $day['completed_appointments'],
            $day['cancelled_appointments'],
            $day['no_show_appointments']
        ]);
    }

    fclose($output);
    exit;
}

// --------------------------------------------------
// 8. CALCULATE COMPLETION RATE
// --------------------------------------------------

$totalAppointments = (int) $appointmentStats['total_appointments'];

$completedAppointments = (int) $appointmentStats['completed_appointments'];

$completionRate = $totalAppointments > 0
    ? round(($completedAppointments / $totalAppointments) * 100, 1)
    : 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Reports & Statistics | Smart Queue Management</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .reports-container {
            max-width: 1250px;
            margin: 30px auto;
            padding: 20px;
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .reports-header h1 {
            margin-bottom: 8px;
        }

        .reports-header p {
            margin-top: 0;
            color: #666;
        }

        .reports-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .reports-button {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .primary-button {
            background: #176b5b;
            color: white;
        }

        .secondary-button {
            background: #eeeeee;
            color: #333333;
        }

        .filter-card,
        .report-card {
            background: #ffffff;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .filter-form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-field {
            flex: 1;
            min-width: 180px;
        }

        .filter-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .filter-field input {
            width: 100%;
            padding: 11px;
            border: 1px solid #cccccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #ffffff;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
            border-left: 4px solid #176b5b;
        }

        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: #666666;
            margin: 0 0 12px;
        }

        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #222222;
        }

        .stat-card p {
            font-size: 13px;
            color: #777777;
            margin-bottom: 0;
        }

        .report-card h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        .report-table th,
        .report-table td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #eeeeee;
        }

        .report-table th {
            background: #f6f8f7;
            font-weight: 600;
        }

        .report-table tr:last-child td {
            border-bottom: none;
        }

        .empty-message {
            color: #777777;
            padding: 15px 0;
        }

        .error-message {
            background: #fdeaea;
            color: #9b2020;
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .progress-track {
            background: #eeeeee;
            height: 8px;
            border-radius: 5px;
            overflow: hidden;
            min-width: 80px;
        }

        .progress-fill {
            background: #176b5b;
            height: 100%;
        }

        @media (max-width: 800px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .reports-container {
                padding: 12px;
            }

            .report-card,
            .filter-card {
                padding: 16px;
            }
        }

        @media print {
            .reports-actions,
            .filter-card,
            .back-link {
                display: none !important;
            }

            .reports-container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }

            .stat-card,
            .report-card {
                box-shadow: none;
                border: 1px solid #dddddd;
                break-inside: avoid;
            }
        }
    </style>

</head>

<body>

<div class="reports-container">

    <a
        class="back-link"
        href="dashboard.php"
    >
        &larr; Back to Hospital Dashboard
    </a>

    <div class="reports-header">

        <div>
            <h1>Reports & Statistics</h1>

            <p>
                <?= e($hospitalName) ?>
                &mdash;
                <?= e($startDate) ?> to <?= e($endDate) ?>
            </p>
        </div>

        <div class="reports-actions">

            <button
                type="button"
                class="reports-button secondary-button"
                onclick="window.print()"
            >
                Print Report
            </button>

            <a
                class="reports-button primary-button"
                href="?start_date=<?= e($startDate) ?>&end_date=<?= e($endDate) ?>&export=csv"
            >
                Export CSV
            </a>

        </div>

    </div>

    <?php if (!empty($errors)): ?>

        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <!-- DATE FILTER -->

    <div class="filter-card">

        <h2>Filter Report Period</h2>

        <form
            method="GET"
            action="reports.php"
            class="filter-form"
        >

            <div class="filter-field">

                <label for="start_date">Start Date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= e($startDate) ?>"
                    max="<?= e($today) ?>"
                    required
                >

            </div>

            <div class="filter-field">

                <label for="end_date">End Date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= e($endDate) ?>"
                    max="<?= e($today) ?>"
                    required
                >

            </div>

            <button
                type="submit"
                class="reports-button primary-button"
            >
                Generate Report
            </button>

        </form>

    </div>

    <!-- APPOINTMENT STATISTICS -->

    <div class="stats-grid">

        <div class="stat-card">
            <h3>Total Appointments</h3>

            <div class="stat-number">
                <?= number_format($totalAppointments) ?>
            </div>

            <p>Appointments in the selected period</p>
        </div>

        <div class="stat-card">
            <h3>Patients Served</h3>

            <div class="stat-number">
                <?= number_format($completedAppointments) ?>
            </div>

            <p>Appointments marked completed</p>
        </div>

        <div class="stat-card">
            <h3>Active Appointments</h3>

            <div class="stat-number">
                <?= number_format((int) $appointmentStats['active_appointments']) ?>
            </div>

            <p>Booked, waiting or ongoing</p>
        </div>

        <div class="stat-card">
            <h3>Cancelled Appointments</h3>

            <div class="stat-number">
                <?= number_format((int) $appointmentStats['cancelled_appointments']) ?>
            </div>

            <p>Appointments cancelled</p>
        </div>

        <div class="stat-card">
            <h3>No-Shows</h3>

            <div class="stat-number">
                <?= number_format((int) $appointmentStats['no_show_appointments']) ?>
            </div>

            <p>Patients marked as no-show</p>
        </div>

        <div class="stat-card">
            <h3>Completion Rate</h3>

            <div class="stat-number">
                <?= e($completionRate) ?>%
            </div>

            <p>Completed appointments as a percentage of all appointments</p>

            <div class="progress-track">
                <div
                    class="progress-fill"
                    style="width: <?= min(100, max(0, $completionRate)) ?>%;"
                ></div>
            </div>
        </div>

    </div>

    <!-- QUEUE STATISTICS -->

    <div class="report-card">

        <h2>Queue Performance</h2>

        <div class="stats-grid">

            <div class="stat-card">
                <h3>Total Queue Entries</h3>

                <div class="stat-number">
                    <?= number_format((int) $queueStats['total_queue_entries']) ?>
                </div>
            </div>

            <div class="stat-card">
                <h3>Waiting</h3>

                <div class="stat-number">
                    <?= number_format((int) $queueStats['waiting_count']) ?>
                </div>
            </div>

            <div class="stat-card">
                <h3>Called</h3>

                <div class="stat-number">
                    <?= number_format((int) $queueStats['called_count']) ?>
                </div>
            </div>

            <div class="stat-card">
                <h3>Consultations in Progress</h3>

                <div class="stat-number">
                    <?= number_format((int) $queueStats['in_progress_count']) ?>
                </div>
            </div>

            <div class="stat-card">
                <h3>Completed Queue Entries</h3>

                <div class="stat-number">
                    <?= number_format((int) $queueStats['completed_count']) ?>
                </div>
            </div>

            <div class="stat-card">
                <h3>Absent or Cancelled</h3>

                <div class="stat-number">
                    <?= number_format(
                        (int) $queueStats['absent_count']
                        + (int) $queueStats['cancelled_count']
                    ) ?>
                </div>
            </div>

        </div>

        <p>
            Queue statistics are based on queue records within the selected
            queue dates. They show current recorded statuses, not historical
            status changes.
        </p>

    </div>

    <!-- DEPARTMENT PERFORMANCE -->

    <div class="report-card">

        <h2>Department Performance</h2>

        <?php if (empty($departmentStats)): ?>

            <p class="empty-message">
                No departments have been registered at this hospital.
            </p>

        <?php else: ?>

            <div class="table-wrapper">

                <table class="report-table">

                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Total Appointments</th>
                            <th>Completed</th>
                            <th>Active</th>
                            <th>Cancelled</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($departmentStats as $department): ?>

                            <tr>

                                <td>
                                    <?= e($department['department_name']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $department['total_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $department['completed_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $department['active_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $department['cancelled_appointments']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <!-- DAILY APPOINTMENT ACTIVITY -->

    <div class="report-card">

        <h2>Daily Appointment Activity</h2>

        <?php if (empty($dailyStats)): ?>

            <p class="empty-message">
                No appointments were recorded during this period.
            </p>

        <?php else: ?>

            <div class="table-wrapper">

                <table class="report-table">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Appointments</th>
                            <th>Completed</th>
                            <th>Cancelled</th>
                            <th>No-Shows</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($dailyStats as $day): ?>

                            <tr>

                                <td>
                                    <?= e($day['appointment_date']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $day['total_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $day['completed_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $day['cancelled_appointments']) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $day['no_show_appointments']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>