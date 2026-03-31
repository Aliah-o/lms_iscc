-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 25, 2026 at 05:34 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `iscc_lms`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_submissions`
--

CREATE TABLE `activity_submissions` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `raw_score` decimal(5,2) DEFAULT NULL,
  `late_penalty` decimal(5,2) DEFAULT 0.00,
  `final_score` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `is_late` tinyint(1) DEFAULT 0,
  `status` enum('submitted','graded','missing') DEFAULT 'submitted',
  `version` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `grading_period` enum('midterm','final') NOT NULL DEFAULT 'midterm',
  `remarks` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `prev_hash` varchar(64) DEFAULT 'GENESIS',
  `block_hash` varchar(64) DEFAULT NULL,
  `block_data` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `prev_hash`, `block_hash`, `block_data`, `created_at`) VALUES
(1, 1, 'system_install', 'BSIT-only system installed', '127.0.0.1', 'GENESIS', NULL, NULL, '2026-03-21 15:29:38'),
(2, 1, 'user_login', 'Superadmin logged in', '127.0.0.1', 'GENESIS', NULL, NULL, '2026-03-21 15:29:38'),
(124, 2, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-21 23:46:33'),
(125, 2, 'user_logout', 'User logged out', '::1', 'GENESIS', '676bffc3767306d395696315db94c94d989a1b972b21bbc29cc132c214047ba5', NULL, '2026-03-21 23:46:48'),
(126, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-21 23:46:57'),
(127, 1, 'student_id_updated', 'Updated student ID for student1 to c-22-00001', '::1', '676bffc3767306d395696315db94c94d989a1b972b21bbc29cc132c214047ba5', '3a282a7e015d760fa69b2b428ff4826bfee2f987ff39c2fe53ac61cd43de13ee', NULL, '2026-03-21 23:47:28'),
(128, 3, 'user_logout', 'User logged out', '::1', '3a282a7e015d760fa69b2b428ff4826bfee2f987ff39c2fe53ac61cd43de13ee', '638909c9cde90c2da68a23826850f6ad53d400bd38b0042cf1a29f7a645a38d1', NULL, '2026-03-25 00:22:34'),
(129, 2, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:22:37'),
(130, 2, 'subject_created', 'Instructor created subject UNS - Understanding the Self (Class Code: JHDQUR)', '::1', '638909c9cde90c2da68a23826850f6ad53d400bd38b0042cf1a29f7a645a38d1', 'eb21a40a3e23aaf3def171bbd1ca52d60c147684376f0865f473b43032fc4d02', NULL, '2026-03-25 00:23:47'),
(131, 2, 'user_logout', 'User logged out', '::1', 'eb21a40a3e23aaf3def171bbd1ca52d60c147684376f0865f473b43032fc4d02', 'ffb220419de6fb5aabd81b02c6532d61cd64be88d670f5f4f3caca97298046fc', NULL, '2026-03-25 00:24:11'),
(132, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:24:19'),
(133, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:24:42'),
(134, 1, 'user_logout', 'User logged out', '::1', 'ffb220419de6fb5aabd81b02c6532d61cd64be88d670f5f4f3caca97298046fc', 'a847e30c9eab8b1156d0ceab84d981deec461d93cbb0fe841e1965cb2bd73609', NULL, '2026-03-25 00:24:46'),
(135, 3, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:24:50'),
(136, 3, 'join_request_sent', 'Student #3 requested to join class #13', '::1', 'a847e30c9eab8b1156d0ceab84d981deec461d93cbb0fe841e1965cb2bd73609', '77a5e9f8aaf729f3d339ac5f4776c84e161f385cf7176b8da4ddf6a6f58e1f71', NULL, '2026-03-25 00:24:55'),
(137, 1, 'user_logout', 'User logged out', '::1', '77a5e9f8aaf729f3d339ac5f4776c84e161f385cf7176b8da4ddf6a6f58e1f71', 'b25502bff906ced511ff245d297ae44f7edb68f591e0d317aae041f0ae83a2d4', NULL, '2026-03-25 00:25:00'),
(138, 2, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:25:05'),
(139, 2, 'join_request_approved', 'Approved student #3 for class #13', '::1', 'b25502bff906ced511ff245d297ae44f7edb68f591e0d317aae041f0ae83a2d4', '3be43058063ad98c86262358d4d6d14437b98b8e57f49f43edc0b3e5854d7ec9', NULL, '2026-03-25 00:25:09'),
(140, 2, 'lesson_created', 'Created lesson: test (class #13)', '::1', '3be43058063ad98c86262358d4d6d14437b98b8e57f49f43edc0b3e5854d7ec9', '7e6d2cc8938166f7e123a6f88e559a899b6f44b6de8138895a3bbb18cd7f4f0c', NULL, '2026-03-25 00:31:15'),
(141, 2, 'lesson_created', 'Created lesson: test (class #13)', '::1', '7e6d2cc8938166f7e123a6f88e559a899b6f44b6de8138895a3bbb18cd7f4f0c', 'de29f86949145af5c2f002160c1270845af6ae7002c4c5ec979162a5a56c065e', NULL, '2026-03-25 00:49:42'),
(142, 3, 'user_logout', 'User logged out', '::1', 'de29f86949145af5c2f002160c1270845af6ae7002c4c5ec979162a5a56c065e', 'd22859c5942bb0bf00ffa489501057d85fbb6eb5f112a39f0782d7bd5d6e4844', NULL, '2026-03-25 00:50:09'),
(143, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:50:14'),
(144, 1, 'settings_updated', 'General settings updated', '::1', 'd22859c5942bb0bf00ffa489501057d85fbb6eb5f112a39f0782d7bd5d6e4844', 'a831b96cecfacb27a227cf707faa3da8c510b2933bb1909c2f13c8681b4b3ea1', NULL, '2026-03-25 00:51:06'),
(145, 1, 'settings_updated', 'General settings updated', '::1', 'a831b96cecfacb27a227cf707faa3da8c510b2933bb1909c2f13c8681b4b3ea1', '425ba153c25d820dfacd0765263a30538c0200a8218b3cb144275465c9d3b1b8', NULL, '2026-03-25 00:51:27'),
(146, 1, 'settings_updated', 'General settings updated', '::1', '425ba153c25d820dfacd0765263a30538c0200a8218b3cb144275465c9d3b1b8', '43393e1b652c5ac7d340e865ca840131b0a18d625268beef4adf1feaf5a98098', NULL, '2026-03-25 00:51:35'),
(147, 1, 'settings_updated', 'General settings updated', '::1', '43393e1b652c5ac7d340e865ca840131b0a18d625268beef4adf1feaf5a98098', '5f8370ee56509e3f00dc8c84a2c2ca4c84a28e09be5e690b571785c37065060f', NULL, '2026-03-25 00:51:40'),
(148, 1, 'settings_updated', 'General settings updated', '::1', '5f8370ee56509e3f00dc8c84a2c2ca4c84a28e09be5e690b571785c37065060f', 'ec04323d9c185bb10e587c069deb72709b9f4722e26dea4ab50e63255315c2c6', NULL, '2026-03-25 00:51:43'),
(149, 1, 'profile_updated', 'Updated personal profile details', '::1', 'ec04323d9c185bb10e587c069deb72709b9f4722e26dea4ab50e63255315c2c6', '10a3e7c997107da425f8543eac34400fbfc08e30d638c06a442d1974b3ea625c', NULL, '2026-03-25 00:52:07'),
(150, 1, 'user_logout', 'User logged out', '::1', '10a3e7c997107da425f8543eac34400fbfc08e30d638c06a442d1974b3ea625c', '1c8d779bc20548ea52d760b65d758e503c1210a87c8361792390a96a0e8154de', NULL, '2026-03-25 00:52:15'),
(151, 2, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:52:19'),
(152, 2, 'profile_updated', 'Updated personal profile details', '::1', '1c8d779bc20548ea52d760b65d758e503c1210a87c8361792390a96a0e8154de', 'b7ca93c7dbe30085f02e70fee0fceb0f44ab8e4a0ec30e0514a676bfb01becdf', NULL, '2026-03-25 00:52:30'),
(153, 2, 'user_logout', 'User logged out', '::1', 'b7ca93c7dbe30085f02e70fee0fceb0f44ab8e4a0ec30e0514a676bfb01becdf', 'f10a5c7190d915c24bd81145f40d90b88ddfca4594c92031a9e4bba4783d2bea', NULL, '2026-03-25 00:52:33'),
(154, 3, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 00:52:39'),
(155, 3, 'profile_updated', 'Updated personal profile details', '::1', 'f10a5c7190d915c24bd81145f40d90b88ddfca4594c92031a9e4bba4783d2bea', 'ab809386cd8c0ef9e00e9e89d531baa6441946cf6ee05381b2aecba03f249cbd', NULL, '2026-03-25 00:52:55'),
(156, 3, 'user_logout', 'User logged out', '::1', 'ab809386cd8c0ef9e00e9e89d531baa6441946cf6ee05381b2aecba03f249cbd', '05a02c50bf46cffd26fbfe3a49f92015534bdceb39131d969b83d570e73cbbb3', NULL, '2026-03-25 00:53:05'),
(157, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:00:33'),
(158, NULL, 'password_recovery_ticket_created', 'Password recovery ticket TKT-20260325-64E404 created for superadmin@iscc.edu.ph', '::1', '05a02c50bf46cffd26fbfe3a49f92015534bdceb39131d969b83d570e73cbbb3', '0765944e3d434e982ff6eecc193aac8d455b72e3ac2aecb042c32e90bf2d0d74', NULL, '2026-03-25 01:02:51'),
(159, 2, 'user_logout', 'User logged out', '::1', '0765944e3d434e982ff6eecc193aac8d455b72e3ac2aecb042c32e90bf2d0d74', 'b2eae7571c7db9d63fa11306fb3297ccf7affca9cf5b558429cb794077a0268b', NULL, '2026-03-25 01:04:09'),
(160, 1, 'ticket_status_update', 'Ticket #TKT-20260325-64E404 status changed to closed', '::1', 'b2eae7571c7db9d63fa11306fb3297ccf7affca9cf5b558429cb794077a0268b', '97db61793cd3992d37f977d41446289ddc63ad9cf82324594166d9293da780cf', NULL, '2026-03-25 01:05:49'),
(161, 1, 'user_logout', 'User logged out', '::1', '97db61793cd3992d37f977d41446289ddc63ad9cf82324594166d9293da780cf', 'f6060ed9912db5ce6da067a49087610862be62268c7c857ba172a5ad49f31dc5', NULL, '2026-03-25 01:05:54'),
(162, 3, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:06:02'),
(163, 3, 'user_logout', 'User logged out', '::1', 'f6060ed9912db5ce6da067a49087610862be62268c7c857ba172a5ad49f31dc5', '21e7eb48b3721f1a1044a43e3d3fb4b1e5a9392edb3e261a843902e0e488542d', NULL, '2026-03-25 01:06:09'),
(164, NULL, 'password_recovery_ticket_created', 'Password recovery ticket TKT-20260325-21018E created for test@gmail.com (not_found)', '::1', '21e7eb48b3721f1a1044a43e3d3fb4b1e5a9392edb3e261a843902e0e488542d', '17fe678767c80da786c7eeff21d55200870c8855f578c77b08f46197c43d56e8', NULL, '2026-03-25 01:07:57'),
(165, NULL, 'password_recovery_ticket_created', 'Password recovery ticket TKT-20260325-F7575B created for student1@iscc.edu.ph (matched)', '::1', '17fe678767c80da786c7eeff21d55200870c8855f578c77b08f46197c43d56e8', '7f00a3930ecd3d348c4fe1c807023b25b1c4eb248307943a78f0b863d3530eec', NULL, '2026-03-25 01:08:25'),
(166, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:08:36'),
(167, 1, 'ticket_password_email_prepared', 'Prepared temporary password email for ticket #TKT-20260325-F7575B and user #3', '::1', '7f00a3930ecd3d348c4fe1c807023b25b1c4eb248307943a78f0b863d3530eec', '8d6e667b0c8703f6bc2defef3927371566d591c80697677076d1f55a2cecd212', NULL, '2026-03-25 01:14:55'),
(168, 1, 'user_logout', 'User logged out', '::1', '8d6e667b0c8703f6bc2defef3927371566d591c80697677076d1f55a2cecd212', '7b41535c9630bbbea7a633cd14f626c562d8e907cf1922693fd7cdb674835775', NULL, '2026-03-25 01:15:25'),
(169, 3, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:15:28'),
(170, 3, 'user_logout', 'User logged out', '::1', '7b41535c9630bbbea7a633cd14f626c562d8e907cf1922693fd7cdb674835775', 'b0151ba9abb064eb661ea443b823a922bb11e0291cb6783a5a7f27c230272c6f', NULL, '2026-03-25 01:15:32'),
(171, 3, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:15:40'),
(172, 3, 'user_logout', 'User logged out', '::1', 'b0151ba9abb064eb661ea443b823a922bb11e0291cb6783a5a7f27c230272c6f', 'acb7ba88988156525899c61da20b188fb1d04cdcd5a9e8877a34ea3798aa9d2e', NULL, '2026-03-25 01:15:42'),
(173, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 01:15:48'),
(174, 2, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 12:30:50'),
(175, 2, 'user_logout', 'User logged out', '::1', 'acb7ba88988156525899c61da20b188fb1d04cdcd5a9e8877a34ea3798aa9d2e', '3e2296eedec3bd1ed807f23e1b0dfe672c9ebb20433c6ef4bbaf02c98ca2c6ae', NULL, '2026-03-25 12:31:29'),
(176, 1, 'user_login', 'Login successful', '::1', 'GENESIS', NULL, NULL, '2026-03-25 12:31:33');

-- --------------------------------------------------------

--
-- Table structure for table `badges`
--

CREATE TABLE `badges` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fa-award',
  `badge_rule` varchar(100) DEFAULT NULL,
  `rule_value` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `badges`
--

INSERT INTO `badges` (`id`, `name`, `description`, `icon`, `badge_rule`, `rule_value`, `is_active`, `created_at`) VALUES
(1, 'First Quiz', 'Completed your first quiz!', 'fa-star', 'first_quiz', 1, 1, '2026-03-01 20:26:43'),
(2, 'Beginner Complete', 'Completed all Beginner nodes', 'fa-seedling', 'beginner_complete', 1, 1, '2026-03-01 20:26:43'),
(3, 'Perfect Score', 'Got 100% on a quiz', 'fa-bullseye', 'perfect_score', 100, 1, '2026-03-01 20:26:43'),
(4, 'Quick Learner', 'Completed 5 knowledge nodes', 'fa-bolt', 'nodes_completed', 5, 1, '2026-03-01 20:26:43'),
(5, 'Growth 10%', 'Improved by 10% from last month', 'fa-chart-line', 'growth_percent', 10, 1, '2026-03-01 20:26:43'),
(6, 'Dedicated Learner', 'Completed 10 knowledge nodes', 'fa-fire', 'nodes_completed', 10, 1, '2026-03-01 20:26:43');

-- --------------------------------------------------------

--
-- Table structure for table `badge_earns`
--

CREATE TABLE `badge_earns` (
  `id` int(11) NOT NULL,
  `badge_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `earned_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `course_code` varchar(20) DEFAULT NULL,
  `subject_name` varchar(100) DEFAULT NULL,
  `final_grade` decimal(5,2) DEFAULT NULL,
  `grade_status` varchar(20) DEFAULT 'PASSED',
  `certificate_hash` varchar(64) NOT NULL,
  `qr_data` text DEFAULT NULL,
  `issued_by` int(11) NOT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_valid` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chain_snapshots`
--

CREATE TABLE `chain_snapshots` (
  `id` int(11) NOT NULL,
  `chain_type` enum('grade','audit') NOT NULL,
  `snapshot_data` longtext NOT NULL,
  `snapshot_hash` varchar(64) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_enrollments`
