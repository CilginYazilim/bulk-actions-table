<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * =====================================================================
 */

declare(strict_types=1);

/* Doğrudan çağrılmaya karşı ikinci katman — bkz. system/.htaccess.
 * ÖLÇÜLEN SORUN: /system/config.php isteği HTTP 200 dönüyordu. Ekrana
 * bir şey basmıyordu ama her istekte gereksiz bir veritabanı bağlantısı
 * açıyordu; kimliği doğrulanmamış bir istekle tetiklenebilen her iş,
 * ucuz bir hizmet dışı bırakma (DoS) kaldıracıdır. */
if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'cy_bulk');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

define('APP_DEBUG', true); // Canlıya alırken MUTLAKA false yapın.

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/**
 * Durum seçenekleri: veritabanı değeri => ekranda görünen etiket + rozet rengi.
 * Form açılır listesi, rozetler ve doğrulama hep buradan beslenir.
 */
define('SUBSCRIBER_STATUSES', [
    'aktif'   => ['label' => 'Aktif',   'css' => 'success'],
    'pasif'   => ['label' => 'Pasif',   'css' => 'secondary'],
    'engelli' => ['label' => 'Engelli', 'css' => 'danger'],
]);

/**
 * TOPLU İŞLEMDE tek seferde en fazla kaç kayıt (id listesiyle)
 * işlenebilir. Sınırsız kabul etmek, binlerce id içeren bir isteğin
 * sunucuyu yormasına kapı aralar. Bunun ÜZERİNDEKİ seçimler
 * "scope=filtered" moduna geçmelidir (bkz. system/ajax.php) — id
 * listesi yerine FİLTRE KOŞULU gönderilir, sorgu WHERE id IN (...)
 * yerine WHERE <arama koşulu> çalışır.
 */
define('BULK_MAX_IDS', 500);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,

            /* rowCount() ARTIK "KAÇ KAYIT DEĞİŞTİ" DEĞİL, "KAÇ KAYIT
             * EŞLEŞTİ" DEMEK.
             *
             * MySQL varsayılanı UPDATE sonrası yalnızca DEĞERİ GERÇEKTEN
             * değişen satırları sayar. Toplu işlemde bu iki yerde
             * yanıltıcıdır:
             *   1) Mesaj: 8 kaydı "Aktif" yapan, 6'sı zaten aktif olan bir
             *      işlem "2 kaydın durumu güncellendi" der. Kullanıcı
             *      8 seçmişti; 2 sayısı hatalı bir işlem yapıldığı
             *      izlenimi verir.
             *   2) Güvenlik kontrolü: ajax.php'deki "etkilenen sayı,
             *      onaylanan sayıyla aynı mı?" denetimi (bkz.
             *      execute_bulk) varsayılan davranışla sürekli YANLIŞ
             *      alarm verir ve meşru işlemleri geri alırdı.
             * FOUND_ROWS ile rowCount() = WHERE'in eşleştirdiği satır
             * sayısı olur; iki sorun da kaynağında kapanır. */
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    /* Geliştirme modunda kurulum dosyasının ADI da yazılır: bu hatayı
     * alan kişinin ilk ihtiyacı "hangi dosyayı içe aktaracağım?"
     * bilgisidir. Canlıda ise tek kelime bile sızdırılmaz — hata
     * metinleri sunucu düzeni hakkında bilgi verir. */
    echo APP_DEBUG
        ? "Veritabanı bağlantı hatası: " . $e->getMessage()
            . "\n\nKurulum yapılmadıysa cy_bulk.sql dosyasını içe aktarın:\n"
            . "    mysql -u root -p < cy_bulk.sql"
        : 'Veritabanına bağlanılamadı. Lütfen daha sonra tekrar deneyin.';

    exit;
}
