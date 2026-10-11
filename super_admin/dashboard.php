<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// Only allow logged-in super administrators
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "super_admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

// Get the total number of hospitals
$stmt = $pdo->query(
    "SELECT COUNT(*) AS total FROM hospitals"
);
$total_hospitals = $stmt->fetch()["total"];

// Get the number of pending hospitals
$stmt = $pdo->query(
    "SELECT COUNT(*) AS total
     FROM hospitals
     WHERE status = 'pending'"
);
$pending_hospitals = $stmt->fetch()["total"];

// Get the number of active hospitals
$stmt = $pdo->query(
    "SELECT COUNT(*) AS total
     FROM hospitals
     WHERE status = 'active'"
);
$active_hospitals = $stmt->fetch()["total"];

// Get the number of registered patients
$stmt = $pdo->query(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role = 'patient'"
);
$total_patients = $stmt->fetch()["total"];

// Get the most recently registered hospitals
$stmt = $pdo->query(
    "SELECT hospital_id,
            hospital_name,
            registration_number,
            email,
            status,
            created_at
     FROM hospitals
     ORDER BY created_at DESC
     LIMIT 10"
);

$hospitals = $stmt->fetchAll();
// Retrieve feedback messages after a hospital management action.
$admin_message = $_SESSION["admin_message"] ?? "";
$admin_message_type = $_SESSION["admin_message_type"] ?? "";

unset($_SESSION["admin_message"], $_SESSION["admin_message_type"]);

function escape($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Super Admin Dashboard | SmartQueue</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <style>
        body {
            margin: 0;
            background: #f4f7fa;
            font-family: Arial, sans-serif;
            color: #263238;
        }

        .admin-header {
            background: #075b63;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .admin-header h1 {
            margin: 0;
            font-size: 24px;
        }

        .admin-header a {
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border: 1px solid white;
            border-radius: 5px;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .welcome-section {
            margin-bottom: 30px;
        }

        .welcome-section h2 {
            margin-bottom: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(200px, 1fr)
            );
            gap: 20px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        .stat-card h3 {
            margin-top: 0;
            font-size: 15px;
            color: #607d8b;
        }

        .stat-card p {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 0;
            color: #087f8c;
        }

        .table-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            overflow-x: auto;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            text-align: left;
            padding: 14px;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f0f5f6;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fff0c2;
            color: #795600;
        }

        .status-active {
            background: #d9f7e5;
            color: #176b32;
        }

        .status-suspended {
            background: #ffe0e0;
            color: #a00000;
        }

        .empty-message {
            padding: 20px 0;
            color: #607d8b;
        }

        @media (max-width: 600px) {
            .admin-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .table-container {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<header class="admin-header">

    <h1>SmartQueue | Super Admin</h1>

    <a href="../auth/logout.php">Log Out</a>

</header>

<main class="dashboard-container">

    <section class="welcome-section">

        <h2>
            Welcome,
            <?= escape($_SESSION["full_name"] ?? "Administrator") ?>!
        </h2>

        <p>
            Manage registered hospitals and monitor the SmartQueue platform.
        </p>
<a href="manage_hospital.php" class="btn">
   + Manage Hospitals
</a>      
<a href="add_hospital.php" class="btn">
    + Register Hospital
</a>
    </section>

    <section class="stats-grid">

        <div class="stat-card">
            <h3>Total Hospitals</h3>
            <p><?= (int) $total_hospitals ?></p>
        </div>

        <div class="stat-card">
            <h3>Pending Approvals</h3>
            <p><?= (int) $pending_hospitals ?></p>
        </div>

        <div class="stat-card">
            <h3>Active Hospitals</h3>
            <p><?= (int) $active_hospitals ?></p>
        </div>

        <div class="stat-card">
            <h3>Registered Patients</h3>
            <p><?= (int) $total_patients ?></p>
        </div>

    </section>

    <section class="table-container">

    <h2>Recently Registered Hospitals</h2>

    <?php if ($admin_message !== ""): ?>

        <div style="
            padding: 12px;
            margin: 15px 0;
            border-radius: 6px;
            background: <?= $admin_message_type === "success" ? "#e2f7e8" : "#ffe5e5" ?>;
            color: <?= $admin_message_type === "success" ? "#176b32" : "#a00000" ?>;
        ">
            <?= escape($admin_message) ?>
        </div>

    <?php endif; ?>

        <?php if (empty($hospitals)): ?>

            <p class="empty-message">
                No hospitals have registered yet.
            </p>

        <?php else: ?>

            <table>

                <thead>
                    <tr>
                        <th>Hospital</th>
                        <th>Registration Number</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Date Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($hospitals as $hospital): ?>

                        <tr>

                            <td>
                                <?= escape($hospital["hospital_name"]) ?>
                            </td>

                            <td>
                                <?= escape($hospital["registration_number"]) ?>
                            </td>

                            <td>
                                <?= escape($hospital["email"]) ?>
                            </td>

                            <td>
                                <span class="status status-<?= escape($hospital["status"]) ?>">
                                    <?= escape($hospital["status"]) ?>
                                </span>
                            </td>

                            <td>
                                <?= escape($hospital["created_at"]) ?>
                            </td>
<td>
    <?php if ($hospital["status"] === "pending"): ?>

        <form method="POST"
              action="manage_hospital.php"
              style="display:inline-block; margin-bottom:5px;">

            <input type="hidden"
                   name="hospital_id"
                   value="<?= (int) $hospital["hospital_id"] ?>">

            <input type="hidden"
                   name="action"
                   value="approve">

            <button type="submit"
                    style="background:#16803c; color:white; border:none; padding:8px 12px; border-radius:5px; cursor:pointer;">
                Approve
            </button>

        </form>

        <form method="POST"
              action="manage_hospital.php"
              style="display:inline-block;">

            <input type="hidden"
                   name="hospital_id"
                   value="<?= (int) $hospital["hospital_id"] ?>">

            <input type="hidden"
                   name="action"
                   value="reject">

            <button type="submit"
                    onclick="return confirm('Are you sure you want to reject this hospital registration?');"
                    style="background:#c62828; color:white; border:none; padding:8px 12px; border-radius:5px; cursor:pointer;">
                Reject
            </button>

        </form>

    <?php else: ?>

        No actions available

    <?php endif; ?>
</td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </section>

</main>

</body>
</html>