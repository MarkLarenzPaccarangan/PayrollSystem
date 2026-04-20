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
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `supplier_companies` text COLLATE utf8mb4_unicode_ci COMMENT 'List of company IDs that supply this item (JSON format or comma-separated)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_no` (`item_no`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.canvas_items: ~6 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `category`, `unit`, `supplier_companies`, `created_at`, `updated_at`) VALUES
	(18, '93', 'cat', 'Consumables', 'feet', NULL, '2026-03-27 09:12:31', '2026-04-10 01:21:49'),
	(19, '99', 'gin', 'Consumables', 'bundle', NULL, '2026-03-31 07:40:30', '2026-04-10 01:23:35'),
	(29, '30012', 'wood', 'Rent & Utilities Expenses', 'pair', NULL, '2026-04-10 02:17:26', '2026-04-10 02:17:26'),
	(32, '999', 'gin', 'Consumables', 'bundle', NULL, '2026-04-10 02:31:38', '2026-04-10 02:31:38'),
	(33, '934', 'cat', 'Consumables', 'feet', NULL, '2026-04-10 02:33:06', '2026-04-10 02:33:06'),
	(34, '43', 'dulli', 'Transportation', 'roll', NULL, '2026-04-10 02:39:28', '2026-04-10 02:39:28'),
	(35, '888', 'dvvdvd', 'Tools and Equipment', 'meter', NULL, '2026-04-10 15:27:25', '2026-04-10 15:27:25');

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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~5 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
	(4, 'TNC', NULL, 'CLYDE', '0807070', 'active', '2026-03-27 08:54:45', '2026-04-10 02:35:09'),
	(5, 'DIY', NULL, 'KIRTIE', '0700707', 'active', '2026-03-27 08:55:06', '2026-03-27 08:55:06'),
	(6, 'JLC', NULL, 'clydeyy', '060060606', 'active', '2026-03-30 02:52:19', '2026-03-30 02:52:19'),
	(7, 'JLL', NULL, 'larenz', '06060606060', 'active', '2026-03-31 07:39:52', '2026-03-31 07:39:52'),
	(9, 'RSG', NULL, 'YNOT', '00550550', 'active', '2026-04-10 01:38:38', '2026-04-10 01:38:38');

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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~7 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `company_color`, `created_at`, `updated_at`) VALUES
	(18, 18, 4, 44, 30.00, 1, '#4e73df', '2026-03-27 08:55:34', '2026-04-10 13:42:55'),
	(20, 33, 5, 31, 60.00, 1, '#4e73df', '2026-03-27 09:14:33', '2026-04-10 13:35:43'),
	(21, 19, 7, 22, 60.00, 1, '#4e73df', '2026-03-31 07:40:30', '2026-04-10 15:57:40'),
	(23, 32, 4, 13, 66.00, 1, '#4e73df', '2026-04-06 02:40:04', '2026-04-10 15:15:38'),
	(25, 29, 9, 20, 60.00, 1, '#4e73df', '2026-04-10 02:13:42', '2026-04-10 13:42:02'),
	(26, 34, 4, 0, 99.00, 1, '#4e73df', '2026-04-10 02:39:28', '2026-04-13 05:43:04'),
	(27, 35, 5, 0, 0.00, 1, '#4e73df', '2026-04-10 15:27:25', '2026-04-10 15:27:47');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.customers: ~0 rows (approximately)

-- Dumping structure for table inventory_system.deduction_history
DROP TABLE IF EXISTS `deduction_history`;
CREATE TABLE IF NOT EXISTS `deduction_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_id` int NOT NULL,
  `site_name` varchar(255) NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `item_no` varchar(100) DEFAULT NULL,
  `quantity_deducted` int NOT NULL,
  `previous_quantity` int NOT NULL,
  `new_quantity` int NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text,
  `remarks` text,
  `deducted_by` int DEFAULT NULL,
  `deducted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_deducted_at` (`deducted_at`),
  KEY `idx_site_id` (`site_id`),
  CONSTRAINT `deduction_history_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deduction_history_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.deduction_history: ~0 rows (approximately)
INSERT INTO `deduction_history` (`id`, `site_id`, `site_name`, `product_id`, `product_name`, `item_no`, `quantity_deducted`, `previous_quantity`, `new_quantity`, `reference`, `notes`, `remarks`, `deducted_by`, `deducted_at`) VALUES
	(14, 3, 'JLLL', 55, 'wood', '30012', 1, 1, 0, 'DEDUCTION-20260413003205-108-55', 'Deducted 1 0 from site: JLLL. Returned to main inventory.', 'damage', 3, '2026-04-13 00:32:05');

-- Dumping structure for table inventory_system.item_companies
DROP TABLE IF EXISTS `item_companies`;
CREATE TABLE IF NOT EXISTS `item_companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `company_id` int NOT NULL,
  `price` decimal(10,2) DEFAULT '0.00',
  `availability` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_item_company` (`item_id`,`company_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `item_companies_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `canvas_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_companies_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.item_companies: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.products: ~6 rows (approximately)
