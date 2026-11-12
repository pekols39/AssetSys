-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 12:48 PM
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
-- Database: `assetsys`
--

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(10) UNSIGNED NOT NULL,
  `asset_code` varchar(100) NOT NULL,
  `asset_tag` varchar(100) DEFAULT NULL,
  `serial_number` varchar(150) DEFAULT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `department_id` int(10) UNSIGNED DEFAULT NULL,
  `asset_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `manufacturer` varchar(150) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `model_number` varchar(150) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `supplier_id` int(10) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `purchase_order_number` varchar(100) DEFAULT NULL,
  `warranty_start` date DEFAULT NULL,
  `warranty_end` date DEFAULT NULL,
  `useful_life_years` decimal(6,2) DEFAULT NULL,
  `salvage_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `depreciation_method` varchar(50) NOT NULL DEFAULT 'straight_line',
  `current_book_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status_id` int(10) UNSIGNED NOT NULL,
  `location_id` int(10) UNSIGNED DEFAULT NULL,
  `condition_id` int(10) UNSIGNED DEFAULT NULL,
  `assigned_to_user_id` int(10) UNSIGNED DEFAULT NULL,
  `assigned_to_name` varchar(150) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assets_asset_code` (`asset_code`),
  ADD UNIQUE KEY `uq_assets_asset_tag` (`asset_tag`),
  ADD UNIQUE KEY `uq_assets_serial_number` (`serial_number`),
  ADD KEY `idx_assets_category_id` (`category_id`),
  ADD KEY `idx_assets_supplier_id` (`supplier_id`),
  ADD KEY `idx_assets_status_id` (`status_id`),
  ADD KEY `idx_assets_location_id` (`location_id`),
  ADD KEY `idx_assets_condition_id` (`condition_id`),
  ADD KEY `idx_assets_assigned_to_user_id` (`assigned_to_user_id`),
  ADD KEY `idx_assets_created_by` (`created_by`),
  ADD KEY `idx_assets_purchase_date` (`purchase_date`),
  ADD KEY `idx_assets_warranty_end` (`warranty_end`),
  ADD KEY `idx_assets_department_id` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `fk_assets_assigned_user` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_condition` FOREIGN KEY (`condition_id`) REFERENCES `asset_conditions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_status` FOREIGN KEY (`status_id`) REFERENCES `asset_statuses` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assets_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
