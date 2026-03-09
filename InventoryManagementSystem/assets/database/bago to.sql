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
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.canvas_items: ~18 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `quantity`, `unit`, `united_power_price`, `united_power_availability`, `vicsteel_price`, `vicsteel_availability`, `anakko_price`, `anakko_availability`, `rclquintera_price`, `rclquintera_availability`, `created_at`, `updated_at`) VALUES
	(58, 'STR-003', 'Stretched Canvas 12x16 inches - Deep Edge', 50, 'pcs', 380.00, 1, 370.00, 1, 390.00, 1, 375.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(60, 'STR-005', 'Stretched Canvas 18x24 inches - Deep Edge', 40, 'pcs', 520.00, 1, 510.00, 1, 530.00, 1, 515.00, 0, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(61, 'STR-006', 'Stretched Canvas 20x24 inches - Deep Edge', 35, 'pcs', 580.00, 1, 570.00, 0, 590.00, 1, 575.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(62, 'STR-007', 'Stretched Canvas 24x30 inches - Deep Edge', 30, 'pcs', 650.00, 1, 640.00, 1, 660.00, 1, 645.00, 1, '2026-03-05 06:19:24', '2026-03-05 06:19:24'),
	(66, '089', 'gsgdsagdgsgddsa', 3, 'pcs', 3333.00, 1, 33333.00, 1, 42353543.00, 1, 888888.00, 1, '2026-03-05 08:24:16', '2026-03-05 08:24:16'),
	(67, '01234', 'plywoods', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 08:44:17', '2026-03-07 16:35:48'),
	(69, '9898989', 'iloveyou', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-05 09:20:26', '2026-03-05 09:20:26'),
	(71, '0123', 'graba', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-06 01:10:33', '2026-03-06 01:10:33'),
	(73, '888888', 'hardhat', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-07 07:12:23', '2026-03-07 07:12:23'),
	(74, '3310', 'Bagga', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-07 09:45:01', '2026-03-07 09:45:01'),
	(75, '4545', 'bilog', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-07 09:52:46', '2026-03-07 09:52:46'),
	(77, '6060', 'bondpaper', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-07 10:56:32', '2026-03-07 10:56:32'),
	(78, '312212', 'hollow ', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-07 13:42:59', '2026-03-07 13:42:59');

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
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~12 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
	(1, 'Landbank', '', 'julianah', '084848484', 'active', '2026-03-05 08:43:32', '2026-03-07 16:35:48'),
	(4, 'GPI', NULL, 'jayjay', '0999999999999', 'active', '2026-03-06 01:10:33', '2026-03-07 16:31:29'),
	(5, 'BDO', NULL, 'Clyde Melendez', '0922222222222', 'active', '2026-03-06 02:26:21', '2026-03-07 16:34:08'),
	(6, 'letus', NULL, 'Clyde Melendez', '0900909090', 'active', '2026-03-07 07:12:23', '2026-03-07 07:12:23'),
	(7, 'florida', NULL, 'Jay cie', '0900909090', 'active', '2026-03-07 09:45:01', '2026-03-07 16:34:42'),
	(8, 'larssse', NULL, 'jaycie', '343545', 'active', '2026-03-07 09:52:46', '2026-03-08 11:00:33'),
	(10, 'bscs', NULL, 'larenz', '343545', 'active', '2026-03-07 10:56:32', '2026-03-07 10:56:32'),
	(11, 'best opc', NULL, 'jeje', '878787878', 'active', '2026-03-07 13:48:05', '2026-03-07 13:48:05');

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
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~12 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `company_color`, `created_at`, `updated_at`) VALUES
	(5, 67, 1, 21, 80.00, 1, '#4e73df', '2026-03-05 11:36:31', '2026-03-08 15:11:10'),
	(6, 71, 4, 31, 6.00, 1, '#4e73df', '2026-03-06 01:10:33', '2026-03-07 12:51:01'),
	(7, 71, 5, 0, 1.00, 1, '#4e73df', '2026-03-06 02:26:21', '2026-03-08 14:35:52'),
	(8, 73, 6, 38, 1500.00, 1, '#4e73df', '2026-03-07 07:12:23', '2026-03-09 00:23:09'),
	(10, 74, 7, 13, 1000.00, 1, '#4e73df', '2026-03-07 09:45:01', '2026-03-07 12:21:22'),
	(11, 75, 8, 40, 500.00, 1, '#4e73df', '2026-03-07 09:52:46', '2026-03-07 12:42:56'),
	(13, 77, 10, 76, 200.00, 1, '#4e73df', '2026-03-07 10:56:32', '2026-03-07 10:59:54'),
	(15, 78, 11, 48, 50.00, 1, '#4e73df', '2026-03-07 13:48:05', '2026-03-08 15:11:02');

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
  `item_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `quantity` int NOT NULL DEFAULT '0',
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `low_stock_threshold` int DEFAULT '10',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category`),
  KEY `idx_products_quantity` (`quantity`),
  KEY `idx_item_no` (`item_no`),
  KEY `idx_products_item_no` (`item_no`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.products: ~10 rows (approximately)
INSERT INTO `products` (`id`, `item_no`, `name`, `description`, `price`, `quantity`, `category`, `unit`, `low_stock_threshold`, `created_at`, `updated_at`) VALUES
	(56, '0123', '0123 - graba', 'graba', 6.00, 24, NULL, 'pcs', 10, '2026-03-07 06:14:19', '2026-03-08 14:35:52'),
	(57, '0123', '0123 - graba', 'graba', 80.00, 2, NULL, 'pcs', 10, '2026-03-06 05:32:16', '2026-03-07 07:03:41'),
	(58, '0123', '0123 - graba', 'graba', 80.00, 3, NULL, 'pcs', 10, '2026-03-06 05:32:16', '2026-03-07 06:44:26'),
	(68, '888888', '888888 - hardhat', 'hardhat', 1500.00, 12, NULL, 'pcs', 10, '2026-03-07 08:31:30', '2026-03-09 00:23:09'),
	(69, '08780', '08780 - tnaga', 'tnaga', 1000.00, 5, NULL, 'pcs', 10, '2026-03-07 09:35:44', '2026-03-07 12:17:36'),
	(70, '999999', '999999 - hgghhg', 'hgghhg', 500.00, 5, NULL, 'pcs', 10, '2026-03-07 09:42:25', '2026-03-07 12:20:12'),
	(71, '3310', '3310 - Bagga', 'Bagga', 1000.00, 37, NULL, 'pcs', 10, '2026-03-07 09:46:07', '2026-03-07 12:21:22'),
	(74, '4545', '4545 - bilog', 'bilog', 500.00, 15, NULL, 'pcs', 10, '2026-03-07 09:54:09', '2026-03-07 12:42:56'),
	(75, '7070', '7070 - fan', 'fan', 50.00, 5, NULL, 'pcs', 10, '2026-03-07 09:59:33', '2026-03-07 09:59:33'),
	(76, '6060', '6060 - bondpaper', 'bondpaper', 200.00, 14, NULL, 'pcs', 10, '2026-03-07 10:57:06', '2026-03-07 10:59:54'),
	(77, '312212', '312212 - hollow ', 'hollow ', 50.00, 2, NULL, 'pcs', 10, '2026-03-08 15:11:02', '2026-03-08 15:11:02'),
	(78, '01234', '01234 - plywoods', 'plywoods', 80.00, 3, NULL, 'pcs', 10, '2026-03-08 15:11:10', '2026-03-08 15:11:10');

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `quantity_purchased` int NOT NULL DEFAULT '0',
  `price_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `available_before` int NOT NULL DEFAULT '0',
  `available_after` int NOT NULL DEFAULT '0',
  `company_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '#6c5ce7',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `payment_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'paid',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'completed',
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
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchases: ~4 rows (approximately)
INSERT INTO `purchases` (`id`, `purchase_number`, `customer_name`, `item_no`, `description`, `company_name`, `contact_person`, `contact_number`, `price_id`, `product_id`, `quantity_purchased`, `price_per_unit`, `total_amount`, `available_before`, `available_after`, `company_color`, `payment_method`, `payment_status`, `status`, `purchase_date`, `updated_at`, `stock_updated`, `stock_movement_id`) VALUES
	(69, 'PUR-20260308095544-2997', 'Walk-in Customer', '0123', 'graba', 'BDO', 'Clyde Melendez', '0922222222222', 7, 56, 1, 1.00, 1.00, 2, 1, '', 'cash', 'pending', 'completed', '2026-03-08 09:55:44', '2026-03-08 09:56:11', 0, 98),
	(70, 'PUR-20260308143327-2507', 'Walk-in Customer', '312212', 'hollow ', 'best opc', 'jeje', '878787878', 15, NULL, 2, 50.00, 100.00, 50, 48, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-08 14:33:27', '2026-03-08 15:11:02', 0, 100),
	(71, 'PUR-20260308143518-3150', 'Walk-in Customer', '0123', 'graba', 'BDO', 'Clyde Melendez', '0922222222222', 7, 56, 1, 1.00, 1.00, 1, 0, '#4e73df', 'cash', 'pending', 'completed', '2026-03-08 14:35:18', '2026-03-08 14:35:52', 0, 99),
	(72, 'PUR-20260308151054-5717', 'Walk-in Customer', '01234', 'plywoods', 'Landbank', 'julianah', '084848484', 5, NULL, 3, 80.00, 240.00, 24, 21, '#6f42c1', 'cash', 'pending', 'completed', '2026-03-08 15:10:54', '2026-03-08 15:11:10', 0, 101),
	(73, 'PUR-20260309002257-3903', 'Walk-in Customer', '888888', 'hardhat', 'letus', 'Clyde Melendez', '0900909090', 8, 68, 1, 1500.00, 1500.00, 39, 38, '#20c9a6', 'cash', 'pending', 'completed', '2026-03-09 00:22:58', '2026-03-09 00:23:09', 0, 102);

-- Dumping structure for table inventory_system.purchase_items
DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `price_id` int NOT NULL,
  `item_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `price` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `company_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '#6c5ce7',
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

-- Dumping structure for table inventory_system.stock_movements
DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `type` enum('in','out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
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
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.stock_movements: ~3 rows (approximately)
INSERT INTO `stock_movements` (`id`, `product_id`, `type`, `quantity`, `reference`, `notes`, `created_at`, `created_by`) VALUES
	(95, 56, 'in', 1, 'PURCHASE #PUR-20260307125645-4186', 'Ordered from clyde - Qty: 1', '2026-03-07 12:56:45', 3),
	(96, 56, 'in', 1, 'PURCHASE #PUR-20260307125823-8915', 'Ordered from fujiko - Qty: 1', '2026-03-07 12:58:29', 3),
	(97, 56, 'in', 3, 'PURCHASE #PUR-20260307145839-4534', 'Ordered from clyde - Qty: 3', '2026-03-07 14:58:48', 3),
	(98, 56, 'in', 1, 'PURCHASE #PUR-20260308095544-2997', 'Ordered from BDO - Qty: 1', '2026-03-08 09:56:11', 3),
	(99, 56, 'in', 1, 'PURCHASE #PUR-20260308143518-3150', 'Ordered from BDO - Qty: 1', '2026-03-08 14:35:52', 3),
	(100, 77, 'in', 2, 'PURCHASE #PUR-20260308143327-2507', 'Ordered from best opc - Qty: 2', '2026-03-08 15:11:02', 3),
	(101, 78, 'in', 3, 'PURCHASE #PUR-20260308151054-5717', 'Ordered from Landbank - Qty: 3', '2026-03-08 15:11:10', 3),
	(102, 68, 'in', 1, 'PURCHASE #PUR-20260309002257-3903', 'Ordered from letus - Qty: 1', '2026-03-09 00:23:09', 3);

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
