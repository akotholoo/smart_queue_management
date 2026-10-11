
<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'super_admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$message = '';
$messageType = 'success';

/*
|--------------------------------------------------------------------------
| Handle hospital status actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospitalId = filter_input(INPUT_POST, 'hospital_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    $allowedActions = [
        'approve',
        'suspend',
        'reactivate',
        'reject'
    ];

    if (!$hospitalId || !in_array($action, $allowedActions, true)) {
        $message = "Invalid request.";
        $messageType = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the hospital record while updating its status.
            $stmt = $pdo->prepare(
                "SELECT hospital_id, hospital_name, status
                 FROM hospitals
                 WHERE hospital_id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$hospitalId]);
            $hospital = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hospital) {
                throw new Exception("Hospital not found.");
            }

            $newStatus = null;
            $successMessage = '';

            switch ($action) {
                case 'approve':
                    if ($hospital['status'] !== 'pending') {
                        throw new Exception(
                            "Only pending hospitals can be approved."
                        );
                    }

                    $newStatus = 'active';
                    $successMessage = "Hospital approved successfully.";
                    break;

                case 'suspend':
                    if ($hospital['status'] !== 'active') {
                        throw new Exception(
                            "Only active hospitals can be suspended."
                        );
                    }

                    $newStatus = 'suspended';
                    $successMessage = "Hospital suspended successfully.";
                    break;

                case 'reactivate':
                    if ($hospital['status'] !== 'suspended') {
                        throw new Exception(
                            "Only suspended hospitals can be reactivated."
                        );
                    }

                    $newStatus = 'active';
                    $successMessage = "Hospital reactivated successfully.";
                    break;

                case 'reject':
                    if ($hospital['status'] !== 'pending') {
                        throw new Exception(
                            "Only pending hospitals can be rejected."
                        );
                    }

                    $newStatus = 'suspended';
                    $successMessage =
                        "Registration rejected. The hospital record has been retained.";
                    break;
            }

            $stmt = $pdo->prepare(
                "UPDATE hospitals
                 SET status = ?
                 WHERE hospital_id = ?"
            );
            $stmt->execute([$newStatus, $hospitalId]);

            /*
             * Keep hospital administrator accounts in sync with
             * the hospital's access status.
             */
            if ($action === 'approve' || $action === 'reactivate') {
    $stmt = $pdo->prepare(
        "UPDATE users
         SET account_status = 'active'
         WHERE hospital_id = ?
         AND role = 'hospital_admin'
         AND account_status IN ('pending', 'suspended')"
    );
    $stmt->execute([$hospitalId]);
}
            $pdo->commit();

            $_SESSION['hospital_success'] = $successMessage;

            header("Location: manage_hospital.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Hospital management error: " . $e->getMessage());

            $message = $e->getMessage();
            $messageType = 'error';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Hospital database error: " . $e->getMessage());

            $message = "A database error occurred. Please try again.";
            $messageType = 'error';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Display one-time success message
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['hospital_success'])) {
    $message = $_SESSION['hospital_success'];
    $messageType = 'success';

    unset($_SESSION['hospital_success']);
}