--

CREATE TABLE `class_enrollments` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `enrolled_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `class_enrollments`
--

INSERT INTO `class_enrollments` (`id`, `class_id`, `student_id`, `enrolled_at`) VALUES
(36, 13, 3, '2026-03-25 00:25:09');

-- --------------------------------------------------------

--
-- Table structure for table `class_grade_weights`
--

CREATE TABLE `class_grade_weights` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `component` enum('attendance','activity','quiz','project','exam') NOT NULL,
  `weight` decimal(5,2) DEFAULT 0.00,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_join_requests`
--

CREATE TABLE `class_join_requests` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('pending','approved','declined') DEFAULT 'pending',
  `instructor_note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `class_join_requests`
--

INSERT INTO `class_join_requests` (`id`, `class_id`, `student_id`, `status`, `instructor_note`, `created_at`, `updated_at`) VALUES
(3, 13, 3, 'approved', NULL, '2026-03-25 00:24:55', '2026-03-25 00:25:09');

-- --------------------------------------------------------

--
-- Table structure for table `forum_attachments`
--

CREATE TABLE `forum_attachments` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) DEFAULT NULL,
  `post_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_categories`
--

CREATE TABLE `forum_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `forum_categories`
--

INSERT INTO `forum_categories` (`id`, `name`, `description`, `sort_order`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'General Discussion', 'Talk about anything related to campus life and learning.', 1, 1, NULL, '2026-03-02 13:04:48', '2026-03-02 13:04:48'),
(2, 'Academic Help', 'Ask questions and get help with your studies.', 2, 1, NULL, '2026-03-02 13:04:48', '2026-03-02 13:04:48'),
(3, 'Announcements', 'Important announcements from staff and administration.', 3, 1, NULL, '2026-03-02 13:04:48', '2026-03-02 13:04:48'),
(4, 'Off-Topic', 'Casual conversations and fun topics.', 4, 1, NULL, '2026-03-02 13:04:48', '2026-03-02 13:04:48');

