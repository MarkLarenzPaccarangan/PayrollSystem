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
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT '0',
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.canvas_items: ~5 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `category`, `quantity`, `unit`, `united_power_price`, `united_power_availability`, `vicsteel_price`, `vicsteel_availability`, `anakko_price`, `anakko_availability`, `rclquintera_price`, `rclquintera_availability`, `created_at`, `updated_at`) VALUES
	(95, '01', 'hardhat', 'supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-16 06:21:46', '2026-03-16 07:28:42'),
	(96, '02', 'hardhat', 'supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-16 06:46:15', '2026-03-16 06:46:15'),
	(97, '03', '4x4', 'supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-17 08:54:27', '2026-03-17 08:54:27'),
	(98, '09', 'boots', 'supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-19 01:11:33', '2026-03-19 01:11:33'),
	(99, '010', 'Red Horse', 'ffsfsd', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-19 01:12:03', '2026-03-19 01:12:03'),
	(100, '12', 'ffff', '', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-19 01:12:35', '2026-03-19 01:12:35');

-- Dumping structure for table inventory_system.companies
DROP TABLE IF EXISTS `companies`;
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~6 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
	(40, 'LIQUID', NULL, 'clydeey', '09999999999', 'active', '2026-03-17 00:54:48', '2026-03-17 00:54:48'),
	(41, 'TNC', NULL, 'JAYCIE', '0997637364105', 'active', '2026-03-17 08:53:53', '2026-03-17 08:53:53'),
	(42, 'JLC BEST CONSTRUCTION OPC', NULL, '', '', 'active', '2026-03-19 01:10:46', '2026-03-19 01:10:46'),
	(43, 'LOKLOK', NULL, '', '', 'active', '2026-03-19 01:10:55', '2026-03-19 01:10:55'),
	(44, 'steelvalley', NULL, '', '', 'active', '2026-03-19 01:11:02', '2026-03-19 01:11:02'),
	(45, 'GST', NULL, '', '', 'active', '2026-03-19 02:44:06', '2026-03-19 02:44:06');

-- Dumping structure for table inventory_system.company_prices
DROP TABLE IF EXISTS `company_prices`;
CREATE TABLE IF NOT EXISTS `company_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `company_id` int NOT NULL,
  `quantity` int DEFAULT '0',
  `price` decimal(10,2) DEFAULT '0.00',
  `availability` tinyint(1) DEFAULT '0',
  `company_color` varchar(20) DEFAULT '#4e73df',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_company` (`item_id`,`company_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `company_prices_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `canvas_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_prices_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~4 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `company_color`, `created_at`, `updated_at`) VALUES
	(58, 95, 40, 23, 60.00, 1, '#4e73df', '2026-03-17 00:55:13', '2026-03-19 02:01:59'),
	(59, 97, 41, 39, 70.00, 1, '#4e73df', '2026-03-17 08:54:27', '2026-03-24 08:17:45'),
	(60, 98, 42, 9, 80.00, 1, '#4e73df', '2026-03-19 01:11:33', '2026-03-19 01:13:48'),
	(61, 99, 43, 9, 70.00, 1, '#4e73df', '2026-03-19 01:12:03', '2026-03-19 01:14:03'),
	(62, 100, 44, 9, 60.00, 1, '#4e73df', '2026-03-19 01:12:35', '2026-03-19 01:13:57');

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
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `quantity` int NOT NULL DEFAULT '0',
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `low_stock_threshold` int DEFAULT '10',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category`),
  KEY `idx_products_quantity` (`quantity`),
  KEY `idx_item_no` (`item_no`),
  KEY `idx_products_item_no` (`item_no`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.products: ~0 rows (approximately)
INSERT INTO `products` (`id`, `item_no`, `name`, `description`, `price`, `quantity`, `category`, `unit`, `low_stock_threshold`, `created_at`, `updated_at`) VALUES
	(103, '01', '01 - hardhat', 'hardhat', 60.00, 16, NULL, 'pcs', 10, '2026-03-19 02:01:59', '2026-03-24 07:56:27'),
	(104, '03', '03 - 4x4', '4x4', 70.00, 8, NULL, 'pcs', 10, '2026-03-24 08:17:45', '2026-03-25 01:30:15');

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `quantity_purchased` int NOT NULL DEFAULT '0',
  `price_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `available_before` int NOT NULL DEFAULT '0',
  `available_after` int NOT NULL DEFAULT '0',
  `company_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6c5ce7',
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `payment_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'paid',
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'completed',
  `purchase_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `stock_updated` tinyint DEFAULT '0',
  `stock_movement_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_number` (`purchase_number`),
  KEY `idx_item_no` (`item_no`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_price_id` (`price_id`),
  KEY `idx_status` (`status`),
  KEY `idx_purchase_date` (`purchase_date`),
  KEY `idx_purchases_product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchases: ~8 rows (approximately)
INSERT INTO `purchases` (`id`, `purchase_number`, `customer_name`, `item_no`, `description`, `category`, `company_name`, `contact_person`, `contact_number`, `price_id`, `product_id`, `quantity_purchased`, `price_per_unit`, `total_amount`, `available_before`, `available_after`, `company_color`, `payment_method`, `payment_status`, `status`, `purchase_date`, `updated_at`, `stock_updated`, `stock_movement_id`) VALUES
	(119, 'PUR-20260316065354-3921', 'Walk-in Customer', '01', '4x4', NULL, 'TNC', 'Mark Larenz Paccarangan', '0807608076', 53, NULL, 5, 50.00, 250.00, 50, 45, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-16 06:53:54', '2026-03-16 06:54:01', 0, 159),
	(121, 'PUR-20260316083604-9315', 'Walk-in Customer', '01', 'hardhat', NULL, 'LIQUID', 'JAYvie', '09087', 57, 97, 11, 60.00, 660.00, 50, 39, '#4e73df', 'cash', 'pending', 'completed', '2026-03-16 08:36:04', '2026-03-16 08:36:30', 0, 160),
	(122, 'PUR-20260317072948-2852', 'Walk-in Customer', '01', 'hardhat', NULL, 'LIQUID', 'clydeey', '09999999999', 58, NULL, 1, 60.00, 60.00, 50, 49, '#4e73df', 'cash', 'pending', 'completed', '2026-03-17 07:29:48', '2026-03-17 07:29:54', 0, 162),
	(123, 'PUR-20260319011031-7973', 'Walk-in Customer', '03', '4x4', NULL, 'TNC', 'JAYCIE', '0997637364105', 59, NULL, 1, 70.00, 70.00, 50, 49, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-19 01:10:31', '2026-03-19 01:14:11', 0, 167),
	(124, 'PUR-20260319011251-6896', 'Walk-in Customer', '010', 'Red Horse', NULL, 'LOKLOK', '', '', 61, NULL, 1, 70.00, 70.00, 10, 9, '#f6c23e', 'cash', 'pending', 'completed', '2026-03-19 01:12:51', '2026-03-19 01:14:03', 0, 166),
	(125, 'PUR-20260319011308-5646', 'Walk-in Customer', '12', 'ffff', NULL, 'steelvalley', '', '', 62, NULL, 1, 60.00, 60.00, 10, 9, '#e74a3b', 'cash', 'pending', 'completed', '2026-03-19 01:13:08', '2026-03-19 01:13:57', 0, 165),
	(126, 'PUR-20260319011337-1526', 'Walk-in Customer', '09', 'boots', NULL, 'JLC BEST CONSTRUCTION OPC', '', '', 60, NULL, 1, 80.00, 80.00, 10, 9, '#4e73df', 'cash', 'pending', 'completed', '2026-03-19 01:13:37', '2026-03-19 01:13:48', 0, 164),
	(127, 'PUR-20260319020153-1695', 'Walk-in Customer', '01', 'hardhat', NULL, 'LIQUID', 'clydeey', '09999999999', 58, NULL, 26, 60.00, 1560.00, 49, 23, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-19 02:01:53', '2026-03-19 02:01:59', 0, 168),
	(128, 'PUR-20260324081738-2127', 'Walk-in Customer', '03', '4x4', NULL, 'TNC', 'JAYCIE', '0997637364105', 59, NULL, 10, 70.00, 700.00, 49, 39, '#6f42c1', 'cash', 'pending', 'completed', '2026-03-24 08:17:38', '2026-03-24 08:17:45', 0, 170);

-- Dumping structure for table inventory_system.purchase_items
DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `price_id` int NOT NULL,
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `price` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `company_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6c5ce7',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `price_id` (`price_id`),
  KEY `item_no` (`item_no`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchase_items: ~0 rows (approximately)

-- Dumping structure for table inventory_system.sites
DROP TABLE IF EXISTS `sites`;
CREATE TABLE IF NOT EXISTS `sites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_name` varchar(255) NOT NULL,
  `location_description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_name` (`site_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.sites: ~0 rows (approximately)
INSERT INTO `sites` (`id`, `site_name`, `location_description`, `created_at`, `updated_at`) VALUES
	(1, 'pgh', 'makati', '2026-03-25 00:48:58', '2026-03-25 00:48:58');

-- Dumping structure for table inventory_system.stock_movements
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `type` enum('in','out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `site_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  KEY `idx_stock_movements_product_date` (`product_id`,`created_at`),
  KEY `idx_stock_movements_created_at` (`created_at`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=173 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.stock_movements: ~0 rows (approximately)
INSERT INTO `stock_movements` (`id`, `product_id`, `type`, `quantity`, `reference`, `notes`, `created_at`, `created_by`, `site_location`) VALUES
	(168, 103, 'in', 26, 'PURCHASE #PUR-20260319020153-1695', 'Ordered from LIQUID - Qty: 26', '2026-03-19 02:01:59', 3, NULL),
	(169, 103, 'out', 10, '', '', '2026-03-24 07:56:27', NULL, NULL),
	(170, 104, 'in', 10, 'PURCHASE #PUR-20260324081738-2127', 'Ordered from TNC - Qty: 10', '2026-03-24 08:17:45', 3, NULL),
	(171, 104, 'out', 1, '', '', '2026-03-24 09:30:53', 3, NULL),
	(172, 104, 'out', 1, '', '', '2026-03-25 01:30:15', NULL, NULL);

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

-- Dumping data for table inventory_system.users: ~0 rows (approximately)
INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`) VALUES
	(3, 'admin', 'admin12345', 'admin@inventory.com', 'administrator', '2026-03-05 06:19:25');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
