<?php
/**
 * =====================================================================
 *  YAPILANDIRMA DOSYASI
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

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

define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', cy_env('DB_NAME') ?: 'cy_bulk');
define('DB_USER', cy_env('DB_USER') ?: 'root');
define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

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
