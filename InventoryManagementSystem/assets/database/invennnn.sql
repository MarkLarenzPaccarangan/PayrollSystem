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


-- Dumping database structure for inventory_system
CREATE DATABASE IF NOT EXISTS `inventory_system` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `inventory_system`;

-- Dumping structure for table inventory_system.canvas_items
DROP TABLE IF EXISTS `canvas_items`;
CREATE TABLE IF NOT EXISTS `canvas_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_no` varchar(50) NOT NULL,
  `description` text,
  `quantity` int DEFAULT '0',
  `unit` varchar(20) DEFAULT NULL,
  `united_power_price` decimal(10,2) DEFAULT '0.00',
  `united_power_availability` tinyint(1) DEFAULT '0',
  `vicsteel_price` decimal(10,2) DEFAULT '0.00',
  `vicsteel_availability` tinyint(1) DEFAULT '0',
  `anakko_price` decimal(10,2) DEFAULT '0.00',
  `anakko_availability` tinyint(1) DEFAULT '0',
  `rclquintera_price` decimal(10,2) DEFAULT '0.00',
  `rclquintera_availability` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_no` (`item_no`),
  KEY `idx_supplier_prices` (`united_power_price`,`vicsteel_price`,`anakko_price`,`rclquintera_price`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.canvas_items: ~20 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `quantity`, `unit`, `united_power_price`, `united_power_availability`, `vicsteel_price`, `vicsteel_availability`, `anakko_price`, `anakko_availability`, `rclquintera_price`, `rclquintera_availability`, `created_at`, `updated_at`) VALUES
	(58, 'STR-003', 'Stretched Canvas 12x16 inches - Deep Edge', 50, 'pcs', 380.00, 1, 370.00, 1, 390.00, 1, 375.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(60, 'STR-005', 'Stretched Canvas 18x24 inches - Deep Edge', 40, 'pcs', 520.00, 1, 510.00, 1, 530.00, 1, 515.00, 0, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(61, 'STR-006', 'Stretched Canvas 20x24 inches - Deep Edge', 35, 'pcs', 580.00, 1, 570.00, 0, 590.00, 1, 575.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(62, 'STR-007', 'Stretched Canvas 24x30 inches - Deep Edge', 30, 'pcs', 650.00, 1, 640.00, 1, 660.00, 1, 645.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(66, '089', 'gsgdsagdgsgddsa', 3, 'pcs', 3333.00, 1, 33333.00, 1, 42353543.00, 1, 888888.00, 1, '2026-03-05 08:24:16', '2026-03-05 08:24:16'),
	(67, '0123', 'graba', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 08:44:17', '2026-03-05 08:44:17'),
	(68, '08780', 'tnaga', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 08:48:41', '2026-03-05 08:48:41'),
	(69, '9898989', 'iloveyou', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 09:20:26', '2026-03-05 09:20:26'),
	(70, '999999', 'hgghhg', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 11:30:05', '2026-03-05 11:30:05'),
	(71, '0123', 'graba', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-06 01:10:33', '2026-03-06 01:10:33');

-- Dumping structure for table inventory_system.companies
DROP TABLE IF EXISTS `companies`;
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `contact_person` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~0 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `status`, `created_at`, `updated_at`) VALUES
	(1, 'fujiko', '', NULL, 'active', '2026-03-05 08:43:32', '2026-03-05 08:43:32'),
	(2, 'kikoy', 'dsdsa', NULL, 'active', '2026-03-05 08:48:04', '2026-03-05 08:48:04'),
	(3, 'jlc', NULL, NULL, 'active', '2026-03-05 11:30:05', '2026-03-05 11:30:05'),
	(4, 'jj', NULL, 'kk', 'active', '2026-03-06 01:10:33', '2026-03-06 01:10:33');

-- Dumping structure for table inventory_system.company_prices
DROP TABLE IF EXISTS `company_prices`;
CREATE TABLE IF NOT EXISTS `company_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `company_id` int NOT NULL,
  `quantity` int DEFAULT '0',
  `price` decimal(10,2) DEFAULT '0.00',
  `availability` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_company` (`item_id`,`company_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `company_prices_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `canvas_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_prices_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~0 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `created_at`, `updated_at`) VALUES
	(2, 68, 2, 80, 1000.00, 1, '2026-03-05 08:48:41', '2026-03-05 08:48:41'),
	(3, 70, 3, 5, 500.00, 1, '2026-03-05 11:30:05', '2026-03-05 11:30:05'),
	(5, 67, 1, 29, 80.00, 1, '2026-03-05 11:36:31', '2026-03-05 11:36:31'),
	(6, 71, 4, 5, 6.00, 1, '2026-03-06 01:10:33', '2026-03-06 01:10:33');

-- Dumping structure for table inventory_system.customers
DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `customer_type` enum('regular','vip','wholesale') DEFAULT 'regular',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.customers: ~0 rows (approximately)

-- Dumping structure for table inventory_system.products
DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `category` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT 'pcs',
  `low_stock_threshold` int DEFAULT '10',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category`),
  KEY `idx_products_quantity` (`quantity`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.products: ~0 rows (approximately)

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) NOT NULL,
  `customer_id` int DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','completed','cancelled') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('paid','unpaid','refunded') DEFAULT 'unpaid',
  `purchase_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_number` (`purchase_number`),
  KEY `customer_id` (`customer_id`),
  KEY `idx_purchases_customer_id` (`customer_id`),
  KEY `idx_purchases_purchase_date` (`purchase_date`),
  KEY `idx_purchases_status` (`status`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.purchases: ~0 rows (approximately)

-- Dumping structure for table inventory_system.stock_movements
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `type` enum('in','out') NOT NULL,
  `quantity` int NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  KEY `idx_stock_movements_product_date` (`product_id`,`created_at`),
  KEY `idx_stock_movements_created_at` (`created_at`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.stock_movements: ~0 rows (approximately)

-- Dumping structure for table inventory_system.users
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('administrator','manager','staff') DEFAULT 'staff',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.users: ~3 rows (approximately)
INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`) VALUES
	(3, 'admin', '0192023a7bbd73250516f069df18b500', 'admin@inventory.com', 'administrator', '2026-03-05 06:19:25'),
	(4, 'manager', '0795151defba7a4b5dfa89170de46277', 'manager@inventory.com', 'manager', '2026-03-05 06:19:25'),
	(5, 'staff', 'de9bf5643eabf80f4a56fda3bbb84483', 'staff@inventory.com', 'staff', '2026-03-05 06:19:25');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
