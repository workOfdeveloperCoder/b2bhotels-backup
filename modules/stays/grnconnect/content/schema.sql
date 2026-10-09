-- GRNConnect content database schema
-- Created automatically by POST /modules/stays/grnconnect/import action=create_tables
-- Run this only if you need to create tables by hand.

CREATE DATABASE IF NOT EXISTS `modulesgrnconnect` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `modulesgrnconnect`;

CREATE TABLE IF NOT EXISTS grnconnect_countries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(4) UNIQUE NOT NULL,
    code3 VARCHAR(4),
    name VARCHAR(191),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grnconnect_cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_code VARCHAR(32) UNIQUE NOT NULL,
    name VARCHAR(255),
    search_name VARCHAR(191),
    country_code VARCHAR(4),
    dest_code VARCHAR(32),
    INDEX idx_search (search_name),
    INDEX idx_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grnconnect_hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_code VARCHAR(32) UNIQUE NOT NULL,
    name VARCHAR(255),
    address TEXT,
    city_code VARCHAR(32),
    country_code VARCHAR(4),
    latitude DECIMAL(10,7),
    longitude DECIMAL(10,7),
    category VARCHAR(8),
    acc_name VARCHAR(64),
    chain_name VARCHAR(128),
    postal_code VARCHAR(32),
    description LONGTEXT,
    facilities LONGTEXT,
    images JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_city (city_code),
    INDEX idx_name (name),
    INDEX idx_geo (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grnconnect_hotel_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_code VARCHAR(32) NOT NULL,
    url TEXT NOT NULL,
    path VARCHAR(512) DEFAULT NULL,
    caption VARCHAR(255) DEFAULT NULL,
    main_image TINYINT(1) NOT NULL DEFAULT 0,
    size_type VARCHAR(16) NOT NULL DEFAULT 'regular',
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hotel_code (hotel_code),
    INDEX idx_hotel_main (hotel_code, main_image),
    INDEX idx_hotel_order (hotel_code, display_order),
    UNIQUE KEY uniq_hotel_path_size (hotel_code, path(191), size_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grnconnect_import_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task VARCHAR(32) UNIQUE NOT NULL,
    total INT DEFAULT 0,
    processed INT DEFAULT 0,
    status ENUM('idle','running','completed','error') DEFAULT 'idle',
    message TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