-- --------------------------------------------------------

--
-- Table structure for table `forum_notifications`
--

CREATE TABLE `forum_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('like','reply','mention','pin','lock','system') NOT NULL,
  `thread_id` int(11) DEFAULT NULL,
  `post_id` int(11) DEFAULT NULL,
  `triggered_by` int(11) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_posts`
--

CREATE TABLE `forum_posts` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `status` enum('active','hidden','deleted') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `anon_display_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_post_reports`
--

CREATE TABLE `forum_post_reports` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) DEFAULT NULL,
  `post_id` int(11) DEFAULT NULL,
  `reported_by` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','reviewed','dismissed') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_threads`
--

CREATE TABLE `forum_threads` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `title` varchar(300) NOT NULL,
  `body` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `is_anonymous` tinyint(1) DEFAULT 0,
  `status` enum('active','hidden','deleted') DEFAULT 'active',
  `is_locked` tinyint(1) DEFAULT 0,
  `is_pinned` tinyint(1) DEFAULT 0,
  `view_count` int(11) DEFAULT 0,
  `reply_count` int(11) DEFAULT 0,
  `last_reply_at` datetime DEFAULT NULL,
  `last_reply_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `anon_display_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_thread_likes`
--

CREATE TABLE `forum_thread_likes` (
  `id` int(11) NOT NULL,
  `thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `graded_activities`
