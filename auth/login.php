<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$error = "";
$email = "";


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {

        $error = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Invalid email or password.";

    } else {

        try {

            $stmt = $pdo->prepare(
                "SELECT user_id, full_name, email, password_hash,
                        role, account_status, hospital_id
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (
                $user &&
                password_verify($password, $user["password_hash"]) &&
                $user["account_status"] === "active"
            ) {

                // Verify hospital status for hospital-linked accounts.
                if (
                    in_array(
                        $user["role"],
                        ["hospital_admin", "doctor"],
                        true
                    )
                ) {

                    if (empty($user["hospital_id"])) {

                        $error = "Your account is not linked to a hospital.";

                    } else {

                        $hospitalStmt = $pdo->prepare(
                            "SELECT status
                             FROM hospitals
                             WHERE hospital_id = ?
                             LIMIT 1"
                        );

                        $hospitalStmt->execute([$user["hospital_id"]]);
                        $hospitalStatus = $hospitalStmt->fetchColumn();

                        if ($hospitalStatus !== "active") {
                            $error = "Your hospital is not active. Please contact the Super Admin.";
                        }
                    }
                }

                // Create a session only if all checks pass.
                if ($error === "") {
                        if (isset($_SESSION['access_error'])) {
    $error = $_SESSION['access_error'];
    unset($_SESSION['access_error']);
}
                    session_regenerate_id(true);

                    $_SESSION["user_id"] = (int) $user["user_id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["email"] = $user["email"];
                    $_SESSION["role"] = $user["role"];
                    $_SESSION["hospital_id"] = $user["hospital_id"];

                    switch ($user["role"]) {

                        case "patient":
                            header("Location: ../patient/dashboard.php");
                            exit;

                        case "doctor":
                            header("Location: ../doctor/dashboard.php");
                            exit;

                        case "hospital_admin":
                            header("Location: ../hospital_admin/dashboard.php");
                            exit;

                        case "super_admin":
                            header("Location: ../super_admin/dashboard.php");
                            exit;

                        default:
                            $_SESSION = [];
                            session_destroy();
                            $error = "Your account has an invalid role.";
                    }
                }

            } else {

                $error = "Invalid email or password, or your account is not active.";

            }

        } catch (PDOException $e) {

            error_log("Login error: " . $e->getMessage());
            $error = "Unable to log in right now. Please try again.";

        }
    }
}


?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login | SmartQueue</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .form-section {
            padding: 55px 0;
        }

        .form-card {
            max-width: 480px;
            margin: 0 auto;
            background: white;
            padding: 35px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.04);
        }

        .form-card h1 {
            color: #083344;
            margin-bottom: 8px;
        }

        .form-intro {
            color: #64748b;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        .form-card button {
            width: 100%;
            margin-top: 5px;
        }

        .form-footer {
            margin-top: 20px;
            text-align: center;
        }

        .form-footer a {
            color: #0f766e;
            font-weight: bold;
        }

        .error-box {
            background: #fef2f2;
            color: #991b1b;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .success-box {
            background: #f0fdf4;
            color: #166534;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 20px;
        }
    </style>

</head>

<body>

    <header class="navbar">

        <div class="container navbar-content">

            <a href="../index.php" class="logo">
                SmartQueue
            </a>

            <nav class="nav-links">
                <a href="../index.php">Home</a>
                <a href="register.php">Register</a>
            </nav>

        </div>

    </header>


    <main class="form-section">

        <div class="container">

            <div class="form-card">

                <h1>Welcome Back</h1>

                <p class="form-intro">
                    Log in to access your SmartQueue account.
                </p>


                <?php if (isset($_SESSION["success_message"])): ?>

                    <div class="success-box">

                        <?= htmlspecialchars(
                            $_SESSION["success_message"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>

                    <?php unset($_SESSION["success_message"]); ?>

                <?php endif; ?>


                <?php if ($error !== ""): ?>

                    <div class="error-box">

                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>

                <?php endif; ?>


                <form method="POST" action="login.php">

                    <div class="form-group">

                        <label for="email">
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            autocomplete="username"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="password">
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >

                    </div>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Log In
                    </button>

                </form>


                <div class="form-footer">

                    <p>
                        Don't have an account?
                        <a href="register.php">Register here</a>
                    </p>

                    <p style="margin-top: 15px;">
                        <a href="../index.php">
                            Return to homepage
                        </a>
                    </p>

                </div>

            </div>

        </div>

    </main>


    <footer class="footer">

        <div class="container">

            <p>
                &copy; <?= date("Y") ?> SmartQueue
            </p>

        </div>

    </footer>

</body>

</html>