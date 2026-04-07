-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.0.30 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for payrollsystem
CREATE DATABASE IF NOT EXISTS `payrollsystem` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `payrollsystem`;

-- Dumping structure for table payrollsystem.admin_users
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.admin_users: ~0 rows (approximately)

-- Dumping structure for table payrollsystem.attendance
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `date` date NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `pm_status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `night_status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `holiday_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status_am` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `status_pm` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `time_in_am` time DEFAULT NULL,
  `time_out_am` time DEFAULT NULL,
  `time_in_pm` time DEFAULT NULL,
  `time_out_pm` time DEFAULT NULL,
  `time_in_night` time DEFAULT NULL,
  `time_out_night` time DEFAULT NULL,
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.attendance: ~44 rows (approximately)
INSERT INTO `attendance` (`id`, `employee_id`, `date`, `status`, `pm_status`, `night_status`, `leave_type`, `holiday_type`, `status_am`, `status_pm`, `time_in_am`, `time_out_am`, `time_in_pm`, `time_out_pm`, `time_in_night`, `time_out_night`, `total_hours`, `remarks`, `created_at`, `updated_at`) VALUES
	(1, 23, '2026-02-10', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-10 15:55:32', '2026-02-10 22:51:48'),
	(2, 24, '2026-02-11', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-11 09:13:32', '2026-02-13 13:25:55'),
	(3, 23, '2026-02-11', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-11 11:12:32', '2026-02-13 13:25:55'),
	(4, 25, '2026-02-11', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-11 14:53:03', '2026-02-13 13:25:55'),
	(5, 24, '2026-02-12', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-12 05:52:46', '2026-02-19 02:24:31'),
	(6, 23, '2026-02-12', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', '10:00:00', NULL, NULL, NULL, NULL, 2.00, '', '2026-02-12 06:09:14', '2026-02-19 02:24:31'),
	(7, 26, '2026-02-12', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-12 06:14:33', '2026-02-13 13:25:55'),
	(8, 25, '2026-02-12', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-12 06:21:03', '2026-02-13 13:25:55'),
	(9, 27, '2026-02-12', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'kamasi', '2026-02-12 13:04:52', '2026-02-13 13:25:55'),
	(10, 23, '2026-02-15', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-12 13:30:39', '2026-02-13 13:25:55'),
	(11, 24, '2026-02-03', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-12 13:46:16', '2026-02-13 13:25:55'),
	(16, 23, '2026-02-13', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', '08:00:00', '13:00:00', '16:00:00', NULL, NULL, 3.00, '', '2026-02-12 16:01:55', '2026-02-19 02:24:31'),
	(17, 24, '2026-02-13', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', '00:00:00', '13:00:00', '13:00:00', NULL, NULL, 0.00, '', '2026-02-12 16:42:32', '2026-02-13 14:36:26'),
	(18, 28, '2026-02-13', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '02:00:00', '08:00:00', NULL, NULL, NULL, NULL, 6.00, '', '2026-02-12 17:23:12', '2026-02-19 02:24:31'),
	(19, 24, '2026-02-02', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-13 01:18:12', '2026-02-13 13:25:55'),
	(20, 26, '2026-02-13', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', '12:00:00', '13:00:00', '17:00:00', NULL, NULL, 8.00, '', '2026-02-13 03:10:45', '2026-02-19 02:24:31'),
	(21, 25, '2026-02-13', 'Present', 'Absent', 'Absent', NULL, NULL, 'Present', 'Present', '08:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-13 07:19:38', '2026-02-13 13:25:55'),
	(22, 24, '2026-03-01', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-13 14:36:57', '2026-02-19 02:24:31'),
	(23, 24, '2026-02-16', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', '13:00:00', '13:05:00', NULL, NULL, 3.08, '', '2026-02-13 14:37:58', '2026-02-19 02:24:31'),
	(24, 24, '2026-02-14', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-13 14:39:08', '2026-02-19 02:24:31'),
	(25, 24, '2026-02-01', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '12:00:00', '13:00:00', '17:00:00', NULL, NULL, 8.00, '', '2026-02-13 14:40:29', '2026-02-19 02:24:31'),
	(26, 23, '2026-02-01', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-13 14:41:30', '2026-02-19 02:24:31'),
	(27, 24, '2026-02-17', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-16 00:26:02', '2026-02-16 00:26:02'),
	(28, 28, '2026-02-16', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', '13:00:00', '16:00:00', NULL, NULL, 6.00, '', '2026-02-16 05:38:37', '2026-02-19 02:24:31'),
	(29, 23, '2026-02-16', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '00:00:00', '14:05:00', '16:04:00', NULL, NULL, 1.98, '', '2026-02-16 05:44:48', '2026-02-19 02:24:31'),
	(30, 26, '2026-02-16', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', '12:00:00', '17:00:00', NULL, NULL, 8.00, '', '2026-02-16 05:46:44', '2026-02-19 02:24:31'),
	(31, 25, '2026-02-16', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '06:00:00', '11:59:00', '13:00:00', '17:00:00', NULL, NULL, 9.98, 'okay lang', '2026-02-16 05:51:32', '2026-02-19 02:24:31'),
	(32, 27, '2026-02-16', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', '13:00:00', '22:00:00', NULL, NULL, 12.00, '', '2026-02-16 05:57:12', '2026-02-19 02:24:31'),
	(33, 30, '2026-02-18', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-18 02:17:41', '2026-02-19 02:24:31'),
	(34, 24, '2026-02-18', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'gwapo', '2026-02-18 05:39:50', '2026-02-18 09:56:50'),
	(35, 29, '2026-02-01', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '12:00:00', NULL, NULL, NULL, NULL, 4.00, '', '2026-02-18 06:55:53', '2026-02-19 02:24:31'),
	(36, 29, '2026-02-18', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '11:00:00', NULL, NULL, NULL, NULL, 3.00, '', '2026-02-18 07:21:36', '2026-02-19 02:24:31'),
	(37, 24, '2026-02-19', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Present', NULL, NULL, '13:00:00', '17:00:00', NULL, NULL, 4.00, 'bubu', '2026-02-19 00:47:15', '2026-02-19 05:56:18'),
	(38, 24, '2026-02-20', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'ayoko', '2026-02-20 00:40:53', '2026-02-20 09:15:29'),
	(39, 23, '2026-02-20', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-20 01:42:41', '2026-02-20 08:52:26'),
	(40, 28, '2026-02-20', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Present', NULL, NULL, '13:00:00', '17:00:00', NULL, NULL, 4.00, '', '2026-02-20 02:07:05', '2026-02-20 02:07:41'),
	(41, 24, '2026-02-23', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, '13:00:00', '17:00:00', '18:00:00', '21:00:00', 7.00, '', '2026-02-23 00:24:53', '2026-02-23 07:57:43'),
	(42, 23, '2026-02-23', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Present', NULL, NULL, '13:00:00', '16:00:00', NULL, NULL, 3.00, '', '2026-02-23 00:54:37', '2026-02-23 00:54:51'),
	(43, 31, '2026-02-23', 'On Leave', 'Absent', 'Absent', 'Sick Leave', NULL, 'On Leave', 'On Leave', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-23 00:55:07', '2026-02-23 00:55:07'),
	(44, 28, '2026-02-23', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-23 03:10:20', '2026-02-23 03:10:20'),
	(45, 24, '2026-02-24', 'Present', 'Absent', 'Present', NULL, NULL, 'Absent', 'Absent', '08:00:00', '10:00:00', NULL, NULL, '18:00:00', '21:00:00', 5.00, '', '2026-02-24 00:28:36', '2026-02-24 09:04:42'),
	(46, 23, '2026-02-24', 'On Leave', 'On Leave', 'On Leave', 'Sick Leave', NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, '', '2026-02-24 01:09:19', '2026-02-24 09:02:39'),
	(47, 31, '2026-02-24', 'Absent', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, '18:00:00', '21:00:00', 3.00, '', '2026-02-24 03:14:47', '2026-02-24 03:15:31'),
	(48, 28, '2026-02-24', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '10:00:00', '13:00:00', '15:00:00', NULL, NULL, 4.00, '', '2026-02-24 05:57:13', '2026-02-24 05:57:13'),
	(49, 26, '2026-02-24', 'Present', 'Absent', 'Absent', NULL, NULL, 'Absent', 'Absent', '08:00:00', '10:00:00', NULL, NULL, NULL, NULL, 2.00, '', '2026-02-24 08:23:13', '2026-02-24 08:23:13'),
	(50, 24, '2026-02-25', 'Absent', 'Absent', 'Present', NULL, NULL, 'Absent', 'Absent', NULL, NULL, NULL, NULL, '18:00:00', '00:01:00', 6.02, '', '2026-02-25 00:10:33', '2026-02-25 00:55:01');

-- Dumping structure for table payrollsystem.attendance_old
DROP TABLE IF EXISTS `attendance_old`;
CREATE TABLE IF NOT EXISTS `attendance_old` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Late','Leave') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `time_in_am` time DEFAULT NULL,
  `time_out_am` time DEFAULT NULL,
  `time_in_pm` time DEFAULT NULL,
  `time_out_pm` time DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`date`),
  KEY `idx_attendance_date` (`date`),
  KEY `idx_attendance_employee_date` (`employee_id`,`date`),
  KEY `idx_employee_date` (`employee_id`,`date`),
  KEY `idx_attendance_employee_date_status` (`employee_id`,`date`,`status`),
  CONSTRAINT `attendance_old_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.attendance_old: ~11 rows (approximately)
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

-- Dumping structure for table payrollsystem.custom_deductions
DROP TABLE IF EXISTS `custom_deductions`;
CREATE TABLE IF NOT EXISTS `custom_deductions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.custom_deductions: ~0 rows (approximately)
INSERT INTO `custom_deductions` (`id`, `name`, `percentage`) VALUES
	(14, 'sss', 0.00);

-- Dumping structure for table payrollsystem.deduction
DROP TABLE IF EXISTS `deduction`;
CREATE TABLE IF NOT EXISTS `deduction` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int DEFAULT NULL,
  `deduction_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` int NOT NULL,
  `date` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','disabled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `salary_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deduction_to_employee` (`employee_id`),
  CONSTRAINT `deduction_to_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.deduction: ~3 rows (approximately)
INSERT INTO `deduction` (`id`, `employee_id`, `deduction_name`, `description`, `amount`, `date`, `start_date`, `end_date`, `status`, `salary_date`) VALUES
	(42, 23, 'SSS', '', 5000, NULL, '2026-02-09', '2026-02-10', 'active', NULL),
	(43, 24, 'sss', '', 5000, NULL, '2026-02-04', '2026-02-25', 'active', NULL),
	(44, 25, 'sss', '', 500, NULL, '2026-02-18', '2026-02-25', 'active', NULL);

-- Dumping structure for table payrollsystem.employees
DROP TABLE IF EXISTS `employees`;
CREATE TABLE IF NOT EXISTS `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `middle_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `contact_num` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gender` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `civil_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `daily_salary` decimal(10,2) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `salary` int NOT NULL DEFAULT '0',
  `net_salary` int NOT NULL DEFAULT '0',
  `is_archived` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `status` enum('active','disabled','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `sss_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pagibig_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tin_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `philhealth_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `site_id` int DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.employees: ~9 rows (approximately)
INSERT INTO `employees` (`id`, `first_name`, `middle_name`, `last_name`, `address`, `contact_num`, `position`, `email`, `gender`, `civil_status`, `date_hired`, `daily_salary`, `dob`, `salary`, `net_salary`, `is_archived`, `is_active`, `status`, `sss_number`, `pagibig_number`, `tin_number`, `philhealth_number`, `site_id`, `hourly_rate`) VALUES
	(23, 'Jay Jay', 'Vinarao', 'Canceran', 'Bangad', '0987654321', 'jungle', 'ejje@gmail.com', 'Male', 'Married', '2026-02-09', 10000.00, '2004-12-09', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL, 0.00),
	(24, 'Davis Kert', 'N.', 'Binalayyy', 'Tumauini', '0009090909', 'manager', 'daviskertb@gmail.com', 'Male', 'Single', '2026-02-18', 10000.00, '1995-02-03', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(25, 'Mark', 'Larenz', 'Paccarangan', 'purok 3', '099', 'hehe', 'aaaa@gmail.com', 'Male', 'Single', '2026-02-03', 9999.99, '1995-02-12', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL, 0.00),
	(26, 'Clyde', 'l', 'melendez', 'fdfdfdf', '09999', 'dd', 'dd@gmail.com', 'Male', 'Single', '2026-02-03', 10.00, '1995-02-18', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL, 0.00),
	(27, 'Jaycie', 'Q', 'Quilang', 'tttttt', '0987654321', 'electrician', 'jaycie@gmail.com', 'Male', 'Single', '2026-02-03', 50000.00, '1995-02-01', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL, 0.00),
	(28, 'Norjay', 'C.', 'Dionesio', 'Bangad, Sta. Maria, Isabela.', '0912345678', 'diko alam', 'Dionesio@gmail.com', 'Male', 'Single', '2026-02-13', 10000.00, '2010-07-04', 0, 0, 0, 1, 'active', NULL, NULL, NULL, NULL, NULL, 0.00),
	(29, 'Markjay', 'E.', 'Macaballug', 'cabagan', '098765433456', 'Technical Director', 'mac@gmail.com', 'Male', 'Single', '2026-02-19', 25000.00, '2004-05-10', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(30, 'Gastoynt', 'T', 'Kupal', 'rs', '0899', 'Chief Executive Officer', 'dd@gmail.com', 'Male', 'Single', '2026-02-19', 2222.00, '2008-02-01', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(31, 'aadadadaadad', 'dddd', 'dddd', 'dadsadasd', '12324214', 'ssssssssss', 'shhashashah@gmail.com', 'Male', 'Single', '2026-02-02', 0.21, '2026-02-06', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00);

-- Dumping structure for table payrollsystem.holidays
DROP TABLE IF EXISTS `holidays`;
CREATE TABLE IF NOT EXISTS `holidays` (
  `id` int NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `holiday_type` enum('Regular Holiday','Special Non-Working Holiday','Special Working Holiday','Local Holiday') NOT NULL,
  `holiday_name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_holiday_date` (`holiday_date`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.holidays: ~13 rows (approximately)
INSERT INTO `holidays` (`id`, `holiday_date`, `holiday_type`, `holiday_name`, `description`, `created_at`) VALUES
	(1, '2024-12-25', 'Regular Holiday', 'Christmas Day', 'Araw ng Pasko', '2026-02-24 07:32:59'),
	(2, '2024-01-01', 'Regular Holiday', 'New Year\'s Day', 'Bagong Taon', '2026-02-24 07:32:59'),
	(3, '2024-12-30', 'Regular Holiday', 'Rizal Day', 'Araw ni Rizal', '2026-02-24 07:32:59'),
	(4, '2024-11-01', 'Special Non-Working Holiday', 'All Saints Day', 'Todos los Santos', '2026-02-24 07:32:59'),
	(5, '2024-11-02', 'Special Non-Working Holiday', 'All Souls Day', 'Araw ng mga Patay', '2026-02-24 07:32:59'),
	(6, '2024-04-09', 'Regular Holiday', 'Araw ng Kagitingan', 'Day of Valor', '2026-02-24 07:32:59'),
	(7, '2024-05-01', 'Regular Holiday', 'Labor Day', 'Araw ng Manggagawa', '2026-02-24 07:32:59'),
	(8, '2024-06-12', 'Regular Holiday', 'Independence Day', 'Araw ng Kalayaan', '2026-02-24 07:32:59'),
	(9, '2024-08-21', 'Regular Holiday', 'Ninoy Aquino Day', 'Araw ni Ninoy Aquino', '2026-02-24 07:32:59'),
	(10, '2024-11-30', 'Regular Holiday', 'Bonifacio Day', 'Araw ni Bonifacio', '2026-02-24 07:32:59'),
	(11, '2024-12-08', 'Special Non-Working Holiday', 'Feast of the Immaculate Conception', 'Kapistahan ng Immaculada Concepcion', '2026-02-24 07:32:59'),
	(12, '2024-12-24', 'Special Working Holiday', 'Christmas Eve', 'Bisperas ng Pasko', '2026-02-24 07:32:59'),
	(13, '2024-12-31', 'Special Working Holiday', 'New Year\'s Eve', 'Bisperas ng Bagong Taon', '2026-02-24 07:32:59');

-- Dumping structure for table payrollsystem.payroll
DROP TABLE IF EXISTS `payroll`;
CREATE TABLE IF NOT EXISTS `payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `base_salary` decimal(10,2) DEFAULT NULL,
  `total_deductions` decimal(10,2) DEFAULT NULL,
  `net_pay` decimal(10,2) DEFAULT NULL,
  `total_work_hours` decimal(10,2) DEFAULT NULL,
  `payroll_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'regular',
  `status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date` date NOT NULL,
  `pay_period` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_to_employee` (`employee_id`),
  CONSTRAINT `payroll_to_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.payroll: ~2 rows (approximately)
INSERT INTO `payroll` (`id`, `employee_id`, `date_from`, `date_to`, `base_salary`, `total_deductions`, `net_pay`, `total_work_hours`, `payroll_type`, `status`, `date`, `pay_period`) VALUES
	(64, 24, '2026-04-01', '2026-04-30', 1500.00, 300.00, 1200.00, 24.00, 'regular', 'pending', '2026-02-24', '2026-02-01'),
	(69, 24, '2026-02-24', '2026-02-25', 500.00, 0.00, 500.00, 8.00, 'regular', 'pending', '2026-02-24', '2026-02-01');

-- Dumping structure for table payrollsystem.payroll_deductions
DROP TABLE IF EXISTS `payroll_deductions`;
CREATE TABLE IF NOT EXISTS `payroll_deductions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `deduction_id` int DEFAULT NULL,
  `deduction_name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_id` (`payroll_id`),
  KEY `deduction_id` (`deduction_id`),
  CONSTRAINT `payroll_deductions_ibfk_1` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_deductions_ibfk_2` FOREIGN KEY (`deduction_id`) REFERENCES `deduction` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.payroll_deductions: ~2 rows (approximately)
INSERT INTO `payroll_deductions` (`id`, `payroll_id`, `deduction_id`, `deduction_name`, `amount`) VALUES
	(2, 64, NULL, 'SSS', 100.00),
	(3, 64, NULL, 'Pag-IBIG', 200.00);

-- Dumping structure for table payrollsystem.payroll_employees
DROP TABLE IF EXISTS `payroll_employees`;
CREATE TABLE IF NOT EXISTS `payroll_employees` (
  `payroll_id` int DEFAULT NULL,
  `employee_id` int DEFAULT NULL,
  KEY `payroll_id` (`payroll_id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `payroll_employees_ibfk_1` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`),
  CONSTRAINT `payroll_employees_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.payroll_employees: ~0 rows (approximately)

-- Dumping structure for table payrollsystem.positions
DROP TABLE IF EXISTS `positions`;
CREATE TABLE IF NOT EXISTS `positions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `position_name` varchar(100) NOT NULL,
  `category` enum('executive','technical','admin') NOT NULL DEFAULT 'technical',
  `is_custom` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `position_name` (`position_name`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.positions: ~28 rows (approximately)
INSERT INTO `positions` (`id`, `position_name`, `category`, `is_custom`, `status`, `created_at`) VALUES
	(1, 'Chief Executive Officer', 'executive', 0, 'active', '2026-02-23 01:14:45'),
	(2, 'General Manager', 'executive', 0, 'active', '2026-02-23 01:14:45'),
	(3, 'Sales and Marketing Manager', 'executive', 0, 'active', '2026-02-23 01:14:45'),
	(4, 'Technical Director', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(5, 'Design Department Head', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(6, 'Design Engineer', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(7, 'Design Technical Engineer', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(8, 'CAD operator', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(9, 'Implementation', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(10, 'Technical Department Head', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(11, 'Site Engineer', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(12, 'Safety Officer 2', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(13, 'Lead man', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(14, 'Electrician', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(15, 'carpenter', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(16, 'welder', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(17, 'Electronics', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(18, 'Painter', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(19, 'Scaffolder', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(20, 'Safety Officer Department Head', 'technical', 0, 'active', '2026-02-23 01:14:45'),
	(21, 'Admin', 'admin', 0, 'active', '2026-02-23 01:14:45'),
	(22, 'Purchasing', 'admin', 0, 'active', '2026-02-23 01:14:45'),
	(23, 'HR Manager', 'admin', 0, 'active', '2026-02-23 01:14:45'),
	(24, 'Finance and Administrative Officer', 'admin', 0, 'active', '2026-02-23 01:14:45'),
	(25, 'Maintenance', 'admin', 0, 'active', '2026-02-23 01:14:45'),
	(51, 'fdsffdf', 'executive', 1, 'active', '2026-02-23 09:15:14'),
	(52, 'hahahahhaha', 'executive', 1, 'active', '2026-02-23 09:41:09'),
	(53, 'ddddddd', 'executive', 1, 'active', '2026-02-23 09:45:36'),
	(54, 'ediee', 'executive', 1, 'active', '2026-02-23 09:50:00');

-- Dumping structure for table payrollsystem.salary_slip
DROP TABLE IF EXISTS `salary_slip`;
CREATE TABLE IF NOT EXISTS `salary_slip` (
  `id` int NOT NULL,
  `employee_id` int DEFAULT NULL,
  `payroll_id` int NOT NULL,
  `deduction_id` int NOT NULL,
  `net_pay` double NOT NULL,
  `pay_period` date NOT NULL,
  KEY `salary_slip_to_employee` (`employee_id`),
  KEY `salary_slip_to_payroll` (`payroll_id`),
  KEY `salary_slip_to_deduction` (`deduction_id`),
  CONSTRAINT `salary_slip_to_deduction` FOREIGN KEY (`deduction_id`) REFERENCES `deduction` (`id`),
  CONSTRAINT `salary_slip_to_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `salary_slip_to_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.salary_slip: ~0 rows (approximately)

-- Dumping structure for table payrollsystem.salary_slips
DROP TABLE IF EXISTS `salary_slips`;
CREATE TABLE IF NOT EXISTS `salary_slips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `month` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `year` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `salary_slips_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.salary_slips: ~0 rows (approximately)

-- Dumping structure for table payrollsystem.site_employee
DROP TABLE IF EXISTS `site_employee`;
CREATE TABLE IF NOT EXISTS `site_employee` (
  `site_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `assigned_date` date DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  PRIMARY KEY (`site_id`,`employee_id`),
  KEY `fk_site_employee_employee` (`employee_id`),
  CONSTRAINT `fk_site_employee_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_site_employee_site` FOREIGN KEY (`site_id`) REFERENCES `site_monitoring` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_employee: ~6 rows (approximately)
INSERT INTO `site_employee` (`site_id`, `employee_id`, `assigned_date`, `status`) VALUES
	(2, 25, NULL, 'active'),
	(8, 23, '2026-02-18', 'active'),
	(8, 24, '2026-02-18', 'active'),
	(8, 26, '2026-02-18', 'active'),
	(8, 27, '2026-02-18', 'active'),
	(8, 28, '2026-02-18', 'active'),
	(8, 30, '2026-02-25', 'active');

-- Dumping structure for table payrollsystem.site_monitoring
DROP TABLE IF EXISTS `site_monitoring`;
CREATE TABLE IF NOT EXISTS `site_monitoring` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `site_manager` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `site_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_others` tinyint(1) DEFAULT '0',
  `others_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_monitoring: ~5 rows (approximately)
INSERT INTO `site_monitoring` (`id`, `site_name`, `site_manager`, `site_address`, `is_others`, `others_id`) VALUES
	(2, 'Site A', 'dkkkkkkkkkkkkkkkkkk', 'sdsds', 0, NULL),
	(3, 'Site B', 'kddddddddddddddd', 'ffff', 0, NULL),
	(4, 'Site C', 'nenana', 'mmmmm', 0, NULL),
	(5, 'Site D', 'makalalakag', 'sampaloc', 0, NULL),
	(8, 'Project', 'lods', 'MANILA', 1, 1);

-- Dumping structure for table payrollsystem.site_others
DROP TABLE IF EXISTS `site_others`;
CREATE TABLE IF NOT EXISTS `site_others` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_id` int NOT NULL,
  `assignment_type` enum('Meeting','Project','Activities') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `person_group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `manager` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_site_others_site` (`site_id`),
  CONSTRAINT `fk_site_others_site` FOREIGN KEY (`site_id`) REFERENCES `site_monitoring` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_others: ~0 rows (approximately)
INSERT INTO `site_others` (`id`, `site_id`, `assignment_type`, `person_group`, `manager`, `location`) VALUES
	(1, 8, 'Project', 'supu', 'lods', 'MANILA');

-- Dumping structure for table payrollsystem.super_user
DROP TABLE IF EXISTS `super_user`;
CREATE TABLE IF NOT EXISTS `super_user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `security_answer1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.super_user: ~0 rows (approximately)
INSERT INTO `super_user` (`id`, `name`, `username`, `password`, `security_answer1`, `security_answer2`, `security_answer3`) VALUES
	(1, 'Super Admin', 'admin', 'admin123', 'Gumiran', 'larenz', 'paccarangan');

-- Dumping structure for table payrollsystem.user_accounts
DROP TABLE IF EXISTS `user_accounts`;
CREATE TABLE IF NOT EXISTS `user_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('ceo','user') COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint DEFAULT '1',
  `security_answer1` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer2` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer3` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.user_accounts: ~2 rows (approximately)
INSERT INTO `user_accounts` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`, `is_active`, `security_answer1`, `security_answer2`, `security_answer3`, `created_at`, `last_login`) VALUES
	(1, 'ceo', 'ceo123', 'CEO User', 'ceo@example.com', 'ceo', 1, NULL, NULL, NULL, '2026-02-16 09:00:07', NULL),
	(2, 'user', 'user123', 'Regular User', 'user@example.com', 'user', 1, NULL, NULL, NULL, '2026-02-16 09:00:07', '2026-02-20 07:12:00');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
