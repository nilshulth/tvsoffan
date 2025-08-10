-- tvsoffan database schema
-- Character set: utf8mb4_unicode_ci as specified in README

CREATE DATABASE IF NOT EXISTS tvsoffan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tvsoffan;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_public BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_name (name)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Titles table (movies and TV shows from TMDB)
CREATE TABLE titles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tmdb_id INT NOT NULL,
    media_type ENUM('movie', 'tv') NOT NULL,
    title VARCHAR(500) NOT NULL,
    original_title VARCHAR(500),
    release_date DATE,
    poster_path VARCHAR(255),
    overview TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tmdb_id (tmdb_id),
    INDEX idx_media_type (media_type),
    INDEX idx_title (title),
    UNIQUE KEY unique_tmdb_title (tmdb_id, media_type)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Lists table
CREATE TABLE lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    visibility ENUM('public', 'private') NOT NULL DEFAULT 'private',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_visibility (visibility),
    INDEX idx_is_default (is_default),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- List ownership (many-to-many relationship)
CREATE TABLE list_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_list_id (list_id),
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_list_user (list_id, user_id),
    FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- List items (titles in lists)
CREATE TABLE list_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    title_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_list_id (list_id),
    INDEX idx_title_id (title_id),
    UNIQUE KEY unique_list_title (list_id, title_id),
    FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
    FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- User title states (watching status, ratings, comments)
CREATE TABLE user_titles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title_id INT NOT NULL,
    state ENUM('want', 'watching', 'watched', 'stopped') NOT NULL DEFAULT 'want',
    rating TINYINT UNSIGNED,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_title_id (title_id),
    INDEX idx_state (state),
    UNIQUE KEY unique_user_title (user_id, title_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;