--

CREATE TABLE `graded_activities` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `activity_type` enum('quiz','lab_activity','exam') NOT NULL,
  `max_score` decimal(5,2) DEFAULT 100.00,
  `grading_period` enum('midterm','final') DEFAULT 'midterm',
  `is_submittable` tinyint(1) DEFAULT 0,
  `allow_late` tinyint(1) DEFAULT 1,
  `allow_resubmit` tinyint(1) DEFAULT 0,
  `open_date` datetime DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `close_date` datetime DEFAULT NULL,
  `late_penalty_type` enum('percentage','fixed') DEFAULT 'percentage',
  `late_penalty_amount` decimal(5,2) DEFAULT 10.00,
  `late_penalty_interval` enum('per_day','per_hour') DEFAULT 'per_day',
  `late_penalty_max` decimal(5,2) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `graded_activity_scores`
--

CREATE TABLE `graded_activity_scores` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `score` decimal(5,2) DEFAULT 0.00,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `component` enum('attendance','activity','quiz','project','exam') NOT NULL,
  `score` decimal(5,2) DEFAULT 0.00,
  `grading_period` enum('midterm','final') DEFAULT 'midterm',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_chain`
--

CREATE TABLE `grade_chain` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `course_code` varchar(20) DEFAULT NULL,
  `subject_name` varchar(100) DEFAULT NULL,
  `component` varchar(30) NOT NULL,
  `grading_period` varchar(20) NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `prev_hash` varchar(64) DEFAULT 'GENESIS',
  `block_hash` varchar(64) NOT NULL,
  `block_data` text NOT NULL,
  `is_valid` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `instructor_classes`
--

CREATE TABLE `instructor_classes` (
  `id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `subject_name` varchar(200) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `subject_image` varchar(255) DEFAULT NULL,
  `units` varchar(10) NOT NULL DEFAULT '3',
  `prerequisite` varchar(200) DEFAULT 'None',
  `section_id` int(11) NOT NULL,
  `semester` varchar(30) NOT NULL,
  `class_code` varchar(10) NOT NULL,
  `program_code` varchar(10) NOT NULL DEFAULT 'BSIT',
  `year_level` tinyint(4) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `school_year_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `instructor_classes`
--

INSERT INTO `instructor_classes` (`id`, `instructor_id`, `subject_name`, `course_code`, `description`, `subject_image`, `units`, `prerequisite`, `section_id`, `semester`, `class_code`, `program_code`, `year_level`, `is_active`, `school_year_id`, `created_at`) VALUES
(13, 2, 'Understanding the Self', 'UNS', 'test', 'uploads/subjects/subject_13_1774369427_921025c5b5c0.png', '3', 'None', 1, 'First Semester', 'JHDQUR', 'BSIT', 1, 1, NULL, '2026-03-25 00:23:47');

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_answers`
--

CREATE TABLE `kahoot_answers` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `participant_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `choice_id` int(11) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `points_earned` int(11) DEFAULT 0,
  `time_taken` decimal(6,2) DEFAULT NULL COMMENT 'seconds',
  `answered_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_choices`
--

CREATE TABLE `kahoot_choices` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `choice_label` char(1) NOT NULL COMMENT 'A, B, C, or D',
  `choice_text` varchar(500) NOT NULL,
  `is_correct` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_games`
--

CREATE TABLE `kahoot_games` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `game_mode` enum('live','practice') DEFAULT 'live',
  `time_limit` int(11) DEFAULT 20 COMMENT 'seconds per question',
  `status` enum('draft','ready','live','completed','archived') DEFAULT 'draft',
  `game_pin` varchar(6) DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `shuffle_choices` tinyint(1) DEFAULT 0,
  `show_leaderboard` tinyint(1) DEFAULT 1,
  `max_participants` int(11) DEFAULT 100,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_participants`
--

CREATE TABLE `kahoot_participants` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `nickname` varchar(50) DEFAULT NULL,
  `score` int(11) DEFAULT 0,
  `correct_count` int(11) DEFAULT 0,
  `streak` int(11) DEFAULT 0,
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_questions`
--

