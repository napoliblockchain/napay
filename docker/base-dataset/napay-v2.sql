-- MySQL dump 10.13  Distrib 8.4.13, for Linux (x86_64)
--
-- Host: localhost    Database: napay_v2
-- ------------------------------------------------------
-- Server version	8.4.13

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
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `timestamp` int NOT NULL,
  `user_id` int NOT NULL,
  `remote_address` varchar(20) NOT NULL,
  `browser` varchar(256) NOT NULL,
  `controller` varchar(60) NOT NULL,
  `action` varchar(60) NOT NULL,
  `description` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logs`
--

LOCK TABLES `logs` WRITE;
/*!40000 ALTER TABLE `logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `merchants`
--

DROP TABLE IF EXISTS `merchants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `merchants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `description` varchar(256) NOT NULL,
  `email` varchar(256) NOT NULL,
  `vatNumber` varchar(16) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `mobile` varchar(30) NOT NULL,
  `addressStreet` varchar(512) NOT NULL,
  `addressNumberHouse` varchar(20) NOT NULL,
  `addressCity` varchar(256) NOT NULL,
  `addressZip` varchar(20) NOT NULL,
  `addressProvince` varchar(10) NOT NULL,
  `addressCountry` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk-merchants-user_id` (`user_id`),
  CONSTRAINT `fk-merchants-user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `merchants`
--