/*
|--------------------------------------------------------------------------
| Retrieve hospitals and their administrators
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query(
    "SELECT
        h.hospital_id,
        h.hospital_name,
        h.registration_number,
        h.address,
        h.phone,
        h.email,
        h.status,
        h.created_at,
        u.full_name AS admin_name,
        u.email AS admin_email,
        u.phone AS admin_phone
     FROM hospitals h
     LEFT JOIN users u
        ON u.hospital_id = h.hospital_id
        AND u.role = 'hospital_admin'
     ORDER BY
        CASE h.status
            WHEN 'pending' THEN 1
            WHEN 'active' THEN 2
            WHEN 'suspended' THEN 3
            ELSE 4
        END,
        h.created_at DESC"
);

$hospitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalHospitals = count($hospitals);
$pendingCount = 0;
$activeCount = 0;
$suspendedCount = 0;

foreach ($hospitals as $hospital) {
    if ($hospital['status'] === 'pending') {
        $pendingCount++;
    } elseif ($hospital['status'] === 'active') {
        $activeCount++;
    } elseif ($hospital['status'] === 'suspended') {
        $suspendedCount++;
    }
}

function escapeHtml($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Hospitals | Smart Queue Management</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .management-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin-bottom: 6px;
        }

        .page-header p {
            margin-top: 0;
            color: #666;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.07);
        }

        .summary-card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #666;
        }

        .summary-card .number {
            font-size: 28px;
            font-weight: bold;
        }

        .hospital-table-wrapper {
            overflow-x: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.07);
        }

        .hospital-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        .hospital-table th,
        .hospital-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .hospital-table th {
            background: #f7f7f9;
            font-size: 13px;
        }

        .hospital-table td {
            font-size: 13px;
        }

        .hospital-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fff0c2;
            color: #805b00;
        }

        .status-active {
            background: #d9f7e5;
            color: #146c38;
        }

        .status-suspended {
            background: #ffe0e0;
            color: #a61b1b;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .action-button {
            border: none;
            border-radius: 6px;
            padding: 8px 11px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        .approve-button,
        .reactivate-button {
            background: #198754;
            color: #fff;
        }

        .suspend-button,
        .reject-button {
            background: #dc3545;
            color: #fff;
        }

        .back-button {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 7px;
            background: #343a40;
            color: #fff;
            text-decoration: none;
        }

        .alert {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 7px;
        }

        .alert-success {
            background: #d9f7e5;
            color: #146c38;
        }

        .alert-error {
            background: #ffe0e0;
            color: #a61b1b;
        }

        .empty-state {
            padding: 35px;
            text-align: center;
            color: #666;
        }

        @media (max-width: 600px) {
            .management-container {
                padding: 12px;
            }

            .page-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<div class="management-container">

    <div class="page-header">
        <div>
            <h1>Manage Hospitals</h1>
            <p>Review registrations and control hospital access.</p>
        </div>

        <a href="dashboard.php" class="back-button">
            Back to Dashboard
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
            <?= escapeHtml($message) ?>
        </div>
    <?php endif; ?>

    <div class="summary-cards">
        <div class="summary-card">
            <h3>Total Hospitals</h3>
            <div class="number"><?= $totalHospitals ?></div>
        </div>

        <div class="summary-card">
            <h3>Pending Approval</h3>
            <div class="number"><?= $pendingCount ?></div>
        </div>

        <div class="summary-card">
            <h3>Active Hospitals</h3>
            <div class="number"><?= $activeCount ?></div>
        </div>

        <div class="summary-card">
            <h3>Suspended Hospitals</h3>
            <div class="number"><?= $suspendedCount ?></div>
        </div>
    </div>

    <div class="hospital-table-wrapper">

        <?php if (empty($hospitals)): ?>

            <div class="empty-state">
                <h3>No hospitals registered yet</h3>
                <p>Register a hospital to begin managing hospital access.</p>
                <a href="add_hospital.php" class="back-button">
                    + Register Hospital
                </a>
            </div>

        <?php else: ?>

            <table class="hospital-table">
                <thead>
                    <tr>
                        <th>Hospital</th>
                        <th>Contact Details</th>
                        <th>Administrator</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($hospitals as $hospital): ?>

                    <tr>
                        <td>
                            <strong><?= escapeHtml($hospital['hospital_name']) ?></strong>
                            <br><br>
                            <strong>Registration No:</strong>
                            <?= escapeHtml($hospital['registration_number']) ?>
                            <br><br>
                            <strong>Address:</strong>
                            <?= escapeHtml($hospital['address']) ?>
                        </td>

                        <td>
                            <?= escapeHtml($hospital['email']) ?>
                            <br><br>
                            <?= escapeHtml($hospital['phone']) ?>
                        </td>

                        <td>
                            <?php if ($hospital['admin_name']): ?>
                                <strong><?= escapeHtml($hospital['admin_name']) ?></strong>
                                <br><br>
                                <?= escapeHtml($hospital['admin_email']) ?>
                                <br><br>
                                <?= escapeHtml($hospital['admin_phone']) ?>
                            <?php else: ?>
                                No administrator found
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="status-badge status-<?= escapeHtml($hospital['status']) ?>">
                                <?= escapeHtml($hospital['status']) ?>
                            </span>
                        </td>

                        <td>
                            <?= escapeHtml(
                                $hospital['created_at']
                                    ? date('d M Y', strtotime($hospital['created_at']))
                                    : 'N/A'
                            ) ?>
                        </td>

                        <td>
                            <div class="action-buttons">

                                <?php if ($hospital['status'] === 'pending'): ?>

                                    <form method="POST">
                                        <input type="hidden" name="hospital_id"
                                               value="<?= (int)$hospital['hospital_id'] ?>">
                                        <input type="hidden" name="action" value="approve">

                                        <button type="submit"
                                                class="action-button approve-button"
                                                onclick="return confirm('Approve this hospital registration?')">
                                            Approve
                                        </button>
                                    </form>

                                    <form method="POST">
                                        <input type="hidden" name="hospital_id"
                                               value="<?= (int)$hospital['hospital_id'] ?>">
                                        <input type="hidden" name="action" value="reject">

                                        <button type="submit"
                                                class="action-button reject-button"
                                                onclick="return confirm('Reject this registration? The hospital record will be retained as suspended.')">
                                            Reject
                                        </button>
                                    </form>

                                <?php elseif ($hospital['status'] === 'active'): ?>

                                    <form method="POST">
                                        <input type="hidden" name="hospital_id"
                                               value="<?= (int)$hospital['hospital_id'] ?>">
                                        <input type="hidden" name="action" value="suspend">

                                        <button type="submit"
                                                class="action-button suspend-button"
                                                onclick="return confirm('Suspend this hospital? Its administrator will lose access.')">
                                            Suspend
                                        </button>
                                    </form>

                                <?php elseif ($hospital['status'] === 'suspended'): ?>

                                    <form method="POST">
                                        <input type="hidden" name="hospital_id"
                                               value="<?= (int)$hospital['hospital_id'] ?>">
                                        <input type="hidden" name="action" value="reactivate">

                                        <button type="submit"
                                                class="action-button reactivate-button"
                                                onclick="return confirm('Reactivate this hospital?')">
                                            Reactivate
                                        </button>
                                    </form>

                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
```
