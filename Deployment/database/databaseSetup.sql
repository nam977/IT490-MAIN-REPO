--create database and go to it
CREATE DATABASE deployment;
USE deployment;

-- create table
CREATE TABLE `files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fileName` VARCHAR(255) NOT NULL,
  `version` INT NOT NULL,
  `filePath` VARCHAR(255) NOT NULL,
  `is_stable` BOOLEAN NOT NULL DEFAULT FALSE
);