INSERT INTO `products` (`id`, `item_no`, `name`, `description`, `price`, `quantity`, `category`, `unit`, `low_stock_threshold`, `created_at`, `updated_at`) VALUES
	(55, '30012', '30012 - wood', 'wood', 60.00, 11, 'Rent & Utilities Expenses', '0', 10, '2026-04-10 11:57:56', '2026-04-13 05:43:47'),
	(56, '43', '43 - dulli', 'dulli', 99.00, 6, 'Transportation', 'roll', 10, '2026-04-10 13:29:34', '2026-04-13 05:43:04'),
	(57, '93', '93 - cat', 'cat', 30.00, 1, 'Consumables', 'feet', 10, '2026-04-10 13:32:08', '2026-04-13 01:15:33'),
	(58, '934', '934 - cat', 'cat', 60.00, 2, 'Consumables', 'feet', 10, '2026-04-10 13:35:43', '2026-04-10 13:39:54'),
	(60, '999', '999 - gin', 'gin', 66.00, 0, 'Consumables', 'bundle', 10, '2026-04-10 15:15:38', '2026-04-13 02:03:01'),
	(62, '99', '99 - gin', 'gin', 60.00, 3, 'Consumables', 'bundle', 10, '2026-04-10 15:57:40', '2026-04-13 06:15:22');

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `quantity_purchased` int NOT NULL DEFAULT '0',
  `price_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_date` date DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchases: ~13 rows (approximately)
