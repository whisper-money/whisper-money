/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `account_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_balances` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `balance_date` date NOT NULL,
  `balance` bigint NOT NULL,
  `invested_amount` bigint DEFAULT NULL,
  `derived` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_balances_account_id_balance_date_unique` (`account_id`,`balance_date`),
  KEY `account_balances_import_id_index` (`import_id`),
  CONSTRAINT `account_balances_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `account_import_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_import_configs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_import_configs_account_id_type_unique` (`account_id`,`type`),
  CONSTRAINT `account_import_configs_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `hidden_on_dashboard` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` timestamp NULL DEFAULT NULL,
  `ownership_percentage` tinyint NOT NULL DEFAULT '100',
  `ownership_applies_to_balance` tinyint(1) NOT NULL DEFAULT '0',
  `banking_connection_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_account_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transactions_paginate_before` date DEFAULT NULL,
  `iban` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `linked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounts_user_id_foreign` (`user_id`),
  KEY `accounts_banking_connection_id_index` (`banking_connection_id`),
  KEY `accounts_bank_id_foreign` (`bank_id`),
  KEY `accounts_space_id_index` (`space_id`),
  KEY `accounts_import_id_foreign` (`import_id`),
  CONSTRAINT `accounts_bank_id_foreign` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounts_banking_connection_id_foreign` FOREIGN KEY (`banking_connection_id`) REFERENCES `banking_connections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `accounts_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `achievements` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `achieved_on` date NOT NULL,
  `value` bigint DEFAULT NULL,
  `percent` decimal(8,2) DEFAULT NULL,
  `currency_code` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `achievements_user_id_key_unique` (`user_id`,`key`),
  KEY `achievements_space_id_foreign` (`space_id`),
  KEY `achievements_user_id_achieved_on_index` (`user_id`,`achieved_on`),
  KEY `achievements_key_index` (`key`),
  CONSTRAINT `achievements_space_id_foreign` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `achievements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_consents` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'finance',
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accepted_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_consents_user_id_scope_index` (`user_id`,`scope`),
  CONSTRAINT `ai_consents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rule_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rule_labels` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `automation_rule_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_rule_labels_automation_rule_id_label_id_unique` (`automation_rule_id`,`label_id`),
  KEY `automation_rule_labels_label_id_foreign` (`label_id`),
  CONSTRAINT `automation_rule_labels_automation_rule_id_foreign` FOREIGN KEY (`automation_rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automation_rule_labels_label_id_foreign` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `origin` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `rules_json` json NOT NULL,
  `action_category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_note` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_rules_user_id_foreign` (`user_id`),
  KEY `automation_rules_action_category_id_foreign` (`action_category_id`),
  KEY `automation_rules_space_id_index` (`space_id`),
  CONSTRAINT `automation_rules_action_category_id_foreign` FOREIGN KEY (`action_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_rules_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banking_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banking_connections` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `authorization_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_token` text COLLATE utf8mb4_unicode_ci,
  `api_secret` text COLLATE utf8mb4_unicode_ci,
  `aspsp_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aspsp_country` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aspsp_logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aspsp_beta` tinyint(1) DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_until` datetime DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `ledger_synced_until` timestamp NULL DEFAULT NULL,
  `bank_transactions_email_cutoff_at` datetime DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `rate_limited_until` datetime DEFAULT NULL,
  `consecutive_sync_failures` tinyint unsigned NOT NULL DEFAULT '0',
  `pending_accounts_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `banking_connections_session_id_unique` (`session_id`),
  UNIQUE KEY `banking_connections_state_token_unique` (`state_token`),
  KEY `banking_connections_user_id_foreign` (`user_id`),
  KEY `banking_connections_space_id_index` (`space_id`),
  CONSTRAINT `banking_connections_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banking_sync_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banking_sync_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `banking_connection_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt` tinyint unsigned NOT NULL DEFAULT '1',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `error_class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `banking_sync_logs_banking_connection_id_foreign` (`banking_connection_id`),
  CONSTRAINT `banking_sync_logs_banking_connection_id_foreign` FOREIGN KEY (`banking_connection_id`) REFERENCES `banking_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banks` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `banks_user_id_foreign` (`user_id`),
  KEY `banks_import_id_foreign` (`import_id`),
  CONSTRAINT `banks_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `banks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_category` (
  `budget_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `budget_category_budget_id_category_id_unique` (`budget_id`,`category_id`),
  KEY `budget_category_category_id_foreign` (`category_id`),
  CONSTRAINT `budget_category_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_label`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_label` (
  `budget_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `budget_label_budget_id_label_id_unique` (`budget_id`,`label_id`),
  KEY `budget_label_label_id_foreign` (`label_id`),
  CONSTRAINT `budget_label_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_label_label_id_foreign` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_periods` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `budget_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `allocated_amount` bigint NOT NULL DEFAULT '0',
  `processing_historical` tinyint(1) NOT NULL DEFAULT '0',
  `carried_over_amount` bigint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `close_to_limit_notified` tinyint(1) NOT NULL DEFAULT '0',
  `over_limit_notified` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `budget_periods_budget_id_start_date_unique` (`budget_id`,`start_date`),
  CONSTRAINT `budget_periods_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_transactions` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `budget_period_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget_transaction_period` (`transaction_id`,`budget_period_id`),
  KEY `budget_transactions_budget_period_id_foreign` (`budget_period_id`),
  CONSTRAINT `budget_transactions_budget_period_id_foreign` FOREIGN KEY (`budget_period_id`) REFERENCES `budget_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_transactions_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int unsigned DEFAULT NULL,
  `period_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_duration` int DEFAULT NULL,
  `period_start_day` int DEFAULT NULL,
  `is_catch_all` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` timestamp NULL DEFAULT NULL,
  `rollover_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'carry_over',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notify_on_new_transaction` tinyint(1) NOT NULL DEFAULT '0',
  `notify_on_close_to_limit` tinyint(1) NOT NULL DEFAULT '0',
  `notify_on_over_limit` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `budgets_user_id_foreign` (`user_id`),
  KEY `budgets_space_id_index` (`space_id`),
  CONSTRAINT `budgets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cashflow_direction` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hidden',
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_unique_marker` tinyint(1) GENERATED ALWAYS AS (if((`deleted_at` is null),1,NULL)) VIRTUAL,
  `parent_unique_marker` varchar(255) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (coalesce(`parent_id`,_utf8mb4'root')) VIRTUAL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_user_id_parent_name_active_unique` (`user_id`,`parent_unique_marker`,`name`,`active_unique_marker`),
  KEY `categories_parent_id_index` (`parent_id`),
  KEY `categories_space_id_index` (`space_id`),
  KEY `categories_import_id_foreign` (`import_id`),
  CONSTRAINT `categories_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `categories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_corrections` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `confidence` decimal(4,3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_corrections_user_id_foreign` (`user_id`),
  KEY `category_corrections_transaction_id_foreign` (`transaction_id`),
  KEY `category_corrections_from_category_id_foreign` (`from_category_id`),
  KEY `category_corrections_to_category_id_foreign` (`to_category_id`),
  KEY `category_corrections_source_created_at_index` (`source`,`created_at`),
  CONSTRAINT `category_corrections_from_category_id_foreign` FOREIGN KEY (`from_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `category_corrections_to_category_id_foreign` FOREIGN KEY (`to_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `category_corrections_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `category_corrections_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credit_card_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_card_details` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `statement_closing_date` date DEFAULT NULL,
  `payment_due_date` date DEFAULT NULL,
  `statement_frequency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `credit_limit` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_card_details_account_id_unique` (`account_id`),
  CONSTRAINT `credit_card_details_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `base_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `rates` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_rates_base_currency_date_unique` (`base_currency`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `features_name_scope_unique` (`name`,`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_chunks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int unsigned NOT NULL,
  `row_count` int unsigned NOT NULL,
  `rows` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_chunks_import_id_kind_position_unique` (`import_id`,`kind`,`position`),
  CONSTRAINT `import_chunks_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `imports` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan` json DEFAULT NULL,
  `options` json DEFAULT NULL,
  `stats` json DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `undone_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `imports_space_id_foreign` (`space_id`),
  KEY `imports_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `imports_space_id_foreign` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_request_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_request_votes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `integration_request_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `integration_request_votes_user_id_foreign` (`user_id`),
  KEY `integration_request_votes_integration_request_id_index` (`integration_request_id`),
  CONSTRAINT `integration_request_votes_integration_request_id_foreign` FOREIGN KEY (`integration_request_id`) REFERENCES `integration_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `integration_request_votes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_requests` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `integration_requests_user_id_foreign` (`user_id`),
  KEY `integration_requests_status_index` (`status`),
  CONSTRAINT `integration_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `label_transaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `label_transaction` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `label_transaction_label_id_transaction_id_unique` (`label_id`,`transaction_id`),
  KEY `label_transaction_transaction_id_foreign` (`transaction_id`),
  CONSTRAINT `label_transaction_label_id_foreign` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `label_transaction_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labels` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `labels_user_id_name_deleted_at_unique` (`user_id`,`name`,`deleted_at`),
  KEY `labels_space_id_index` (`space_id`),
  CONSTRAINT `labels_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_details` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `annual_interest_rate` decimal(6,3) NOT NULL,
  `loan_term_months` int NOT NULL,
  `start_date` date NOT NULL,
  `original_amount` bigint NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_details_account_id_unique` (`account_id`),
  CONSTRAINT `loan_details_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mcp_tool_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mcp_tool_calls` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tool` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mcp_tool_calls_user_id_foreign` (`user_id`),
  KEY `mcp_tool_calls_created_at_index` (`created_at`),
  CONSTRAINT `mcp_tool_calls_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monthly_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monthly_summaries` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period` char(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `ai_analysis` text COLLATE utf8mb4_unicode_ci,
  `ai_generated_at` timestamp NULL DEFAULT NULL,
  `card` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `complete` tinyint(1) NOT NULL DEFAULT '0',
  `share_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shared_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_summaries_user_id_space_id_period_unique` (`user_id`,`space_id`,`period`),
  UNIQUE KEY `monthly_summaries_share_token_unique` (`share_token`),
  KEY `monthly_summaries_space_id_foreign` (`space_id`),
  KEY `monthly_summaries_period_sent_at_index` (`period`,`sent_at`),
  CONSTRAINT `monthly_summaries_space_id_foreign` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_summaries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` char(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scopes` text COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` char(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` text COLLATE utf8mb4_unicode_ci,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_uris` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `grant_types` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_owner_type_owner_id_index` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_device_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_device_codes` (
  `id` char(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_code` char(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `user_approved_at` datetime DEFAULT NULL,
  `last_polled_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_device_codes_user_code_unique` (`user_code`),
  KEY `oauth_device_codes_user_id_index` (`user_id`),
  KEY `oauth_device_codes_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` char(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token_id` char(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `real_estate_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `real_estate_details` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `linked_loan_account_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `purchase_price` bigint DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `area_value` decimal(12,2) DEFAULT NULL,
  `area_unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `revaluation_percentage` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `real_estate_details_account_id_unique` (`account_id`),
  KEY `real_estate_details_linked_loan_account_id_foreign` (`linked_loan_account_id`),
  CONSTRAINT `real_estate_details_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `real_estate_details_linked_loan_account_id_foreign` FOREIGN KEY (`linked_loan_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rule_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rule_suggestions` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggestion_run_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_field` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_operator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'contains',
  `match_token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proposed_category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_category_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_category_parent_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_category_direction` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` decimal(4,3) NOT NULL DEFAULT '0.000',
  `group_size` int unsigned NOT NULL DEFAULT '0',
  `sample_descriptions` json DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rule_suggestions_proposed_category_id_foreign` (`proposed_category_id`),
  KEY `rule_suggestions_new_category_parent_id_foreign` (`new_category_parent_id`),
  KEY `rule_suggestions_suggestion_run_id_status_index` (`suggestion_run_id`,`status`),
  CONSTRAINT `rule_suggestions_new_category_parent_id_foreign` FOREIGN KEY (`new_category_parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rule_suggestions_proposed_category_id_foreign` FOREIGN KEY (`proposed_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rule_suggestions_suggestion_run_id_foreign` FOREIGN KEY (`suggestion_run_id`) REFERENCES `suggestion_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `saved_filters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saved_filters` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filters` json NOT NULL,
  `analysis_days` int unsigned DEFAULT NULL,
  `analysis_mode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `saved_filters_user_id_name_unique` (`user_id`,`name`),
  KEY `saved_filters_space_id_index` (`space_id`),
  CONSTRAINT `saved_filters_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `savings_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `savings_goals` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int unsigned DEFAULT NULL,
  `target_amount` bigint NOT NULL,
  `initial_amount` bigint NOT NULL DEFAULT '0',
  `target_date` date DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_saved_amount` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `savings_goals_user_id_foreign` (`user_id`),
  KEY `savings_goals_label_id_foreign` (`label_id`),
  KEY `savings_goals_space_id_index` (`space_id`),
  CONSTRAINT `savings_goals_label_id_foreign` FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `savings_goals_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_last_activity_index` (`last_activity`),
  KEY `sessions_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `space_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `space_invitations` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invited_by_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `space_invitations_token_unique` (`token`),
  KEY `space_invitations_invited_by_id_foreign` (`invited_by_id`),
  KEY `space_invitations_space_id_email_index` (`space_id`,`email`),
  CONSTRAINT `space_invitations_invited_by_id_foreign` FOREIGN KEY (`invited_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `space_invitations_space_id_foreign` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `space_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `space_user` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `space_user_space_id_user_id_unique` (`space_id`,`user_id`),
  KEY `space_user_user_id_foreign` (`user_id`),
  CONSTRAINT `space_user_space_id_foreign` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `space_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spaces` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `personal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `spaces_owner_id_index` (`owner_id`),
  CONSTRAINT `spaces_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stuck_cohort_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stuck_cohort_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `onboarded_count` int unsigned NOT NULL,
  `stuck_count` int unsigned NOT NULL,
  `stuck_pct` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stuck_cohort_snapshots_date_unique` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint unsigned NOT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_product` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_price` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meter_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `meter_event_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_items_stripe_id_unique` (`stripe_id`),
  KEY `subscription_items_subscription_id_stripe_price_index` (`subscription_id`,`stripe_price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `upsell_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscriptions_stripe_id_unique` (`stripe_id`),
  KEY `subscriptions_user_id_stripe_status_index` (`user_id`,`stripe_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suggestion_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suggestion_runs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `transactions_considered` int unsigned NOT NULL DEFAULT '0',
  `merchants_considered` int unsigned NOT NULL DEFAULT '0',
  `suggestions_count` int unsigned NOT NULL DEFAULT '0',
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `suggestion_runs_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `suggestion_runs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppressed_email_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppressed_email_addresses` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `suppressed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppressed_email_addresses_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `split_parent_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `categorized_by_rule_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_suggested_category_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_suggested_category_at` timestamp NULL DEFAULT NULL,
  `ai_model` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_description` text COLLATE utf8mb4_unicode_ci,
  `transaction_date` date NOT NULL,
  `source_date` date DEFAULT NULL,
  `amount` bigint NOT NULL,
  `currency_code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'imported',
  `external_transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dedup_fingerprint` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_data` json DEFAULT NULL,
  `creditor_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `debtor_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transactions_account_fp_unique` (`account_id`,`dedup_fingerprint`),
  KEY `transactions_category_id_foreign` (`category_id`),
  KEY `idx_transactions_budget_lookup` (`user_id`,`transaction_date`,`category_id`),
  KEY `transactions_account_id_external_transaction_id_index` (`account_id`,`external_transaction_id`),
  KEY `transactions_creditor_name_index` (`creditor_name`),
  KEY `transactions_debtor_name_index` (`debtor_name`),
  KEY `transactions_categorized_by_rule_id_foreign` (`categorized_by_rule_id`),
  KEY `transactions_ai_suggested_category_id_foreign` (`ai_suggested_category_id`),
  KEY `idx_transactions_user_source_created` (`user_id`,`source`,`created_at`),
  KEY `transactions_space_id_index` (`space_id`),
  KEY `transactions_split_parent_id_foreign` (`split_parent_id`),
  KEY `transactions_import_id_index` (`import_id`),
  CONSTRAINT `transactions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_ai_suggested_category_id_foreign` FOREIGN KEY (`ai_suggested_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_categorized_by_rule_id_foreign` FOREIGN KEY (`categorized_by_rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_split_parent_id_foreign` FOREIGN KEY (`split_parent_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_leads` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `position` int unsigned DEFAULT NULL,
  `referral_code` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referred_by_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `cohort` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promo_code_monthly` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promo_code_yearly` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitation_sent_at` timestamp NULL DEFAULT NULL,
  `re_invitation_sent_at` timestamp NULL DEFAULT NULL,
  `re_invitation_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_leads_email_unique` (`email`),
  UNIQUE KEY `user_leads_referral_code_unique` (`referral_code`),
  KEY `user_leads_referred_by_id_foreign` (`referred_by_id`),
  KEY `user_leads_cohort_index` (`cohort`),
  KEY `user_leads_invitation_sent_at_index` (`invitation_sent_at`),
  KEY `user_leads_re_invitation_sent_at_index` (`re_invitation_sent_at`),
  CONSTRAINT `user_leads_referred_by_id_foreign` FOREIGN KEY (`referred_by_id`) REFERENCES `user_leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_mail_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_mail_logs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_mail_logs_unique` (`user_id`,`email_type`,`email_identifier`),
  KEY `user_mail_logs_email_type_index` (`email_type`),
  CONSTRAINT `user_mail_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_settings` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chart_color_scheme` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'colorful',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `include_loans_in_net_worth_chart` tinyint(1) NOT NULL DEFAULT '0',
  `include_real_estate_in_net_worth_chart` tinyint(1) NOT NULL DEFAULT '0',
  `notify_on_bank_transactions_synced` tinyint(1) NOT NULL DEFAULT '1',
  `budget_notify_on_new_transaction` tinyint(1) NOT NULL DEFAULT '0',
  `budget_notify_on_close_to_limit` tinyint(1) NOT NULL DEFAULT '1',
  `budget_notify_on_over_limit` tinyint(1) NOT NULL DEFAULT '1',
  `notify_on_inactive_no_bank` tinyint(1) NOT NULL DEFAULT '1',
  `notify_monthly_summary` tinyint(1) NOT NULL DEFAULT '1',
  `notify_achievements` tinyint(1) NOT NULL DEFAULT '1',
  `notify_marketing` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_settings_user_id_unique` (`user_id`),
  CONSTRAINT `user_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `locale` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `format_locale` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `achievements_count` smallint unsigned NOT NULL DEFAULT '0',
  `price_arm` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signup_plan` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `onboarded_at` timestamp NULL DEFAULT NULL,
  `onboarding_answers` json DEFAULT NULL,
  `last_logged_in_at` timestamp NULL DEFAULT NULL,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `visit_streak` smallint unsigned NOT NULL DEFAULT '0',
  `longest_visit_streak` smallint unsigned NOT NULL DEFAULT '0',
  `visit_week_streak` smallint unsigned NOT NULL DEFAULT '0',
  `longest_visit_week_streak` smallint unsigned NOT NULL DEFAULT '0',
  `transactions_last_visited_at` timestamp NULL DEFAULT NULL,
  `paywall_seen_at` timestamp NULL DEFAULT NULL,
  `ai_consent_prompt_dismissed_at` timestamp NULL DEFAULT NULL,
  `uncategorized_prompt_snoozed_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pm_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pm_last_four` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `current_space_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_stripe_id_index` (`stripe_id`),
  KEY `users_current_space_id_foreign` (`current_space_id`),
  CONSTRAINT `users_current_space_id_foreign` FOREIGN KEY (`current_space_id`) REFERENCES `spaces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_08_26_100418_add_two_factor_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2025_11_07_135255_add_encryption_salt_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2025_11_07_135256_create_encrypted_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2025_11_07_150038_create_banks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2025_11_07_150122_create_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2025_11_07_150613_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2025_11_07_150659_create_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2025_11_07_184056_modify_banks_table_add_user_id_and_simplify_name',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2025_11_07_185018_change_banks_logo_to_text',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2025_11_08_140230_change_transactions_id_to_uuid',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2025_11_08_141344_make_category_id_nullable_in_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2025_11_08_144530_add_soft_deletes_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2025_11_10_110100_create_automation_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2025_11_15_172640_create_account_balances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2025_11_15_175636_change_transactions_amount_to_bigint',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2025_11_15_195739_convert_all_ids_to_uuid',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2025_11_18_184409_create_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2025_11_28_104227_add_source_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2025_11_29_170353_add_type_to_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2025_11_29_170955_fix_account_balances_unique_constraint',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2025_12_01_101443_add_unique_constraint_to_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2025_12_06_112515_create_customer_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2025_12_06_112516_create_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2025_12_06_112517_create_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2025_12_06_112518_add_meter_id_to_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2025_12_06_112519_add_meter_event_name_to_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2025_12_10_142006_add_onboarded_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2025_12_12_092647_create_labels_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2025_12_12_092650_create_label_transaction_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2025_12_12_092651_create_automation_rule_labels_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2025_12_12_141955_update_labels_unique_constraint_include_deleted_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2025_12_16_151952_create_user_mail_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2025_12_19_092437_create_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2025_12_19_092443_create_budget_periods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2025_12_19_092446_create_budget_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2025_12_20_154221_create_features_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2025_12_29_063338_add_currency_code_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_01_08_192757_add_email_identifier_to_user_mail_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_01_08_204223_update_user_mail_logs_unique_constraint',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_01_21_151353_add_indexes_for_budget_transaction_assignment',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_01_22_071325_add_processing_historical_to_budget_periods',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_01_22_143232_add_locale_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_02_08_103953_create_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_02_09_110624_add_encrypted_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_09_131403_make_description_iv_nullable_on_transactions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_09_144915_create_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_02_09_144959_add_banking_connection_fields_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_02_09_144959_add_external_transaction_id_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_02_10_085759_add_aspsp_logo_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_02_10_095639_add_raw_data_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_02_11_113115_add_pending_accounts_data_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_02_11_113119_add_linked_at_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_02_13_083716_add_original_description_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_02_18_084241_add_api_token_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_02_18_110556_add_api_secret_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_02_20_074846_create_exchange_rates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_02_22_180317_add_invested_amount_to_account_balances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_02_24_193117_fix_budget_transaction_refund_amounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_03_03_114620_add_paywall_seen_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_03_04_085843_add_waitlist_columns_to_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_03_04_094926_add_locale_to_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_03_12_095411_add_iban_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_18_112307_add_cashflow_direction_to_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_19_151039_add_include_loans_in_net_worth_chart_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_20_110659_create_real_estate_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_20_132058_make_bank_id_nullable_on_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_03_20_154755_add_include_real_estate_in_net_worth_chart_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_03_25_125717_add_revaluation_percentage_to_real_estate_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_03_25_132244_create_loan_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_03_30_123011_add_consecutive_sync_failures_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_03_30_123011_create_banking_sync_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_04_14_120000_add_email_verification_to_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_04_16_063515_add_bank_transactions_email_cutoff_at_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_04_16_092644_add_timezone_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_04_22_084113_add_deleted_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_04_25_092824_add_launch_columns_to_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_05_05_070442_add_rate_limited_until_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_05_05_132023_convert_custom_budgets_to_monthly',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_05_13_085027_add_dedup_fingerprint_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_05_25_115100_update_default_saving_and_investment_category_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_05_26_061232_add_reinvitation_columns_to_user_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_05_27_061513_add_counterparty_names_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_05_28_073203_update_categories_unique_constraint_for_soft_deletes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_05_29_085835_update_saving_and_investment_category_cashflow_direction',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_06_01_085554_create_budget_category_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_06_01_085554_create_budget_label_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_06_01_085554_migrate_budget_category_label_to_pivots',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_06_02_090000_add_parent_id_to_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_06_02_094823_add_notify_on_bank_transactions_synced_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_06_05_120000_create_saved_filters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_06_05_185212_add_state_token_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_06_08_114044_add_analysis_days_to_saved_filters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_06_09_124010_add_analysis_mode_to_saved_filters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_06_10_083644_add_last_logged_in_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_06_10_084055_add_last_active_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_06_12_134225_create_ai_consents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_06_12_134523_create_suggestion_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_06_12_134524_create_rule_suggestions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_06_13_000000_add_is_catch_all_to_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_06_15_120911_add_ai_categorization_fields_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_06_15_120911_add_origin_to_automation_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_06_15_120911_create_category_corrections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_06_17_081319_add_ai_suggested_category_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_06_17_114601_create_integration_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_06_17_114602_create_integration_request_votes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_06_17_120850_add_status_to_integration_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_06_17_122001_seed_initial_integration_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_06_17_141827_add_comment_to_integration_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_06_18_092830_drop_unique_user_vote_from_integration_request_votes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_06_19_134854_create_stuck_cohort_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_06_20_105609_align_accounts_encrypted_flag_with_plaintext_names',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_06_20_165235_add_position_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_06_26_124609_add_ai_model_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_06_27_000000_backfill_xxx_account_currencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_06_27_114559_add_refunded_at_to_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_06_27_154041_add_hidden_on_dashboard_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_06_29_161916_add_transactions_last_visited_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_07_01_064324_add_ai_consent_prompt_dismissed_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_07_02_133321_add_user_source_created_at_index_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_07_06_120000_create_spaces_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_07_06_120001_create_space_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_07_06_120002_create_space_invitations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_07_06_120003_add_current_space_id_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_07_06_120004_add_space_id_to_owned_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_07_06_120005_backfill_spaces',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_07_17_125642_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_07_17_154027_create_oauth_auth_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_07_17_154028_create_oauth_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_07_17_154029_create_oauth_refresh_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_07_17_154030_create_oauth_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_07_17_154031_create_oauth_device_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_07_18_104433_create_account_import_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_07_18_111320_add_upsell_source_to_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_07_20_151522_add_source_to_labels_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_07_20_151523_create_savings_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_07_24_083854_add_notification_toggles_to_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_07_24_083855_add_budget_notification_defaults_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_07_24_083855_add_limit_notified_flags_to_budget_periods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_07_24_100000_enable_budget_notifications_by_default',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_07_25_102200_disable_budget_new_transaction_notifications',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_08_09_150908_retry_enable_banking_connections_stuck_after_auth_failures',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_08_10_090000_add_ownership_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_08_10_144811_create_mcp_tool_calls_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_08_11_101501_purge_subscription_experiment_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_08_12_070623_retry_banking_connections_stranded_before_transient_fix',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_08_12_100000_weigh_budget_transactions_by_account_ownership',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_08_12_142520_add_price_arm_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_08_18_090000_add_archived_at_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_08_19_070501_purge_mcp_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_08_19_132730_add_signup_plan_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_08_20_104233_delete_runaway_future_budget_periods',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_08_20_132408_realign_monthly_budgets_stored_with_day_zero',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_08_21_050014_deduplicate_budget_transactions_from_overlapping_created_periods',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_08_21_090000_add_notify_on_inactive_no_bank_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_08_21_090000_add_transactions_paginate_before_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_08_21_101500_add_aspsp_beta_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_08_24_090000_add_split_parent_id_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_08_25_090000_uncategorize_transactions_left_on_deleted_categories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_08_25_120000_add_initial_amount_to_savings_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_08_25_140000_add_archived_at_to_budgets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_08_25_140100_add_archived_at_to_savings_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_08_28_122406_add_position_to_budgets_and_savings_goals_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_08_28_122633_rescale_money_to_currency_decimals',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_08_28_140000_add_source_date_to_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_09_01_090000_create_monthly_summaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_09_01_090100_add_notify_monthly_summary_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_09_03_091344_add_dismissed_at_to_monthly_summaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_09_03_103243_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_09_03_111044_create_achievements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_09_03_111045_add_notify_achievements_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_09_04_073036_add_achievements_count_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_09_04_074828_add_visit_streaks_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_09_07_073934_add_derived_to_account_balances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_09_08_090000_add_uncategorized_prompt_snoozed_until_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_09_09_090000_purge_split_transactions_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_09_09_100000_purge_achievements_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_09_10_090000_add_format_locale_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_09_14_134443_add_onboarding_answers_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_09_14_222718_add_merchants_considered_to_suggestion_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_09_16_090000_add_notify_marketing_to_user_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_09_16_120000_default_net_worth_chart_loans_and_real_estate_off',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_09_18_080658_drop_legacy_client_side_encryption',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_09_18_151717_create_suppressed_email_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_09_21_090000_purge_pay_now_experiment_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_09_23_085453_add_ledger_synced_until_to_banking_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_10_05_085108_create_imports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_10_05_085109_add_import_id_to_imported_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_10_10_091110_create_credit_card_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_10_10_164151_add_credit_limit_to_credit_card_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_10_11_100000_purge_credit_card_statements_feature_assignments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_10_12_043825_widen_loan_details_annual_interest_rate',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_10_13_150216_add_statement_frequency_to_credit_card_details_table',1);
