-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 27, 2025 at 11:56 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gear_guard`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(1, 'Production', 'Manufacturing and production department', 'active', '2025-12-27 06:24:45'),
(2, 'IT', 'Information Technology department', 'active', '2025-12-27 06:24:45'),
(3, 'Facilities', 'Building and facilities management', 'active', '2025-12-27 06:24:45');

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `purchase_date` date DEFAULT NULL,
  `warranty_date` date DEFAULT NULL,
  `maintenance_team_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`id`, `name`, `serial_number`, `category_id`, `department_id`, `location`, `status`, `notes`, `created_at`, `purchase_date`, `warranty_date`, `maintenance_team_id`, `created_by`) VALUES
(1, 'CNC Machine 01', 'CNC-2023-001', 1, 1, 'Production Floor A', 'active', 'Primary production machine', '2025-12-27 06:24:45', NULL, NULL, 1, NULL),
(2, 'Office Laptop 01', 'LT-2023-001', 2, 2, 'IT Department', 'active', 'IT department laptop', '2025-12-27 06:24:45', NULL, NULL, 2, NULL),
(3, 'Delivery Van', 'VAN-2022-001', 3, 3, 'Parking Lot', 'active', 'General delivery vehicle', '2025-12-27 06:24:45', NULL, NULL, 1, NULL),
(5, 'printer', 'uoewfhoh2ee44', 2, 2, 'floor 3', 'active', '8oiol', '2025-12-27 10:43:48', '2025-12-05', '2026-12-17', 2, 1),
(7, 'CNC 4', 'sgrdtfjhg546789', 1, 1, 'Factory area 23', 'active', 'efr', '2025-12-27 10:53:11', '2025-12-27', '2026-10-13', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `equipment_categories`
--

CREATE TABLE `equipment_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment_categories`
--

INSERT INTO `equipment_categories` (`id`, `name`, `description`, `status`) VALUES
(1, 'CNC Machines', 'Computer Numerical Control machines', 'active'),
(2, 'Computers', 'Computers and IT equipment', 'active'),
(3, 'Vehicles', 'Transportation vehicles', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_attachments`
--

CREATE TABLE `maintenance_attachments` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_comments`
--

CREATE TABLE `maintenance_comments` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_history`
--

CREATE TABLE `maintenance_history` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_notes`
--

CREATE TABLE `maintenance_notes` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_requests`
--

CREATE TABLE `maintenance_requests` (
  `id` int(11) NOT NULL,
  `request_number` varchar(50) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `equipment_id` int(11) NOT NULL,
  `type` varchar(20) DEFAULT 'Corrective',
  `priority` varchar(20) DEFAULT 'Medium',
  `status` varchar(20) DEFAULT 'New',
  `scheduled_date` date DEFAULT NULL,
  `duration_hours` int(11) DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `actual_cost` decimal(10,2) DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_requests`
--

INSERT INTO `maintenance_requests` (`id`, `request_number`, `subject`, `description`, `equipment_id`, `type`, `priority`, `status`, `scheduled_date`, `duration_hours`, `estimated_cost`, `actual_cost`, `completion_date`, `assigned_to`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'REQ-001', 'Monthly preventive maintenance', 'Regular monthly maintenance for CNC machine', 1, 'Preventive', 'Medium', 'Scheduled', '2025-12-28', NULL, NULL, NULL, NULL, 2, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45'),
(2, 'REQ-002', 'Laptop software update', 'Update operating system and software', 2, 'Preventive', 'Low', 'Scheduled', '2025-12-29', NULL, NULL, NULL, NULL, 2, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45'),
(3, 'REQ-003', 'Vehicle oil change', 'Regular oil change for delivery van', 3, 'Preventive', 'Medium', 'Scheduled', '2025-12-30', NULL, NULL, NULL, NULL, 2, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45'),
(4, 'REQ-004', 'CNC calibration', 'Quarterly calibration check', 1, 'Preventive', 'High', 'Scheduled', '2026-01-05', NULL, NULL, NULL, NULL, NULL, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45'),
(5, 'REQ-005', 'Server maintenance', 'Server hardware check', 2, 'Corrective', 'Medium', 'Scheduled', '2026-01-10', NULL, NULL, NULL, NULL, NULL, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45'),
(6, 'REQ-006', 'Brake inspection', 'Vehicle brake system inspection', 3, 'Preventive', 'High', 'Scheduled', '2026-01-15', NULL, NULL, NULL, NULL, NULL, 1, '2025-12-27 06:24:45', '2025-12-27 06:24:45');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedules`
--

CREATE TABLE `maintenance_schedules` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `schedule_type` enum('daily','weekly','monthly','quarterly','yearly') NOT NULL,
  `description` text DEFAULT NULL,
  `next_schedule` date NOT NULL,
  `last_performed` date DEFAULT NULL,
  `assigned_team` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_teams`
--

CREATE TABLE `maintenance_teams` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_teams`
--

INSERT INTO `maintenance_teams` (`id`, `name`, `specialization`, `description`, `status`, `created_at`) VALUES
(1, 'Mechanics Team', 'Mechanical repairs', 'Handles mechanical equipment', 'active', '2025-12-27 06:24:45'),
(2, 'IT Support Team', 'Computer and network support', 'IT equipment maintenance', 'active', '2025-12-27 06:24:45'),
(3, 'Electrical Team', 'Electrical systems maintenance', 'Electrical maintenance team', 'active', '2025-12-27 06:24:45');

-- --------------------------------------------------------

--
-- Table structure for table `request_attachments`
--

CREATE TABLE `request_attachments` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team_members`
--

CREATE TABLE `team_members` (
  `id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_in_team` varchar(50) DEFAULT 'Member',
  `joined_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `team_members`
--

INSERT INTO `team_members` (`id`, `team_id`, `user_id`, `role_in_team`, `joined_date`, `status`) VALUES
(1, 1, 2, 'Lead Technician', '2025-01-01', 'active'),
(2, 2, 1, 'IT Manager', '2025-01-01', 'active'),
(3, 3, 3, 'Electrician', '2025-01-01', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','technician','manager','user') DEFAULT 'user',
  `team_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `role`, `team_id`, `status`, `created_at`) VALUES
(1, 'admin', '0192023a7bbd73250516f069df18b500', 'admin@gearguard.com', 'System Administrator', 'admin', 2, 'active', '2025-12-27 06:24:45'),
(2, 'tech1', '7b591a2a55585e166465a838c28a2c5f', 'tech1@gearguard.com', 'John Technician', 'technician', 1, 'active', '2025-12-27 06:24:45'),
(3, 'user1', '6ad14ba9986e3615423dfca256d04e3f', 'user1@gearguard.com', 'Regular User', 'user', 3, 'active', '2025-12-27 06:24:45'),
(4, 'tech2', '$2y$10$YourHashedPasswordHere', 'tech2@gearguard.com', 'Jane Technician', 'technician', 1, 'active', '2025-12-27 09:11:52');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `maintenance_team_id` (`maintenance_team_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_equipment_status` (`status`);

--
-- Indexes for table `equipment_categories`
--
ALTER TABLE `equipment_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_attachments`
--
ALTER TABLE `maintenance_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `maintenance_history`
--
ALTER TABLE `maintenance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `maintenance_notes`
--
ALTER TABLE `maintenance_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_requests_status` (`status`),
  ADD KEY `idx_requests_priority` (`priority`),
  ADD KEY `idx_requests_equipment` (`equipment_id`),
  ADD KEY `idx_requests_assigned` (`assigned_to`);

--
-- Indexes for table `maintenance_schedules`
--
ALTER TABLE `maintenance_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `assigned_team` (`assigned_team`);

--
-- Indexes for table `maintenance_teams`
--
ALTER TABLE `maintenance_teams`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `request_attachments`
--
ALTER TABLE `request_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `request_id` (`request_id`);

--
-- Indexes for table `team_members`
--
ALTER TABLE `team_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `team_user` (`team_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_users_team` (`team_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `equipment_categories`
--
ALTER TABLE `equipment_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maintenance_attachments`
--
ALTER TABLE `maintenance_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_history`
--
ALTER TABLE `maintenance_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_notes`
--
ALTER TABLE `maintenance_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `maintenance_schedules`
--
ALTER TABLE `maintenance_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_teams`
--
ALTER TABLE `maintenance_teams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `request_attachments`
--
ALTER TABLE `request_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `team_members`
--
ALTER TABLE `team_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`),
  ADD CONSTRAINT `activity_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`maintenance_team_id`) REFERENCES `maintenance_teams` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `equipment_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_attachments`
--
ALTER TABLE `maintenance_attachments`
  ADD CONSTRAINT `maintenance_attachments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  ADD CONSTRAINT `maintenance_comments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `maintenance_history`
--
ALTER TABLE `maintenance_history`
  ADD CONSTRAINT `maintenance_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_history_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `maintenance_notes`
--
ALTER TABLE `maintenance_notes`
  ADD CONSTRAINT `maintenance_notes_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `maintenance_schedules`
--
ALTER TABLE `maintenance_schedules`
  ADD CONSTRAINT `maintenance_schedules_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  ADD CONSTRAINT `maintenance_schedules_ibfk_2` FOREIGN KEY (`assigned_team`) REFERENCES `maintenance_teams` (`id`);

--
-- Constraints for table `request_attachments`
--
ALTER TABLE `request_attachments`
  ADD CONSTRAINT `request_attachments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`);

--
-- Constraints for table `team_members`
--
ALTER TABLE `team_members`
  ADD CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `maintenance_teams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
