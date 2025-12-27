<?php
// Create database connection
$conn = mysqli_connect("localhost", "root", "");

if (!$conn) {
    die("Could not connect to MySQL: " . mysqli_connect_error());
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS gear_guard_db";
if (mysqli_query($conn, $sql)) {
    echo "Database created successfully<br>";
} else {
    echo "Error creating database: " . mysqli_error($conn) . "<br>";
}

// Select database
mysqli_select_db($conn, "gear_guard_db");

// Create tables
$tables = [];

// Users table
$tables[] = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    role VARCHAR(20) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// Departments table
$tables[] = "CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// Equipment categories table
$tables[] = "CREATE TABLE IF NOT EXISTS equipment_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT
)";

// Maintenance teams table
$tables[] = "CREATE TABLE IF NOT EXISTS maintenance_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// Equipment table
$tables[] = "CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    serial_number VARCHAR(100),
    category_id INT,
    department_id INT,
    maintenance_team_id INT,
    purchase_date DATE,
    warranty_expiry DATE,
    location VARCHAR(200),
    status VARCHAR(20) DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// Maintenance requests table
$tables[] = "CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50),
    subject VARCHAR(200) NOT NULL,
    description TEXT,
    equipment_id INT NOT NULL,
    type VARCHAR(20) DEFAULT 'Corrective',
    priority VARCHAR(20) DEFAULT 'Medium',
    status VARCHAR(20) DEFAULT 'New',
    scheduled_date DATE,
    actual_start_date DATETIME,
    actual_end_date DATETIME,
    duration_hours DECIMAL(5,2),
    assigned_to INT,
    maintenance_team_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Execute table creation
foreach ($tables as $tableSql) {
    if (mysqli_query($conn, $tableSql)) {
        echo "Table created successfully<br>";
    } else {
        echo "Error creating table: " . mysqli_error($conn) . "<br>";
    }
}

// Insert sample data
// Users
$sampleData[] = "INSERT IGNORE INTO users (username, password, full_name, role) VALUES
    ('admin', MD5('admin123'), 'System Administrator', 'admin'),
    ('tech1', MD5('tech123'), 'John Technician', 'technician'),
    ('user1', MD5('user123'), 'Regular User', 'user')";

// Departments
$sampleData[] = "INSERT IGNORE INTO departments (name, description) VALUES
    ('Production', 'Manufacturing and production department'),
    ('IT', 'Information Technology department'),
    ('Facilities', 'Building and facilities management')";

// Categories
$sampleData[] = "INSERT IGNORE INTO equipment_categories (name) VALUES
    ('CNC Machines'), 
    ('Computers'), 
    ('Vehicles'), 
    ('Office Equipment')";

// Teams
$sampleData[] = "INSERT IGNORE INTO maintenance_teams (name, specialization) VALUES
    ('Mechanics Team', 'Mechanical repairs'),
    ('IT Support Team', 'Computer and network support'),
    ('Electrical Team', 'Electrical systems maintenance')";

// Equipment
$sampleData[] = "INSERT IGNORE INTO equipment (name, serial_number, category_id, department_id, location) VALUES
    ('CNC Machine 01', 'CNC-2023-001', 1, 1, 'Production Floor A'),
    ('Office Laptop 01', 'LT-2023-001', 2, 2, 'IT Department'),
    ('Delivery Van', 'VAN-2022-001', 3, 3, 'Parking Lot')";

// Maintenance requests
$sampleData[] = "INSERT IGNORE INTO maintenance_requests (request_number, subject, equipment_id, type, priority, status) VALUES
    ('REQ-2023-001', 'Leaking hydraulic fluid', 1, 'Corrective', 'High', 'In Progress'),
    ('REQ-2023-002', 'Monthly preventive maintenance', 2, 'Preventive', 'Medium', 'New'),
    ('REQ-2023-003', 'Engine oil change', 3, 'Preventive', 'Low', 'Repaired')";

// Execute sample data insertion
foreach ($sampleData as $dataSql) {
    if (mysqli_query($conn, $dataSql)) {
        echo "Sample data inserted<br>";
    } else {
        echo "Error inserting data: " . mysqli_error($conn) . "<br>";
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Setup Complete</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 50px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4CAF50;
            margin-bottom: 20px;
        }
        .success {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #4CAF50;
        }
        .credentials {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #2196F3;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            font-weight: 500;
        }
        .btn:hover {
            background: #5a6fd8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ Database Setup Complete!</h1>
        
        <div class="success">
            <h3>Database and tables have been created successfully.</h3>
            <p>Sample data has been inserted into the database.</p>
        </div>
        
        <div class="credentials">
            <h3>Default Login Credentials:</h3>
            <ul>
                <li><strong>Username:</strong> admin</li>
                <li><strong>Password:</strong> admin123</li>
                <li><strong>Role:</strong> Administrator</li>
            </ul>
            <p>Additional users: tech1 / tech123 (Technician), user1 / user123 (Regular User)</p>
        </div>
        
        <h3>Next Steps:</h3>
        <ol>
            <li>Click the button below to go to the login page</li>
            <li>Login with the admin credentials</li>
            <li>Start managing your equipment and maintenance requests</li>
        </ol>
        
        <a href="index.php" class="btn">🚀 Go to Login Page</a>
    </div>
</body>
</html>