CREATE TABLE `kahoot_questions` (
  `id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_image` varchar(255) DEFAULT NULL,
  `question_order` int(11) DEFAULT 0,
  `points` int(11) DEFAULT 1000,
  `time_limit` int(11) DEFAULT NULL COMMENT 'override per-question, NULL = use game default',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kahoot_sessions`
--

CREATE TABLE `kahoot_sessions` (
  `id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `status` enum('lobby','playing','reviewing','finished') DEFAULT 'lobby',
  `current_question` int(11) DEFAULT 0,
  `question_started_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `knowledge_nodes`
--

CREATE TABLE `knowledge_nodes` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `level` enum('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  `sort_order` int(11) DEFAULT 0,
  `content` text DEFAULT NULL,
  `quiz_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `knowledge_nodes`
--

INSERT INTO `knowledge_nodes` (`id`, `class_id`, `title`, `description`, `level`, `sort_order`, `content`, `quiz_id`, `created_at`) VALUES
(1, 1, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(2, 1, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(3, 1, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(4, 1, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(5, 1, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(6, 1, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(7, 1, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(8, 1, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(9, 1, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(10, 2, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(11, 2, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(12, 2, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(13, 2, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(14, 2, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(15, 2, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(16, 2, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(17, 2, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(18, 2, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(19, 3, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(20, 3, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(21, 3, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(22, 3, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(23, 3, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(24, 3, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(25, 3, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(26, 3, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(27, 3, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(28, 4, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(29, 4, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(30, 4, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(31, 4, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(32, 4, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(33, 4, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(34, 4, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(35, 4, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(36, 4, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(37, 5, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(38, 5, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(39, 5, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(40, 5, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(41, 5, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(42, 5, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(43, 5, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(44, 5, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(45, 5, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(46, 6, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(47, 6, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(48, 6, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(49, 6, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(50, 6, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(51, 6, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(52, 6, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(53, 6, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(54, 6, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(55, 7, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(56, 7, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(57, 7, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(58, 7, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(59, 7, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(60, 7, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(61, 7, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(62, 7, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(63, 7, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(64, 8, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(65, 8, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(66, 8, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(67, 8, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(68, 8, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(69, 8, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(70, 8, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(71, 8, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(72, 8, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(73, 9, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(74, 9, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(75, 9, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(76, 9, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(77, 9, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(78, 9, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(79, 9, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(80, 9, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(81, 9, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(82, 10, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(83, 10, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(84, 10, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(85, 10, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(86, 10, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(87, 10, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(88, 10, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(89, 10, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(90, 10, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(91, 11, 'Basics & Terminology', 'Learn about Basics & Terminology.', 'beginner', 1, '<p>Content for <strong>Basics & Terminology</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(92, 11, 'First Steps', 'Learn about First Steps.', 'beginner', 2, '<p>Content for <strong>First Steps</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(93, 11, 'Core Principles', 'Learn about Core Principles.', 'beginner', 3, '<p>Content for <strong>Core Principles</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(94, 11, 'Applied Concepts', 'Learn about Applied Concepts.', 'intermediate', 4, '<p>Content for <strong>Applied Concepts</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(95, 11, 'Problem Solving', 'Learn about Problem Solving.', 'intermediate', 5, '<p>Content for <strong>Problem Solving</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(96, 11, 'Case Studies', 'Learn about Case Studies.', 'intermediate', 6, '<p>Content for <strong>Case Studies</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(97, 11, 'Advanced Theory', 'Learn about Advanced Theory.', 'advanced', 7, '<p>Content for <strong>Advanced Theory</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(98, 11, 'Complex Scenarios', 'Learn about Complex Scenarios.', 'advanced', 8, '<p>Content for <strong>Complex Scenarios</strong>.</p>', NULL, '2026-03-01 20:26:43'),
(99, 11, 'Capstone Challenge', 'Learn about Capstone Challenge.', 'advanced', 9, '<p>Content for <strong>Capstone Challenge</strong>.</p>', NULL, '2026-03-01 20:26:43');

-- --------------------------------------------------------

--
-- Table structure for table `knowledge_node_progress`
--

CREATE TABLE `knowledge_node_progress` (
  `id` int(11) NOT NULL,
  `node_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lessons`
--

CREATE TABLE `lessons` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_title` varchar(200) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_published` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_attachments`
--

CREATE TABLE `lesson_attachments` (
  `id` int(11) NOT NULL,
  `lesson_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meetings`
--

CREATE TABLE `meetings` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `meeting_link` varchar(500) NOT NULL,
  `platform` enum('zoom','gmeet','teams','other') NOT NULL DEFAULT 'other',
  `description` text DEFAULT NULL,
  `meeting_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `reference_id` int(11) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `is_read`, `reference_id`, `link`, `created_at`) VALUES
(5, 2, 'join_request', 'John Rivera wants to join \"Understanding the Self\" (JHDQUR).', 1, 13, NULL, '2026-03-25 00:24:55'),
(6, 3, 'join_approved', 'Your request to join \"Understanding the Self\" (Code: JHDQUR) has been approved!', 1, 13, NULL, '2026-03-25 00:25:09'),
(7, 1, 'ticket', 'New password reset request for superadmin@iscc.edu.ph', 1, 3, '/iscc-lms/tickets.php?view=3', '2026-03-25 01:02:51'),
(8, 1, 'ticket', 'Your ticket \"Password reset request\" status changed to Closed', 1, 3, NULL, '2026-03-25 01:05:49'),
(9, 1, 'ticket', 'New password reset request for test@gmail.com', 1, 4, '/iscc-lms/tickets.php?view=4', '2026-03-25 01:07:57'),
(10, 1, 'ticket', 'New password reset request for student1@iscc.edu.ph', 1, 5, '/iscc-lms/tickets.php?view=5', '2026-03-25 01:08:25');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `quiz_type` enum('multiple_choice','word_scramble','mixed') DEFAULT 'multiple_choice',
  `time_limit` int(11) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `deadline` datetime DEFAULT NULL,
  `max_attempts` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `class_id`, `title`, `description`, `quiz_type`, `time_limit`, `is_published`, `created_at`, `deadline`, `max_attempts`) VALUES
(1, 1, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 0, '2026-03-01 20:26:43', NULL, 0),
(2, 1, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 0, '2026-03-01 20:26:43', NULL, 0),
(3, 2, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(4, 2, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(5, 3, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(6, 3, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(7, 4, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(8, 4, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(9, 5, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(10, 5, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(11, 6, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(12, 6, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(13, 7, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(14, 7, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(15, 8, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(16, 8, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(17, 9, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(18, 9, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(19, 10, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(20, 10, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(21, 11, 'Quiz 1: Fundamentals', 'Test your understanding.', 'multiple_choice', NULL, 1, '2026-03-01 20:26:43', NULL, 0),
(22, 11, 'Word Scramble Challenge', 'Unscramble the letters!', 'word_scramble', NULL, 1, '2026-03-01 20:26:43', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `quiz_answers`
--

CREATE TABLE `quiz_answers` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `student_answer` varchar(255) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_attempts`
--

CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `score` decimal(5,2) DEFAULT 0.00,
  `total_items` int(11) DEFAULT 0,
  `correct_items` int(11) DEFAULT 0,
  `completed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--

CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','word_scramble') DEFAULT 'multiple_choice',
  `option_a` varchar(255) DEFAULT NULL,
  `option_b` varchar(255) DEFAULT NULL,
  `option_c` varchar(255) DEFAULT NULL,
  `option_d` varchar(255) DEFAULT NULL,
  `correct_answer` varchar(255) NOT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quiz_questions`
--

INSERT INTO `quiz_questions` (`id`, `quiz_id`, `question_text`, `question_type`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `sort_order`) VALUES
(1, 1, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(2, 1, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(3, 1, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(4, 2, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(5, 2, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(6, 2, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(7, 3, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(8, 3, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(9, 3, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(10, 4, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(11, 4, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(12, 4, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(13, 5, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(14, 5, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(15, 5, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(16, 6, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(17, 6, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(18, 6, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(19, 7, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(20, 7, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(21, 7, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(22, 8, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(23, 8, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(24, 8, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(25, 9, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(26, 9, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(27, 9, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(28, 10, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(29, 10, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(30, 10, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(31, 11, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(32, 11, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(33, 11, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(34, 12, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(35, 12, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(36, 12, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(37, 13, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(38, 13, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(39, 13, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(40, 14, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(41, 14, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(42, 14, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(43, 15, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(44, 15, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(45, 15, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(46, 16, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(47, 16, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(48, 16, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(49, 17, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(50, 17, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(51, 17, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(52, 18, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(53, 18, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(54, 18, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(55, 19, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(56, 19, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(57, 19, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(58, 20, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(59, 20, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(60, 20, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3),
(61, 21, 'What is the primary goal of this course?', 'multiple_choice', 'Entertainment', 'Learning', 'Competition', 'Socializing', 'B', 1),
(62, 21, 'Which method is most effective for learning?', 'multiple_choice', 'Memorization only', 'Practice and application', 'Guessing', 'Skipping lessons', 'B', 2),
(63, 21, 'What level comes after Beginner?', 'multiple_choice', 'Expert', 'Intermediate', 'Advanced', 'Master', 'B', 3),
(64, 22, 'The process of gaining new skills', 'word_scramble', NULL, NULL, NULL, NULL, 'LEARNING', 1),
(65, 22, 'Information and understanding', 'word_scramble', NULL, NULL, NULL, NULL, 'KNOWLEDGE', 2),
(66, 22, 'Repetition to improve ability', 'word_scramble', NULL, NULL, NULL, NULL, 'PRACTICE', 3);

-- --------------------------------------------------------

--
-- Table structure for table `school_years`
--

CREATE TABLE `school_years` (
  `id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `school_years`
--

INSERT INTO `school_years` (`id`, `name`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, '2025-2026', '2025-08-01', '2026-06-10', 'active', '2026-03-03 15:54:23', '2026-03-03 15:54:23');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `program_code` varchar(10) NOT NULL DEFAULT 'BSIT',
  `year_level` tinyint(4) NOT NULL,
  `section_name` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `program_code`, `year_level`, `section_name`, `is_active`, `created_at`) VALUES
(1, 'BSIT', 1, 'A', 1, '2026-03-01 20:26:41'),
(2, 'BSIT', 1, 'B', 1, '2026-03-01 20:26:41'),
(3, 'BSIT', 1, 'C', 1, '2026-03-01 20:26:41'),
(4, 'BSIT', 2, 'A', 1, '2026-03-01 20:26:41'),
(5, 'BSIT', 2, 'B', 1, '2026-03-01 20:26:41'),
(6, 'BSIT', 2, 'C', 1, '2026-03-01 20:26:41'),
(7, 'BSIT', 3, 'A', 1, '2026-03-01 20:26:41'),
(8, 'BSIT', 3, 'B', 1, '2026-03-01 20:26:41'),
(9, 'BSIT', 3, 'C', 1, '2026-03-01 20:26:41'),
(10, 'BSIT', 4, 'A', 1, '2026-03-01 20:26:41'),
(11, 'BSIT', 4, 'B', 1, '2026-03-01 20:26:41'),
(12, 'BSIT', 4, 'C', 1, '2026-03-01 20:26:41');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'app_name', 'Ilocos Sur Community College', '2026-03-01 20:26:41'),
(2, 'app_logo', '', '2026-03-01 20:26:41'),
(3, 'theme_accent', '#18192B', '2026-03-25 00:51:06'),
(4, 'maintenance_mode', '0', '2026-03-01 20:26:41'),
(5, 'weight_attendance', '10', '2026-03-01 20:26:41'),
(6, 'weight_activity', '20', '2026-03-01 20:26:41'),
(7, 'weight_quiz', '30', '2026-03-01 20:26:41'),
(8, 'weight_project', '0', '2026-03-01 20:26:41'),
(9, 'weight_exam', '40', '2026-03-01 20:26:41'),
(10, 'passing_grade', '75', '2026-03-01 20:26:41'),
(11, 'grading_scale', 'percentage', '2026-03-01 20:26:41'),
(12, 'academic_year', '2025-2026', '2026-03-01 20:26:41'),
(13, 'academic_semester', 'First Semester', '2026-03-01 20:26:41'),
(14, 'max_absences', '4', '2026-03-03 13:36:33'),
(15, 'absence_auto_fail', '1', '2026-03-01 20:26:41'),
(16, 'attendance_weight_type', 'percentage', '2026-03-01 20:26:41'),
(28, 'sy_auto_archive', '1', '2026-03-03 15:54:23'),
(29, 'theme_mode', 'light', '2026-03-25 00:22:30'),
(30, 'theme_sidebar', '#0C103B', '2026-03-25 00:51:43'),
(31, 'theme_navbar', '#BCF000', '2026-03-25 00:51:27');

-- --------------------------------------------------------

--
-- Table structure for table `student_assignments`
--

CREATE TABLE `student_assignments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `program_code` varchar(10) NOT NULL DEFAULT 'BSIT',
  `year_level` tinyint(4) NOT NULL,
  `section_id` int(11) NOT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_completed_subjects`
--

CREATE TABLE `student_completed_subjects` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `grade` decimal(5,2) DEFAULT NULL,
  `completed_at` datetime DEFAULT current_timestamp(),
  `recorded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submission_history`
--

CREATE TABLE `submission_history` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `version` int(11) DEFAULT 1,
  `submitted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `ticket_number` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `account_lookup_status` enum('matched','not_found') DEFAULT 'matched',
  `request_source` varchar(50) DEFAULT NULL,
  `category` enum('bug','feature','account','grades','classes','quiz','general') DEFAULT 'general',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `support_tickets`
--

INSERT INTO `support_tickets` (`id`, `ticket_number`, `user_id`, `requester_email`, `account_lookup_status`, `request_source`, `category`, `priority`, `subject`, `description`, `status`, `assigned_to`, `resolution_notes`, `resolved_at`, `closed_at`, `created_at`, `updated_at`) VALUES
(3, 'TKT-20260325-64E404', 1, NULL, 'matched', NULL, 'account', 'high', 'Password reset request', 'A public forgot-password request was submitted from the login page.\nRequester email: superadmin@iscc.edu.ph\nUsername: superadmin\nRole: Superadmin\nAction needed: Contact the user and assist with a manual password reset. Do not send the current password.', 'closed', 1, NULL, NULL, '2026-03-25 01:05:49', '2026-03-25 01:02:51', '2026-03-25 01:05:49'),
(4, 'TKT-20260325-21018E', 1, 'test@gmail.com', 'not_found', 'forgot_password', 'account', 'high', 'Password reset request', 'A public forgot-password request was submitted from the login page.\nRequester email: test@gmail.com\nLookup status: No active LMS account found\nMatched name: Not found\nUsername: Not found\nRole: Not found\nAction needed: Review the requester email and assist with a manual password reset if appropriate. Do not send the current password.', 'open', NULL, NULL, NULL, NULL, '2026-03-25 01:07:57', '2026-03-25 01:07:57'),
(5, 'TKT-20260325-F7575B', 3, 'student1@iscc.edu.ph', 'matched', 'forgot_password', 'account', 'high', 'Password reset request', 'A public forgot-password request was submitted from the login page.\nRequester email: student1@iscc.edu.ph\nLookup status: Matched active LMS account\nMatched name: John Rivera\nUsername: student1\nRole: Student\nAction needed: Review the requester email and assist with a manual password reset if appropriate. Do not send the current password.', 'in_progress', 1, NULL, NULL, NULL, '2026-03-25 01:08:25', '2026-03-25 01:14:55');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `reply_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_replies`
--

CREATE TABLE `ticket_replies` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_internal` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `plain_password` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `role` enum('superadmin','staff','instructor','student') NOT NULL,
  `student_id_no` varchar(10) DEFAULT NULL,
  `program_code` varchar(10) DEFAULT 'BSIT',
  `year_level` tinyint(4) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `forum_karma` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `plain_password`, `email`, `profile_picture`, `first_name`, `last_name`, `role`, `student_id_no`, `program_code`, `year_level`, `section_id`, `semester`, `is_active`, `created_at`, `updated_at`, `forum_karma`) VALUES
(1, 'superadmin', '$2y$10$v2ohEz18wxH5UL0mThOxUu15gWNN1Dk6YkzS9s9c7j8DFxbi2ePBi', 'password123', 'superadmin@iscc.edu.ph', 'uploads/profile/profile_1_1774371127_a873f4736c39.png', 'Super', 'Admin', 'superadmin', NULL, 'BSIT', NULL, NULL, NULL, 1, '2026-03-01 20:26:41', '2026-03-25 00:52:07', 0),
(2, 'instructor1', '$2y$10$v2ohEz18wxH5UL0mThOxUu15gWNN1Dk6YkzS9s9c7j8DFxbi2ePBi', 'password123', 'instructor1@iscc.edu.ph', 'uploads/profile/profile_2_1774371150_a3f069c84a45.png', 'Carl Jonar', 'Palado', 'instructor', NULL, 'BSIT', NULL, NULL, NULL, 1, '2026-03-01 20:26:41', '2026-03-25 00:52:30', 0),
(3, 'student1', '$2y$10$9yhrno/mvFAJNdPbsuvhYex6jQTMU99s7sTY22oBl7Yxagv/wJhB.', NULL, 'student1@iscc.edu.ph', 'uploads/profile/profile_3_1774371175_3e2cea141a75.png', 'John', 'Rivera', 'student', 'c-22-00001', 'BSIT', 1, 1, 'First Semester', 1, '2026-03-01 20:26:41', '2026-03-25 01:14:55', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_submissions`
--
ALTER TABLE `activity_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_student` (`activity_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`class_id`,`student_id`,`attendance_date`,`grading_period`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `badges`
--
ALTER TABLE `badges`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `badge_earns`
--
ALTER TABLE `badge_earns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_earn` (`badge_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_hash` (`certificate_hash`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `certificate_hash_2` (`certificate_hash`);

--
-- Indexes for table `chain_snapshots`
--
ALTER TABLE `chain_snapshots`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`class_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `class_grade_weights`
--
ALTER TABLE `class_grade_weights`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class_weight` (`class_id`,`component`);

--
-- Indexes for table `class_join_requests`
--
ALTER TABLE `class_join_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_request` (`class_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `forum_attachments`
--
ALTER TABLE `forum_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `forum_categories`
--
ALTER TABLE `forum_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `forum_notifications`
--
ALTER TABLE `forum_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `triggered_by` (`triggered_by`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `forum_posts`
--
ALTER TABLE `forum_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `forum_post_reports`
--
ALTER TABLE `forum_post_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `thread_id` (`thread_id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `reported_by` (`reported_by`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `forum_threads`
--
ALTER TABLE `forum_threads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `last_reply_by` (`last_reply_by`);

--
-- Indexes for table `forum_thread_likes`
--
ALTER TABLE `forum_thread_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like` (`thread_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `graded_activities`
--
ALTER TABLE `graded_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_class_type` (`class_id`,`activity_type`,`grading_period`);

--
-- Indexes for table `graded_activity_scores`
--
ALTER TABLE `graded_activity_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_activity_score` (`activity_id`,`student_id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_grade` (`class_id`,`student_id`,`component`,`grading_period`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `grade_chain`
--
ALTER TABLE `grade_chain`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `block_hash` (`block_hash`);

--
-- Indexes for table `instructor_classes`
--
ALTER TABLE `instructor_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `class_code` (`class_code`),
  ADD KEY `instructor_id` (`instructor_id`),
  ADD KEY `section_id` (`section_id`);

--
-- Indexes for table `kahoot_answers`
--
ALTER TABLE `kahoot_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_answer` (`session_id`,`participant_id`,`question_id`),
  ADD KEY `participant_id` (`participant_id`),
  ADD KEY `question_id` (`question_id`),
  ADD KEY `choice_id` (`choice_id`);

--
-- Indexes for table `kahoot_choices`
--
ALTER TABLE `kahoot_choices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_question` (`question_id`);

--
-- Indexes for table `kahoot_games`
--
ALTER TABLE `kahoot_games`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_pin` (`game_pin`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `kahoot_participants`
--
ALTER TABLE `kahoot_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_session_user` (`session_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_session_score` (`session_id`,`score`);

--
-- Indexes for table `kahoot_questions`
--
ALTER TABLE `kahoot_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_game_order` (`game_id`,`question_order`);

--
-- Indexes for table `kahoot_sessions`
--
ALTER TABLE `kahoot_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `host_id` (`host_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_game` (`game_id`);

--
-- Indexes for table `knowledge_nodes`
--
ALTER TABLE `knowledge_nodes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `knowledge_node_progress`
--
ALTER TABLE `knowledge_node_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_progress` (`node_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `lessons`
--
ALTER TABLE `lessons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `lesson_attachments`
--
ALTER TABLE `lesson_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lesson_id` (`lesson_id`);

--
-- Indexes for table `meetings`
--
ALTER TABLE `meetings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `attempt_id` (`attempt_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `school_years`
--
ALTER TABLE `school_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_sy_name` (`name`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section` (`program_code`,`year_level`,`section_name`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `student_assignments`
--
ALTER TABLE `student_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `section_id` (`section_id`);

--
-- Indexes for table `student_completed_subjects`
--
ALTER TABLE `student_completed_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_completed` (`student_id`,`course_code`);

--
-- Indexes for table `submission_history`
--
ALTER TABLE `submission_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ticket` (`ticket_number`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_ticket` (`ticket_id`);

--
-- Indexes for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_ticket` (`ticket_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `unique_student_id_no` (`student_id_no`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_submissions`
--
ALTER TABLE `activity_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=177;

--
-- AUTO_INCREMENT for table `badges`
--
ALTER TABLE `badges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `badge_earns`
--
ALTER TABLE `badge_earns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `chain_snapshots`
--
ALTER TABLE `chain_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `class_grade_weights`
--
ALTER TABLE `class_grade_weights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_join_requests`
--
ALTER TABLE `class_join_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `forum_attachments`
--
ALTER TABLE `forum_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `forum_categories`
--
ALTER TABLE `forum_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `forum_notifications`
--
ALTER TABLE `forum_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `forum_posts`
--
ALTER TABLE `forum_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forum_post_reports`
--
ALTER TABLE `forum_post_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forum_threads`
--
ALTER TABLE `forum_threads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `forum_thread_likes`
--
ALTER TABLE `forum_thread_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `graded_activities`
--
ALTER TABLE `graded_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `graded_activity_scores`
--
ALTER TABLE `graded_activity_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `grade_chain`
--
ALTER TABLE `grade_chain`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `instructor_classes`
--
ALTER TABLE `instructor_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `kahoot_answers`
--
ALTER TABLE `kahoot_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `kahoot_choices`
--
ALTER TABLE `kahoot_choices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `kahoot_games`
--
ALTER TABLE `kahoot_games`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `kahoot_participants`
--
ALTER TABLE `kahoot_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `kahoot_questions`
--
ALTER TABLE `kahoot_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kahoot_sessions`
--
ALTER TABLE `kahoot_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `knowledge_nodes`
--
ALTER TABLE `knowledge_nodes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `knowledge_node_progress`
--
ALTER TABLE `knowledge_node_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `lessons`
--
ALTER TABLE `lessons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `lesson_attachments`
--
ALTER TABLE `lesson_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `meetings`
--
ALTER TABLE `meetings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

--
-- AUTO_INCREMENT for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2788;

--
-- AUTO_INCREMENT for table `student_assignments`
--
ALTER TABLE `student_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `student_completed_subjects`
--
ALTER TABLE `student_completed_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=595;

--
-- AUTO_INCREMENT for table `submission_history`
--
ALTER TABLE `submission_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_replies`
--
ALTER TABLE `ticket_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_submissions`
--
ALTER TABLE `activity_submissions`
  ADD CONSTRAINT `activity_submissions_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `graded_activities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `badge_earns`
--
ALTER TABLE `badge_earns`
  ADD CONSTRAINT `badge_earns_ibfk_1` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `badge_earns_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_enrollments`
--
ALTER TABLE `class_enrollments`
  ADD CONSTRAINT `class_enrollments_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `class_enrollments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_grade_weights`
--
ALTER TABLE `class_grade_weights`
  ADD CONSTRAINT `class_grade_weights_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_join_requests`
--
ALTER TABLE `class_join_requests`
  ADD CONSTRAINT `class_join_requests_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `class_join_requests_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forum_attachments`
--
ALTER TABLE `forum_attachments`
  ADD CONSTRAINT `forum_attachments_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_attachments_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_attachments_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forum_categories`
--
ALTER TABLE `forum_categories`
  ADD CONSTRAINT `forum_categories_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `forum_notifications`
--
ALTER TABLE `forum_notifications`
  ADD CONSTRAINT `forum_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_notifications_ibfk_2` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_notifications_ibfk_3` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_notifications_ibfk_4` FOREIGN KEY (`triggered_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forum_posts`
--
ALTER TABLE `forum_posts`
  ADD CONSTRAINT `forum_posts_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_posts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forum_post_reports`
--
ALTER TABLE `forum_post_reports`
  ADD CONSTRAINT `forum_post_reports_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_post_reports_ibfk_2` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_post_reports_ibfk_3` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_post_reports_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `forum_threads`
--
ALTER TABLE `forum_threads`
  ADD CONSTRAINT `forum_threads_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_threads_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_threads_ibfk_3` FOREIGN KEY (`last_reply_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `forum_thread_likes`
--
ALTER TABLE `forum_thread_likes`
  ADD CONSTRAINT `forum_thread_likes_ibfk_1` FOREIGN KEY (`thread_id`) REFERENCES `forum_threads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `forum_thread_likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `graded_activities`
--
ALTER TABLE `graded_activities`
  ADD CONSTRAINT `graded_activities_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `graded_activity_scores`
--
ALTER TABLE `graded_activity_scores`
  ADD CONSTRAINT `graded_activity_scores_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `graded_activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `instructor_classes`
--
ALTER TABLE `instructor_classes`
  ADD CONSTRAINT `instructor_classes_ibfk_1` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `instructor_classes_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kahoot_answers`
--
ALTER TABLE `kahoot_answers`
  ADD CONSTRAINT `kahoot_answers_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `kahoot_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_answers_ibfk_2` FOREIGN KEY (`participant_id`) REFERENCES `kahoot_participants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_answers_ibfk_3` FOREIGN KEY (`question_id`) REFERENCES `kahoot_questions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_answers_ibfk_4` FOREIGN KEY (`choice_id`) REFERENCES `kahoot_choices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `kahoot_choices`
--
ALTER TABLE `kahoot_choices`
  ADD CONSTRAINT `kahoot_choices_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `kahoot_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kahoot_games`
--
ALTER TABLE `kahoot_games`
  ADD CONSTRAINT `kahoot_games_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_games_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `instructor_classes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `kahoot_participants`
--
ALTER TABLE `kahoot_participants`
  ADD CONSTRAINT `kahoot_participants_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `kahoot_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kahoot_questions`
--
ALTER TABLE `kahoot_questions`
  ADD CONSTRAINT `kahoot_questions_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `kahoot_games` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kahoot_sessions`
--
ALTER TABLE `kahoot_sessions`
  ADD CONSTRAINT `kahoot_sessions_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `kahoot_games` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kahoot_sessions_ibfk_2` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `knowledge_node_progress`
--
ALTER TABLE `knowledge_node_progress`
  ADD CONSTRAINT `knowledge_node_progress_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `knowledge_nodes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `knowledge_node_progress_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