LOCK TABLES `merchants` WRITE;
/*!40000 ALTER TABLE `merchants` DISABLE KEYS */;
/*!40000 ALTER TABLE `merchants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migration`
--

DROP TABLE IF EXISTS `migration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migration` (
  `version` varchar(180) NOT NULL,
  `apply_time` int DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migration`
--

LOCK TABLES `migration` WRITE;
/*!40000 ALTER TABLE `migration` DISABLE KEYS */;
INSERT INTO `migration` VALUES ('m000000_000000_base',1710758526),('m230315_103235_create_logs_table',1710758528),('m230921_155524_create_table_np_comuni_italiani',1710758528),('m231113_093319_create_privileges_table',1710758528),('m231113_093348_create_np_users_table',1710758528),('m240310_142112_create_merchants_table',1710758528),('m240315_142026_create_user_consensus_table',1710758528),('m240318_163449_create_store_table',1710780202),('m240318_163458_create_storesettings_table',1710780202),('m240318_163509_create_webhook_table',1710780202),('m240318_163521_create_settings_table',1710780202);
/*!40000 ALTER TABLE `migration` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `np_comuni_italiani`
--

DROP TABLE IF EXISTS `np_comuni_italiani`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `np_comuni_italiani` (
  `id` int NOT NULL AUTO_INCREMENT,
  `citta` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provincia` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sigla` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `np_comuni_italiani`
--

LOCK TABLES `np_comuni_italiani` WRITE;
/*!40000 ALTER TABLE `np_comuni_italiani` DISABLE KEYS */;
/*!40000 ALTER TABLE `np_comuni_italiani` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `privileges`
--

DROP TABLE IF EXISTS `privileges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `privileges` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(50) NOT NULL,
  `level` int NOT NULL,
  `codice_ruolo` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `privileges`
--

LOCK TABLES `privileges` WRITE;
/*!40000 ALTER TABLE `privileges` DISABLE KEYS */;
INSERT INTO `privileges` VALUES (1,'Administrator',50,'ROLE_ADMIN'),(2,'User',0,'ROLE_USER'),(3,'Merchant',30,'ROLE_MERCHANT');
/*!40000 ALTER TABLE `privileges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `description` varchar(256) NOT NULL,
  `code` varchar(256) NOT NULL,
  `value` varchar(2048) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stores`
--

DROP TABLE IF EXISTS `stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `merchant_id` int NOT NULL,
  `description` varchar(256) NOT NULL,
  `email` varchar(256) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `mobile` varchar(30) NOT NULL,
  `addressStreet` varchar(512) NOT NULL,
  `addressNumberHouse` varchar(20) NOT NULL,
  `addressCity` varchar(256) NOT NULL,
  `addressZip` varchar(20) NOT NULL,
  `addressProvince` varchar(10) NOT NULL,
  `addressCountry` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx-stores-merchant_id` (`merchant_id`),
  CONSTRAINT `fk-stores-merchant_id` FOREIGN KEY (`merchant_id`) REFERENCES `merchants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stores`
--

LOCK TABLES `stores` WRITE;
/*!40000 ALTER TABLE `stores` DISABLE KEYS */;
/*!40000 ALTER TABLE `stores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `storesettings`
--

DROP TABLE IF EXISTS `storesettings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `storesettings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `store_id` int NOT NULL,
  `bps_storeid` varchar(512) DEFAULT NULL,
  `website` varchar(256) DEFAULT NULL,
  `defaultCurrency` varchar(16) NOT NULL,
  `invoiceExpiration` int DEFAULT '900',
  `displayExpirationTimer` int DEFAULT '300',
  `monitoringExpiration` int DEFAULT '3600',
  `speedPolicy` varchar(256) DEFAULT NULL,
  `lightningDescriptionTemplate` varchar(512) DEFAULT NULL,
  `paymentTolerance` int DEFAULT '0',
  `anyoneCanCreateInvoice` tinyint(1) DEFAULT '0',
  `requiresRefundEmail` tinyint(1) DEFAULT '0',
  `checkoutType` varchar(16) DEFAULT 'V1',
  `receipt` json DEFAULT NULL,
  `lightningAmountInSatoshi` tinyint(1) DEFAULT '0',
  `lightningPrivateRouteHints` tinyint(1) DEFAULT '0',
  `onChainWithLnInvoiceFallback` tinyint(1) DEFAULT '0',
  `redirectAutomatically` tinyint(1) DEFAULT '0',
  `showRecommendedFee` tinyint(1) DEFAULT '1',
  `recommendedFeeBlockTarget` int DEFAULT '1',
  `defaultLang` varchar(16) DEFAULT 'it',
  `customLogo` varchar(512) DEFAULT NULL,
  `customCSS` varchar(512) DEFAULT NULL,
  `htmlTitle` varchar(512) DEFAULT NULL,
  `networkFeeMode` varchar(256) DEFAULT 'Always',
  `payJoinEnabled` tinyint(1) DEFAULT '0',
  `lazyPaymentMethods` tinyint(1) DEFAULT '0',
  `defaultPaymentMethod` varchar(256) DEFAULT 'BTC',
  `paymentMethodCriteria` json DEFAULT NULL,
  `spread` float DEFAULT '0',
  `preferredSource` varchar(512) DEFAULT NULL,
  `isCustomScript` tinyint(1) DEFAULT '0',
  `effectiveScript` varchar(512) DEFAULT NULL,
  `derivationScheme` varchar(512) DEFAULT NULL,
  `label` varchar(512) DEFAULT NULL,
  `accountKeyPath` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx-storesettings-store_id` (`store_id`),
  CONSTRAINT `fk-storesettings-store_id` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `storesettings`
--

LOCK TABLES `storesettings` WRITE;
/*!40000 ALTER TABLE `storesettings` DISABLE KEYS */;
/*!40000 ALTER TABLE `storesettings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_consensus`
--

DROP TABLE IF EXISTS `user_consensus`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_consensus` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `consenso_statuto` int NOT NULL DEFAULT '0',
  `consenso_privacy` int NOT NULL DEFAULT '0',
  `consenso_condizioni` int NOT NULL DEFAULT '0',
  `consenso_condizioni_pos` int DEFAULT NULL,
  `consenso_marketing` int NOT NULL DEFAULT '0',
  `timestamp_statuto` int NOT NULL,
  `timestamp_privacy` int NOT NULL,
  `timestamp_condizioni` int NOT NULL,
  `timestamp_condizioni_pos` int DEFAULT NULL,
  `timestamp_marketing` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk-user_consensus-user_id` (`user_id`),
  CONSTRAINT `fk-user_consensus-user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_consensus`
--

LOCK TABLES `user_consensus` WRITE;
/*!40000 ALTER TABLE `user_consensus` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_consensus` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `oauth_provider` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `oauth_uid` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `authKey` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accessToken` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `picture` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `privilege_id` int NOT NULL,
  `is_merchant` int DEFAULT '0',
  `is_active` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk-user-privilege_id` (`privilege_id`),
  CONSTRAINT `fk-user-privilege_id` FOREIGN KEY (`privilege_id`) REFERENCES `privileges` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `webhooks`
--

DROP TABLE IF EXISTS `webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhooks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `store_id` int NOT NULL,
  `bps_storeid` varchar(512) NOT NULL,
  `webhookId` varchar(512) NOT NULL,
  `url` varchar(512) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx-webhooks-store_id` (`store_id`),
  CONSTRAINT `fk-webhooks-store_id` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhooks`
--

LOCK TABLES `webhooks` WRITE;
/*!40000 ALTER TABLE `webhooks` DISABLE KEYS */;
/*!40000 ALTER TABLE `webhooks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'napay_v2'
--

--
-- Dumping routines for database 'napay_v2'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