INSERT INTO `purchases` (`id`, `purchase_number`, `customer_name`, `item_no`, `description`, `category`, `unit`, `company_name`, `contact_person`, `contact_number`, `price_id`, `product_id`, `quantity_purchased`, `price_per_unit`, `total_amount`, `delivery_date`, `available_before`, `available_after`, `company_color`, `payment_method`, `payment_status`, `status`, `purchase_date`, `updated_at`, `stock_updated`, `stock_movement_id`) VALUES
	(80, 'PUR-20260410115746-2589', 'Walk-in Customer', '30012', 'wood', NULL, 'pcs', 'RSG', 'YNOT', '00550550', 25, 55, 10, 60.00, 600.00, '2026-04-10', 33, 23, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 11:57:56', 1, 110),
	(81, 'PUR-20260410132840-2598', 'Walk-in Customer', '30012', 'wood', NULL, 'pcs', 'RSG', 'YNOT', '00550550', 25, 55, 1, 60.00, 60.00, '2026-04-10', 23, 22, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:28:46', 1, 111),
	(82, 'PUR-20260410132921-8164', 'Walk-in Customer', '43', 'dulli', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 26, 56, 1, 99.00, 99.00, '2026-04-10', 6, 5, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:29:34', 1, 112),
	(83, 'PUR-20260410133202-6123', 'Walk-in Customer', '93', 'cat', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 18, 57, 1, 30.00, 30.00, '2026-04-10', 46, 45, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:32:08', 1, 113),
	(84, 'PUR-20260410133537-6299', 'Walk-in Customer', '934', 'cat', NULL, 'pcs', 'DIY', 'KIRTIE', '0700707', 20, 58, 2, 60.00, 120.00, '2026-04-10', 33, 31, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:35:43', 1, 114),
	(85, 'PUR-20260410134022-5077', 'Walk-in Customer', '99', 'gin', NULL, 'pcs', 'JLL', 'larenz', '06060606060', 21, 59, 1, 60.00, 60.00, '2026-04-10', 30, 29, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:40:27', 1, 115),
	(86, 'PUR-20260410134121-7300', 'Walk-in Customer', '30012', 'wood', NULL, 'pcs', 'RSG', 'YNOT', '00550550', 25, 55, 2, 60.00, 120.00, '2026-04-10', 22, 20, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:42:02', 1, 116),
	(87, 'PUR-20260410134137-3594', 'Walk-in Customer', '43', 'dulli', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 26, 56, 2, 99.00, 198.00, '2026-04-10', 5, 3, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-09 16:00:00', '2026-04-10 13:42:12', 1, 117),
	(88, 'PUR-20260410134249-8133', 'Walk-in Customer', '93', 'cat', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 18, 57, 1, 30.00, 30.00, '2026-04-11', 45, 44, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-10 16:00:00', '2026-04-10 13:42:55', 1, 118),
	(89, 'PUR-20260410151511-5557', 'Walk-in Customer', '999', 'gin', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 23, 60, 1, 66.00, 66.00, '2026-04-12', 14, 13, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-11 16:00:00', '2026-04-10 15:15:38', 1, 119),
	(90, 'PUR-20260410151532-6218', 'Walk-in Customer', '99', 'gin', NULL, 'pcs', 'JLL', 'larenz', '06060606060', 21, 59, 2, 60.00, 120.00, '2026-04-12', 29, 27, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-11 16:00:00', '2026-04-10 15:15:47', 1, 120),
	(91, 'PUR-20260410152741-2949', 'Walk-in Customer', '888', 'dvvdvd', NULL, 'pcs', 'DIY', 'KIRTIE', '0700707', 27, 61, 10, 0.00, 0.00, '2026-04-13', 10, 0, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-12 16:00:00', '2026-04-10 15:27:47', 1, 123),
	(92, 'PUR-20260410155730-6211', 'Walk-in Customer', '99', 'gin', NULL, 'pcs', 'JLL', 'larenz', '06060606060', 21, 62, 5, 60.00, 300.00, '2026-04-13', 27, 22, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-12 16:00:00', '2026-04-10 15:57:40', 1, 125),
	(93, 'PUR-20260413054257-2846', 'Walk-in Customer', '43', 'dulli', NULL, 'pcs', 'TNC', 'CLYDE', '0807070', 26, 56, 3, 99.00, 297.00, '2026-04-13', 3, 0, '#6c5ce7', 'cash', 'pending', 'completed', '2026-04-12 16:00:00', '2026-04-13 05:43:04', 1, 129);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.sites: ~3 rows (approximately)
INSERT INTO `sites` (`id`, `site_name`, `location_description`, `created_at`, `updated_at`) VALUES
	(3, 'JLLL', 'QC', '2026-03-31 07:41:54', '2026-04-01 01:41:10'),
	(4, 'PGH', 'Marikina', '2026-04-01 01:42:02', '2026-04-01 01:42:02'),
	(5, 'PLDT', 'Makati', '2026-04-01 01:42:18', '2026-04-01 01:42:18'),
	(6, 'BGC', 'Manila', '2026-04-06 00:57:29', '2026-04-06 00:57:29');

-- Dumping structure for table inventory_system.site_items
DROP TABLE IF EXISTS `site_items`;
CREATE TABLE IF NOT EXISTS `site_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_site_product` (`site_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `site_items_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.site_items: ~0 rows (approximately)

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
  `site_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`),
  KEY `idx_stock_movements_product_date` (`product_id`,`created_at`),
  KEY `idx_stock_movements_created_at` (`created_at`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.stock_movements: ~11 rows (approximately)
INSERT INTO `stock_movements` (`id`, `product_id`, `type`, `quantity`, `reference`, `notes`, `created_at`, `created_by`, `site_location`) VALUES
	(116, 55, 'in', 2, 'PURCHASE #PUR-20260410134121-7300', 'Ordered from RSG - Qty: 2', '2026-04-09 16:00:00', 3, NULL),
	(117, 56, 'in', 2, 'PURCHASE #PUR-20260410134137-3594', 'Ordered from TNC - Qty: 2', '2026-04-09 16:00:00', 3, NULL),
	(118, 57, 'in', 1, 'PURCHASE #PUR-20260410134249-8133', 'Ordered from TNC - Qty: 1', '2026-04-10 16:00:00', 3, NULL),
	(119, 60, 'in', 1, 'PURCHASE #PUR-20260410151511-5557', 'Ordered from TNC - Qty: 1', '2026-04-11 16:00:00', 3, NULL),
	(125, 62, 'in', 5, 'PURCHASE #PUR-20260410155730-6211', 'Ordered from JLL - Qty: 5', '2026-04-12 16:00:00', 3, NULL),
	(126, 55, 'in', 1, 'DEDUCTION-20260413003205-108', 'Returned from site: JLLL. Deducted quantity: 1 0', '2026-04-12 16:32:05', 3, 'JLLL'),
	(127, 57, 'out', 1, '', '', '2026-04-12 17:15:33', 3, 'BGC'),
	(128, 60, 'out', 1, '', '', '2026-04-11 18:03:01', 3, 'JLLL'),
	(129, 56, 'in', 3, 'PURCHASE #PUR-20260413054257-2846', 'Ordered from TNC - Qty: 3', '2026-04-12 16:00:00', 3, NULL),
	(130, 55, 'out', 2, '', '', '2026-04-12 21:43:47', 3, 'BGC'),
	(131, 62, 'out', 1, '', '', '2026-04-12 22:14:53', 3, 'BGC'),
	(132, 62, 'out', 1, '', '', '2026-04-12 22:15:22', 3, 'BGC');

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

-- Dumping structure for trigger inventory_system.sync_product_category
DROP TRIGGER IF EXISTS `sync_product_category`;
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER `sync_product_category` BEFORE INSERT ON `products` FOR EACH ROW BEGIN
    DECLARE canvas_category VARCHAR(100);
    DECLARE canvas_unit VARCHAR(50);
    
    -- Get category and unit from canvas_items
    SELECT category, unit INTO canvas_category, canvas_unit 
    FROM canvas_items 
    WHERE item_no = NEW.item_no 
    LIMIT 1;
    
    -- Set the values if found and current values are empty/'0'
    IF (NEW.category IS NULL OR NEW.category = '' OR NEW.category = '0') AND canvas_category IS NOT NULL THEN
        SET NEW.category = canvas_category;
    END IF;
    
    IF (NEW.unit IS NULL OR NEW.unit = '' OR NEW.unit = '0') AND canvas_unit IS NOT NULL THEN
        SET NEW.unit = canvas_unit;
    END IF;
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
