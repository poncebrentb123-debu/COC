CREATE DATABASE IF NOT EXISTS coc_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE coc_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    role ENUM(
        'admin',
        'hr_officer',
        'hr_staff',
        'department_head',
        'mayor',
        'vice_mayor',
        'sb_member',
        'accounting_staff',
        'budget_officer',
        'records_officer',
        'employee'
    ) NOT NULL DEFAULT 'employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(190) NOT NULL,
    position VARCHAR(120) NOT NULL,
    department VARCHAR(120) NOT NULL,
    status ENUM('Active','On Leave') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS coc_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    activity_label VARCHAR(190) NOT NULL,
    activity_date DATE NULL,
    hours_earned DECIMAL(7,2) NOT NULL DEFAULT 0,
    valid_until DATE NULL,
    cto_date DATE NULL,
    cto_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_coc_entries_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;
