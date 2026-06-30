-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 30, 2026 at 04:53 PM
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
-- Database: `ivoo_gestion_infraestructura`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_modules`
--

CREATE TABLE `app_modules` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `route` varchar(120) NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `app_modules`
--

INSERT INTO `app_modules` (`id`, `code`, `name`, `route`, `is_public`, `description`, `created_at`) VALUES
(1, 'PRESIDENCIA', 'Presidencia', '/presidencia', 0, 'Dashboard ejecutivo, indicadores y trazabilidad general.', '2026-06-29 19:51:30'),
(2, 'INFRAESTRUCTURA', 'Infraestructura / Mantenimiento', '/infraestructura', 0, 'Registro de solicitudes de obra y materiales requeridos.', '2026-06-29 19:51:30'),
(3, 'CIERRE_DE_OBRA', 'Cierre de Obra', '/cierre-obra', 0, 'Revision tecnica, planos, calculos y certificacion final.', '2026-06-29 19:51:30'),
(4, 'PROCURA', 'Procura', '/procura', 0, 'Aprobacion de inversion y adjudicacion de contratistas.', '2026-06-29 19:51:30'),
(5, 'ANALISTA', 'Analistas', '/analistas', 0, 'Carga de propuestas y cuadro comparativo.', '2026-06-29 19:51:30'),
(6, 'FINANZAS', 'Finanzas', '/finanzas', 0, 'Pago de anticipos y liquidaciones finales.', '2026-06-29 19:51:30'),
(7, 'CATALOGOS', 'Proveedores registrados', '/catalogos', 0, 'Listado interno de proveedores recibidos desde el portal publico.', '2026-06-29 19:51:30'),
(8, 'REGISTRO_PROVEEDORES', 'Registro publico de proveedores', '/registro-proveedores', 1, 'Pagina publica sin sidebar para alta de proveedores.', '2026-06-29 19:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` varchar(40) NOT NULL,
  `project_id` varchar(40) NOT NULL,
  `project_title_snapshot` varchar(220) NOT NULL,
  `role` enum('PRESIDENCIA','INFRAESTRUCTURA','CIERRE_DE_OBRA','PROCURA','ANALISTA','FINANZAS','SISTEMA') NOT NULL,
  `action` varchar(180) NOT NULL,
  `logged_at` datetime NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `project_id`, `project_title_snapshot`, `role`, `action`, `logged_at`, `details`, `created_at`) VALUES
