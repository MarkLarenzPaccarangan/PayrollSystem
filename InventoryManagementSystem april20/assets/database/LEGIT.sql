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
  `item_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT '0',
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=92 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.canvas_items: ~0 rows (approximately)
INSERT INTO `canvas_items` (`id`, `item_no`, `description`, `category`, `quantity`, `unit`, `united_power_price`, `united_power_availability`, `vicsteel_price`, `vicsteel_availability`, `anakko_price`, `anakko_availability`, `rclquintera_price`, `rclquintera_availability`, `created_at`, `updated_at`) VALUES
	(82, '01', 'hardhat', 'Large', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:03:31', '2026-03-10 08:03:31'),
	(83, '02', 'bondpaper', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:06:18', '2026-03-10 08:06:18'),
	(84, '03', '4x4', 'alcohol', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:07:28', '2026-03-10 08:07:28'),
	(85, '04', ' mouse', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:08:24', '2026-03-10 08:08:24'),
	(86, '05', 'laptop', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:09:22', '2026-03-10 08:09:22'),
	(87, '06', 'boots', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:10:20', '2026-03-10 08:10:20'),
	(88, '07', 'shoes', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:11:19', '2026-03-10 08:11:19'),
	(89, '08', 'Empelights', 'alcohol', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:12:24', '2026-03-10 08:12:24'),
	(90, '09', 'Red Horse', 'alcohol', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:13:07', '2026-03-10 08:13:07'),
	(91, '010', 'Printer', 'Supplies', 0, NULL, 0.00, 0, 0.00, 0, 0.00, 0, 0.00, 0, '2026-03-10 08:14:03', '2026-03-10 08:14:03');

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
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.companies: ~10 rows (approximately)
INSERT INTO `companies` (`id`, `name`, `description`, `contact_person`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
	(1, 'Landbank', '', 'julianah', '084848484', 'active', '2026-03-05 08:43:32', '2026-03-07 16:35:48'),
	(4, 'GPI', NULL, 'jayjay', '0999999999999', 'active', '2026-03-06 01:10:33', '2026-03-07 16:31:29'),
	(5, 'BDO', NULL, 'Clyde Melendez', '0922222222222', 'active', '2026-03-06 02:26:21', '2026-03-07 16:34:08'),
	(6, 'letus', NULL, 'Clyde Melendez', '0900909090', 'active', '2026-03-07 07:12:23', '2026-03-07 07:12:23'),
	(7, 'floridas', NULL, 'Jay cie', '0900909090', 'active', '2026-03-07 09:45:01', '2026-03-10 00:18:39'),
	(8, 'larssses', NULL, 'jaycie', '343545', 'active', '2026-03-07 09:52:46', '2026-03-09 09:12:45'),
	(10, 'bscs', NULL, 'larenz', '343545', 'active', '2026-03-07 10:56:32', '2026-03-07 10:56:32'),
	(11, 'best opc', NULL, 'jeje', '878787878', 'active', '2026-03-07 13:48:05', '2026-03-07 13:48:05'),
	(14, 'wewqew', NULL, 'wwwwe', '', 'active', '2026-03-09 09:19:58', '2026-03-10 02:53:11'),
	(15, 'bscsi', NULL, 'larenz', '343545', 'active', '2026-03-10 05:19:31', '2026-03-10 05:19:31'),
	(16, 'JLC', NULL, 'Jay Jay', '0900909090', 'active', '2026-03-10 08:03:31', '2026-03-10 08:03:31'),
	(17, 'OPC', NULL, 'kikoy', '0999988', 'active', '2026-03-10 08:06:18', '2026-03-10 08:06:18'),
	(18, 'Ginebra', NULL, 'Marl Larenz', '079765757', 'active', '2026-03-10 08:07:28', '2026-03-10 08:07:28'),
	(19, 'kikay', NULL, 'hahahaha', '7657657', 'active', '2026-03-10 08:08:24', '2026-03-10 08:08:24'),
	(20, 'ACER', NULL, 'KIRTIE', '094949545', 'active', '2026-03-10 08:09:22', '2026-03-10 08:09:22'),
	(21, ' GOMA', NULL, 'huhuhu', '4845848534', 'active', '2026-03-10 08:10:20', '2026-03-10 08:10:20'),
	(22, 'NIKE', NULL, 'CLYDE', '070707070', 'active', '2026-03-10 08:11:19', '2026-03-10 08:11:19'),
	(23, 'Norbie', NULL, 'norbie', '0779797979', 'active', '2026-03-10 08:12:23', '2026-03-10 08:12:23'),
	(24, 'LARING', NULL, 'hahahh', '08076060760', 'active', '2026-03-10 08:13:07', '2026-03-10 08:13:07'),
	(25, 'FEEE', NULL, 'hdsadhahd', '00706076', 'active', '2026-03-10 08:14:03', '2026-03-10 08:14:03'),
	(26, 'LIQUID', NULL, 'JAYCIE', '45435435', 'active', '2026-03-10 09:08:45', '2026-03-10 09:08:45'),
	(27, 'ONIC', NULL, 'MELENDEZ', '0999988', 'active', '2026-03-10 09:09:35', '2026-03-10 09:09:35'),
	(28, 'JLC BEST CONSTRUCTION OPC', NULL, 'Jay Jay', '0900909090', 'active', '2026-03-10 09:14:45', '2026-03-10 09:14:45'),
	(29, 'JJ', NULL, 'JAYCIE', '0909778', 'active', '2026-03-11 03:34:02', '2026-03-11 03:34:02');

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
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Dumping data for table inventory_system.company_prices: ~7 rows (approximately)
INSERT INTO `company_prices` (`id`, `item_id`, `company_id`, `quantity`, `price`, `availability`, `company_color`, `created_at`, `updated_at`) VALUES
	(21, 83, 17, 40, 70.00, 1, '#4e73df', '2026-03-10 08:06:18', '2026-03-10 08:19:50'),
	(22, 84, 18, 70, 65.00, 1, '#4e73df', '2026-03-10 08:07:28', '2026-03-10 08:19:42'),
	(23, 85, 19, 79, 50.00, 1, '#4e73df', '2026-03-10 08:08:24', '2026-03-10 08:19:14'),
	(24, 86, 20, 40, 17000.00, 1, '#4e73df', '2026-03-10 08:09:22', '2026-03-10 08:19:28'),
	(25, 87, 21, 40, 120.00, 1, '#4e73df', '2026-03-10 08:10:20', '2026-03-10 08:19:21'),
	(26, 88, 22, 35, 4000.00, 1, '#4e73df', '2026-03-10 08:11:19', '2026-03-11 01:46:45'),
	(27, 89, 23, 40, 80.00, 1, '#4e73df', '2026-03-10 08:12:24', '2026-03-10 08:19:00'),
	(28, 90, 24, 47, 70.00, 1, '#4e73df', '2026-03-10 08:13:07', '2026-03-11 06:28:48'),
	(29, 91, 25, 39, 60000.00, 1, '#4e73df', '2026-03-10 08:14:03', '2026-03-11 06:36:36'),
	(30, 82, 26, 40, 60.00, 1, '#4e73df', '2026-03-10 09:08:45', '2026-03-10 09:10:48'),
	(31, 82, 27, 37, 80.00, 1, '#4e73df', '2026-03-10 09:09:35', '2026-03-11 06:43:09'),
	(32, 82, 28, 32, 60.00, 1, '#4e73df', '2026-03-10 09:14:45', '2026-03-11 06:33:23'),
	(33, 83, 29, 30, 70.00, 1, '#4e73df', '2026-03-11 03:34:02', '2026-03-11 03:34:47');

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
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.products: ~0 rows (approximately)
INSERT INTO `products` (`id`, `item_no`, `name`, `description`, `price`, `quantity`, `category`, `unit`, `low_stock_threshold`, `created_at`, `updated_at`) VALUES
	(85, '010', '010 - Printer', 'Printer', 60000.00, 10, 'Supplies', 'pcs', 10, '2026-03-10 08:18:37', '2026-03-11 06:37:34'),
	(86, '09', '09 - Red Horse', 'Red Horse', 70.00, 13, 'alcohol', 'pcs', 10, '2026-03-10 08:18:50', '2026-03-11 06:28:48'),
	(87, '08', '08 - Empelights', 'Empelights', 80.00, 10, 'alcohol', 'pcs', 10, '2026-03-10 08:19:00', '2026-03-10 08:48:39'),
	(88, '07', '07 - shoes', 'shoes', 4000.00, 15, 'Supplies', 'pcs', 10, '2026-03-10 08:19:06', '2026-03-11 01:46:45'),
	(89, '04', '04 -  mouse', ' mouse', 50.00, 0, 'Supplies', 'pcs', 10, '2026-03-10 08:19:14', '2026-03-10 09:27:47'),
	(90, '06', '06 - boots', 'boots', 120.00, 10, 'Supplies', 'pcs', 10, '2026-03-10 08:19:21', '2026-03-10 08:48:39'),
	(91, '05', '05 - laptop', 'laptop', 17000.00, 10, 'Supplies', 'pcs', 10, '2026-03-10 08:19:28', '2026-03-10 08:48:39'),
	(92, '03', '03 - 4x4', '4x4', 65.00, 2, 'alcohol', 'pcs', 10, '2026-03-10 08:19:42', '2026-03-10 09:31:23'),
	(93, '02', '02 - bondpaper', 'bondpaper', 70.00, 30, 'Supplies', 'pcs', 10, '2026-03-10 08:19:50', '2026-03-11 03:34:47'),
	(94, '01', '01 - hardhat', 'hardhat', 60.00, 41, 'Large', 'pcs', 10, '2026-03-10 08:20:01', '2026-03-11 06:43:09');

-- Dumping structure for table inventory_system.purchases
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.purchases: ~0 rows (approximately)
INSERT INTO `purchases` (`id`, `purchase_number`, `customer_name`, `item_no`, `description`, `category`, `company_name`, `contact_person`, `contact_number`, `price_id`, `product_id`, `quantity_purchased`, `price_per_unit`, `total_amount`, `available_before`, `available_after`, `company_color`, `payment_method`, `payment_status`, `status`, `purchase_date`, `updated_at`, `stock_updated`, `stock_movement_id`) VALUES
	(87, 'PUR-20260310081622-6402', 'Walk-in Customer', '01', 'hardhat', NULL, 'JLC', 'Jay Jay', '0900909090', 20, NULL, 10, 60.00, 600.00, 50, 40, '#f6c23e', 'cash', 'pending', 'completed', '2026-03-10 08:16:22', '2026-03-10 08:20:01', 0, 130),
	(88, 'PUR-20260310081636-5285', 'Walk-in Customer', '02', 'bondpaper', NULL, 'OPC', 'kikoy', '0999988', 21, NULL, 10, 70.00, 700.00, 50, 40, '#f6c23e', 'cash', 'pending', 'completed', '2026-03-10 08:16:36', '2026-03-10 08:19:50', 0, 129),
	(89, 'PUR-20260310081648-8888', 'Walk-in Customer', '03', '4x4', NULL, 'Ginebra', 'Marl Larenz', '079765757', 22, NULL, 10, 65.00, 650.00, 80, 70, '#4e73df', 'cash', 'pending', 'completed', '2026-03-10 08:16:48', '2026-03-10 08:19:42', 0, 128),
	(90, 'PUR-20260310081702-2558', 'Walk-in Customer', '04', ' mouse', NULL, 'kikay', 'hahahaha', '7657657', 23, NULL, 1, 50.00, 50.00, 80, 79, '#e74a3b', 'cash', 'pending', 'completed', '2026-03-10 08:17:02', '2026-03-10 08:19:14', 0, 125),
	(91, 'PUR-20260310081727-1880', 'Walk-in Customer', '05', 'laptop', NULL, 'ACER', 'KIRTIE', '094949545', 24, NULL, 10, 17000.00, 170000.00, 50, 40, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-10 08:17:27', '2026-03-10 08:19:28', 0, 127),
	(92, 'PUR-20260310081742-6827', 'Walk-in Customer', '06', 'boots', NULL, ' GOMA', 'huhuhu', '4845848534', 25, NULL, 10, 120.00, 1200.00, 50, 40, '#4e73df', 'cash', 'pending', 'completed', '2026-03-10 08:17:42', '2026-03-10 08:19:21', 0, 126),
	(93, 'PUR-20260310081754-5799', 'Walk-in Customer', '07', 'shoes', NULL, 'NIKE', 'CLYDE', '070707070', 26, NULL, 10, 4000.00, 40000.00, 50, 40, '#4e73df', 'cash', 'pending', 'completed', '2026-03-10 08:17:54', '2026-03-10 08:19:06', 0, 124),
	(94, 'PUR-20260310081806-6150', 'Walk-in Customer', '08', 'Empelights', NULL, 'Norbie', 'norbie', '0779797979', 27, NULL, 10, 80.00, 800.00, 50, 40, '#1cc88a', 'cash', 'pending', 'completed', '2026-03-10 08:18:06', '2026-03-10 08:19:00', 0, 123),
	(95, 'PUR-20260310081821-2578', 'Walk-in Customer', '09', 'Red Horse', NULL, 'LARING', 'hahahh', '08076060760', 28, NULL, 10, 70.00, 700.00, 60, 50, '#6f42c1', 'cash', 'pending', 'completed', '2026-03-10 08:18:21', '2026-03-10 08:18:50', 0, 122),
	(96, 'PUR-20260310081832-2870', 'Walk-in Customer', '010', 'Printer', NULL, 'FEEE', 'hdsadhahd', '00706076', 29, NULL, 10, 60000.00, 600000.00, 50, 40, '#fd7e14', 'cash', 'pending', 'completed', '2026-03-10 08:18:32', '2026-03-10 08:18:37', 0, 121),
	(97, 'PUR-20260310090954-5573', 'Walk-in Customer', '01', 'hardhat', NULL, 'JLC', 'Jay Jay', '0900909090', 20, 94, 10, 60.00, 600.00, 40, 40, '#f6c23e', 'cash', 'pending', 'pending', '2026-03-10 09:09:54', '2026-03-10 09:09:54', 0, NULL),
	(98, 'PUR-20260310091024-3350', 'Walk-in Customer', '01', 'hardhat', NULL, 'ONIC', 'MELENDEZ', '0999988', 31, 94, 10, 80.00, 800.00, 50, 40, '#e74a3b', 'cash', 'pending', 'completed', '2026-03-10 09:10:24', '2026-03-10 09:10:59', 0, 132),
	(99, 'PUR-20260310091036-4656', 'Walk-in Customer', '01', 'hardhat', NULL, 'LIQUID', 'JAYCIE', '45435435', 30, 94, 10, 60.00, 600.00, 50, 40, '#4e73df', 'cash', 'pending', 'completed', '2026-03-10 09:10:36', '2026-03-10 09:10:48', 0, 131),
	(100, 'PUR-20260311002932-9184', 'Walk-in Customer', '01', 'hardhat', NULL, 'JLC BEST CONSTRUCTION OPC', 'Jay Jay', '0900909090', 32, 94, 3, 60.00, 180.00, 40, 37, '#e74a3b', 'cash', 'pending', 'completed', '2026-03-11 00:29:32', '2026-03-11 00:29:47', 0, 136),
	(101, 'PUR-20260311014638-2426', 'Walk-in Customer', '07', 'shoes', NULL, 'NIKE', 'CLYDE', '070707070', 26, 88, 5, 4000.00, 20000.00, 40, 35, '#f6c23e', 'cash', 'pending', 'completed', '2026-03-11 01:46:38', '2026-03-11 01:46:45', 0, 137),
	(102, 'PUR-20260311033440-4345', 'Walk-in Customer', '02', 'bondpaper', NULL, 'JJ', 'JAYCIE', '0909778', 33, 93, 20, 70.00, 1400.00, 50, 30, '#f6c23e', 'cash', 'pending', 'completed', '2026-03-11 03:34:40', '2026-03-11 03:34:47', 0, 138),
	(103, 'PUR-20260311062841-7888', 'Walk-in Customer', '09', 'Red Horse', NULL, 'LARING', 'hahahh', '08076060760', 28, 86, 3, 70.00, 210.00, 50, 47, '#20c9a6', 'cash', 'pending', 'completed', '2026-03-11 06:28:41', '2026-03-11 06:28:48', 0, 139),
	(104, 'PUR-20260311063203-6026', 'Walk-in Customer', '01', 'hardhat', NULL, 'JLC BEST CONSTRUCTION OPC', 'Jay Jay', '0900909090', 32, 94, 5, 60.00, 300.00, 37, 32, '#36b9cc', 'cash', 'pending', 'completed', '2026-03-11 06:32:03', '2026-03-11 06:33:23', 0, 140),
	(105, 'PUR-20260311063555-8472', 'Walk-in Customer', '01', 'hardhat', NULL, 'ONIC', 'MELENDEZ', '0999988', 31, 94, 3, 80.00, 240.00, 40, 37, '#6f42c1', 'cash', 'pending', 'completed', '2026-03-11 06:35:55', '2026-03-11 06:43:09', 0, 143),
	(106, 'PUR-20260311063615-1883', 'Walk-in Customer', '010', 'Printer', NULL, 'FEEE', 'hdsadhahd', '00706076', 29, 85, 1, 60000.00, 60000.00, 40, 39, '#fd7e14', 'cash', 'pending', 'completed', '2026-03-11 06:36:15', '2026-03-11 06:36:36', 0, 141);

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
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table inventory_system.stock_movements: ~10 rows (approximately)
INSERT INTO `stock_movements` (`id`, `product_id`, `type`, `quantity`, `reference`, `notes`, `created_at`, `created_by`) VALUES
	(121, 85, 'in', 10, 'PURCHASE #PUR-20260310081832-2870', 'Ordered from FEEE - Qty: 10', '2026-03-10 08:18:37', 3),
	(122, 86, 'in', 10, 'PURCHASE #PUR-20260310081821-2578', 'Ordered from LARING - Qty: 10', '2026-03-10 08:18:50', 3),
	(123, 87, 'in', 10, 'PURCHASE #PUR-20260310081806-6150', 'Ordered from Norbie - Qty: 10', '2026-03-10 08:19:00', 3),
	(124, 88, 'in', 10, 'PURCHASE #PUR-20260310081754-5799', 'Ordered from NIKE - Qty: 10', '2026-03-10 08:19:06', 3),
	(125, 89, 'in', 1, 'PURCHASE #PUR-20260310081702-2558', 'Ordered from kikay - Qty: 1', '2026-03-10 08:19:14', 3),
	(126, 90, 'in', 10, 'PURCHASE #PUR-20260310081742-6827', 'Ordered from  GOMA - Qty: 10', '2026-03-10 08:19:21', 3),
	(127, 91, 'in', 10, 'PURCHASE #PUR-20260310081727-1880', 'Ordered from ACER - Qty: 10', '2026-03-10 08:19:28', 3),
	(128, 92, 'in', 10, 'PURCHASE #PUR-20260310081648-8888', 'Ordered from Ginebra - Qty: 10', '2026-03-10 08:19:42', 3),
	(129, 93, 'in', 10, 'PURCHASE #PUR-20260310081636-5285', 'Ordered from OPC - Qty: 10', '2026-03-10 08:19:50', 3),
	(130, 94, 'in', 10, 'PURCHASE #PUR-20260310081622-6402', 'Ordered from JLC - Qty: 10', '2026-03-10 08:20:01', 3),
	(131, 94, 'in', 10, 'PURCHASE #PUR-20260310091036-4656', 'Ordered from LIQUID - Qty: 10', '2026-03-10 09:10:48', 3),
	(132, 94, 'in', 10, 'PURCHASE #PUR-20260310091024-3350', 'Ordered from ONIC - Qty: 10', '2026-03-10 09:10:59', 3),
	(133, 89, 'out', 1, '', '', '2026-03-10 09:27:47', 3),
	(134, 92, 'out', 4, '', '', '2026-03-10 09:30:53', 3),
	(135, 92, 'out', 4, '', '', '2026-03-10 09:31:23', 3),
	(136, 94, 'in', 3, 'PURCHASE #PUR-20260311002932-9184', 'Ordered from JLC BEST CONSTRUCTION OPC - Qty: 3', '2026-03-11 00:29:47', 3),
	(137, 88, 'in', 5, 'PURCHASE #PUR-20260311014638-2426', 'Ordered from NIKE - Qty: 5', '2026-03-11 01:46:45', 3),
	(138, 93, 'in', 20, 'PURCHASE #PUR-20260311033440-4345', 'Ordered from JJ - Qty: 20', '2026-03-11 03:34:47', 3),
	(139, 86, 'in', 3, 'PURCHASE #PUR-20260311062841-7888', 'Ordered from LARING - Qty: 3', '2026-03-11 06:28:48', 3),
	(140, 94, 'in', 5, 'PURCHASE #PUR-20260311063203-6026', 'Ordered from JLC BEST CONSTRUCTION OPC - Qty: 5', '2026-03-11 06:33:23', 3),
	(141, 85, 'in', 1, 'PURCHASE #PUR-20260311063615-1883', 'Ordered from FEEE - Qty: 1', '2026-03-11 06:36:36', 3),
	(142, 85, 'out', 1, '', '', '2026-03-11 06:37:34', 3),
	(143, 94, 'in', 3, 'PURCHASE #PUR-20260311063555-8472', 'Ordered from ONIC - Qty: 3', '2026-03-11 06:43:09', 3);

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
