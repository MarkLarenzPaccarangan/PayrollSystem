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
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_no` (`item_no`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.canvas_items: ~15 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `category`, `unit`, `created_at`, `updated_at`) VALUES
	(2, '02', 'bondpaper', 'Office Supplies', 'dozen', '2026-03-26 03:31:37', '2026-03-26 08:41:30'),
	(3, '03', 'bondpaper', 'Office Supplies', 'dozen', '2026-03-26 06:04:22', '2026-03-27 08:24:34'),
	(4, '04', 'Hard Hats', 'Tools and Equipment', 'pcs', '2026-03-26 06:22:38', '2026-03-27 05:28:45'),
	(5, '05', 'Laptop', 'Office Supplies', 'pcs', '2026-03-26 06:41:47', '2026-03-26 08:27:12'),
	(6, '06', 'Cellphone', 'Tools and Equipment', 'pcs', '2026-03-26 07:44:25', '2026-03-26 08:28:39'),
	(7, '07', 'Paper', 'Office Supplies', 'bundle', '2026-03-26 07:57:47', '2026-03-26 07:57:47'),
	(10, '111114', '4x5', 'Consumables', 'bundle', '2026-03-27 05:58:18', '2026-03-27 05:58:18'),
	(11, '09', 'bilog', 'Office Supplies', 'bundle', '2026-03-27 05:59:42', '2026-03-27 05:59:42'),
	(12, '010', 'bilog', 'Office Supplies', 'bundle', '2026-03-27 06:00:49', '2026-03-27 06:00:49'),
	(13, '011', 'bilog', 'Office Supplies', 'bundle', '2026-03-27 08:47:38', '2026-03-27 08:47:38'),
	(14, '90', 'CAT', 'Consumables', 'pcs', '2026-03-27 08:55:34', '2026-03-27 08:55:34'),
	(15, '01', 'DOG', 'Consumables', 'pair', '2026-03-27 08:56:11', '2026-03-27 08:56:11'),
	(16, '91', 'CAT', 'Consumables', 'pcs', '2026-03-27 08:57:12', '2026-03-27 08:57:12'),
	(17, '92', 'cat', 'Consumables', 'feet', '2026-03-27 09:10:32', '2026-03-27 09:14:33'),
	(18, '93', 'CAT', 'Consumables', 'pcs', '2026-03-27 09:12:31', '2026-03-27 09:12:31');

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~0 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
	(4, 'AURORA', NULL, 'CLYDE', '0807070', 'active', '2026-03-27 08:54:45', '2026-03-27 08:54:45'),
	(5, 'DIY', NULL, 'KIRTIE', '0700707', 'active', '2026-03-27 08:55:06', '2026-03-27 08:55:06');

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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~3 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `company_color`, `created_at`, `updated_at`) VALUES
	(18, 18, 4, 46, 30.00, 1, '#4e73df', '2026-03-27 08:55:34', '2026-03-27 09:13:10'),
	(19, 15, 4, 50, 2000.00, 1, '#4e73df', '2026-03-27 08:56:11', '2026-03-27 08:56:11'),
	(20, 17, 5, 39, 60.00, 1, '#4e73df', '2026-03-27 09:14:33', '2026-03-27 09:15:08');

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
  `deducted_by` int DEFAULT NULL,
  `deducted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_deducted_at` (`deducted_at`),
  KEY `idx_site_id` (`site_id`),
  CONSTRAINT `deduction_history_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deduction_history_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.deduction_history: ~0 rows (approximately)

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
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.products: ~3 rows (approximately)
INSERT INTO `products` (`id`, `item_no`, `name`, `description`, `price`, `quantity`, `category`, `unit`, `low_stock_threshold`, `created_at`, `updated_at`) VALUES
	(39, '91', '90 - CAT', 'CAT', 30.00, 2, '0', 'pcs', 10, '2026-03-27 08:56:42', '2026-03-27 08:57:38'),
	(40, '92', '92 - CAT', 'CAT', 30.00, 0, '0', '0', 10, '2026-03-27 09:10:55', '2026-03-27 09:45:02'),
	(41, '93', '93 - CAT', 'CAT', 30.00, 1, '0', '0', 10, '2026-03-27 09:13:10', '2026-03-27 09:13:10');

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
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
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchases: ~34 rows (approximately)
INSERT INTO `purchases` (`id`, `purchase_number`, `customer_name`, `item_no`, `description`, `category`, `unit`, `company_name`, `contact_person`, `contact_number`, `price_id`, `product_id`, `quantity_purchased`, `price_per_unit`, `total_amount`, `delivery_date`, `available_before`, `available_after`, `company_color`, `payment_method`, `payment_status`, `status`, `purchase_date`, `updated_at`, `stock_updated`, `stock_movement_id`) VALUES
	(53, 'PUR-20260327084841-6821', 'Walk-in Customer', '011', 'bilog', NULL, 'pcs', 'SM', 'Davis Kert B.', '0987654321', 16, 36, 1, 60.00, 60.00, '2026-03-27', 19, 18, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 08:49:13', 1, 54),
	(54, 'PUR-20260327084857-4133', 'Walk-in Customer', '011', 'bilog', NULL, 'pcs', 'SOHO', 'Clyde', '0707070', 17, 36, 1, 80.00, 80.00, '2026-03-27', 27, 26, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 08:49:26', 1, 55),
	(55, 'PUR-20260327085012-1706', 'Walk-in Customer', '011', 'bilog', NULL, 'pcs', 'SM', 'Davis Kert B.', '0987654321', 16, 36, 1, 60.00, 60.00, '2026-03-27', 18, 17, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 08:50:18', 1, 56),
	(56, 'PUR-20260327085636-3727', 'Walk-in Customer', '90', 'CAT', NULL, 'pcs', 'AURORA', 'CLYDE', '0807070', 18, 39, 1, 30.00, 30.00, '2026-03-27', 50, 49, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 08:56:42', 1, 57),
	(57, 'PUR-20260327085733-2149', 'Walk-in Customer', '91', 'CAT', NULL, 'pcs', 'AURORA', 'CLYDE', '0807070', 18, 39, 1, 30.00, 30.00, '2026-03-27', 49, 48, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 08:57:38', 1, 58),
	(58, 'PUR-20260327091050-6306', 'Walk-in Customer', '92', 'CAT', NULL, 'pcs', 'AURORA', 'CLYDE', '0807070', 18, 40, 1, 30.00, 30.00, '2026-03-27', 48, 47, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 09:10:55', 1, 59),
	(59, 'PUR-20260327091255-2774', 'Walk-in Customer', '93', 'CAT', NULL, 'pcs', 'AURORA', 'CLYDE', '0807070', 18, 41, 1, 30.00, 30.00, '2026-03-27', 47, 46, '#4e73df', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 09:13:10', 1, 60),
	(60, 'PUR-20260327091502-4198', 'Walk-in Customer', '92', 'cat', NULL, 'pcs', 'DIY', 'KIRTIE', '0700707', 20, 40, 1, 60.00, 60.00, '2026-03-27', 40, 39, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-26 16:00:00', '2026-03-27 09:15:08', 1, 61);

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.sites: ~0 rows (approximately)
INSERT INTO `sites` (`id`, `site_name`, `location_description`, `created_at`, `updated_at`) VALUES
	(1, 'PLDT', 'Makati', '2026-03-26 02:45:55', '2026-03-26 02:45:55');

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
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.stock_movements: ~5 rows (approximately)
INSERT INTO `stock_movements` (`id`, `product_id`, `type`, `quantity`, `reference`, `notes`, `created_at`, `created_by`, `site_location`) VALUES
	(57, 39, 'in', 1, 'PURCHASE #PUR-20260327085636-3727', 'Ordered from AURORA - Qty: 1', '2026-03-26 16:00:00', 3, NULL),
	(58, 39, 'in', 1, 'PURCHASE #PUR-20260327085733-2149', 'Ordered from AURORA - Qty: 1', '2026-03-26 16:00:00', 3, NULL),
	(59, 40, 'in', 1, 'PURCHASE #PUR-20260327091050-6306', 'Ordered from AURORA - Qty: 1', '2026-03-26 16:00:00', 3, NULL),
	(60, 41, 'in', 1, 'PURCHASE #PUR-20260327091255-2774', 'Ordered from AURORA - Qty: 1', '2026-03-26 16:00:00', 3, NULL),
	(61, 40, 'in', 1, 'PURCHASE #PUR-20260327091502-4198', 'Ordered from DIY - Qty: 1', '2026-03-26 16:00:00', 3, NULL),
	(62, 40, 'out', 1, '', '', '2026-03-27 01:27:00', 3, 'PLDT'),
	(63, 40, 'out', 1, '', '', '2026-03-27 01:44:00', 3, 'PLDT');

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

-- Dumping data for table inventory_system.users: ~1 rows (approximately)
INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`) VALUES
	(3, 'admin', 'admin12345', 'admin@inventory.com', 'administrator', '2026-03-05 06:19:25');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
