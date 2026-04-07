-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 13, 2026 at 02:19 AM
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
-- Database: `payrollsystem`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent') DEFAULT 'Present',
  `time_in_am` time DEFAULT NULL,
  `time_out_am` time DEFAULT NULL,
  `time_in_pm` time DEFAULT NULL,
  `time_out_pm` time DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `date`, `status`, `time_in_am`, `time_out_am`, `time_in_pm`, `time_out_pm`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 23, '2026-02-10', 'Absent', NULL, NULL, NULL, NULL, '', '2026-02-10 15:55:32', '2026-02-10 22:51:48'),
(2, 24, '2026-02-11', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-11 09:13:32', '2026-02-11 14:47:41'),
(3, 23, '2026-02-11', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-11 11:12:32', '2026-02-11 11:41:09'),
(4, 25, '2026-02-11', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-11 14:53:03', '2026-02-11 14:53:03'),
(5, 24, '2026-02-12', 'Present', '08:00:00', NULL, NULL, NULL, '', '2026-02-12 05:52:46', '2026-02-12 15:45:02'),
(6, 23, '2026-02-12', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-12 06:09:14', '2026-02-12 15:37:55'),
(7, 26, '2026-02-12', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-12 06:14:33', '2026-02-12 06:14:33'),
(8, 25, '2026-02-12', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-12 06:21:03', '2026-02-12 06:21:03'),
(9, 27, '2026-02-12', 'Present', NULL, NULL, NULL, NULL, 'kamasi', '2026-02-12 13:04:52', '2026-02-12 13:04:52'),
(10, 23, '2026-02-15', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-12 13:30:39', '2026-02-12 13:30:39'),
(11, 24, '2026-02-03', 'Present', NULL, NULL, NULL, NULL, '', '2026-02-12 13:46:16', '2026-02-12 13:46:16'),
(16, 23, '2026-02-13', 'Present', '08:00:00', NULL, NULL, NULL, '', '2026-02-12 16:01:55', '2026-02-12 16:01:55'),
(17, 24, '2026-02-13', 'Present', '08:00:00', NULL, NULL, NULL, '', '2026-02-12 16:42:32', '2026-02-13 01:17:50'),
(18, 28, '2026-02-13', 'Present', '02:00:00', NULL, NULL, NULL, '', '2026-02-12 17:23:12', '2026-02-12 17:23:12'),
(19, 24, '2026-02-02', 'Present', '08:00:00', NULL, NULL, NULL, '', '2026-02-13 01:18:12', '2026-02-13 01:18:12');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_old`
--

CREATE TABLE `attendance_old` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Late','Leave') NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `time_in_am` time DEFAULT NULL,
  `time_out_am` time DEFAULT NULL,
  `time_in_pm` time DEFAULT NULL,
  `time_out_pm` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_old`
--

INSERT INTO `attendance_old` (`id`, `employee_id`, `date`, `status`, `time_in`, `time_out`, `remarks`, `created_at`, `updated_at`, `time_in_am`, `time_out_am`, `time_in_pm`, `time_out_pm`) VALUES
(1, 23, '2026-02-10', 'Absent', NULL, NULL, '', '2026-02-10 15:55:32', '2026-02-10 22:51:48', NULL, NULL, NULL, NULL),
(2, 24, '2026-02-11', 'Present', '10:45:00', '17:10:00', '', '2026-02-11 09:13:32', '2026-02-11 14:47:41', NULL, NULL, NULL, NULL),
(3, 23, '2026-02-11', 'Present', '07:12:00', '19:40:00', '', '2026-02-11 11:12:32', '2026-02-11 11:41:09', NULL, NULL, NULL, NULL),
(4, 25, '2026-02-11', 'Present', '22:05:00', NULL, '', '2026-02-11 14:53:03', '2026-02-11 14:53:03', NULL, NULL, NULL, NULL),
(5, 24, '2026-02-12', 'Absent', '13:50:00', '16:35:00', '', '2026-02-12 05:52:46', '2026-02-12 15:37:39', '07:00:00', NULL, NULL, NULL),
(6, 23, '2026-02-12', 'Present', '09:00:00', NULL, '', '2026-02-12 06:09:14', '2026-02-12 15:37:55', '08:00:00', NULL, NULL, NULL),
(7, 26, '2026-02-12', 'Present', '14:15:00', NULL, '', '2026-02-12 06:14:33', '2026-02-12 06:14:33', NULL, NULL, NULL, NULL),
(8, 25, '2026-02-12', 'Present', '14:20:00', NULL, '', '2026-02-12 06:21:03', '2026-02-12 06:21:03', NULL, NULL, NULL, NULL),
(9, 27, '2026-02-12', 'Present', NULL, NULL, 'kamasi', '2026-02-12 13:04:52', '2026-02-12 13:04:52', NULL, NULL, NULL, NULL),
(10, 23, '2026-02-15', 'Present', '09:30:00', NULL, '', '2026-02-12 13:30:39', '2026-02-12 13:30:39', NULL, NULL, NULL, NULL),
(11, 24, '2026-02-03', 'Present', '21:45:00', NULL, '', '2026-02-12 13:46:16', '2026-02-12 13:46:16', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `custom_deductions`
--

CREATE TABLE `custom_deductions` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `percentage` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `custom_deductions`
--

INSERT INTO `custom_deductions` (`id`, `name`, `percentage`) VALUES
(14, 'sss', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `deduction`
--

CREATE TABLE `deduction` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `deduction_name` varchar(100) NOT NULL,
  `description` varchar(200) NOT NULL,
  `amount` int(100) NOT NULL,
  `date` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','disabled') DEFAULT 'active',
  `salary_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deduction`
--

INSERT INTO `deduction` (`id`, `employee_id`, `deduction_name`, `description`, `amount`, `date`, `start_date`, `end_date`, `status`, `salary_date`) VALUES
(42, 23, 'SSS', '', 5000, NULL, '2026-02-09', '2026-02-10', 'active', NULL),
(43, 24, 'sss', '', 5000, NULL, '2026-02-04', '2026-02-25', 'active', NULL),
(44, 25, 'sss', '', 500, NULL, '2026-02-18', '2026-02-25', 'active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_num` varchar(15) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `civil_status` varchar(20) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `monthly_salary` decimal(10,2) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `salary` int(11) NOT NULL,
  `net_salary` int(50) NOT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `status` enum('active','disabled') DEFAULT 'active',
  `sss_number` varchar(50) DEFAULT NULL,
  `pagibig_number` varchar(50) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `first_name`, `middle_name`, `last_name`, `address`, `contact_num`, `position`, `email`, `gender`, `civil_status`, `date_hired`, `monthly_salary`, `dob`, `salary`, `net_salary`, `is_archived`, `is_active`, `status`, `sss_number`, `pagibig_number`, `tin_number`, `philhealth_number`, `site_id`) VALUES
(23, 'Jay Jay', 'Vinarao', 'Canceran', 'Bangad', '0987654321', 'jungle', 'ejje@gmail.com', 'Male', 'Married', '2026-02-09', 10000.00, '2004-12-09', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL),
(24, 'Davis Kert', 'N.', 'Binalay', 'Tumauini', '0009090909', 'manager', 'daviskertb@gmail.com', 'Male', 'Single', '2026-02-18', 10000.00, '2026-02-03', 0, 0, 0, 1, 'active', '22-7777777-1', '', '', '', NULL),
(25, 'Mark', 'Larenz', 'Paccarangan', 'purok 3', '099', 'hehe', 'aaaa@gmail.com', 'Male', 'Single', '2026-02-03', 9999.99, '2026-02-12', 0, 0, 0, 1, 'active', '', '', '', '', NULL),
(26, 'Clyde', 'l', 'melendez', 'fdfdfdf', '09999', 'dd', 'dd@gmail.com', 'Male', 'Single', '2026-02-03', 10.00, '2026-02-18', 0, 0, 0, 1, 'active', '', '', '', '', NULL),
(27, 'Jaycie', 'Q', 'Quilang', 'tttttt', '0987654321', 'electrician', 'jaycie@gmail.com', 'Male', 'Single', '2026-02-03', 50000.00, '2026-02-01', 0, 0, 0, 1, 'active', '', '', '', '', NULL),
(28, 'Norjay', 'C.', 'Dionesio', 'Bangad, Sta. Maria, Isabela.', '0912345678', 'diko alam', 'Dionesio@gmail.com', 'Male', 'Single', '2026-02-13', 10000.00, '2010-07-04', 0, 0, 0, 1, 'active', '', '', '', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `payroll_type` varchar(50) NOT NULL,
  `status` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `pay_period` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`id`, `employee_id`, `date_from`, `date_to`, `payroll_type`, `status`, `date`, `pay_period`) VALUES
(53, 24, '2026-02-11', '2026-03-11', '', '', '0000-00-00', NULL),
(54, 23, '2026-02-11', '2026-03-11', '', '', '0000-00-00', NULL),
(55, 26, '2026-02-12', '2026-03-12', '', '', '0000-00-00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payroll_employees`
--

CREATE TABLE `payroll_employees` (
  `payroll_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_slip`
--

CREATE TABLE `salary_slip` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `payroll_id` int(11) NOT NULL,
  `deduction_id` int(11) NOT NULL,
  `net_pay` double NOT NULL,
  `pay_period` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_slips`
--

CREATE TABLE `salary_slips` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `month` varchar(20) NOT NULL,
  `year` int(11) DEFAULT year(curdate())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `site_employee`
--

CREATE TABLE `site_employee` (
  `site_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `assigned_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_employee`
--

INSERT INTO `site_employee` (`site_id`, `employee_id`, `assigned_date`, `status`) VALUES
(11, 23, NULL, 'active'),
(11, 26, NULL, 'active'),
(14, 25, NULL, 'active'),
(14, 27, '2026-02-12', 'active'),
(19, 24, '2026-02-12', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `site_monitoring`
--

CREATE TABLE `site_monitoring` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `site_manager` varchar(255) NOT NULL,
  `site_address` varchar(255) NOT NULL,
  `site_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_monitoring`
--

INSERT INTO `site_monitoring` (`id`, `employee_id`, `site_manager`, `site_address`, `site_name`) VALUES
(11, NULL, 'Mark Larenz Paccarangan', 'Sampaloc', 'Main office'),
(14, NULL, 'sdsd', 'sdsds', 'Site A'),
(15, NULL, 'ddd', 'ffff', 'Site B'),
(16, NULL, 'nnnn', 'mmmmm', 'Site C'),
(17, NULL, 'maka', 'sampaloc', 'Site D'),
(18, NULL, 'MAa', 'RRRR', 'Site E'),
(19, NULL, 'Sir. Louis', 'Corola St.', 'Site F');

-- --------------------------------------------------------

--
-- Table structure for table `super_user`
--

CREATE TABLE `super_user` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(100) NOT NULL,
  `security_answer1` varchar(255) DEFAULT NULL,
  `security_answer2` varchar(255) DEFAULT NULL,
  `security_answer3` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `super_user`
--

INSERT INTO `super_user` (`id`, `name`, `username`, `password`, `security_answer1`, `security_answer2`, `security_answer3`) VALUES
(1, 'Super Admin', 'admin', 'admin123', 'Gumiran', 'larenz', 'paccarangan');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`date`);

--
-- Indexes for table `attendance_old`
--
ALTER TABLE `attendance_old`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`date`),
  ADD KEY `idx_attendance_date` (`date`),
  ADD KEY `idx_attendance_employee_date` (`employee_id`,`date`),
  ADD KEY `idx_employee_date` (`employee_id`,`date`),
  ADD KEY `idx_attendance_employee_date_status` (`employee_id`,`date`,`status`);

--
-- Indexes for table `custom_deductions`
--
ALTER TABLE `custom_deductions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deduction`
--
ALTER TABLE `deduction`
  ADD PRIMARY KEY (`id`),
  ADD KEY `deduction to employee` (`employee_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payroll to employee` (`employee_id`);

--
-- Indexes for table `payroll_employees`
--
ALTER TABLE `payroll_employees`
  ADD KEY `payroll_id` (`payroll_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `salary_slip`
--
ALTER TABLE `salary_slip`
  ADD KEY `salary_slip to employee` (`employee_id`),
  ADD KEY `salary_slip to payroll` (`payroll_id`),
  ADD KEY `salary_slip to deduction` (`deduction_id`);

--
-- Indexes for table `salary_slips`
--
ALTER TABLE `salary_slips`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `site_employee`
--
ALTER TABLE `site_employee`
  ADD PRIMARY KEY (`site_id`,`employee_id`),
  ADD KEY `branch_employee_ibfk_2` (`employee_id`),
  ADD KEY `idx_site_id` (`site_id`),
  ADD KEY `idx_employee_id` (`employee_id`),
  ADD KEY `idx_site_employee_site` (`site_id`),
  ADD KEY `idx_site_employee_employee` (`employee_id`);

--
-- Indexes for table `site_monitoring`
--
ALTER TABLE `site_monitoring`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_employee_id` (`employee_id`);

--
-- Indexes for table `super_user`
--
ALTER TABLE `super_user`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `attendance_old`
--
ALTER TABLE `attendance_old`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `custom_deductions`
--
ALTER TABLE `custom_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `deduction`
--
ALTER TABLE `deduction`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `salary_slips`
--
ALTER TABLE `salary_slips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_monitoring`
--
ALTER TABLE `site_monitoring`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `super_user`
--
ALTER TABLE `super_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_old`
--
ALTER TABLE `attendance_old`
  ADD CONSTRAINT `attendance_old_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `deduction`
--
ALTER TABLE `deduction`
  ADD CONSTRAINT `deduction to employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll to employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `payroll_employees`
--
ALTER TABLE `payroll_employees`
  ADD CONSTRAINT `payroll_employees_ibfk_1` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`),
  ADD CONSTRAINT `payroll_employees_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `salary_slip`
--
ALTER TABLE `salary_slip`
  ADD CONSTRAINT `salary_slip to deduction` FOREIGN KEY (`deduction_id`) REFERENCES `deduction` (`id`),
  ADD CONSTRAINT `salary_slip to employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `salary_slip to payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`),
  ADD CONSTRAINT `salary_slip_to_deduction` FOREIGN KEY (`deduction_id`) REFERENCES `deduction` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salary_slips`
--
ALTER TABLE `salary_slips`
  ADD CONSTRAINT `salary_slips_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `site_employee`
--
ALTER TABLE `site_employee`
  ADD CONSTRAINT `fk_site_employee_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_site_employee_site` FOREIGN KEY (`site_id`) REFERENCES `site_monitoring` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `site_employee_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `site_monitoring` (`id`),
  ADD CONSTRAINT `site_employee_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_monitoring`
--
ALTER TABLE `site_monitoring`
  ADD CONSTRAINT `fk_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