('LOG-101', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'INFRAESTRUCTURA', 'Creacion de peticion de obra', '2026-05-10 09:30:00', 'Se generaron requerimientos de concreto y herreria para la zona de despacho.', '2026-06-29 19:51:30'),
('LOG-102', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', '2026-05-12 11:15:00', 'Calculos estructurales corregidos y aprobados. 4 planos cargados al servidor.', '2026-06-29 19:51:30'),
('LOG-103', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'PROCURA', 'Confirmacion de presupuesto y envio a licitacion', '2026-05-14 14:00:00', 'Monto aprobado de $4,365. Peticion transferida a los analistas de licitacion.', '2026-06-29 19:51:30'),
('LOG-104', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'ANALISTA', 'Carga de cuadro comparativo', '2026-05-16 10:45:00', 'Propuesta de Constructora Andes C.A. cargada con un anticipo pactado del 30%.', '2026-06-29 19:51:30'),
('LOG-105', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'PROCURA', 'Confirmacion de contratacion', '2026-05-17 16:30:00', 'Contratista Constructora Andes C.A. asignada bajo codigo CON-301.', '2026-06-29 19:51:30'),
('LOG-106', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'FINANZAS', 'Liberacion de anticipo del 30%', '2026-05-18 09:00:00', 'Liberado anticipo de $2,280 para inicio de obras civiles.', '2026-06-29 19:51:30'),
('LOG-107', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra', '2026-06-12 15:20:00', 'Trabajo culminado satisfactoriamente bajo estandares de resistencia de concreto.', '2026-06-29 19:51:30'),
('LOG-108', 'PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'FINANZAS', 'Liberacion total de fondos', '2026-06-14 10:10:00', 'Pago final de $5,320 liberado. Obra cerrada presupuestariamente.', '2026-06-29 19:51:30'),
('LOG-20260630132118287', 'PRJ-001', 'Optimizacion de Planta de Enfriamiento Sede Norte', 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', '2026-06-30 13:21:18', 'Revisión técnica registrada desde la app mobile.', '2026-06-30 17:21:18'),
('LOG-20260630132119347', 'PRJ-001', 'Optimizacion de Planta de Enfriamiento Sede Norte', 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', '2026-06-30 13:21:19', 'Revisión técnica registrada desde la app mobile.', '2026-06-30 17:21:19'),
('LOG-20260630132127207', 'PRJ-002', 'Mantenimiento General y Pintura de Fachada IVOO', 'PROCURA', 'Confirmacion de contratacion', '2026-06-30 13:21:27', 'Contratista CON-303 adjudicado.', '2026-06-30 17:21:27'),
('LOG-20260630132131505', 'PRJ-001', 'Optimizacion de Planta de Enfriamiento Sede Norte', 'PROCURA', 'Confirmacion de presupuesto y envio a licitacion', '2026-06-30 13:21:31', 'Inversión aprobada desde mobile.', '2026-06-30 17:21:31'),
('LOG-20260630134835507', 'PRJ-004', 'Arreglo de techo ivoo caracas', 'INFRAESTRUCTURA', 'Creacion de peticion de obra', '2026-06-30 13:48:35', 'Peticion registrada desde el modulo de infraestructura.', '2026-06-30 17:48:35'),
('LOG-20260630134916408', 'PRJ-004', 'Arreglo de techo ivoo caracas', 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', '2026-06-30 13:49:16', 'Revisión técnica registrada desde la app mobile.', '2026-06-30 17:49:16');

-- --------------------------------------------------------

--
-- Table structure for table `contractors`
--

CREATE TABLE `contractors` (
  `code` varchar(30) NOT NULL,
  `name` varchar(180) NOT NULL,
  `specialty` varchar(180) NOT NULL,
  `rating` decimal(3,1) NOT NULL DEFAULT 4.0,
  `contact` varchar(180) NOT NULL,
  `registration_source` enum('SEED','PUBLIC_PORTAL','INTERNAL') NOT NULL DEFAULT 'PUBLIC_PORTAL',
  `status` enum('PENDING_REVIEW','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `contractors`
--

INSERT INTO `contractors` (`code`, `name`, `specialty`, `rating`, `contact`, `registration_source`, `status`, `created_at`, `updated_at`) VALUES
('CON-301', 'Constructora Andes C.A.', 'Obras Civiles y Estructuras', 4.8, 'contacto@constandes.com', 'SEED', 'ACTIVE', '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
('CON-302', 'Sistemas Electricos Voltio, S.A.', 'Alta Tension e Iluminacion', 4.5, 'proyectos@voltiosa.com', 'SEED', 'ACTIVE', '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
('CON-303', 'Mantenimiento Integral Express', 'Pintura, Drywall y Acabados', 4.2, 'gerencia@mantexpress.net', 'SEED', 'ACTIVE', '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
('CON-304', 'Tuberias y Soldaduras Occidente', 'Sistemas de Enfriamiento e Hidraulicos', 4.7, 'ventas@tuboccidente.com', 'SEED', 'ACTIVE', '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
('CON-305', 'Soluciones de Climatizacion Termo-Control', 'Aire Acondicionado y Ventilacion', 4.6, 'soporte@termocontrol.ve', 'SEED', 'ACTIVE', '2026-06-29 19:51:30', '2026-06-29 19:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `material_catalog`
--

CREATE TABLE `material_catalog` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(180) NOT NULL,
  `unit` varchar(80) NOT NULL,
  `estimated_unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `material_catalog`
--

INSERT INTO `material_catalog` (`id`, `name`, `unit`, `estimated_unit_price`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Cemento Portland (Saco 42.5kg)', 'Saco', 12.50, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(2, 'Acero de Refuerzo 1/2 pulgada', 'Cabilla', 18.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(3, 'Bloque de Arcilla de 15cm', 'Millar', 450.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(4, 'Arena Lavada para Concreto', 'm3', 35.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(5, 'Piedra Picada para Mezcla', 'm3', 40.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(6, 'Cable de Cobre THHN #10 AWG', 'Rollo (100m)', 110.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(7, 'Lampara LED Industrial 150W', 'Unidad', 55.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(8, 'Pintura de Caucho Profesional (Cunete)', 'Cunete', 85.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(9, 'Tubo de PVC de Agua 3 pulgadas', 'Tubo (6m)', 22.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
(10, 'Tablero Electrico Principal de 24 Circuitos', 'Unidad', 320.00, 1, '2026-06-29 19:51:30', '2026-06-29 19:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_resets_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(7, 'App\\Models\\User', 1, 'ivoo-infraestructura', '712a32794c0e76ab0de357aec4503e10ef4081a8247816af08bbd8392d7770e0', '[\"*\"]', '2026-06-30 18:36:26', NULL, '2026-06-30 17:57:09', '2026-06-30 18:36:26');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` varchar(40) NOT NULL,
  `title` varchar(220) NOT NULL,
  `type` enum('INFRAESTRUCTURA','MANTENIMIENTO') NOT NULL,
  `description` text NOT NULL,
  `location` varchar(180) NOT NULL,
  `created_date` date NOT NULL,
  `status` enum('CREADO','REVISADO_CIERRE','CONFIRMADO_PROCURA','COMPARATIVA_ENVIADA','CONTRATADO','EN_EJECUCION','VERIFICANDO_FINALIZACION','LISTO_PAGO_FINAL','COMPLETADO_PAGADO') NOT NULL DEFAULT 'CREADO',
  `estimated_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cierre_obra_notes` text DEFAULT NULL,
  `calculations_added` tinyint(1) NOT NULL DEFAULT 0,
  `blueprints_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `procura_review_notes` text DEFAULT NULL,
  `approved_investment_amount` decimal(14,2) DEFAULT NULL,
  `selected_contractor_code` varchar(30) DEFAULT NULL,
  `selected_proposal_id` varchar(40) DEFAULT NULL,
  `quality_verified` tinyint(1) NOT NULL DEFAULT 0,
  `completion_verified_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `title`, `type`, `description`, `location`, `created_date`, `status`, `estimated_total`, `cierre_obra_notes`, `calculations_added`, `blueprints_count`, `procura_review_notes`, `approved_investment_amount`, `selected_contractor_code`, `selected_proposal_id`, `quality_verified`, `completion_verified_date`, `created_at`, `updated_at`) VALUES
('PRJ-001', 'Optimizacion de Planta de Enfriamiento Sede Norte', 'INFRAESTRUCTURA', 'Sustitucion de tuberias de refrigeracion oxidadas y optimizacion de bombas de agua helada para los chillers principales.', 'Sede Principal Norte', '2026-06-20', 'CONFIRMADO_PROCURA', 915.50, 'Revisión técnica registrada desde la app mobile.', 1, 1, 'Inversión aprobada desde mobile.', 915.50, NULL, NULL, 0, NULL, '2026-06-29 19:51:30', '2026-06-30 17:21:31'),
('PRJ-002', 'Mantenimiento General y Pintura de Fachada IVOO', 'MANTENIMIENTO', 'Reparacion de grietas superficiales en fachada externa y aplicacion de pintura de alta resistencia para intemperie.', 'Tienda IVOO Valencia', '2026-06-15', 'CONTRATADO', 1225.00, 'Se validaron los calculos de area de fachada (1200 m2). Requiere andamios de seguridad y equipo de arnes.', 1, 1, 'Monto estimado inicial de $1,225 aprobado para licitacion. Se solicita un anticipo no mayor al 40%.', 1225.00, 'CON-303', 'PROP-201', 0, NULL, '2026-06-29 19:51:30', '2026-06-30 17:21:27'),
('PRJ-003', 'Ampliacion de Galpon de Despacho Logistico', 'INFRAESTRUCTURA', 'Construccion de losa de concreto de 150m2 y estructura metalica techada para zona de carga express de mercancia.', 'Centro de Distribucion Central', '2026-05-10', 'COMPLETADO_PAGADO', 4365.00, 'Planos estructurales aprobados por ingenieria municipal. Calculos de resistencia de suelo verificados.', 1, 4, 'Proyecto estrategico para despacho de ventas e-commerce. Aprobado para licitacion de emergencia.', 4365.00, 'CON-301', 'PROP-301', 1, '2026-06-12', '2026-06-29 19:51:30', '2026-06-29 19:51:30'),
('PRJ-004', 'Arreglo de techo ivoo caracas', 'INFRAESTRUCTURA', 'Nuevo techo', 'Caracas', '2026-06-30', 'REVISADO_CIERRE', 2000.00, 'Revisión técnica registrada desde la app mobile.', 1, 1, NULL, NULL, NULL, NULL, 0, NULL, '2026-06-30 17:48:35', '2026-06-30 17:49:16');

-- --------------------------------------------------------

--
-- Table structure for table `project_materials`
--

CREATE TABLE `project_materials` (
  `id` varchar(40) NOT NULL,
  `project_id` varchar(40) NOT NULL,
  `material_catalog_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(80) NOT NULL,
  `estimated_unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_materials`
--

INSERT INTO `project_materials` (`id`, `project_id`, `material_catalog_id`, `name`, `quantity`, `unit`, `estimated_unit_price`, `created_at`) VALUES
('m1', 'PRJ-001', 9, 'Tubo de PVC de Agua 3 pulgadas', 24.00, 'Tubo (6m)', 22.00, '2026-06-29 19:51:30'),
('m10', 'PRJ-003', 3, 'Bloque de Arcilla de 15cm', 2.00, 'Millar', 450.00, '2026-06-29 19:51:30'),
('m2', 'PRJ-001', 5, 'Piedra Picada para Mezcla', 5.00, 'm3', 40.00, '2026-06-29 19:51:30'),
('m3', 'PRJ-001', 1, 'Cemento Portland (Saco 42.5kg)', 15.00, 'Saco', 12.50, '2026-06-29 19:51:30'),
('m4', 'PRJ-002', 8, 'Pintura de Caucho Profesional (Cunete)', 12.00, 'Cunete', 85.00, '2026-06-29 19:51:30'),
('m5', 'PRJ-002', 1, 'Cemento Portland (Saco 42.5kg)', 8.00, 'Saco', 12.50, '2026-06-29 19:51:30'),
('m6', 'PRJ-002', 4, 'Arena Lavada para Concreto', 3.00, 'm3', 35.00, '2026-06-29 19:51:30'),
('m7', 'PRJ-003', 1, 'Cemento Portland (Saco 42.5kg)', 120.00, 'Saco', 12.50, '2026-06-29 19:51:30'),
('m8', 'PRJ-003', 2, 'Acero de Refuerzo 1/2 pulgada', 80.00, 'Cabilla', 18.00, '2026-06-29 19:51:30'),
('m9', 'PRJ-003', 4, 'Arena Lavada para Concreto', 15.00, 'm3', 35.00, '2026-06-29 19:51:30'),
('PRJ-004-MAT-1', 'PRJ-004', NULL, 'Techo', 100.00, 'Unidad', 20.00, '2026-06-30 17:48:35');

-- --------------------------------------------------------

--
-- Table structure for table `project_payments`
--

CREATE TABLE `project_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` varchar(40) NOT NULL,
  `proposal_id` varchar(40) DEFAULT NULL,
  `payment_type` enum('ADVANCE','FINAL') NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_payments`
--

INSERT INTO `project_payments` (`id`, `project_id`, `proposal_id`, `payment_type`, `amount`, `paid_date`, `notes`, `created_at`) VALUES
(1, 'PRJ-003', 'PROP-301', 'ADVANCE', 2280.00, '2026-05-18', 'Anticipo del 30% para inicio de obras civiles.', '2026-06-29 19:51:30'),
(2, 'PRJ-003', 'PROP-301', 'FINAL', 5320.00, '2026-06-14', 'Pago final y cierre presupuestario.', '2026-06-29 19:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `project_proposals`
--

CREATE TABLE `project_proposals` (
  `id` varchar(40) NOT NULL,
  `project_id` varchar(40) NOT NULL,
  `contractor_code` varchar(30) NOT NULL,
  `contractor_name_snapshot` varchar(180) NOT NULL,
  `material_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `labor_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `delivery_weeks` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `negotiated_advance_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `project_proposals`
--

INSERT INTO `project_proposals` (`id`, `project_id`, `contractor_code`, `contractor_name_snapshot`, `material_cost`, `labor_cost`, `total_cost`, `delivery_weeks`, `negotiated_advance_percent`, `description`, `created_at`) VALUES
('PROP-201', 'PRJ-002', 'CON-303', 'Mantenimiento Integral Express', 1100.00, 1500.00, 2600.00, 2, 30.00, 'Trabajo completo de andamiaje, lavado previo a presion, sellado de fisuras y dos manos de pintura premium. 30% de anticipo negociado.', '2026-06-29 19:51:30'),
('PROP-202', 'PRJ-002', 'CON-301', 'Constructora Andes C.A.', 1200.00, 1800.00, 3000.00, 3, 40.00, 'Reparacion estructural menor con malla de fibra y pintura de intemperie con garantia de 5 anos. 40% de anticipo requerido.', '2026-06-29 19:51:30'),
('PROP-301', 'PRJ-003', 'CON-301', 'Constructora Andes C.A.', 4100.00, 3500.00, 7600.00, 4, 30.00, 'Construccion de losa con fibra de alta resistencia y herreria de columnas de soporte para techado de zinc.', '2026-06-29 19:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'Arcadio Arevalo', 'admin@ivoo.local', NULL, '$2y$10$9PmJ/FotdiGiPHF13iL64OsddXgyKxy/alxt6g3uRF71m4g3WFiXi', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_project_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_project_summary` (
`id` varchar(40)
,`title` varchar(220)
,`type` enum('INFRAESTRUCTURA','MANTENIMIENTO')
,`location` varchar(180)
,`created_date` date
,`status` enum('CREADO','REVISADO_CIERRE','CONFIRMADO_PROCURA','COMPARATIVA_ENVIADA','CONTRATADO','EN_EJECUCION','VERIFICANDO_FINALIZACION','LISTO_PAGO_FINAL','COMPLETADO_PAGADO')
,`estimated_total` decimal(14,2)
,`approved_investment_amount` decimal(14,2)
,`selected_contractor_code` varchar(30)
,`selected_contractor_name` varchar(180)
,`selected_proposal_id` varchar(40)
,`selected_total_cost` decimal(14,2)
,`paid_total` decimal(36,2)
,`quality_verified` tinyint(1)
,`completion_verified_date` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_registered_contractors`
-- (See below for the actual view)
--
CREATE TABLE `vw_registered_contractors` (
`code` varchar(30)
,`name` varchar(180)
,`specialty` varchar(180)
,`rating` decimal(3,1)
,`contact` varchar(180)
,`registration_source` enum('SEED','PUBLIC_PORTAL','INTERNAL')
,`status` enum('PENDING_REVIEW','ACTIVE','INACTIVE')
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `vw_project_summary`
--
DROP TABLE IF EXISTS `vw_project_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_project_summary`  AS SELECT `p`.`id` AS `id`, `p`.`title` AS `title`, `p`.`type` AS `type`, `p`.`location` AS `location`, `p`.`created_date` AS `created_date`, `p`.`status` AS `status`, `p`.`estimated_total` AS `estimated_total`, `p`.`approved_investment_amount` AS `approved_investment_amount`, `p`.`selected_contractor_code` AS `selected_contractor_code`, `c`.`name` AS `selected_contractor_name`, `p`.`selected_proposal_id` AS `selected_proposal_id`, `pp`.`total_cost` AS `selected_total_cost`, coalesce(sum(`pay`.`amount`),0) AS `paid_total`, `p`.`quality_verified` AS `quality_verified`, `p`.`completion_verified_date` AS `completion_verified_date` FROM (((`projects` `p` left join `contractors` `c` on(`c`.`code` = `p`.`selected_contractor_code`)) left join `project_proposals` `pp` on(`pp`.`id` = `p`.`selected_proposal_id`)) left join `project_payments` `pay` on(`pay`.`project_id` = `p`.`id`)) GROUP BY `p`.`id`, `p`.`title`, `p`.`type`, `p`.`location`, `p`.`created_date`, `p`.`status`, `p`.`estimated_total`, `p`.`approved_investment_amount`, `p`.`selected_contractor_code`, `c`.`name`, `p`.`selected_proposal_id`, `pp`.`total_cost`, `p`.`quality_verified`, `p`.`completion_verified_date` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_registered_contractors`
--
DROP TABLE IF EXISTS `vw_registered_contractors`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_registered_contractors`  AS SELECT `contractors`.`code` AS `code`, `contractors`.`name` AS `name`, `contractors`.`specialty` AS `specialty`, `contractors`.`rating` AS `rating`, `contractors`.`contact` AS `contact`, `contractors`.`registration_source` AS `registration_source`, `contractors`.`status` AS `status`, `contractors`.`created_at` AS `created_at` FROM `contractors` ORDER BY `contractors`.`created_at` DESC, `contractors`.`code` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_modules`
--
ALTER TABLE `app_modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_app_modules_code` (`code`),
  ADD UNIQUE KEY `uk_app_modules_route` (`route`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_logs_project` (`project_id`),
  ADD KEY `idx_audit_logs_role` (`role`),
  ADD KEY `idx_audit_logs_logged_at` (`logged_at`);

--
-- Indexes for table `contractors`
--
ALTER TABLE `contractors`
  ADD PRIMARY KEY (`code`),
  ADD KEY `idx_contractors_name` (`name`),
  ADD KEY `idx_contractors_specialty` (`specialty`),
  ADD KEY `idx_contractors_status` (`status`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `material_catalog`
--
ALTER TABLE `material_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_material_catalog_name_unit` (`name`,`unit`),
  ADD KEY `idx_material_catalog_active` (`is_active`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_projects_status` (`status`),
  ADD KEY `idx_projects_type` (`type`),
  ADD KEY `idx_projects_created_date` (`created_date`),
  ADD KEY `idx_projects_selected_contractor` (`selected_contractor_code`),
  ADD KEY `fk_projects_selected_proposal` (`selected_proposal_id`);

--
-- Indexes for table `project_materials`
--
ALTER TABLE `project_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_materials_project` (`project_id`),
  ADD KEY `idx_project_materials_catalog` (`material_catalog_id`);

--
-- Indexes for table `project_payments`
--
ALTER TABLE `project_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_project_payment_type` (`project_id`,`payment_type`),
  ADD KEY `idx_project_payments_proposal` (`proposal_id`);

--
-- Indexes for table `project_proposals`
--
ALTER TABLE `project_proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_proposals_project` (`project_id`),
  ADD KEY `idx_project_proposals_contractor` (`contractor_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_modules`
--
ALTER TABLE `app_modules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_catalog`
--
ALTER TABLE `material_catalog`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `project_payments`
--
ALTER TABLE `project_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_selected_contractor` FOREIGN KEY (`selected_contractor_code`) REFERENCES `contractors` (`code`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_projects_selected_proposal` FOREIGN KEY (`selected_proposal_id`) REFERENCES `project_proposals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `project_materials`
--
ALTER TABLE `project_materials`
  ADD CONSTRAINT `fk_project_materials_catalog` FOREIGN KEY (`material_catalog_id`) REFERENCES `material_catalog` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_project_materials_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `project_payments`
--
ALTER TABLE `project_payments`
  ADD CONSTRAINT `fk_project_payments_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_project_payments_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `project_proposals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `project_proposals`
--
ALTER TABLE `project_proposals`
  ADD CONSTRAINT `fk_project_proposals_contractor` FOREIGN KEY (`contractor_code`) REFERENCES `contractors` (`code`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_project_proposals_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
