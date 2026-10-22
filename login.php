<?php
session_start();

$error = "";
$success = false;

$admin_username = "sherdil";
$admin_password = "2139898";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($username === $admin_username && $password === $admin_password) {

        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_username"] = $username;

        $success = true;

    } else {

        $error = "Username or password is incorrect.";

    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Login | Awaisi Emporium</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>

        * {
            margin:  0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 25px;
            font-family: "Montserrat", sans-serif;
            background: #4b101d;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                linear-gradient(
                    90deg,
                    rgba(58, 8, 20, 0.94),
                    rgba(77, 13, 29, 0.82)
                ),
                url("https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1800&q=85");

            background-size: cover;
            background-position: center;
            filter: blur(5px);
            transform: scale(1.06);
            z-index: -2;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(
                    circle at top left,
                    rgba(218, 174, 92, 0.20),
                    transparent 35%
                ),
                radial-gradient(
                    circle at bottom right,
                    rgba(255, 230, 160, 0.12),
                    transparent 35%
                );
            z-index: -1;
        }

        .login-wrapper {
            width: 100%;
            max-width: 980px;
            min-height: 570px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid rgba(236, 197, 112, 0.45);
            border-radius: 24px;
            overflow: hidden;
            background: rgba(255, 250, 239, 0.12);
            box-shadow:
                0 25px 80px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(18px);
        }

        .brand-section {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 50px;
            background:
                linear-gradient(
                    145deg,
                    rgba(103, 22, 40, 0.90),
                    rgba(55, 8, 20, 0.95)
                );
            border-right: 1px solid rgba(236, 197, 112, 0.25);
        }

        .ae-logo {
            width: 125px;
            height: 125px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            border: 2px solid #e2b968;
            border-radius: 50%;
            color: #f4d48d;
            font-family: "Cormorant Garamond", serif;
            font-size: 58px;
            font-weight: 700;
            letter-spacing: -7px;
            text-indent: -6px;
            box-shadow:
                0 0 0 8px rgba(226, 185, 104, 0.07),
                0 0 35px rgba(226, 185, 104, 0.15);
        }

        .brand-section h1 {
            color: #f5d58e;
            font-family: "Cormorant Garamond", serif;
            font-size: 46px;
            font-weight: 700;
            letter-spacing: 2px;
            line-height: 1;
        }

        .brand-section h2 {
            margin-top: 12px;
            color: #fff8e8;
            font-family: "Cormorant Garamond", serif;
            font-size: 25px;
            font-weight: 500;
            letter-spacing: 1px;
        }

        .brand-section p {
            max-width: 300px;
            margin-top: 22px;
            color: #eadbc1;
            font-size: 13px;
            line-height: 1.8;
        }

        .gold-line {
            width: 80px;
            height: 2px;
            margin-top: 25px;
            background: #e2b968;
        }

        .login-section {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 55px;
            background: rgba(255, 250, 239, 0.96);
        }

        .login-section .small-heading {
            color: #9b6c27;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .login-section h2 {
            margin-top: 10px;
            color: #571426;
            font-family: "Cormorant Garamond", serif;
            font-size: 43px;
            line-height: 1;
        }

        .login-section .description {
            margin-top: 14px;
            color: #806d62;
            font-size: 13px;
            line-height: 1.7;
        }

        .login-form {
            margin-top: 32px;
        }

        .input-group {
            margin-bottom: 21px;
        }

        .input-group label {
            display: block;
            margin-bottom: 9px;
            color: #571426;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .input-group input {
            width: 100%;
            height: 52px;
            padding: 0 16px;
            border: 1px solid #e1d1ba;
            border-radius: 10px;
            outline: none;
            background: #fffdf8;
            color: #42101d;
            font-family: "Montserrat", sans-serif;
            font-size: 13px;
            transition: 0.3s ease;
        }

        .input-group input:focus {
            border-color: #b98535;
            box-shadow: 0 0 0 3px rgba(185, 133, 53, 0.12);
        }

        .login-button {
            width: 100%;
            height: 53px;
            margin-top: 8px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            background: linear-gradient(135deg, #701b32, #4b0e1e);
            color: #f9d990;
            font-family: "Montserrat", sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            box-shadow: 0 10px 25px rgba(75, 14, 30, 0.20);
            transition: 0.3s ease;
        }

        .login-button:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #84243e, #5a1226);
            box-shadow: 0 14px 30px rgba(75, 14, 30, 0.28);
        }

        .back-link {
            display: block;
            margin-top: 25px;
            color: #98733e;
            font-size: 12px;
            text-align: center;
            text-decoration: none;
            transition: 0.3s ease;
        }

        .back-link:hover {
            color: #571426;
        }

        .footer-text {
            margin-top: 30px;
            color: #aa988a;
            font-size: 10px;
            text-align: center;
            letter-spacing: 0.5px;
        }

        /* Custom Alert */

        .alert-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(35, 5, 14, 0.70);
            backdrop-filter: blur(8px);
            z-index: 9999;
        }

        .alert-box {
            width: 100%;
            max-width: 390px;
            padding: 35px 28px;
            border: 1px solid rgba(226, 185, 104, 0.55);
            border-radius: 20px;
            background: #fffaf0;
            text-align: center;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35);
            animation: alertShow 0.35s ease;
        }

        @keyframes alertShow {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.96);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .alert-logo {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border: 2px solid #c4923e;
            border-radius: 50%;
            color: #6b172d;
            font-family: "Cormorant Garamond", serif;
            font-size: 33px;
            font-weight: 700;
            letter-spacing: -4px;
            text-indent: -4px;
        }

        .alert-icon {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #f4e4c2;
            color: #751c34;
            font-size: 34px;
            font-weight: 700;
        }

        .alert-box h3 {
            color: #571426;
            font-family: "Cormorant Garamond", serif;
            font-size: 32px;
            font-weight: 700;
        }

        .alert-box p {
            margin-top: 12px;
            color: #806d62;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-button {
            width: 100%;
            height: 46px;
            margin-top: 25px;
            border: none;
            border-radius: 9px;
            cursor: pointer;
            background: #65162b;
            color: #f8d58f;
            font-family: "Montserrat", sans-serif;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: 0.3s ease;
        }

        .alert-button:hover {
            background: #84243e;
        }

        @media (max-width: 800px) {

            .login-wrapper {
                max-width: 500px;
                grid-template-columns: 1fr;
            }

            .brand-section {
                min-height: 300px;
                padding: 35px 25px;
                border-right: none;
                border-bottom: 1px solid rgba(236, 197, 112, 0.25);
            }

            .ae-logo {
                width: 85px;
                height: 85px;
                margin-bottom: 18px;
                font-size: 40px;
            }

            .brand-section h1 {
                font-size: 36px;
            }

            .brand-section h2 {
                font-size: 21px;
            }

            .brand-section p {
                margin-top: 15px;
            }

            .gold-line {
                margin-top: 18px;
            }

            .login-section {
                padding: 40px 28px;
            }

            .login-section h2 {
                font-size: 37px;
            }
        }

        @media (max-width: 420px) {

            body {
                padding: 12px;
            }

            .brand-section {
                min-height: 270px;
            }

            .login-section {
                padding: 32px 22px;
            }

            .login-section h2 {
                font-size: 34px;
            }
        }

    </style>
