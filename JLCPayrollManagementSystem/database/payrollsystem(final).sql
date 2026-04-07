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

-- Dumping structure for table payrollsystem.attendance
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `date` date NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pm_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `night_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `workday_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.attendance: ~1 rows (approximately)
INSERT INTO `attendance` (`id`, `employee_id`, `date`, `status`, `pm_status`, `night_status`, `leave_type`, `workday_type`, `status_am`, `status_pm`, `time_in_am`, `time_out_am`, `time_in_pm`, `time_out_pm`, `time_in_night`, `time_out_night`, `total_hours`, `remarks`, `created_at`, `updated_at`) VALUES
	(1, 1, '2026-03-11', 'Present', 'Present', 'No Record', NULL, 'Ordinary Working Day', 'Absent', 'Absent', '08:00:00', '12:00:00', '13:00:00', '17:00:00', NULL, NULL, 8.00, '', '2026-03-11 05:43:05', '2026-03-11 05:43:05');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.attendance_old: ~0 rows (approximately)

-- Dumping structure for table payrollsystem.custom_deductions
DROP TABLE IF EXISTS `custom_deductions`;
CREATE TABLE IF NOT EXISTS `custom_deductions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.custom_deductions: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.deduction: ~2 rows (approximately)

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
  `employment_type` enum('regular','non_regular') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'regular',
  `date_hired` date DEFAULT NULL,
  `daily_salary` decimal(10,2) DEFAULT NULL,
  `monthly_salary` decimal(10,2) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.employees: ~6 rows (approximately)
INSERT INTO `employees` (`id`, `first_name`, `middle_name`, `last_name`, `address`, `contact_num`, `position`, `email`, `gender`, `civil_status`, `employment_type`, `date_hired`, `daily_salary`, `monthly_salary`, `dob`, `salary`, `net_salary`, `is_archived`, `is_active`, `status`, `sss_number`, `pagibig_number`, `tin_number`, `philhealth_number`, `site_id`, `hourly_rate`) VALUES
	(1, 'Davis Kert', 'Narag', 'Binalay', 'Paragu, Tumauini, Isabela', '09876543212', 'General Manager', 'daviskertb@gmail.com', 'Male', 'Single', 'regular', '2026-02-09', 500.00, 10000.00, '2004-01-04', 0, 0, 0, 1, 'active', '13-4677654-3', '1212-1223-4545', '323-234-344-545', '87-876765656-5', NULL, 0.00);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.holidays: ~0 rows (approximately)

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
  CONSTRAINT `payroll_to_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.payroll: ~1 rows (approximately)
INSERT INTO `payroll` (`id`, `employee_id`, `date_from`, `date_to`, `base_salary`, `total_deductions`, `net_pay`, `total_work_hours`, `payroll_type`, `status`, `date`, `pay_period`) VALUES
	(1, 1, '2026-03-01', '2026-03-31', 500.00, 0.00, 500.00, 8.00, 'regular', 'pending', '2026-03-11', '2026-03-01');

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
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.payroll_deductions: ~2 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.positions: ~27 rows (approximately)
INSERT INTO `positions` (`id`, `position_name`, `category`, `is_custom`, `status`, `created_at`) VALUES
	(1, 'Chief Executive Officer', 'executive', 0, 'active', '2026-03-05 08:49:03'),
	(2, 'General Manager', 'executive', 0, 'active', '2026-03-05 08:49:03'),
	(3, 'Sales and Marketing Manager', 'executive', 0, 'active', '2026-03-05 08:49:03'),
	(4, 'Technical Director', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(5, 'Design Department Head', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(6, 'Design Engineer', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(7, 'Design Technical Engineer', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(8, 'CAD operator', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(9, 'Implementation', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(10, 'Technical Department Head', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(11, 'Site Engineer', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(12, 'Safety Officer 2', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(13, 'Lead man', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(14, 'Electrician', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(15, 'Carpenter', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(16, 'Welder', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(17, 'Electronics', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(18, 'Painter', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(19, 'Scaffolder', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(20, 'Safety Officer Department Head', 'technical', 0, 'active', '2026-03-05 08:49:03'),
	(21, 'Admin', 'admin', 0, 'active', '2026-03-05 08:49:03'),
	(22, 'Purchasing', 'admin', 0, 'active', '2026-03-05 08:49:03'),
	(23, 'HR Manager', 'admin', 0, 'active', '2026-03-05 08:49:03'),
	(24, 'Finance and Administrative Officer', 'admin', 0, 'active', '2026-03-05 08:49:03'),
	(25, 'Maintenance', 'admin', 0, 'active', '2026-03-05 08:49:03'),
	(30, 'Technical Manager', 'technical', 1, 'active', '2026-03-11 01:33:54'),
	(31, 'Structured Cabling', 'technical', 1, 'active', '2026-03-11 01:36:34');

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
	(1, 1, '2026-03-11', 'active');

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_monitoring: ~2 rows (approximately)
INSERT INTO `site_monitoring` (`id`, `site_name`, `site_manager`, `site_address`, `is_others`, `others_id`) VALUES
	(1, 'Main Office', 'Engr. Julius L. Calimag', 'Sampaloc, Manila', 0, NULL);

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

-- Dumping data for table payrollsystem.site_others: ~1 rows (approximately)

-- Dumping structure for table payrollsystem.super_user
DROP TABLE IF EXISTS `super_user`;
CREATE TABLE IF NOT EXISTS `super_user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `security_answer1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_question1` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What is your mother''s maiden name?',
  `security_question2` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What was the name of your first pet?',
  `security_question3` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What city were you born in?',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.super_user: ~1 rows (approximately)
INSERT INTO `super_user` (`id`, `name`, `username`, `email`, `password`, `security_answer1`, `security_answer2`, `security_answer3`, `security_question1`, `security_question2`, `security_question3`) VALUES
	(1, 'Super Admin', 'admin', 'admin@jlcpayroll.com', 'admin123', '', '', '', '', '', '');

-- Dumping structure for table payrollsystem.user_accounts
DROP TABLE IF EXISTS `user_accounts`;
CREATE TABLE IF NOT EXISTS `user_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('ceo','user') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint DEFAULT '1',
  `security_answer1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `security_answer3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `security_question1` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What is your mother''s maiden name?',
  `security_question2` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What was the name of your first pet?',
  `security_question3` varchar(255) COLLATE utf8mb4_general_ci DEFAULT 'What city were you born in?',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.user_accounts: ~2 rows (approximately)
INSERT INTO `user_accounts` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`, `is_active`, `security_answer1`, `security_answer2`, `security_answer3`, `created_at`, `last_login`, `security_question1`, `security_question2`, `security_question3`) VALUES
	(1, 'ceo123', 'ceo1234', 'CEO User', 'ceo@example.com', 'ceo', 1, '', '', '', '2026-02-16 09:00:07', '2026-03-03 01:30:51', '', '', ''),
	(2, 'Lead Engineer', 'engineer123', 'Regular User', 'user@example.com', 'user', 1, 'Isabela', '21', 'lucena', '2026-02-16 09:00:07', '2026-03-04 09:03:23', 'Where do you live?', 'How old are you?', 'What is your Middle name?');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
