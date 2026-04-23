-- MySQL dump 10.13  Distrib 9.1.0, for Win64 (x86_64)
--
-- Host: localhost    Database: helferstunden
-- ------------------------------------------------------
-- Server version	9.1.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `session_id` int unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` enum('create','update','delete','restore','login','logout','login_failed','status_change','export','import','config_change','dialog_message') COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_id` int unsigned DEFAULT NULL,
  `entry_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Antragsnummer für einfache Zuordnung',
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL COMMENT 'Zusätzliche Daten wie Filter bei Export',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_table` (`table_name`),
  KEY `idx_audit_record` (`table_name`,`record_id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entry_number` (`entry_number`)
) ENGINE=InnoDB AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-21 20:25:10'),(2,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 07:14:36'),(3,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 07:15:21'),(4,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: frfrf (Benutzer nicht gefunden)',NULL,'2026-04-22 07:15:53'),(5,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@vaes.test (Falsches Passwort (Versuch 1))',NULL,'2026-04-22 07:16:19'),(6,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@vaes.test (Falsches Passwort (Versuch 2))',NULL,'2026-04-22 07:16:26'),(7,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@vaes.test (Falsches Passwort (Versuch 3))',NULL,'2026-04-22 07:16:31'),(8,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@vaes.test (Falsches Passwort (Versuch 4))',NULL,'2026-04-22 07:16:38'),(9,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@vaes.test (Account gesperrt nach 5 Fehlversuchen)',NULL,'2026-04-22 07:16:47'),(10,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 07:17:08'),(11,NULL,NULL,NULL,NULL,'config_change','users',3,NULL,'{\"locked_until\": \"2026-04-22 09:31:47\", \"failed_login_attempts\": 5}','{\"locked_until\": null, \"failed_login_attempts\": 0}','Account-Sperre manuell aufgehoben: Test Mitglied','{\"reason\": \"manual_admin_unlock\"}','2026-04-22 07:38:40'),(12,NULL,NULL,NULL,NULL,'logout','users',1,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 07:39:09'),(13,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 07:39:13'),(14,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 07:39:20'),(15,NULL,NULL,NULL,NULL,'update','users',2,NULL,NULL,NULL,'Passwort-Reset angefordert',NULL,'2026-04-22 07:50:22'),(16,NULL,NULL,NULL,NULL,'update','users',2,NULL,NULL,NULL,'Passwort-Reset angefordert',NULL,'2026-04-22 07:50:48'),(17,NULL,NULL,NULL,NULL,'update','users',3,NULL,NULL,NULL,'Passwort-Reset angefordert',NULL,'2026-04-22 07:52:32'),(18,NULL,NULL,NULL,NULL,'update','users',3,NULL,NULL,NULL,'Passwort-Reset angefordert',NULL,'2026-04-22 07:55:07'),(19,NULL,NULL,NULL,NULL,'update','users',3,NULL,NULL,NULL,'Passwort geändert - alle Sessions beendet',NULL,'2026-04-22 07:55:41'),(20,NULL,NULL,NULL,NULL,'update','users',3,NULL,NULL,NULL,'Passwort über Reset-Link geändert - alle Sessions beendet',NULL,'2026-04-22 07:55:41'),(21,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 07:55:48'),(22,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 08:13:03'),(23,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 08:13:49'),(24,2,NULL,'127.0.0.1',NULL,'login','users',2,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 08:14:17'),(25,NULL,NULL,NULL,NULL,'create','work_entries',1,'',NULL,'{\"id\": 1, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 1, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:16:48\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:16:48\", \"category_id\": 5, \"description\": \"Gekocht\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"\", \"is_corrected\": 0, \"submitted_at\": null, \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag erstellt',NULL,'2026-04-22 08:16:48'),(26,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:16:48'),(27,NULL,NULL,NULL,NULL,'dialog_message','work_entry_dialogs',1,'',NULL,'{\"message\": \"Testnachricht\"}','Dialog-Nachricht hinzugefügt',NULL,'2026-04-22 08:17:16'),(28,NULL,NULL,NULL,NULL,'dialog_message','work_entry_dialogs',1,'',NULL,'{\"message\": \"BlaBla\"}','Dialog-Nachricht hinzugefügt',NULL,'2026-04-22 08:18:16'),(29,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"eingereicht\"}','{\"status\": \"entwurf\"}','Antrag zurückgezogen',NULL,'2026-04-22 08:20:10'),(30,NULL,NULL,NULL,NULL,'update','work_entries',1,'','{\"id\": 1, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 3, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:16:48\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:20:10\", \"category_id\": 5, \"description\": \"Gekocht\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:16:48\", \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','{\"id\": 1, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 4, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:16:48\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:20:29\", \"category_id\": 5, \"description\": \"Gekocht und gespült\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:16:48\", \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag bearbeitet',NULL,'2026-04-22 08:20:29'),(31,NULL,NULL,NULL,NULL,'update','work_entries',1,'','{\"id\": 1, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 4, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:16:48\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:20:29\", \"category_id\": 5, \"description\": \"Gekocht und gespült\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:16:48\", \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','{\"id\": 1, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 5, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:16:48\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:20:41\", \"category_id\": 5, \"description\": \"Gekocht und gespült\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:16:48\", \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag bearbeitet',NULL,'2026-04-22 08:20:41'),(32,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:20:45'),(33,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"eingereicht\"}','{\"status\": \"storniert\"}','Antrag storniert',NULL,'2026-04-22 08:21:51'),(34,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"storniert\"}','{\"status\": \"entwurf\"}','Stornierter Antrag reaktiviert',NULL,'2026-04-22 08:22:48'),(35,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:22:51'),(36,NULL,NULL,NULL,NULL,'status_change','work_entries',1,'','{\"status\": \"eingereicht\"}','{\"status\": \"abgelehnt\", \"rejection_reason\": \"Kein Sachgrund\"}','Antrag abgelehnt',NULL,'2026-04-22 08:24:41'),(37,NULL,NULL,NULL,NULL,'create','work_entries',4,'2026-00001',NULL,'{\"id\": 4, \"hours\": \"0.50\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"23:50:00\", \"user_id\": 3, \"version\": 1, \"time_from\": \"23:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:33:04\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:33:04\", \"category_id\": 1, \"description\": \"Rasen gemäht\", \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00001\", \"is_corrected\": 0, \"submitted_at\": null, \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag erstellt',NULL,'2026-04-22 08:33:04'),(38,NULL,NULL,NULL,NULL,'status_change','work_entries',4,'2026-00001','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:33:04'),(39,NULL,NULL,NULL,NULL,'create','work_entries',5,'2026-00002',NULL,'{\"id\": 5, \"hours\": \"0.50\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 1, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:33:43\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:33:43\", \"category_id\": 6, \"description\": null, \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00002\", \"is_corrected\": 0, \"submitted_at\": null, \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag erstellt',NULL,'2026-04-22 08:33:43'),(40,NULL,NULL,NULL,NULL,'status_change','work_entries',5,'2026-00002','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:33:48'),(41,NULL,NULL,NULL,NULL,'status_change','work_entries',5,'2026-00002','{\"status\": \"eingereicht\"}','{\"status\": \"in_klaerung\", \"return_reason\": \"Allles frisch?\"}','Antrag zur Klärung zurückgegeben',NULL,'2026-04-22 08:34:17'),(42,NULL,NULL,NULL,NULL,'status_change','work_entries',4,'2026-00001','{\"status\": \"eingereicht\"}','{\"status\": \"freigegeben\", \"reviewed_by_user_id\": 2}','Antrag freigegeben',NULL,'2026-04-22 08:35:03'),(43,NULL,NULL,NULL,NULL,'update','work_entries',4,'2026-00001','{\"hours\": 0.5, \"is_corrected\": false}','{\"hours\": 0.75, \"is_corrected\": true, \"correction_reason\": \"Mehr Arbeit akzeptiert\"}','Antrag korrigiert',NULL,'2026-04-22 08:40:04'),(44,2,NULL,'127.0.0.1',NULL,'login','users',2,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 08:49:38'),(45,NULL,NULL,NULL,NULL,'create','work_entries',6,'2026-00003',NULL,'{\"id\": 6, \"hours\": \"2.75\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"22:00:00\", \"user_id\": 2, \"version\": 1, \"time_from\": \"09:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:50:19\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 10:50:19\", \"category_id\": 2, \"description\": null, \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00003\", \"is_corrected\": 0, \"submitted_at\": null, \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 2, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag erstellt',NULL,'2026-04-22 08:50:19'),(46,NULL,NULL,NULL,NULL,'status_change','work_entries',6,'2026-00003','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 08:50:19'),(47,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 08:51:05'),(48,NULL,NULL,NULL,NULL,'logout','users',1,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:05:00'),(49,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: admin@musfeld.local (Benutzer nicht gefunden)',NULL,'2026-04-22 09:05:05'),(50,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:05:13'),(51,NULL,NULL,NULL,NULL,'logout','users',1,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:05:17'),(52,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:05:20'),(53,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:05:32'),(54,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:05:36'),(55,NULL,NULL,NULL,NULL,'logout','users',1,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:06:13'),(56,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:06:17'),(57,NULL,NULL,NULL,NULL,'dialog_message','work_entry_dialogs',5,'2026-00002',NULL,'{\"message\": \"Bitte noch einmal zurück zur Überarbeitung\"}','Dialog-Nachricht hinzugefügt',NULL,'2026-04-22 09:07:00'),(58,2,NULL,'::1',NULL,'login','users',2,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:19:51'),(59,NULL,NULL,NULL,NULL,'dialog_message','work_entry_dialogs',5,'2026-00002',NULL,'{\"message\": \"Ich gebe noch einmal zur Bearbeitung zurück\"}','Dialog-Nachricht hinzugefügt',NULL,'2026-04-22 09:20:23'),(60,NULL,NULL,NULL,NULL,'status_change','work_entries',5,'2026-00002','{\"status\": \"in_klaerung\"}','{\"status\": \"entwurf\", \"return_reason\": \"Wie besprochen\"}','Antrag zur Überarbeitung zurück an Mitglied',NULL,'2026-04-22 09:20:37'),(61,NULL,NULL,NULL,NULL,'update','work_entries',5,'2026-00002','{\"id\": 5, \"hours\": \"0.50\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 4, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:33:43\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 11:20:37\", \"category_id\": 6, \"description\": null, \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00002\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:33:48\", \"return_reason\": \"Wie besprochen\", \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','{\"id\": 5, \"hours\": \"0.50\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"11:00:00\", \"user_id\": 3, \"version\": 5, \"time_from\": \"10:00:00\", \"work_date\": \"2026-04-22\", \"created_at\": \"2026-04-22 10:33:43\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 11:21:45\", \"category_id\": 6, \"description\": null, \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00002\", \"is_corrected\": 0, \"submitted_at\": \"2026-04-22 10:33:48\", \"return_reason\": \"Wie besprochen\", \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag bearbeitet',NULL,'2026-04-22 09:21:45'),(62,NULL,NULL,NULL,NULL,'status_change','work_entries',5,'2026-00002','{\"status\": \"entwurf\"}','{\"status\": \"eingereicht\"}','Antrag eingereicht',NULL,'2026-04-22 09:26:28'),(63,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:42:41'),(64,NULL,NULL,NULL,NULL,'logout','users',2,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:43:45'),(65,NULL,NULL,'::1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@test.vaes (Benutzer nicht gefunden)',NULL,'2026-04-22 09:43:59'),(66,NULL,NULL,'::1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@test.vaes (Benutzer nicht gefunden)',NULL,'2026-04-22 09:44:07'),(67,NULL,NULL,'::1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@test.vaes (Benutzer nicht gefunden)',NULL,'2026-04-22 09:44:56'),(68,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 09:45:06'),(69,NULL,NULL,'::1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: mitglied@test.vaes (Benutzer nicht gefunden)',NULL,'2026-04-22 09:45:15'),(70,3,NULL,'::1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:45:36'),(71,3,NULL,'127.0.0.1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 09:45:41'),(72,NULL,NULL,NULL,NULL,'create','work_entries',7,'2026-00004',NULL,'{\"id\": 7, \"hours\": \"1.00\", \"origin\": \"manual\", \"status\": \"entwurf\", \"project\": null, \"time_to\": \"09:00:00\", \"user_id\": 3, \"version\": 1, \"time_from\": \"08:00:00\", \"work_date\": \"2026-04-21\", \"created_at\": \"2026-04-22 11:46:58\", \"deleted_at\": null, \"updated_at\": \"2026-04-22 11:46:58\", \"category_id\": 3, \"description\": null, \"reviewed_at\": null, \"corrected_at\": null, \"entry_number\": \"2026-00004\", \"is_corrected\": 0, \"submitted_at\": null, \"return_reason\": null, \"original_hours\": null, \"rejection_reason\": null, \"correction_reason\": null, \"created_by_user_id\": 3, \"reviewed_by_user_id\": null, \"corrected_by_user_id\": null, \"event_task_assignment_id\": null}','Arbeitsstunden-Eintrag erstellt',NULL,'2026-04-22 09:46:58'),(73,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 10:02:16'),(74,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 10:02:18'),(75,NULL,NULL,NULL,NULL,'create','users',8,NULL,NULL,'{\"ort\": null, \"plz\": null, \"email\": \"tina-test@vaes.test\", \"roles\": [\"mitglied\"], \"strasse\": null, \"telefon\": null, \"vorname\": \"Tina\", \"nachname\": \"Test\", \"eintrittsdatum\": null, \"mitgliedsnummer\": \"TINA-TEST\"}','Manuell angelegt: Tina Test (TINA-TEST)',NULL,'2026-04-22 10:03:42'),(76,NULL,NULL,NULL,NULL,'update','user_roles',8,NULL,'{\"roles\": [\"mitglied\"]}','{\"roles\": [\"mitglied\", \"pruefer\"]}','Rollen aktualisiert für Tina Test',NULL,'2026-04-22 10:03:52'),(77,NULL,NULL,NULL,NULL,'delete','users',8,NULL,NULL,NULL,'Benutzer deaktiviert: Tina Test',NULL,'2026-04-22 10:04:20'),(78,NULL,NULL,NULL,NULL,'create','users',9,NULL,NULL,'{\"ort\": null, \"plz\": null, \"email\": \"Test-Tina@vaes.de\", \"roles\": [\"mitglied\"], \"strasse\": null, \"telefon\": null, \"vorname\": \"Tina\", \"nachname\": \"Test\", \"eintrittsdatum\": null, \"mitgliedsnummer\": \"TEST-TINA\"}','Manuell angelegt: Tina Test (TEST-TINA)',NULL,'2026-04-22 10:23:23'),(79,NULL,NULL,NULL,NULL,'update','user_roles',9,NULL,'{\"roles\": [\"mitglied\"]}','{\"roles\": [\"mitglied\", \"pruefer\"]}','Rollen aktualisiert für Tina Test',NULL,'2026-04-22 10:23:28'),(80,NULL,NULL,NULL,NULL,'config_change','users',9,NULL,'{\"is_active\": true}','{\"is_active\": false}','Benutzer deaktiviert: Tina Test',NULL,'2026-04-22 10:23:34'),(81,NULL,NULL,NULL,NULL,'config_change','users',9,NULL,'{\"is_active\": false}','{\"is_active\": true}','Benutzer aktiviert: Tina Test',NULL,'2026-04-22 10:23:44'),(82,NULL,NULL,NULL,NULL,'create','categories',9,NULL,NULL,'{\"name\": \"Küchendienst\", \"color\": \"#ffff00\", \"sort_order\": 0, \"description\": \"Hilft in der Küche\"}','Kategorie erstellt: Küchendienst',NULL,'2026-04-22 10:25:13'),(83,3,NULL,'::1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 10:26:10'),(84,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 10:34:52'),(85,NULL,NULL,NULL,NULL,'config_change','settings',NULL,NULL,'{\"value\": \"true\"}','{\"value\": \"false\"}','Einstellung geändert: event_module_enabled',NULL,'2026-04-22 10:48:06'),(86,NULL,NULL,NULL,NULL,'config_change','settings',NULL,NULL,'{\"value\": \"false\"}','{\"value\": \"true\"}','Einstellung geändert: target_hours_enabled',NULL,'2026-04-22 10:48:06'),(87,NULL,NULL,NULL,NULL,'config_change','settings',NULL,NULL,'{\"value\": null}','{\"value\": \"\"}','Einstellung geändert: vereinslogo_path',NULL,'2026-04-22 10:48:06'),(88,NULL,NULL,NULL,NULL,'config_change','settings',NULL,NULL,'{\"value\": \"true\"}','{\"value\": \"false\"}','Einstellung geändert: target_hours_enabled',NULL,'2026-04-22 10:48:32'),(89,NULL,NULL,NULL,NULL,'export','work_entries',NULL,NULL,NULL,NULL,'Report-Export: pdf, 4 Einträge','{\"format\": \"pdf\", \"filters\": {\"status\": null, \"date_to\": null, \"project\": null, \"date_from\": null, \"member_id\": null, \"category_id\": null}, \"role_scope\": \"all_including_deleted\", \"report_type\": \"work_entries\", \"result_count\": 4}','2026-04-22 10:51:01'),(90,NULL,NULL,NULL,NULL,'export','work_entries',NULL,NULL,NULL,NULL,'Report-Export: csv, 4 Einträge','{\"format\": \"csv\", \"filters\": {\"status\": null, \"date_to\": null, \"project\": null, \"date_from\": null, \"member_id\": null, \"category_id\": null}, \"role_scope\": \"all_including_deleted\", \"report_type\": \"work_entries\", \"result_count\": 4}','2026-04-22 10:51:14'),(91,NULL,NULL,NULL,NULL,'update','events',2,NULL,'{\"end_at\": \"2026-04-19 14:00:00\", \"start_at\": \"2026-04-19 10:00:00\"}','{\"end_at\": \"2026-04-24T14:00\", \"start_at\": \"2026-04-24T10:00\"}','Event aktualisiert','{\"fields_changed\": [\"start_at\", \"end_at\"], \"organizer_added\": [], \"organizer_removed\": []}','2026-04-22 10:57:51'),(92,NULL,NULL,NULL,NULL,'update','events',2,NULL,'{\"end_at\": \"2026-04-24 14:00:00\", \"start_at\": \"2026-04-24 10:00:00\"}','{\"end_at\": \"2026-04-24T14:00\", \"start_at\": \"2026-04-24T10:00\"}','Event aktualisiert','{\"fields_changed\": [\"start_at\", \"end_at\"], \"organizer_added\": [], \"organizer_removed\": []}','2026-04-22 10:58:09'),(93,NULL,NULL,NULL,NULL,'create','event_tasks',2,NULL,NULL,'{\"title\": \"Thekendienst\", \"event_id\": 2}','Task \'Thekendienst\' zu Event #2','{\"task_type\": \"aufgabe\"}','2026-04-22 10:59:33'),(94,NULL,NULL,NULL,NULL,'update','events',2,NULL,'{\"end_at\": \"2026-04-24 14:00:00\", \"start_at\": \"2026-04-24 10:00:00\"}','{\"end_at\": \"2026-04-24T14:00\", \"start_at\": \"2026-04-24T10:00\"}','Event aktualisiert','{\"fields_changed\": [\"start_at\", \"end_at\"], \"organizer_added\": [], \"organizer_removed\": []}','2026-04-22 10:59:58'),(95,1,NULL,'127.0.0.1',NULL,'login','users',1,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 11:33:07'),(96,NULL,NULL,NULL,NULL,'update','events',2,NULL,'{\"end_at\": \"2026-04-24 14:00:00\", \"start_at\": \"2026-04-24 10:00:00\"}','{\"end_at\": \"2026-04-24T14:00\", \"start_at\": \"2026-04-24T10:00\"}','Event aktualisiert','{\"fields_changed\": [\"start_at\", \"end_at\"], \"organizer_added\": [2], \"organizer_removed\": []}','2026-04-22 11:33:54'),(97,NULL,NULL,NULL,NULL,'status_change','events',2,NULL,'{\"status\": \"entwurf\"}','{\"status\": \"veroeffentlicht\"}','Event veroeffentlicht: Smoke-Event 607158',NULL,'2026-04-22 11:33:58'),(98,3,NULL,'::1',NULL,'login','users',3,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 11:34:17'),(99,NULL,NULL,NULL,NULL,'create','event_task_assignments',1,NULL,NULL,'{\"status\": \"bestaetigt\", \"task_id\": 1}','Aufgabe \'Smoke-Task 607158\' uebernommen (fix)','{\"event_id\": 2, \"slot_mode\": \"fix\", \"hours_default\": 2.5}','2026-04-22 11:37:06'),(100,NULL,NULL,NULL,NULL,'create','event_task_assignments',2,NULL,NULL,'{\"status\": \"bestaetigt\", \"task_id\": 2}','Aufgabe \'Thekendienst\' uebernommen (fix)','{\"event_id\": 2, \"slot_mode\": \"fix\", \"hours_default\": 0}','2026-04-22 11:37:34'),(101,NULL,NULL,NULL,NULL,'logout','users',1,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 11:38:25'),(102,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: pruefer@vaes.test (Falsches Passwort (Versuch 1))',NULL,'2026-04-22 11:39:01'),(103,NULL,NULL,'127.0.0.1',NULL,'login_failed','users',NULL,NULL,NULL,NULL,'Fehlgeschlagener Login für: pruefer@vaes.test (Falsches Passwort (Versuch 2))',NULL,'2026-04-22 11:39:16'),(104,NULL,NULL,NULL,NULL,'logout','users',3,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 11:39:32'),(105,2,NULL,'::1',NULL,'login','users',2,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 11:39:39'),(106,NULL,NULL,NULL,NULL,'logout','users',2,NULL,NULL,NULL,'Logout',NULL,'2026-04-22 11:40:34'),(107,2,NULL,'::1',NULL,'login','users',2,NULL,NULL,NULL,'Erfolgreicher Login',NULL,'2026-04-22 11:46:40');
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `audit_log_no_update` BEFORE UPDATE ON `audit_log` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit-Log darf nicht verändert werden.';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `audit_log_no_delete` BEFORE DELETE ON `audit_log` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Audit-Log darf nicht gelöscht werden.';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0d6efd' COMMENT 'Hex-Farbcode #RRGGBB fuer Kalender-Darstellung',
  `sort_order` int unsigned DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `is_contribution` tinyint(1) DEFAULT '0' COMMENT 'Beigaben-Kategorie (Kuchen/Salat/Sachspende) - 0 Stunden-Charakter',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_categories_active` (`is_active`),
  KEY `idx_categories_sort` (`sort_order`),
  KEY `idx_categories_deleted_at` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Rasenpflege','Mähen, Vertikutieren, Düngen','#0d6efd',10,1,0,'2026-04-20 21:08:09','2026-04-20 21:08:09',NULL),(2,'Gebäudepflege','Reinigung, kleine Reparaturen','#0d6efd',20,1,0,'2026-04-20 21:08:09','2026-04-20 21:08:09',NULL),(3,'Veranstaltungen','Auf- und Abbau, Bewirtung','#0d6efd',30,1,0,'2026-04-20 21:08:09','2026-04-20 21:08:09',NULL),(4,'Verwaltung','Büroarbeiten, Organisation','#0d6efd',40,1,0,'2026-04-20 21:08:09','2026-04-20 21:08:09',NULL),(5,'Sonstiges','Nicht kategorisierte Tätigkeiten','#0d6efd',100,1,0,'2026-04-20 21:08:09','2026-04-20 21:08:09',NULL),(6,'Beigabe: Kuchen','Selbstgebackener Kuchen fuer Vereinsfeste','#0d6efd',200,1,1,'2026-04-22 08:16:33','2026-04-22 08:16:33',NULL),(7,'Beigabe: Salat','Salat, Beilagen fuer Buffet','#0d6efd',210,1,1,'2026-04-22 08:16:33','2026-04-22 08:16:33',NULL),(8,'Beigabe: Sachspende','Nicht-materielle Sachspende (z.B. Getraenke, Dekoration)','#0d6efd',220,1,1,'2026-04-22 08:16:33','2026-04-22 08:16:33',NULL),(9,'Küchendienst','Hilft in der Küche','#ffff00',0,1,0,'2026-04-22 10:25:13','2026-04-22 10:25:13',NULL);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dialog_read_status`
--

DROP TABLE IF EXISTS `dialog_read_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dialog_read_status` (
  `user_id` int unsigned NOT NULL,
  `work_entry_id` int unsigned NOT NULL,
  `last_read_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`work_entry_id`),
  KEY `fk_dialog_read_entry` (`work_entry_id`),
  CONSTRAINT `fk_dialog_read_entry` FOREIGN KEY (`work_entry_id`) REFERENCES `work_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dialog_read_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dialog_read_status`
--

LOCK TABLES `dialog_read_status` WRITE;
/*!40000 ALTER TABLE `dialog_read_status` DISABLE KEYS */;
INSERT INTO `dialog_read_status` VALUES (2,4,'2026-04-22 08:40:04'),(2,5,'2026-04-22 09:20:23'),(3,4,'2026-04-22 08:40:40'),(3,5,'2026-04-22 09:26:28'),(3,7,'2026-04-22 10:01:43');
/*!40000 ALTER TABLE `dialog_read_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_verification_codes`
--

DROP TABLE IF EXISTS `email_verification_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_verification_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` enum('login','password_reset','email_verify') COLLATE utf8mb4_unicode_ci DEFAULT 'login',
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_codes_user` (`user_id`),
  KEY `idx_email_codes_expires` (`expires_at`),
  CONSTRAINT `fk_email_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_verification_codes`
--

LOCK TABLES `email_verification_codes` WRITE;
/*!40000 ALTER TABLE `email_verification_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_verification_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entry_locks`
--

DROP TABLE IF EXISTS `entry_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `entry_locks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `work_entry_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `session_id` int unsigned DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_entry_lock` (`work_entry_id`),
  KEY `idx_entry_locks_expires` (`expires_at`),
  KEY `fk_entry_locks_user` (`user_id`),
  KEY `fk_entry_locks_session` (`session_id`),
  CONSTRAINT `fk_entry_locks_entry` FOREIGN KEY (`work_entry_id`) REFERENCES `work_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_entry_locks_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_entry_locks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entry_locks`
--

LOCK TABLES `entry_locks` WRITE;
/*!40000 ALTER TABLE `entry_locks` DISABLE KEYS */;
INSERT INTO `entry_locks` VALUES (7,7,3,16,'2026-04-22 10:15:15','2026-04-22 10:20:15');
/*!40000 ALTER TABLE `entry_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entry_number_sequence`
--

DROP TABLE IF EXISTS `entry_number_sequence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `entry_number_sequence` (
  `year` year NOT NULL,
  `last_number` int unsigned DEFAULT '0',
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entry_number_sequence`
--

LOCK TABLES `entry_number_sequence` WRITE;
/*!40000 ALTER TABLE `entry_number_sequence` DISABLE KEYS */;
INSERT INTO `entry_number_sequence` VALUES (2026,4);
/*!40000 ALTER TABLE `entry_number_sequence` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_organizers`
--

DROP TABLE IF EXISTS `event_organizers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_organizers` (
  `event_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`event_id`,`user_id`),
  KEY `fk_eo_assigned_by` (`assigned_by`),
  KEY `idx_eo_user` (`user_id`),
  CONSTRAINT `fk_eo_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eo_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eo_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_organizers`
--

LOCK TABLES `event_organizers` WRITE;
/*!40000 ALTER TABLE `event_organizers` DISABLE KEYS */;
INSERT INTO `event_organizers` VALUES (2,2,'2026-04-22 11:33:54',1);
/*!40000 ALTER TABLE `event_organizers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_task_assignments`
--

DROP TABLE IF EXISTS `event_task_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_task_assignments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `status` enum('vorgeschlagen','bestaetigt','storno_angefragt','storniert','abgeschlossen') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vorgeschlagen',
  `proposed_start` datetime DEFAULT NULL COMMENT 'Nur bei slot_mode=variabel',
  `proposed_end` datetime DEFAULT NULL,
  `actual_hours` decimal(6,2) DEFAULT NULL COMMENT 'Ueberschreibt task.hours_default bei Abschluss',
  `replacement_suggested_user_id` int unsigned DEFAULT NULL COMMENT 'Ersatzvorschlag bei Storno',
  `work_entry_id` int unsigned DEFAULT NULL COMMENT 'Nach I3: Referenz auf auto-generierten work_entry',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` int unsigned NOT NULL DEFAULT '1' COMMENT 'Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_eta_replacement` (`replacement_suggested_user_id`),
  KEY `fk_eta_work_entry` (`work_entry_id`),
  KEY `fk_eta_deleted_by` (`deleted_by`),
  KEY `idx_eta_task` (`task_id`),
  KEY `idx_eta_user` (`user_id`),
  KEY `idx_eta_status` (`status`),
  KEY `idx_eta_deleted` (`deleted_at`),
  CONSTRAINT `fk_eta_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eta_replacement` FOREIGN KEY (`replacement_suggested_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eta_task` FOREIGN KEY (`task_id`) REFERENCES `event_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_eta_work_entry` FOREIGN KEY (`work_entry_id`) REFERENCES `work_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_eta_proposed` CHECK (((`proposed_start` is null) or (`proposed_end` is null) or (`proposed_end` > `proposed_start`)))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_task_assignments`
--

LOCK TABLES `event_task_assignments` WRITE;
/*!40000 ALTER TABLE `event_task_assignments` DISABLE KEYS */;
INSERT INTO `event_task_assignments` VALUES (1,1,1,'bestaetigt',NULL,NULL,NULL,NULL,NULL,'2026-04-22 11:37:06','2026-04-22 11:37:06',1,NULL,NULL),(2,2,3,'bestaetigt',NULL,NULL,NULL,NULL,NULL,'2026-04-22 11:37:34','2026-04-22 11:37:34',1,NULL,NULL);
/*!40000 ALTER TABLE `event_task_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_tasks`
--

DROP TABLE IF EXISTS `event_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int unsigned NOT NULL,
  `parent_task_id` int unsigned DEFAULT NULL COMMENT 'Self-FK fuer hierarchischen Aufgabenbaum (Adjacency List). NULL = Top-Level.',
  `is_group` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Gruppenknoten (kein Helferbedarf, keine Zuweisungen, darf Kinder haben). 0 = Leaf.',
  `category_id` int unsigned DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `task_type` enum('aufgabe','beigabe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aufgabe',
  `slot_mode` enum('fix','variabel') COLLATE utf8mb4_unicode_ci DEFAULT 'fix' COMMENT 'NULL bei Gruppenknoten (is_group=1), sonst Slot-Modus des Leafs',
  `start_at` datetime DEFAULT NULL COMMENT 'NULL wenn slot_mode=variabel',
  `end_at` datetime DEFAULT NULL,
  `capacity_mode` enum('unbegrenzt','ziel','maximum') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unbegrenzt',
  `capacity_target` int unsigned DEFAULT NULL COMMENT 'Pflicht bei ziel/maximum',
  `hours_default` decimal(6,2) NOT NULL DEFAULT '0.00',
  `sort_order` int unsigned DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` int unsigned NOT NULL DEFAULT '1' COMMENT 'Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_et_category` (`category_id`),
  KEY `fk_et_deleted_by` (`deleted_by`),
  KEY `idx_et_event` (`event_id`),
  KEY `idx_et_deleted` (`deleted_at`),
  KEY `idx_et_sort` (`event_id`,`sort_order`),
  KEY `idx_et_parent_sort` (`parent_task_id`,`sort_order`),
  CONSTRAINT `fk_et_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_et_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_et_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_et_parent` FOREIGN KEY (`parent_task_id`) REFERENCES `event_tasks` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_et_capacity` CHECK ((((`capacity_mode` = _utf8mb4'unbegrenzt') and (`capacity_target` is null)) or ((`capacity_mode` in (_utf8mb4'ziel',_utf8mb4'maximum')) and (`capacity_target` is not null) and (`capacity_target` > 0)))),
  CONSTRAINT `chk_et_fix_times` CHECK (((`is_group` = 1) or (`slot_mode` = _utf8mb4'variabel') or ((`slot_mode` = _utf8mb4'fix') and (`start_at` is not null) and (`end_at` is not null) and (`end_at` > `start_at`)))),
  CONSTRAINT `chk_et_group_shape` CHECK (((`is_group` = 0) or ((`slot_mode` is null) and (`capacity_mode` = _utf8mb4'unbegrenzt') and (`capacity_target` is null) and (`hours_default` = 0) and (`task_type` = _utf8mb4'aufgabe'))))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_tasks`
--

LOCK TABLES `event_tasks` WRITE;
/*!40000 ALTER TABLE `event_tasks` DISABLE KEYS */;
INSERT INTO `event_tasks` VALUES (1,2,NULL,0,NULL,'Smoke-Task 607158',NULL,'aufgabe','fix','2026-04-19 10:00:00','2026-04-19 14:00:00','unbegrenzt',NULL,2.50,0,'2026-04-18 20:36:49','2026-04-18 20:36:49',1,NULL,NULL),(2,2,NULL,0,3,'Thekendienst',NULL,'aufgabe','fix','2026-04-24 08:00:00','2026-04-24 12:00:00','unbegrenzt',NULL,0.00,0,'2026-04-22 10:59:33','2026-04-22 10:59:33',1,NULL,NULL);
/*!40000 ALTER TABLE `event_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_template_tasks`
--

DROP TABLE IF EXISTS `event_template_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_template_tasks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int unsigned NOT NULL,
  `parent_template_task_id` int unsigned DEFAULT NULL COMMENT 'Self-FK fuer Template-Baum (Adjacency List). NULL = Top-Level.',
  `is_group` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Gruppenknoten, 0 = Leaf',
  `category_id` int unsigned DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `task_type` enum('aufgabe','beigabe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aufgabe',
  `slot_mode` enum('fix','variabel') COLLATE utf8mb4_unicode_ci DEFAULT 'fix' COMMENT 'NULL bei Gruppenknoten',
  `default_offset_minutes_start` int DEFAULT NULL COMMENT 'Offset zu event.start_at in Minuten',
  `default_offset_minutes_end` int DEFAULT NULL,
  `capacity_mode` enum('unbegrenzt','ziel','maximum') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unbegrenzt',
  `capacity_target` int unsigned DEFAULT NULL,
  `hours_default` decimal(6,2) NOT NULL DEFAULT '0.00',
  `sort_order` int unsigned DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ett_category` (`category_id`),
  KEY `idx_ett_template` (`template_id`,`sort_order`),
  KEY `idx_ett_parent_sort` (`parent_template_task_id`,`sort_order`),
  CONSTRAINT `fk_ett_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ett_parent` FOREIGN KEY (`parent_template_task_id`) REFERENCES `event_template_tasks` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ett_template` FOREIGN KEY (`template_id`) REFERENCES `event_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ett_group_shape` CHECK (((`is_group` = 0) or ((`slot_mode` is null) and (`capacity_mode` = _utf8mb4'unbegrenzt') and (`capacity_target` is null) and (`hours_default` = 0) and (`task_type` = _utf8mb4'aufgabe') and (`default_offset_minutes_start` is null) and (`default_offset_minutes_end` is null))))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_template_tasks`
--

LOCK TABLES `event_template_tasks` WRITE;
/*!40000 ALTER TABLE `event_template_tasks` DISABLE KEYS */;
INSERT INTO `event_template_tasks` VALUES (1,2,NULL,0,NULL,'Smoke-Task 405377',NULL,'aufgabe','fix',NULL,NULL,'unbegrenzt',NULL,2.50,0,'2026-04-18 20:33:27'),(2,3,NULL,0,NULL,'Smoke-Task 607158',NULL,'aufgabe','fix',0,240,'unbegrenzt',NULL,2.50,0,'2026-04-18 20:36:48'),(3,4,NULL,0,NULL,'Smoke-Task 607158',NULL,'aufgabe','fix',0,240,'unbegrenzt',NULL,2.50,0,'2026-04-18 20:36:50');
/*!40000 ALTER TABLE `event_template_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_templates`
--

DROP TABLE IF EXISTS `event_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `version` int unsigned NOT NULL DEFAULT '1',
  `parent_template_id` int unsigned DEFAULT NULL COMMENT 'Vorgaenger-Version',
  `is_current` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = aktuelle Version',
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_etpl_created_by` (`created_by`),
  KEY `fk_etpl_deleted_by` (`deleted_by`),
  KEY `idx_etpl_current` (`is_current`,`name`),
  KEY `idx_etpl_deleted` (`deleted_at`),
  KEY `idx_etpl_parent` (`parent_template_id`),
  CONSTRAINT `fk_etpl_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_etpl_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_etpl_parent` FOREIGN KEY (`parent_template_id`) REFERENCES `event_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_templates`
--

LOCK TABLES `event_templates` WRITE;
/*!40000 ALTER TABLE `event_templates` DISABLE KEYS */;
INSERT INTO `event_templates` VALUES (1,'Smoke-Template 027128','Automatisch erstellt durch Playwright-Smoke-Test',1,NULL,1,5,'2026-04-18 20:27:07',NULL,NULL),(2,'Smoke-Template 405377','Playwright-Smoke-Test',1,NULL,1,5,'2026-04-18 20:33:26',NULL,NULL),(3,'Smoke-Template 607158','Playwright-Smoke-Test',1,NULL,0,5,'2026-04-18 20:36:47',NULL,NULL),(4,'Smoke-Template 607158','Playwright-Smoke-Test',2,3,1,5,'2026-04-18 20:36:50',NULL,NULL);
/*!40000 ALTER TABLE `event_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `location` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `status` enum('entwurf','veroeffentlicht','abgeschlossen','abgesagt') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'entwurf',
  `cancel_deadline_hours` int unsigned DEFAULT '24' COMMENT 'Vorlauf-Stunden bis eigenstaendiger Storno moeglich ist',
  `source_template_id` int unsigned DEFAULT NULL COMMENT 'Template, aus dem dieses Event abgeleitet wurde',
  `source_template_version` int unsigned DEFAULT NULL COMMENT 'Version-Snapshot zum Zeitpunkt der Ableitung',
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` int unsigned NOT NULL DEFAULT '1' COMMENT 'Optimistic-Locking-Counter (wird bei jedem Write inkrementiert)',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_events_created_by` (`created_by`),
  KEY `fk_events_deleted_by` (`deleted_by`),
  KEY `idx_events_status` (`status`),
  KEY `idx_events_start` (`start_at`),
  KEY `idx_events_deleted` (`deleted_at`),
  KEY `idx_events_source_template` (`source_template_id`),
  CONSTRAINT `fk_events_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_events_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_events_source_template` FOREIGN KEY (`source_template_id`) REFERENCES `event_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_events_timespan` CHECK ((`end_at` >= `start_at`))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (2,'Smoke-Event 607158',NULL,NULL,'2026-04-24 10:00:00','2026-04-24 14:00:00','veroeffentlicht',24,3,1,5,'2026-04-18 20:36:49','2026-04-22 11:33:58',6,NULL,NULL);
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_token` (`token`),
  KEY `fk_password_resets_user` (`user_id`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES (1,2,'e3a4d1c1b32da4f266d4bdd05008064bc66e8ef23040ec7226bbdbf75a22158d2cc1f8addc54326501559fc9dc04046a46a7a4e88bf5a798c7240c24fe0fc077','2026-04-22 08:50:22',NULL,'2026-04-22 07:50:22'),(2,2,'6dc5a9bf5e00d90000788aca4d7d59953aa1d4417c514e5434d2f9e68c24a02daf267e74e20ceb1e33f67f23c0a24a4840258633a359d7a05c998182b0076038','2026-04-22 08:50:48',NULL,'2026-04-22 07:50:48'),(3,3,'2518df49115f1ce48b81a2e4e064e1f7ca88e7d50024fb6e05ed8e10bc61eaa15f2a5b7a9a35ae781b8969e7a7a7a73f790cdc226e8b76a4269b91d017725266','2026-04-22 08:52:32',NULL,'2026-04-22 07:52:32'),(4,3,'74031bdcb2672f0a4bce1e580be98cfde057c2b7d944988fd4988a6e3d96fb51aa099ed276841d319a3fdf56fd62f99f6f3ebfb7cabc8567a07966a2dc19e202','2026-04-22 08:55:07','2026-04-22 07:55:41','2026-04-22 07:55:07');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rate_limits`
--

DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional: Empfaenger-Email fuer Email-basierten Bucket (Forgot-Password Anti-Flood)',
  `endpoint` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rate_limits_lookup` (`ip_address`,`endpoint`,`attempted_at`),
  KEY `idx_rate_limits_email_lookup` (`email`,`endpoint`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rate_limits`
--

LOCK TABLES `rate_limits` WRITE;
/*!40000 ALTER TABLE `rate_limits` DISABLE KEYS */;
INSERT INTO `rate_limits` VALUES (37,'127.0.0.1',NULL,'login','2026-04-22 11:33:07'),(38,'::1',NULL,'login','2026-04-22 11:34:17'),(39,'127.0.0.1',NULL,'login','2026-04-22 11:39:01'),(40,'127.0.0.1',NULL,'login','2026-04-22 11:39:16'),(41,'::1',NULL,'login','2026-04-22 11:39:39'),(42,'::1',NULL,'login','2026-04-22 11:46:40');
/*!40000 ALTER TABLE `rate_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'mitglied','Reguläres Vereinsmitglied - kann eigene Arbeitsstunden erfassen','2026-04-20 21:08:09','2026-04-20 21:08:09'),(2,'erfasser','Kann Stunden für andere Mitglieder eintragen','2026-04-20 21:08:09','2026-04-20 21:08:09'),(3,'pruefer','Kann Anträge freigeben, ablehnen und Rückfragen stellen','2026-04-20 21:08:09','2026-04-20 21:08:09'),(4,'auditor','Lesender Zugriff auf alle Vorgänge inkl. gelöschter Daten','2026-04-20 21:08:09','2026-04-20 21:08:09'),(5,'administrator','Vollzugriff auf alle Funktionen','2026-04-20 21:08:09','2026-04-20 21:08:09'),(6,'event_admin','Darf Events anlegen, Organisatoren zuweisen und Event-Templates verwalten','2026-04-22 08:16:33','2026-04-22 08:16:33');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scheduled_jobs`
--

DROP TABLE IF EXISTS `scheduled_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'z.B. event_reminder_24h, assignment_invite, dialog_reminder',
  `unique_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optionaler Dedup-Schluessel (z.B. event:42:remind24h)',
  `payload` json DEFAULT NULL COMMENT 'Job-spezifische Daten (z.B. event_id, user_id)',
  `run_at` datetime NOT NULL COMMENT 'Frueheste Ausfuehrungszeit (serverlokal)',
  `status` enum('pending','running','done','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `max_attempts` int unsigned NOT NULL DEFAULT '3',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sj_key` (`unique_key`),
  KEY `idx_sj_due` (`status`,`run_at`),
  KEY `idx_sj_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scheduled_jobs`
--

LOCK TABLES `scheduled_jobs` WRITE;
/*!40000 ALTER TABLE `scheduled_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `scheduled_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scheduler_runs`
--

DROP TABLE IF EXISTS `scheduler_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduler_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trigger_source` enum('external','request','manual') COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `jobs_processed` int unsigned NOT NULL DEFAULT '0',
  `jobs_failed` int unsigned NOT NULL DEFAULT '0',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nur bei trigger_source=external gesetzt',
  PRIMARY KEY (`id`),
  KEY `idx_sr_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scheduler_runs`
--

LOCK TABLES `scheduler_runs` WRITE;
/*!40000 ALTER TABLE `scheduler_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `scheduler_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_sessions_token` (`token`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_expires_at` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES (8,2,'d45ee0ba73dcfbed9d570520154060fc8a38c18a9aff9844dfa10692d89d486dfbcfadc18de0f667f425946eadf1a7fcf54976c4c985a3a99f512df206b65a7a','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-04-22 09:18:46','2026-04-22 09:48:46','2026-04-22 08:49:38'),(13,3,'0405c9a8536410e941a621904d1dbdba2bb7877745b9d4aa286312197668c70a0f280764bfc46ac586982ae7219dc8210f6cf43a50a44e0ef0714761fc14f718','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0','2026-04-22 09:35:43','2026-04-22 10:05:43','2026-04-22 09:06:17'),(16,3,'d87e177cb564574a769a9e3e11c0ab5c3223a25e7feae0bef3f8f27ca50bef299ffc7e8d3c2207e3c532d2daef75b5d72bd0e745aa67053addb4a7acd5e31e76','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-04-22 10:15:15','2026-04-22 10:45:15','2026-04-22 09:45:36'),(18,1,'4940c9adc67bff81ccae04778a5f5a9569c923a7e03b17c9ab09ba4f3279544c83792ae70f567ff236ae84da029ac421f091e4062f8f411b638af740ac910c7c','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0','2026-04-22 10:32:18','2026-04-22 11:02:18','2026-04-22 10:02:18'),(19,3,'dd87f60853ab1a4b4d3e49a8a8ddf3f45b84317789c3401989b5abf2f86ad75896d7776e6887c1bd171bf2390df137923885b4c54fe5e26f44e082907a411fd4','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-04-22 10:55:38','2026-04-22 11:25:38','2026-04-22 10:26:10'),(20,1,'db82c2dd736672730026e38ced1f790dfcd6a9ee6ed4c480469999ee54ee914bbe28fe354751aa3cc9169d7c8516855f94436d7767be425939b8ec7710066014','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/149.0','2026-04-22 11:03:58','2026-04-22 11:33:58','2026-04-22 10:34:52'),(24,2,'a3dae843bc34f25a1b949e0f9b786789307edadccd42e6221111a3022ecb2c10f2e9e5c4aa097ea153ed9c8c1f28eab14bbd232be9dcdc5f86a3192a7d0873fb','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0','2026-04-22 11:59:51','2026-04-22 12:29:51','2026-04-22 11:46:40');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `setting_type` enum('string','integer','boolean','json') COLLATE utf8mb4_unicode_ci DEFAULT 'string',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT '0' COMMENT 'Darf im Frontend angezeigt werden?',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_settings_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'app_name','VAES','string','Name der Anwendung',1,'2026-04-20 21:08:09',NULL),(2,'app_version','1.1.0','string','Aktuelle Version der Anwendung',1,'2026-04-20 21:08:09',NULL),(3,'vereinsname','Mein Verein e.V.','string','Name des Vereins für Anzeige und Exporte',1,'2026-04-20 21:08:09',NULL),(4,'vereinslogo_path','','string','Pfad zum Vereinslogo für PDF-Exporte',0,'2026-04-22 10:48:06',1),(5,'session_timeout_minutes','30','integer','Session-Timeout in Minuten',0,'2026-04-20 21:08:09',NULL),(6,'max_login_attempts','5','integer','Maximale Fehlversuche vor Sperrung',0,'2026-04-20 21:08:09',NULL),(7,'lockout_duration_minutes','15','integer','Sperrdauer nach zu vielen Fehlversuchen',0,'2026-04-20 21:08:09',NULL),(8,'require_2fa','true','boolean','2FA für alle Benutzer verpflichtend',0,'2026-04-20 21:08:09',NULL),(9,'reminder_days','7','integer','Tage bis zur Erinnerungs-E-Mail bei offenen Fragen',0,'2026-04-20 21:08:09',NULL),(10,'reminder_enabled','true','boolean','Erinnerungs-E-Mails aktiviert',0,'2026-04-20 21:08:09',NULL),(11,'target_hours_enabled','false','boolean','Soll-Stunden-Funktion aktiviert',0,'2026-04-22 10:48:32',1),(12,'target_hours_default','20','integer','Standard-Sollstunden pro Jahr',0,'2026-04-20 21:08:09',NULL),(13,'data_retention_years','10','integer','Aufbewahrungsfrist in Jahren',0,'2026-04-20 21:08:09',NULL),(14,'invitation_expiry_days','7','integer','Gültigkeit von Einladungslinks in Tagen',0,'2026-04-20 21:08:09',NULL),(15,'smtp_host','','string','SMTP-Server für E-Mail-Versand',0,'2026-04-20 21:08:09',NULL),(16,'smtp_port','587','integer','SMTP-Port',0,'2026-04-20 21:08:09',NULL),(17,'smtp_username','','string','SMTP-Benutzername',0,'2026-04-20 21:08:09',NULL),(18,'smtp_password','','string','SMTP-Passwort (verschlüsselt)',0,'2026-04-20 21:08:09',NULL),(19,'smtp_encryption','tls','string','SMTP-Verschlüsselung (tls/ssl)',0,'2026-04-20 21:08:09',NULL),(20,'email_from_address','noreply@example.com','string','Absender-E-Mail-Adresse',0,'2026-04-20 21:08:09',NULL),(21,'email_from_name','VAES System','string','Absender-Name',0,'2026-04-20 21:08:09',NULL),(22,'field_datum_required','required','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(23,'field_zeit_von_required','optional','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(24,'field_zeit_bis_required','optional','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(25,'field_stunden_required','required','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(26,'field_kategorie_required','required','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(27,'field_projekt_required','optional','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(28,'field_beschreibung_required','optional','string','Pflichtfeld-Status: required/optional/hidden',0,'2026-04-20 21:08:09',NULL),(29,'lock_timeout_minutes','5','integer','Timeout für Bearbeitungssperren in Minuten',0,'2026-04-20 21:08:09',NULL),(30,'event_module_enabled','false','boolean','Events & Helferplanung-Modul aktiviert',0,'2026-04-22 10:48:06',1),(31,'notifications_enabled','false','boolean','Feature-Flag: E-Mail-Benachrichtigungen aktiv',0,'2026-04-22 08:16:33',NULL),(32,'cron_external_token_hash',NULL,'string','SHA-256-Hash des externen Cron-Pinger-Tokens (leer = deaktiviert)',0,'2026-04-22 08:16:33',NULL),(33,'cron_min_interval_seconds','300','integer','Minimales Intervall zwischen Scheduler-Laeufen in Sekunden',0,'2026-04-22 08:16:33',NULL),(34,'cron_last_run_at',NULL,'string','Zeitstempel des letzten erfolgreichen Scheduler-Laufs',0,'2026-04-22 08:16:33',NULL),(36,'events.tree_editor_enabled','0','boolean','Aufgabenbaum-Editor freigeschaltet (Modul 6 I7). 0 = aus, 1 = an.',0,'2026-04-22 15:12:00',NULL),(37,'events.tree_max_depth','4','integer','Maximale Tiefe des Aufgabenbaums (Service-enforced, ohne Wurzel-Event).',0,'2026-04-22 15:12:00',NULL);
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_invitations`
--

DROP TABLE IF EXISTS `user_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_invitations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_invitations_token` (`token`),
  KEY `idx_invitations_user` (`user_id`),
  KEY `fk_invitations_created_by` (`created_by`),
  CONSTRAINT `fk_invitations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invitations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_invitations`
--

LOCK TABLES `user_invitations` WRITE;
/*!40000 ALTER TABLE `user_invitations` DISABLE KEYS */;
INSERT INTO `user_invitations` VALUES (1,8,'beaf1da2633ad194215d904b44d00ca5944ead5e07c182a019b5a47cfa6fd25883c5f5cd7f7a19ad3a47112e5b800e2875c3c84bd7005fc357b2f54630abe580','2026-04-29 10:03:42',NULL,'2026-04-22 10:03:42','2026-04-22 10:03:42',1),(2,9,'cc35d85d0f9f7a7f1a741a10f7a32b6c79a1571e36d0bf28ae1017cb3968ca0a46f6cf69bd59774f73887351ef973fba2c00a21ea4655979f70149ad4f32cb9e','2026-04-29 10:23:23',NULL,'2026-04-22 10:23:23','2026-04-22 10:23:23',1);
/*!40000 ALTER TABLE `user_invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  KEY `fk_user_roles_role` (`role_id`),
  KEY `fk_user_roles_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (6,1,5,'2026-04-21 20:00:32',NULL),(7,1,1,'2026-04-21 20:00:32',NULL),(8,2,3,'2026-04-21 20:00:32',NULL),(9,2,1,'2026-04-21 20:00:32',NULL),(10,3,1,'2026-04-21 20:00:32',NULL),(11,1,6,'2026-04-22 08:16:33',NULL),(13,8,1,'2026-04-22 10:03:52',1),(14,8,3,'2026-04-22 10:03:52',1),(16,9,1,'2026-04-22 10:23:28',1),(17,9,3,'2026-04-22 10:23:28',1);
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mitgliedsnummer` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vorname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nachname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `strasse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plz` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ort` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eintrittsdatum` date DEFAULT NULL,
  `totp_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `totp_enabled` tinyint(1) DEFAULT '0',
  `email_2fa_enabled` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `failed_login_attempts` int unsigned DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `ical_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hex-Token (bin2hex(random_bytes(32))) fuer /ical/subscribe/{token}',
  PRIMARY KEY (`id`),
  UNIQUE KEY `mitgliedsnummer` (`mitgliedsnummer`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uniq_users_ical_token` (`ical_token`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_mitgliedsnummer` (`mitgliedsnummer`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_nachname` (`nachname`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'TEST-ADMIN','admin@vaes.test','$2y$12$VhYP1EIP03zK7oIZXDnHWuHFDtvyII1fOhhI9aYux9WdF2oAvD1jG','Test','Administrator',NULL,NULL,NULL,NULL,'2020-01-01',NULL,0,0,1,'2026-04-21 19:59:11','2026-04-21 19:59:11','2026-04-22 11:33:07',0,NULL,'2026-04-21 19:59:11','2026-04-22 11:33:07',NULL,NULL),(2,'TEST-PRUEFER','pruefer@vaes.test','$2y$12$VhYP1EIP03zK7oIZXDnHWuHFDtvyII1fOhhI9aYux9WdF2oAvD1jG','Test','Pruefer',NULL,NULL,NULL,NULL,'2020-01-01',NULL,0,0,1,'2026-04-21 19:59:11','2026-04-21 19:59:11','2026-04-22 11:46:40',0,NULL,'2026-04-21 19:59:11','2026-04-22 11:46:40',NULL,NULL),(3,'TEST-MITGLIED','mitglied@vaes.test','$2y$12$xxRpYOO3rHB2FCmMcZUpQ.2N19eNn9rJ//NY5tvhnfmFjHDuuCtmG','Test','Mitglied',NULL,NULL,NULL,NULL,'2020-01-01',NULL,0,0,1,'2026-04-21 19:59:11','2026-04-22 07:55:41','2026-04-22 11:34:17',0,NULL,'2026-04-21 19:59:11','2026-04-22 11:34:17',NULL,NULL),(7,'SYSTEM','system@vaes.internal',NULL,'System','Automat',NULL,NULL,NULL,NULL,'2020-01-01',NULL,0,0,0,NULL,NULL,NULL,0,NULL,'2026-04-22 08:16:33','2026-04-22 08:16:33',NULL,NULL),(8,'TINA-TEST','tina-test@vaes.test',NULL,'Tina','Test',NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,NULL,NULL,NULL,0,NULL,'2026-04-22 10:03:42','2026-04-22 10:04:20','2026-04-22 10:04:20',NULL),(9,'TEST-TINA','Test-Tina@vaes.de',NULL,'Tina','Test',NULL,NULL,NULL,NULL,NULL,NULL,0,0,1,NULL,NULL,NULL,0,NULL,'2026-04-22 10:23:23','2026-04-22 10:23:44',NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `v_entries_with_open_questions`
--

DROP TABLE IF EXISTS `v_entries_with_open_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_entries_with_open_questions` (
  `id` int unsigned DEFAULT NULL,
  `entry_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `created_by_user_id` int unsigned DEFAULT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `work_date` date DEFAULT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `hours` decimal(5,2) DEFAULT NULL,
  `project` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('entwurf','eingereicht','in_klaerung','freigegeben','abgelehnt','storniert') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_user_id` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `return_reason` text COLLATE utf8mb4_unicode_ci,
  `is_corrected` tinyint(1) DEFAULT NULL,
  `corrected_by_user_id` int unsigned DEFAULT NULL,
  `corrected_at` timestamp NULL DEFAULT NULL,
  `correction_reason` text COLLATE utf8mb4_unicode_ci,
  `original_hours` decimal(5,2) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `version` int unsigned DEFAULT NULL,
  `vorname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nachname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `open_questions_count` bigint DEFAULT NULL,
  `last_dialog_at` timestamp NULL DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `v_entries_with_open_questions`
--

LOCK TABLES `v_entries_with_open_questions` WRITE;
/*!40000 ALTER TABLE `v_entries_with_open_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_entries_with_open_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `v_target_comparison`
--

DROP TABLE IF EXISTS `v_target_comparison`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_target_comparison` (
  `user_id` int unsigned DEFAULT NULL,
  `mitgliedsnummer` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vorname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nachname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vollname` varchar(201) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` int DEFAULT NULL,
  `target_hours` decimal(5,2) DEFAULT NULL,
  `is_exempt` int DEFAULT NULL,
  `actual_hours` decimal(27,2) DEFAULT NULL,
  `remaining_hours` decimal(28,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `v_target_comparison`
--

LOCK TABLES `v_target_comparison` WRITE;
/*!40000 ALTER TABLE `v_target_comparison` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_target_comparison` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `v_users_with_roles`
--

DROP TABLE IF EXISTS `v_users_with_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `v_users_with_roles` (
  `id` int unsigned DEFAULT NULL,
  `mitgliedsnummer` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vorname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nachname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vollname` varchar(201) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `rollen` text COLLATE utf8mb4_unicode_ci
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `v_users_with_roles`
--

LOCK TABLES `v_users_with_roles` WRITE;
/*!40000 ALTER TABLE `v_users_with_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_users_with_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `work_entries`
--

DROP TABLE IF EXISTS `work_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `entry_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT 'Mitglied, für das die Stunden erfasst werden',
  `created_by_user_id` int unsigned NOT NULL COMMENT 'Benutzer, der den Eintrag erstellt hat',
  `category_id` int unsigned DEFAULT NULL,
  `work_date` date NOT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `hours` decimal(5,2) NOT NULL,
  `project` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('entwurf','eingereicht','in_klaerung','freigegeben','abgelehnt','storniert') COLLATE utf8mb4_unicode_ci DEFAULT 'entwurf',
  `origin` enum('manual','event','correction') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'Herkunft des Antrags: manual=regulaer, event=auto-generiert, correction=nachtraegliche Korrektur',
  `reviewed_by_user_id` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `return_reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Begründung bei Zurück zur Überarbeitung',
  `is_corrected` tinyint(1) DEFAULT '0',
  `corrected_by_user_id` int unsigned DEFAULT NULL,
  `corrected_at` timestamp NULL DEFAULT NULL,
  `correction_reason` text COLLATE utf8mb4_unicode_ci,
  `event_task_assignment_id` int unsigned DEFAULT NULL COMMENT 'Verknuepfung zur Event-Task-Zusage, falls origin=event',
  `original_hours` decimal(5,2) DEFAULT NULL COMMENT 'Ursprüngliche Stundenzahl vor Korrektur',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `version` int unsigned DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_number` (`entry_number`),
  KEY `idx_work_entries_user` (`user_id`),
  KEY `idx_work_entries_status` (`status`),
  KEY `idx_work_entries_date` (`work_date`),
  KEY `idx_work_entries_category` (`category_id`),
  KEY `idx_work_entries_created_by` (`created_by_user_id`),
  KEY `idx_work_entries_deleted_at` (`deleted_at`),
  KEY `idx_work_entries_entry_number` (`entry_number`),
  KEY `fk_work_entries_reviewed_by` (`reviewed_by_user_id`),
  KEY `fk_work_entries_corrected_by` (`corrected_by_user_id`),
  KEY `idx_work_entries_origin` (`origin`),
  KEY `idx_work_entries_eta` (`event_task_assignment_id`),
  CONSTRAINT `fk_work_entries_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_work_entries_corrected_by` FOREIGN KEY (`corrected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_work_entries_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_work_entries_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_work_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_entries`
--

LOCK TABLES `work_entries` WRITE;
/*!40000 ALTER TABLE `work_entries` DISABLE KEYS */;
INSERT INTO `work_entries` VALUES (4,'2026-00001',3,3,1,'2026-04-22','23:00:00','23:50:00',0.75,NULL,'Rasen gemäht','freigegeben','manual',2,'2026-04-22 08:35:03',NULL,NULL,1,2,'2026-04-22 08:40:04','Mehr Arbeit akzeptiert',NULL,0.50,'2026-04-22 08:33:04','2026-04-22 08:33:04','2026-04-22 08:40:04',NULL,4),(5,'2026-00002',3,3,6,'2026-04-22','10:00:00','11:00:00',0.50,NULL,NULL,'eingereicht','manual',NULL,NULL,NULL,'Wie besprochen',0,NULL,NULL,NULL,NULL,NULL,'2026-04-22 09:26:28','2026-04-22 08:33:43','2026-04-22 09:26:28',NULL,6),(6,'2026-00003',2,2,2,'2026-04-22','09:00:00','22:00:00',2.75,NULL,NULL,'eingereicht','manual',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2026-04-22 08:50:19','2026-04-22 08:50:19','2026-04-22 08:50:19',NULL,2),(7,'2026-00004',3,3,3,'2026-04-21','08:00:00','09:00:00',1.00,NULL,NULL,'entwurf','manual',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-22 09:46:58','2026-04-22 09:46:58',NULL,1);
/*!40000 ALTER TABLE `work_entries` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `before_work_entry_insert` BEFORE INSERT ON `work_entries` FOR EACH ROW BEGIN
    DECLARE new_number VARCHAR(20);

    IF NEW.entry_number IS NULL OR NEW.entry_number = '' THEN
        CALL generate_entry_number(new_number);
        SET NEW.entry_number = new_number;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `work_entry_dialogs`
--

DROP TABLE IF EXISTS `work_entry_dialogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_entry_dialogs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `work_entry_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_question` tinyint(1) DEFAULT '0' COMMENT 'Markiert Nachrichten als Frage vom Prüfer',
  `is_answered` tinyint(1) DEFAULT '0' COMMENT 'Wurde die Frage beantwortet?',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dialogs_entry` (`work_entry_id`),
  KEY `idx_dialogs_user` (`user_id`),
  KEY `idx_dialogs_created` (`created_at`),
  KEY `idx_dialogs_unanswered` (`work_entry_id`,`is_question`,`is_answered`),
  CONSTRAINT `fk_dialogs_entry` FOREIGN KEY (`work_entry_id`) REFERENCES `work_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dialogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_entry_dialogs`
--

LOCK TABLES `work_entry_dialogs` WRITE;
/*!40000 ALTER TABLE `work_entry_dialogs` DISABLE KEYS */;
INSERT INTO `work_entry_dialogs` VALUES (3,5,2,'Allles frisch?',1,1,'2026-04-22 08:34:17'),(4,5,3,'Bitte noch einmal zurück zur Überarbeitung',0,0,'2026-04-22 09:07:00'),(5,5,2,'Ich gebe noch einmal zur Bearbeitung zurück',0,0,'2026-04-22 09:20:23'),(6,5,2,'Wie besprochen',1,0,'2026-04-22 09:20:37');
/*!40000 ALTER TABLE `work_entry_dialogs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `yearly_targets`
--

DROP TABLE IF EXISTS `yearly_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `yearly_targets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `year` year NOT NULL,
  `target_hours` decimal(5,2) NOT NULL DEFAULT '0.00',
  `is_exempt` tinyint(1) DEFAULT '0' COMMENT 'Befreit von Sollstunden (z.B. Ehrenmitglieder)',
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_year` (`user_id`,`year`),
  CONSTRAINT `fk_yearly_targets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `yearly_targets`
--

LOCK TABLES `yearly_targets` WRITE;
/*!40000 ALTER TABLE `yearly_targets` DISABLE KEYS */;
/*!40000 ALTER TABLE `yearly_targets` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-22 17:22:10
