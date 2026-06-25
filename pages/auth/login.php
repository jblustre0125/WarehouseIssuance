<?php
require_once __DIR__ . '/../../includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $conn = get_whpokayoke_connection();

    $user = fetch_one(
        $conn,
        'SELECT TOP 1 * FROM AppUsers WHERE Username = ? AND IsActive = 1',
        [$username]
    );

    if ($user && password_verify($password, $user['PasswordHash'])) {
        $_SESSION['user'] = [
            'id' => $user['UserID'],
            'username' => $user['Username'],
            'full_name' => $user['FullName'],
            'role' => strtolower($user['RoleName']),
            'receiver_area' => $user['ReceiverArea'] ?? '',
            'device_hostname' => $user['DeviceHostname'] ?? '',
            'device_ip' => $user['DeviceIPAddress'] ?? ''
        ];

        sqlsrv_query(
            $conn,
            'UPDATE AppUsers 
             SET LastLoginAt = GETDATE(), LastLoginHostname = ?, LastLoginIPAddress = ? 
             WHERE UserID = ?',
            [client_hostname(), client_ip(), $user['UserID']]
        );

        header('Location: ' . app_path('index.php'));
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <title>Login | NBC Rawmats Traceability</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <base href="<?= h(app_path('')) ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background: #f4f7fb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border: 1px solid #e5eaf2;
            border-radius: 20px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            padding: 32px;
        }

        .brand-logo {
            width: 120px;
            height: 90px;
            margin: 0 auto 14px;
            border-radius: 0;
            overflow: hidden;
            background: transparent;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .login-title {
            text-align: center;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 6px;
            letter-spacing: -0.03em;
        }

        .login-subtitle {
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 26px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-control {
            min-height: 46px;
            border-radius: 12px;
            border: 1px solid #d9e2ef;
            background: #f9fbfd;
            font-size: 15px;
        }

        .form-control:focus {
            background: #ffffff;
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.12);
        }

        .btn-login {
            min-height: 48px;
            border-radius: 12px;
            font-weight: 700;
            background: #0d6efd;
            border: none;
        }

        .btn-login:hover {
            background: #0b5ed7;
        }

        .alert {
            border-radius: 12px;
            font-size: 14px;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 26px;
                border-radius: 18px;
            }

            .brand-logo {
                width: 105px;
                height: 75px;
                margin-bottom: 12px;
            }
        }
    </style>
</head>

<body>
<div class="login-wrapper">
    <div class="login-card">

        <div class="brand-logo">
            <img src="image/nbc-bg-dashboard.jpg" alt="NBC Logo">
        </div>

        <h4 class="login-title">Rawmats Traceability</h4>
        <div class="login-subtitle">Login with your assigned role</div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label class="form-label" for="username">Employee Code</label>
                <input
                    class="form-control"
                    id="username"
                    name="username"
                    required
                    autofocus
                    placeholder="Enter employee code"
                    value="<?= h($_POST['username'] ?? '') ?>"
                >
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input
                    class="form-control"
                    id="password"
                    type="password"
                    name="password"
                    required
                    placeholder="Enter password"
                >
            </div>

            <button class="btn btn-primary btn-login w-100" type="submit">
                Login
            </button>
        </form>

    </div>
</div>
</body>
</html>
