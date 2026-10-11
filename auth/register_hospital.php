<?php
session_start();

require_once __DIR__ . "/../config/database.php";

$errors = [];
$success = "";

$hospital_name = "";
$registration_number = "";
$address = "";
$hospital_phone = "";
$hospital_email = "";
$admin_name = "";
$admin_email = "";
$admin_phone = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Collect hospital details
    $hospital_name = trim($_POST["hospital_name"] ?? "");
    $registration_number = trim($_POST["registration_number"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $hospital_phone = trim($_POST["hospital_phone"] ?? "");
    $hospital_email = trim($_POST["hospital_email"] ?? "");

    // Collect initial hospital administrator details
    $admin_name = trim($_POST["admin_name"] ?? "");
    $admin_email = trim($_POST["admin_email"] ?? "");
    $admin_phone = trim($_POST["admin_phone"] ?? "");

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    // Validate required fields
    if (
        $hospital_name === "" ||
        $registration_number === "" ||
        $address === "" ||
        $hospital_phone === "" ||
        $hospital_email === "" ||
        $admin_name === "" ||
        $admin_email === "" ||
        $admin_phone === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {
        $errors[] = "Please fill in all fields.";
    }

    // Validate email addresses
    if (
        $hospital_email !== "" &&
        !filter_var($hospital_email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = "Please enter a valid hospital email address.";
    }

    if (
        $admin_email !== "" &&
        !filter_var($admin_email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = "Please enter a valid administrator email address.";
    }

    // Validate password
    if ($password !== "" && strlen($password) < 8) {
        $errors[] = "The password must contain at least 8 characters.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "The passwords do not match.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Check whether the registration number already exists
            $stmt = $pdo->prepare(
                "SELECT hospital_id
                 FROM hospitals
                 WHERE registration_number = ?"
            );

            $stmt->execute([$registration_number]);

            if ($stmt->fetch()) {
                $errors[] = "This hospital registration number is already registered.";
            }

            // Check whether the administrator email already exists
            $stmt = $pdo->prepare(
                "SELECT user_id
                 FROM users
                 WHERE email = ?"
            );

            $stmt->execute([$admin_email]);

            if ($stmt->fetch()) {
                $errors[] = "This administrator email is already registered.";
            }

            if (empty($errors)) {

                // Create hospital with pending approval
                $stmt = $pdo->prepare(
                    "INSERT INTO hospitals
                    (
                        hospital_name,
                        registration_number,
                        address,
                        phone,
                        email,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, 'pending')"
                );

                $stmt->execute([
                    $hospital_name,
                    $registration_number,
                    $address,
                    $hospital_phone,
                    $hospital_email
                ]);

                $hospital_id = $pdo->lastInsertId();

                // Securely hash the administrator password
                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Create the hospital administrator account
                $stmt = $pdo->prepare(
                    "INSERT INTO users
                    (
                        hospital_id,
                        full_name,
                        email,
                        phone,
                        password_hash,
                        role,
                        account_status
                    )
                    VALUES (?, ?, ?, ?, ?, 'hospital_admin', 'pending')"
                );

                $stmt->execute([
                    $hospital_id,
                    $admin_name,
                    $admin_email,
                    $admin_phone,
                    $password_hash
                ]);

                $pdo->commit();

                $success = "Hospital registration submitted successfully! Your hospital must be approved by the platform administrator before you can access the management dashboard.";

                // Clear submitted values after successful registration
                $hospital_name = "";
                $registration_number = "";
                $address = "";
                $hospital_phone = "";
                $hospital_email = "";
                $admin_name = "";
                $admin_email = "";
                $admin_phone = "";
            } else {
                $pdo->rollBack();
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Hospital registration error: " . $e->getMessage());

            $errors[] = "Registration could not be completed. Please check your details and try again.";
        }
    }
}

// Safely display submitted values in the form
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register Hospital | SmartQueue</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .registration-container {
            max-width: 750px;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .registration-container h1 {
            text-align: center;
            margin-bottom: 10px;
        }

        .registration-description {
            text-align: center;
            margin-bottom: 30px;
            color: #666;
        }

        .form-section {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }

        .form-section h2 {
            font-size: 20px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 15px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .submit-button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 6px;
            background: #087f8c;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        .submit-button:hover {
            background: #066570;
        }

        .message {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error-message {
            background: #ffe5e5;
            color: #a00000;
        }

        .success-message {
            background: #e2f7e8;
            color: #176b32;
        }

        .form-footer {
            text-align: center;
            margin-top: 20px;
        }

        .form-footer a {
            color: #087f8c;
        }
    </style>
</head>

<body>

    <div class="registration-container">

        <h1>Register Your Hospital 🏥</h1>

        <p class="registration-description">
            Join SmartQueue to manage appointments and hospital queues.
        </p>

        <?php if (!empty($errors)): ?>
            <div class="message error-message">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="message success-message">
                <?= escape($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="form-section">
                <h2>Hospital Details</h2>

                <div class="form-group">
                    <label for="hospital_name">Hospital Name</label>
                    <input
                        type="text"
                        id="hospital_name"
                        name="hospital_name"
                        value="<?= escape($hospital_name) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="registration_number">
                        Hospital Registration Number
                    </label>
                    <input
                        type="text"
                        id="registration_number"
                        name="registration_number"
                        value="<?= escape($registration_number) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="address">Hospital Address</label>
                    <textarea
                        id="address"
                        name="address"
                        required
                    ><?= escape($address) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="hospital_phone">Hospital Phone Number</label>
                    <input
                        type="tel"
                        id="hospital_phone"
                        name="hospital_phone"
                        value="<?= escape($hospital_phone) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="hospital_email">Hospital Email</label>
                    <input
                        type="email"
                        id="hospital_email"
                        name="hospital_email"
                        value="<?= escape($hospital_email) ?>"
                        required
                    >
                </div>
            </div>

            <div class="form-section">
                <h2>Hospital Administrator Details</h2>

                <div class="form-group">
                    <label for="admin_name">Administrator Full Name</label>
                    <input
                        type="text"
                        id="admin_name"
                        name="admin_name"
                        value="<?= escape($admin_name) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="admin_email">Administrator Email</label>
                    <input
                        type="email"
                        id="admin_email"
                        name="admin_email"
                        value="<?= escape($admin_email) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="admin_phone">Administrator Phone Number</label>
                    <input
                        type="tel"
                        id="admin_phone"
                        name="admin_phone"
                        value="<?= escape($admin_phone) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Create Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="submit-button">
                Submit Hospital Registration
            </button>

        </form>

        <div class="form-footer">
            Already registered?
            <a href="login.php">Log in</a>
        </div>

    </div>

</body>
</html>