-- ===============================================================
--  Çoklu Seçim ve Toplu İşlem Tablosu  |  Veritabanı Kurulum Dosyası
--  cilginyazilim.com
-- ---------------------------------------------------------------
--  KURULUM (iki yoldan biri):
--    1) Terminal :  mysql -u root -p < cy_bulk.sql
--    2) phpMyAdmin > İçe Aktar > Dosya seç > cy_bulk.sql > Başlat
--
--  DOSYA ADI = VERİTABANI ADI (cy_bulk). Marka kuralı: tüm
--  veritabanları "cy_" önekiyle adlandırılır ve kurulum dosyası
--  veritabanıyla aynı adı taşır — hangi dosyanın hangi veritabanını
--  kurduğu isme bakarak anlaşılır.
-- ===============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_bulk`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cy_bulk`;

DROP TABLE IF EXISTS `subscribers`;

-- ---------------------------------------------------------------
--  subscribers – Bülten aboneleri (örnek veri kümesi)
-- ---------------------------------------------------------------
--  Neden bülten aboneliği? Toplu işlemin en doğal olduğu senaryo:
--  "seçili 40 kişiyi pasif yap", "bu segmenti toplu sil" gibi
--  işlemler pazarlama araçlarında GÜNLÜK kullanılır. Basit bir
--  yapı olması, dersin asıl konusu olan ÇOKLU SEÇİM mekaniğinin
--  önüne geçmesini engeller.
-- ---------------------------------------------------------------
CREATE TABLE `subscribers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `segment`    VARCHAR(60)  NOT NULL DEFAULT 'Genel',

  -- 'aktif'   : bültenleri alır
  -- 'pasif'   : geçici olarak durdurulmuş
  -- 'engelli' : kalıcı olarak çıkarılmış (spam şikayeti vb.)
  `status`     ENUM('aktif','pasif','engelli') NOT NULL DEFAULT 'aktif',

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subscribers_email` (`email`),

  -- Durum filtresi ("Sadece engellileri göster" ve scope=filtered ile
  -- toplu işlem) bu indeksi kullanır. ÖLÇÜM (123.000 kayıt):
  -- status='engelli' sayımı 12 ms; indekssiz olsaydı tam tablo
  -- taraması gerekirdi (~175 ms).
  KEY `idx_subscribers_status` (`status`),
  KEY `idx_subscribers_segment` (`segment`),

  -- ---------------------------------------------------------------
  --  SIRALAMA İNDEKSLERİ — ölçümle eklendi
  -- ---------------------------------------------------------------
  --  Tablo başlığına tıklanınca sıralama sütunu değişir. 60 kayıtta
  --  hepsi anlıktır; 123.000 kayıtla ÖLÇÜLDÜĞÜNDE tablo şöyleydi:
  --    ORDER BY id    (PRIMARY var)      →     1 ms
  --    ORDER BY email (UNIQUE var)       →     1 ms
  --    ORDER BY name  (indeks YOK)       →   355 ms  ← "Using filesort"
  --    ORDER BY created_at (indeks YOK)  →   134 ms  ← "Using filesort"
  --  10 satır göstermek için MySQL 123.000 satırı okuyup sıralıyordu.
  --  Sıralanan sütunda indeks varsa LIMIT 10, indeksin ilk 10
  --  girdisini okumaya dönüşür.
  --
  --  NOT: Arama kutusu LIKE '%metin%' kullanır; baştan joker içeren
  --  bir kalıp HİÇBİR indeksten yararlanamaz (ölçüm: ~540 ms).
  --  Bunu çözmenin yolu indeks eklemek değil, FULLTEXT indekse veya
  --  ayrı bir arama motoruna geçmektir — örneğin basit tutmak için
  --  bilinçli olarak yapılmadı.
  KEY `idx_subscribers_name` (`name`),
  KEY `idx_subscribers_created_at` (`created_at`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
--  Örnek veriler (60 kayıt) — çoklu seçimi anlamlı kılacak kadar
--  kalabalık, ama sayfalamayı da göstermek için 10'dan fazla.
-- ---------------------------------------------------------------
INSERT INTO `subscribers` (`name`, `email`, `segment`, `status`, `created_at`) VALUES
('Evren ÇILGIN',    'evren.cilgin@ornek.com',    'Yazılım',    'aktif',   '2025-01-06 09:12:00'),
('Taha BAYAR',       'taha.bayar@ornek.com',      'Pazarlama',  'aktif',   '2025-01-07 10:20:00'),
('Zeynep TURAN',     'zeynep.turan@ornek.com',    'Genel',      'pasif',   '2025-01-08 11:05:00'),
('Mustafa YILMAZ',   'mustafa.yilmaz@ornek.com',  'Satış',      'aktif',   '2025-01-08 14:44:00'),
('Elif KAYA',        'elif.kaya@ornek.com',       'Genel',      'aktif',   '2025-01-09 08:47:00'),
('Ahmet DEMİR',      'ahmet.demir@ornek.com',     'Yazılım',    'engelli', '2025-01-11 11:49:00'),
('Ayşe ŞAHİN',       'ayse.sahin@ornek.com',      'Pazarlama',  'aktif',   '2025-01-12 21:17:00'),
('Mehmet ÇELİK',     'mehmet.celik@ornek.com',    'Satış',      'aktif',   '2025-01-14 04:24:00'),
('Fatma YILDIZ',     'fatma.yildiz@ornek.com',    'Genel',      'pasif',   '2025-01-15 23:58:00'),
('Emre YILDIRIM',    'emre.yildirim@ornek.com',   'Yazılım',    'aktif',   '2025-01-16 16:30:00'),
('Selin ÖZTÜRK',     'selin.ozturk@ornek.com',    'Tasarım',    'aktif',   '2025-01-17 17:06:00'),
('Burak AYDIN',      'burak.aydin@ornek.com',     'Yazılım',    'aktif',   '2025-01-17 18:08:00'),
('Merve ÖZDEMİR',    'merve.ozdemir@ornek.com',   'Pazarlama',  'aktif',   '2025-01-19 01:30:00'),
('Onur ARSLAN',      'onur.arslan@ornek.com',     'Satış',      'pasif',   '2025-01-19 20:00:00'),
('Ceren DOĞAN',      'ceren.dogan@ornek.com',     'Genel',      'aktif',   '2025-01-20 14:09:00'),
('Kaan KILIÇ',       'kaan.kilic@ornek.com',      'Yazılım',    'aktif',   '2025-01-20 22:05:00'),
('Büşra ASLAN',      'busra.aslan@ornek.com',     'Tasarım',    'aktif',   '2025-01-21 08:36:00'),
('Serkan ÇETİN',     'serkan.cetin@ornek.com',    'Genel',      'engelli', '2025-01-22 16:06:00'),
('Gizem KARA',       'gizem.kara@ornek.com',      'Pazarlama',  'aktif',   '2025-01-24 10:30:00'),
('Barış KOÇ',        'baris.koc@ornek.com',       'Yazılım',    'aktif',   '2025-01-25 07:19:00'),
('Deniz KURT',       'deniz.kurt@ornek.com',      'Satış',      'pasif',   '2025-01-26 01:28:00'),
('Hakan ÖZKAN',      'hakan.ozkan@ornek.com',     'Yazılım',    'aktif',   '2025-01-27 19:52:00'),
('İrem ŞİMŞEK',      'irem.simsek@ornek.com',     'Genel',      'aktif',   '2025-01-29 12:43:00'),
('Yusuf POLAT',      'yusuf.polat@ornek.com',     'Genel',      'aktif',   '2025-01-29 20:10:00'),
('Melis ÖZER',       'melis.ozer@ornek.com',      'Tasarım',    'aktif',   '2025-01-30 22:06:00'),
('Cem KORKMAZ',      'cem.korkmaz@ornek.com',     'Yazılım',    'aktif',   '2025-01-31 03:44:00'),
('Esra ÇAKIR',       'esra.cakir@ornek.com',      'Pazarlama',  'pasif',   '2025-01-31 18:25:00'),
('Volkan ERDOĞAN',   'volkan.erdogan@ornek.com',  'Satış',      'aktif',   '2025-02-01 08:14:00'),
('Şeyma GÜNEŞ',      'seyma.gunes@ornek.com',     'Genel',      'aktif',   '2025-02-01 14:27:00'),
('Uğur AKSOY',       'ugur.aksoy@ornek.com',      'Yazılım',    'aktif',   '2025-02-03 03:12:00'),
('Pınar BULUT',      'pinar.bulut@ornek.com',     'Tasarım',    'aktif',   '2025-02-04 20:02:00'),
('Tolga TAŞ',        'tolga.tas@ornek.com',       'Satış',      'engelli', '2025-02-04 21:02:00'),
('Nazlı KAPLAN',     'nazli.kaplan@ornek.com',    'Genel',      'aktif',   '2025-02-06 16:07:00'),
('Görkem SOYLU',     'gorkem.soylu@ornek.com',    'Yazılım',    'aktif',   '2025-02-08 01:23:00'),
('Damla ATEŞ',       'damla.ates@ornek.com',      'Pazarlama',  'pasif',   '2025-02-09 07:56:00'),
('Berk GÜLER',       'berk.guler@ornek.com',      'Genel',      'aktif',   '2025-02-10 02:16:00'),
('Sude BOZKURT',     'sude.bozkurt@ornek.com',    'Tasarım',    'aktif',   '2025-02-10 18:54:00'),
('Alper TEKİN',      'alper.tekin@ornek.com',     'Yazılım',    'aktif',   '2025-02-11 10:55:00'),
('Ebru ACAR',        'ebru.acar@ornek.com',       'Satış',      'aktif',   '2025-02-13 09:17:00'),
('Sinan BARAN',      'sinan.baran@ornek.com',     'Yazılım',    'pasif',   '2025-02-15 08:26:00'),
('Aslı SEZER',       'asli.sezer@ornek.com',      'Genel',      'aktif',   '2025-02-16 06:25:00'),
('Furkan KOCA',      'furkan.koca@ornek.com',     'Pazarlama',  'aktif',   '2025-02-17 21:37:00'),
('Nesrin UZUN',      'nesrin.uzun@ornek.com',     'Genel',      'aktif',   '2025-02-18 17:36:00'),
('Okan AVCI',        'okan.avci@ornek.com',       'Yazılım',    'engelli', '2025-02-19 06:17:00'),
('Tuğçe KESKİN',     'tugce.keskin@ornek.com',    'Tasarım',    'aktif',   '2025-02-20 05:21:00'),
('Murat ÜNAL',       'murat.unal@ornek.com',      'Satış',      'aktif',   '2025-02-21 08:10:00'),
('Yasemin GÜL',      'yasemin.gul@ornek.com',     'Genel',      'aktif',   '2025-02-22 02:55:00'),
('Halil DURMAZ',     'halil.durmaz@ornek.com',    'Genel',      'pasif',   '2025-02-22 18:23:00'),
('Beyza SARI',       'beyza.sari@ornek.com',      'Pazarlama',  'aktif',   '2025-02-23 10:36:00'),
('Ozan TOPAL',       'ozan.topal@ornek.com',      'Yazılım',    'aktif',   '2025-02-23 23:28:00'),
('Aylin ÇAĞLAR',     'aylin.caglar@ornek.com',    'Tasarım',    'aktif',   '2025-02-24 09:00:00'),
('Doğukan ARAS',     'dogukan.aras@ornek.com',    'Satış',      'aktif',   '2025-02-24 11:20:00'),
('Ezgi VURAL',       'ezgi.vural@ornek.com',      'Genel',      'aktif',   '2025-02-25 08:15:00'),
('Kerem BİLGİN',     'kerem.bilgin@ornek.com',    'Yazılım',    'aktif',   '2025-02-25 15:40:00'),
('Sevgi ORAL',       'sevgi.oral@ornek.com',      'Pazarlama',  'pasif',   '2025-02-26 10:10:00'),
('Emirhan ÇAKMAK',   'emirhan.cakmak@ornek.com',  'Genel',      'aktif',   '2025-02-26 19:33:00'),
('Nihan ERGÜN',      'nihan.ergun@ornek.com',     'Tasarım',    'aktif',   '2025-02-27 07:55:00'),
('Rıdvan KOÇAK',     'ridvan.kocak@ornek.com',    'Satış',      'aktif',   '2025-02-27 21:12:00'),
('Selim ÖZKAN',      'selim.ozkan@ornek.com',     'Yazılım',    'aktif',   '2025-02-28 09:44:00'),
('Yağmur ATAK',      'yagmur.atak@ornek.com',     'Genel',      'aktif',   '2025-02-28 16:05:00');