</head>

<body>

    <div class="login-wrapper">

        <!-- Brand Section -->

        <section class="brand-section">

            <div class="ae-logo">AE</div>

            <h1>Awaisi</h1>
            <h2>Emporium</h2>

            <div class="gold-line"></div>

            <p>
                Welcome to the Awaisi Emporium administration panel.
                Manage products, orders, customers and your online store
                from one elegant dashboard.
            </p>

        </section>


        <!-- Login Section -->

        <section class="login-section">

            <span class="small-heading">Admin Access</span>

            <h2>Welcome Back</h2>

            <p class="description">
                Sign in to access your Awaisi Emporium dashboard.
            </p>

            <form method="POST" class="login-form">

                <div class="input-group">
                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        required
                        autocomplete="username"
                    >
                </div>

                <div class="input-group">
                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button type="submit" class="login-button">
                    Login to Dashboard
                </button>

            </form>

            <a href="index.php" class="back-link">
                ← Back to Website
            </a>

            <div class="footer-text">
                © <?php echo date("Y"); ?> Awaisi Emporium. All Rights Reserved.
            </div>

        </section>

    </div>


    <!-- Incorrect Login Alert -->

    <?php if (!empty($error)): ?>

        <div class="alert-overlay">

            <div class="alert-box">

                <div class="alert-icon">!</div>

                <h3>Login Failed</h3>

                <p>
                    Username or password is incorrect.
                    Please check your details and try again.
                </p>

                <button
                    type="button"
                    class="alert-button"
                    onclick="closeAlert()"
                >
                    Try Again
                </button>

            </div>

        </div>

    <?php endif; ?>


    <!-- Successful Login Alert -->

    <?php if ($success): ?>

        <div class="alert-overlay">

            <div class="alert-box">

                <div class="alert-logo">AE</div>

                <h3>Login Successful</h3>

                <p>
                    Welcome to Awaisi Emporium Admin Dashboard.
                    You have successfully logged in.
                </p>

                <button
                    type="button"
                    class="alert-button"
                    onclick="goToDashboard()"
                >
                    Continue to Dashboard
                </button>

            </div>

        </div>

    <?php endif; ?>


    <script>

        function closeAlert() {
            const alertOverlay = document.querySelector(".alert-overlay");

            if (alertOverlay) {
                alertOverlay.remove();
            }
        }

        function goToDashboard() {
            window.location.href = "dashboard.php";
        }

    </script>

</body>

</html>