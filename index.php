<?php
// index.php - Login Page
session_start();

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: pages/dashboard.php');
    } elseif ($_SESSION['role'] === 'technician') {
        header('Location: pages/technician.php');
    } else {
        header('Location: pages/dashboard.php');
    }
    exit();
}

// Database connection
require_once 'includes/db_connect.php';

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Prepare SQL statement to prevent SQL injection
        $query = "SELECT id, username, password, full_name, role, status FROM users WHERE username = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            // Check if user is active
            if ($row['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact administrator.';
            } 
            // Check password (using MD5 as shown in your database)
            elseif (md5($password) === $row['password']) {
                // Login successful - set session variables
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['role'] = $row['role'];
                
                // Redirect based on role
                if ($row['role'] === 'admin') {
                    header('Location: pages/dashboard.php');
                } elseif ($row['role'] === 'technician') {
                    header('Location: technician/technician.php');
                } elseif ($row['role'] === 'user') {
                    header('Location: user/user_dashboard.php');
                } else {
                    header('Location: pages/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GearGuard</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .login-form {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .login-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 14px;
        }
        
        .logo {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .test-credentials {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 13px;
            color: #666;
        }
        
        .test-credentials h4 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .test-credentials ul {
            list-style: none;
            padding-left: 0;
        }
        
        .test-credentials li {
            margin-bottom: 5px;
            padding-left: 20px;
            position: relative;
        }
        
        .test-credentials li:before {
            content: "👉";
            position: absolute;
            left: 0;
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 15px;
            }
            
            .login-header {
                padding: 30px 20px;
            }
            
            .login-form {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🔧</div>
            <h1>GearGuard</h1>
            <p>Maintenance Management System</p>
        </div>
        
        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-input" required 
                           placeholder="Enter your username" autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" required 
                           placeholder="Enter your password" autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn-login">
                    Sign In
                </button>
            </form>
            
            <!-- <div class="test-credentials">
                <h4>Test Credentials:</h4>
                <ul>
                    <li><strong>Admin:</strong> admin / admin123</li>
                    <li><strong>Technician:</strong> tech1 / tech123</li>
                    <li><strong>User:</strong> user1 / user123</li>
                </ul>
            </div> -->
        </div>
        
        <div class="login-footer">
            <p>© 2025 GearGuard. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Add animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.login-container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                container.style.transition = 'opacity 0.5s, transform 0.5s';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        });
        
        // Focus on username field
        document.querySelector('input[name="username"]').focus();
        
        // Show password on icon click (optional)
        const passwordInput = document.querySelector('input[name="password"]');
        const togglePassword = document.createElement('span');
        togglePassword.innerHTML = '👁️';
        togglePassword.style.cssText = 'position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; opacity: 0.5;';
        togglePassword.addEventListener('click', function() {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.innerHTML = '👁️‍🗨️';
            } else {
                passwordInput.type = 'password';
                this.innerHTML = '👁️';
            }
        });
        
        passwordInput.parentElement.style.position = 'relative';
        passwordInput.parentElement.appendChild(togglePassword);
    </script>
</body>
</html>