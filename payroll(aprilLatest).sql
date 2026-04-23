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
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pm_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `night_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Absent',
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `workday_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `time_in_am` time DEFAULT NULL,
  `time_out_am` time DEFAULT NULL,
  `time_in_pm` time DEFAULT NULL,
  `time_out_pm` time DEFAULT NULL,
  `time_in_night` time DEFAULT NULL,
  `time_out_night` time DEFAULT NULL,
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `site_assignment_am` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `site_assignment_pm` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `site_assignment_night` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.attendance: ~6 rows (approximately)
INSERT INTO `attendance` (`id`, `employee_id`, `date`, `status`, `pm_status`, `night_status`, `leave_type`, `workday_type`, `time_in_am`, `time_out_am`, `time_in_pm`, `time_out_pm`, `time_in_night`, `time_out_night`, `total_hours`, `site_assignment_am`, `site_assignment_pm`, `site_assignment_night`, `remarks`, `created_at`, `updated_at`) VALUES
	(1, 8, '2026-04-17', 'Present', 'Present', 'Present', NULL, 'Double Holiday', '08:00:00', '12:00:00', '13:00:00', '17:00:00', '22:00:00', '08:00:00', 18.00, 'Main Office', 'PLDT', 'Meeting', '', '2026-04-17 02:15:49', '2026-04-22 00:28:24'),
	(2, 12, '2026-04-17', 'Present', '', '', NULL, 'Regular Holiday', '08:00:00', '12:00:00', NULL, NULL, NULL, NULL, 4.00, 'PLDT', NULL, NULL, '', '2026-04-17 02:52:17', '2026-04-17 02:52:17'),
	(3, 7, '2026-04-17', '', '', '', 'Vacation Leave', 'Ordinary Working Day', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'Main Office', 'Main Office', 'Main Office', '', '2026-04-17 06:05:54', '2026-04-17 06:05:54'),
	(4, 13, '2026-04-17', 'Absent', 'Absent', 'Present', NULL, 'Ordinary Working Day', NULL, NULL, NULL, NULL, '18:00:00', '06:00:00', 12.00, 'Main Office', 'Meeting', 'PLDT', '', '2026-04-17 06:21:58', '2026-04-17 06:21:58'),
	(5, 8, '2026-04-21', 'Absent', 'Absent', 'Absent', 'Vacation Leave', 'Ordinary Working Day', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'Meeting', 'Main Office', 'PLDT', '', '2026-04-21 00:41:06', '2026-04-21 00:42:53'),
	(6, 8, '2026-04-22', 'Present', '', '', NULL, 'Special (Non-Working) Day', '08:00:00', '12:00:00', NULL, NULL, NULL, NULL, 4.00, 'Main Office', NULL, NULL, '', '2026-04-22 08:28:14', '2026-04-22 08:29:09');

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.deduction: ~0 rows (approximately)
INSERT INTO `deduction` (`id`, `employee_id`, `deduction_name`, `description`, `amount`, `date`, `start_date`, `end_date`, `status`, `salary_date`) VALUES
	(2, 12, 'SSS', 'Custom deduction: SSS', 200, NULL, '2026-04-01', '2026-04-30', 'active', NULL);

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
  `employment_type` enum('regular','non_regular') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'regular',
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
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.employees: ~30 rows (approximately)
INSERT INTO `employees` (`id`, `first_name`, `middle_name`, `last_name`, `address`, `contact_num`, `position`, `email`, `gender`, `civil_status`, `employment_type`, `date_hired`, `daily_salary`, `monthly_salary`, `dob`, `salary`, `net_salary`, `is_archived`, `is_active`, `status`, `sss_number`, `pagibig_number`, `tin_number`, `philhealth_number`, `site_id`, `hourly_rate`) VALUES
	(1, 'Julius', 'Layugan', 'Calimag', 'El Dela Cruz Appartment, 756 Carola St. Brgy 455, Zone 45, Sampaloc Manila', '09175014376', 'Chief Executive Officer', 'louisitodeguzman@gmail.com', 'Male', 'Married', 'regular', '2026-03-18', 1923.00, 50000.00, '1977-07-08', 0, 0, 0, 1, 'active', '33-6768335-8', '1090-0234-0564', '209-669-217-000', '', NULL, 0.00),
	(2, 'Louisito', 'Alcantara', 'De Guzman', 'El Dela Cruz Appartment, 756 Carola St. Brgy 455, Zone 45, Sampaloc Manila', '09778065406', 'General Manager', 'louisitodeguzman@gmail.com', 'Male', 'Married', 'regular', '2021-02-01', 1730.00, 45000.00, '1977-02-26', 0, 0, 0, 1, 'active', '33-6762003-6', '', '205-580-541-000', '19-051207804-8', NULL, 0.00),
	(3, 'Michael Jovit', 'Layugan', 'Calimag', 'Lot 32, Blk 3, Camella Home Petal St., Bayan Luma 3, Imus Cavite ', '09175016312', 'Technical Manager', 'michaeljovit@gmail.cm', 'Male', 'Married', 'regular', '2026-03-18', 1538.00, 40000.00, '1982-05-08', 0, 0, 0, 1, 'active', '33-8568526-0', '1210-0233-7076', '933-376-443-000', '', NULL, 0.00),
	(4, 'Lolita', 'Paguigan', 'Crabuena', 'Blk 32 Lot 4 Ph3, Brgy.101, Bagumbong, Caloocan City', '09771027634', 'Technical Department Head, Implementation', 'lolitacrabuena@gmail.com', 'Female', 'Married', 'regular', '2026-03-18', 827.00, 21500.00, '1987-09-03', 0, 0, 0, 1, 'active', '34-1180991-0', '1210-0777-8147', '201-001-062-000', '', NULL, 0.00),
	(5, 'Jolyn', 'Balacanao', 'Derije', '#54A San Juan Evangelista St., Payatas A. Quezon City', '09127250186', 'Finance and Administrative Officer', 'jolynderije19@gmail.com', 'Female', 'Married', 'regular', '2026-03-18', 750.00, 19500.00, '1980-05-19', 0, 0, 0, 1, 'active', '33-8077967-6', '1213-5799-1041', '304-115-435-000', '01-050378875-1', NULL, 0.00),
	(6, 'Jorja', 'Pagulayan', 'Lumabi', 'Minanga, San Pablo Isabela', '09677313089', 'Maintenance', '', 'Female', 'Married', 'regular', '2026-03-18', 500.00, 13000.00, '1994-10-03', 0, 0, 0, 1, 'active', '35-3646945-5', '1213-5741-0327', '', '', NULL, 0.00),
	(7, 'Fedeliz Hope', 'Cubong', 'Arguelles', 'Ugad Cabagan, Isabela', '09227052823', 'Admin, Purchasing, HR Manager', 'fedhope15@gmail.com', 'Female', 'Married', 'regular', '2026-03-18', 827.00, 21500.00, '1986-08-18', 0, 0, 0, 1, 'active', '09-3070199-0', '1211-1732-8052', '743-539-364-000', '17-025257304-9', NULL, 0.00),
	(8, 'Vincent', 'Gatan', 'Aggabao', 'Ugad Cabagan, Isabela/Banlat RD Tandang Sora QC/739 C Prudencio St. Brgy.450 Sampaloc Manila', '09662015978', 'Liaison Officer, Driver', 'vincentaggabao@gmail.cm', 'Male', 'Married', 'regular', '2026-03-18', 700.00, 18000.00, '1987-03-03', 0, 0, 0, 1, 'active', '34-3172418-5', '1211-3184-6064', '447-031-380-000', '01-025391769-0', NULL, 0.00),
	(9, 'Chriestian Michelle', 'N/A', 'Gallos', 'Bagsik Alcantara Romblon/1514 San Diego St. Brgy.500 Sampaloc Manila', '09472517073', 'Site Engineer', 'marckseddmelchor13@gmail.com', 'Male', 'Single', 'regular', '2026-03-18', 909.00, 23634.00, '2002-01-22', 0, 0, 0, 1, 'active', '04-5204389-3', '', '', '09-202851430-3', NULL, 0.00),
	(10, 'John David', 'Ferrer', 'Noche', 'Libertad, Odiongan, Romblon/ Rm-10B, Almond St., Brgy. Claro, Anonas QC', '09197419214', 'Safety Officer Department Head, Site Engineer', 'dabijohn006@gmail.com', 'Male', 'Single', 'regular', '2026-03-18', 909.00, 32634.00, '2001-12-15', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(11, 'Lovely Joy', 'Peralta', 'Molo', 'Brgy. Macalas, Romblon,Romblon/424 Palmera St., Sampaloc Manila', '09511299478', 'Design Technical Engineer', 'lovelyjoymolo7@gmail.com', 'Female', 'Single', 'regular', '2026-03-18', 909.00, 23634.00, '2002-12-04', 0, 0, 0, 1, 'active', '04-4548719-4', '1213-2049-6808', '', '09-202722103-5', NULL, 0.00),
	(12, 'Alan', 'Tan', 'Allam', 'Blk 9 Lot 4 Malambing St Villa Muzon Subdivision San Jose Del Monte Bulacan, 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09271834207', 'Safety Officer 2, Lead man, Electrician', 'alantanallam@gmail.com', 'Male', 'Married', 'regular', '2026-03-18', 800.00, 20800.00, '1996-06-24', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(13, 'Jake', 'Malsi', 'Arguelles', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09751120625', 'Painter', '', 'Male', 'Married', 'non_regular', '2026-03-18', 700.00, 18200.00, '1991-11-19', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(14, 'Prince Jeffrey', 'Pascua', 'Bautista', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09059133081', 'Labor, Installation', '', 'Male', 'Single', 'regular', '2026-03-18', 750.00, 19500.00, '2004-11-15', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(15, 'Rene', 'Paguigan', 'Bautista', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09354915180', 'Electrician', '', 'Male', 'Single', 'regular', '2026-03-18', 800.00, 20800.00, '1991-01-27', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(16, 'Rowel', 'Taguibao', 'Bautista', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09071085738', 'Painter', '', 'Male', 'Married', 'regular', '2026-03-18', 750.00, 19500.00, '1983-02-04', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(17, 'Robert', 'Paguigan', 'Bautista', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09354915180', 'Helper', '', 'Male', 'Married', 'non_regular', '2026-03-18', 750.00, 19500.00, '1973-12-17', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(18, 'Mark Joseph', 'D.', 'Bermudez', 'San Jose City / 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09636147338', 'Electrician', '', 'Male', 'Single', 'regular', '2026-03-18', 800.00, 20800.00, '2000-04-25', 0, 0, 0, 1, 'active', '02-4565930-0', '1212-5275-8953', '', '21-252778420-1', NULL, 0.00),
	(19, 'Christian', 'Dagan', 'Lictaoa', 'San Agustin, Iba Zambalez/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09923610645', 'Electrician', '', 'Male', 'Married', 'regular', '2026-03-18', 800.00, 20800.00, '1996-10-01', 0, 0, 0, 1, 'active', '34-5269509-9', '', '', '', NULL, 0.00),
	(20, 'Bryan John', 'Totto', 'Paccarangan', 'Catabayungan, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09079373492', 'Carpenter', '', 'Male', 'Married', 'regular', '2026-03-18', 800.00, 20800.00, '1996-03-19', 0, 0, 0, 1, 'active', '01-3495327-2', '', '', '06-201463804-6', NULL, 0.00),
	(21, 'John Mark', 'Totto', 'Paccarangan', 'Catabayungan ,Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09079373492', 'Helper', '', 'Male', 'Married', 'non_regular', '2026-03-18', 700.00, 18200.00, '1999-07-04', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(22, 'Freman', 'Logquiao', 'Paclibare', 'Minagbag Quezon, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09301798259', 'Electrician, Structured Cabling', '', 'Male', 'Married', 'regular', '2026-03-18', 750.00, 19500.00, '1999-12-15', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(23, 'Ricky', 'Taguibao', 'Paguigan', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09667039167', 'Installation, Carpenter', '', 'Male', 'Married', 'regular', '2026-03-18', 750.00, 19500.00, '1994-10-20', 0, 0, 0, 1, 'active', '34-7966341-5', '1212-3717-7732', '', '06-201554497-5', NULL, 0.00),
	(24, 'Numerto', 'Barameda', 'Pepito', 'Tuy, Batangas/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09677452886', 'Plumbing', '', 'Male', 'Married', 'regular', '2026-03-18', 750.00, 19500.00, '1982-01-27', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(25, 'Patrick Sor', 'Clamosa', 'Pepito', 'Biclatan General Trias Cavite/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09933906552', 'Safety Officer 2, Lead man', 'patricksor15@gmail.com', 'Male', 'Married', 'regular', '2026-03-18', 800.00, 20800.00, '1990-10-15', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(26, 'John Onofre', 'E.', 'Ramirez', 'PNR Legaspi City Albay/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09858505589', 'Helper', 'jhononofre@gmail.com', 'Male', 'Married', 'non_regular', '2026-03-18', 700.00, 18500.00, '1998-08-22', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(27, 'Brian', 'Villegas', 'Tan', 'Novaliches Quezon City/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09687677704', 'Safety Officer 2', '', 'Male', 'Single', 'regular', '2026-03-18', 800.00, 20800.00, '2001-02-12', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(28, 'Robert', 'Villanda', 'Tolentino', 'Ugad, Cabagan, Isabela/ 739 C Prudencio St. Brgy. 450 Sampaloc Manila', '09207744467', 'Assistant Carpentry, Labor', '', 'Male', 'Single', 'non_regular', '2026-03-18', 700.00, 18200.00, '2006-04-26', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(29, 'Jone', 'S.', 'Magas', 'San Mateo, Isabela', '09532493935', 'HVAC Technician', 'jonemagas@gmail.com', 'Male', 'Married', 'non_regular', '2026-03-18', 800.00, 20800.00, '1981-04-07', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00),
	(30, 'Aurelio', 'Ramos', 'Eda JR.', '08 Maramag St. Sta Barbara ,Ilagan City ,Isabela', '09778270641', 'Helper', 'edaaurelio500@gmail.com', 'Male', 'Married', 'non_regular', '2026-03-18', 700.00, 18200.00, '1968-01-29', 0, 0, 0, 1, 'active', '', '', '', '', NULL, 0.00);

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
  `date` date NOT NULL,
  `pay_period` date DEFAULT NULL,
  `status` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `payroll_to_employee` (`employee_id`),
  CONSTRAINT `payroll_to_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.payroll: ~0 rows (approximately)
INSERT INTO `payroll` (`id`, `employee_id`, `date_from`, `date_to`, `base_salary`, `total_deductions`, `net_pay`, `total_work_hours`, `payroll_type`, `date`, `pay_period`, `status`) VALUES
	(7, 8, '2026-04-01', '2026-04-30', 6247.50, 0.00, 6247.50, 30.00, 'regular', '2026-04-23', '2026-04-01', 'pending'),
	(8, 12, '2026-04-01', '2026-04-30', 1200.00, 200.00, 1000.00, 4.00, 'regular', '2026-04-23', '2026-04-01', 'pending');

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.payroll_deductions: ~0 rows (approximately)
INSERT INTO `payroll_deductions` (`id`, `payroll_id`, `deduction_id`, `deduction_name`, `amount`) VALUES
	(6, 8, 2, 'SSS', 200.00);

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
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table payrollsystem.positions: ~42 rows (approximately)
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
	(31, 'Structured Cabling', 'technical', 1, 'active', '2026-03-11 01:36:34'),
	(32, 'Technical Coordinator', 'technical', 1, 'active', '2026-03-18 06:07:50'),
	(33, 'Admin Manager', 'admin', 1, 'active', '2026-03-18 06:13:20'),
	(35, 'Admin Staff', 'admin', 1, 'active', '2026-03-18 06:22:02'),
	(36, 'Liaison Officer', 'technical', 1, 'active', '2026-03-18 06:28:57'),
	(37, 'Driver', 'technical', 1, 'active', '2026-03-18 06:29:06'),
	(38, 'Safety Officer', 'technical', 1, 'active', '2026-03-18 06:53:10'),
	(39, 'Head Electrician', 'technical', 1, 'active', '2026-03-18 06:53:34'),
	(40, 'Helper', 'technical', 1, 'active', '2026-03-18 07:02:38'),
	(41, 'Labor', 'technical', 1, 'active', '2026-03-18 07:05:24'),
	(42, 'Installation', 'technical', 1, 'active', '2026-03-18 07:05:40'),
	(43, 'Electrician Assistant', 'technical', 1, 'active', '2026-03-18 07:23:11'),
	(44, 'Plumbing', 'technical', 1, 'active', '2026-03-18 07:42:10'),
	(45, 'Assistant Engineer', 'technical', 1, 'active', '2026-03-18 07:53:07'),
	(46, 'Assistant Carpentry', 'technical', 1, 'active', '2026-03-18 07:57:11'),
	(47, 'HVAC Technician', 'technical', 1, 'active', '2026-03-18 08:00:34');

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

-- Dumping data for table payrollsystem.site_employee: ~2 rows (approximately)
INSERT INTO `site_employee` (`site_id`, `employee_id`, `assigned_date`, `status`) VALUES
	(9, 12, '2026-04-16', 'active'),
	(9, 30, '2026-04-16', 'active');

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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_monitoring: ~3 rows (approximately)
INSERT INTO `site_monitoring` (`id`, `site_name`, `site_manager`, `site_address`, `is_others`, `others_id`) VALUES
	(9, 'Main Office', 'Julius Calimag', 'Sampaloc, Manila', 0, NULL),
	(10, 'PLDT', 'Louis', 'Manila', 0, NULL),
	(12, 'Meeting', 'Michael', 'Mandaluyong', 1, NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.site_others: ~0 rows (approximately)
INSERT INTO `site_others` (`id`, `site_id`, `assignment_type`, `person_group`, `manager`, `location`) VALUES
	(4, 12, 'Meeting', 'GST', 'Michael', 'Mandaluyong');

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
  `security_question1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What is your mother''s maiden name?',
  `security_question2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What was the name of your first pet?',
  `security_question3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What city were you born in?',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table payrollsystem.super_user: ~0 rows (approximately)
INSERT INTO `super_user` (`id`, `name`, `username`, `email`, `password`, `security_answer1`, `security_answer2`, `security_answer3`, `security_question1`, `security_question2`, `security_question3`) VALUES
	(1, 'Super Admin', 'admin', 'admin@jlcpayroll.com', 'admin1234', '', '', '', '', '', '');

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
  `security_question1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What is your mother''s maiden name?',
  `security_question2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What was the name of your first pet?',
  `security_question3` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'What city were you born in?',
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
