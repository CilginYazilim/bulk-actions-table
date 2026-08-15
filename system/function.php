<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * =====================================================================
 */

declare(strict_types=1);

/* Bu dosya tek başına çağrılmak üzere yazılmamıştır; index.php ve
 * system/ajax.php onu include eder. Doğrudan çağrıldığında hiçbir şey
 * üretmez ama yine de kapatıyoruz: .htaccess'i desteklemeyen bir
 * sunucuda (nginx gibi) TEK savunma bu kontroldür. Bkz. system/.htaccess */
if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}


/* =====================================================================
 *  BÖLÜM 1 – ÇIKTI VE YANIT
 * ================================================================== */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success(string $description, array $extra = []): void
{
    json_response(array_merge(['success' => true, 'type' => 'success', 'description' => $description], $extra));
}

function json_error(string $description, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['success' => false, 'type' => 'danger', 'description' => $description], $extra), $status);
}


/* =====================================================================
 *  BÖLÜM 2 – CSRF KORUMASI
 * ================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($token) || $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {

        /* NEDEN 403, 419 DEĞİL?
         * 419 ("Page Expired") Laravel'in yaygınlaştırdığı, RESMÎ OLMAYAN
         * bir koddur. ÖLÇÜM: bu Apache kurulumunda 419 döndürüldüğünde
         * sunucu kodu sessizce 500'e çeviriyor — istemci "oturumun bitmiş,
         * sayfayı yenile" yerine "sunucu çöktü" görüyor ve gerçek bir hata
         * ile karıştırıyor. 403 standart, her sunucuda aynı geçiyor ve
         * anlamı zaten doğru: "kimliğini doğrulayamadım, reddediyorum". */
        json_error('Oturum doğrulaması başarısız. Lütfen sayfayı yenileyin.', 403);
    }
}


/* =====================================================================
 *  BÖLÜM 3 – DOĞRULAMA
 * ================================================================== */

function validate_name(?string $value): array
{
    $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

    if ($value === '') {
        return ['', 'Ad soyad boş bırakılamaz.'];
    }

    if (mb_strlen($value, 'UTF-8') > 150) {
        return [$value, 'Ad soyad en fazla 150 karakter olabilir.'];
    }

    return [$value, null];
}

function validate_email(?string $value): array
{
    $value = trim((string) $value);

    if ($value === '') {
        return ['', 'E-posta boş bırakılamaz.'];
    }

    $value = mb_strtolower($value, 'UTF-8');

    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        return [$value, 'Geçerli bir e-posta adresi giriniz.'];
    }

    return [$value, null];
}

function validate_status(?string $value): array
{
    $value = trim((string) $value);

    if (!array_key_exists($value, SUBSCRIBER_STATUSES)) {
        return ['aktif', 'Geçersiz durum değeri.'];
    }

    return [$value, null];
}


/* =====================================================================
 *  BÖLÜM 4 – VERİ ERİŞİMİ
 * ================================================================== */

function find_subscriber(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT id, name, email, segment, status, created_at FROM subscribers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * ARAMA + DURUM FİLTRESİNİN TEK DOĞRULUK KAYNAĞI.
 *
 * Ekrandaki listeyi (handle_list), sayfa altındaki "N kayıt" sayacını
 * (count_subscribers) ve "filtreye uyan TÜMÜ" toplu işlemini
 * (resolve_bulk_scope) besleyen WHERE koşulu BURADA, tek bir yerde
 * üretilir.
 *
 * NEDEN TEK YERDE? Bu koşul üç yerde ayrı ayrı yazılıydı ve üçü de
 * birbirinin kopyasıydı. Biri güncellenip diğeri unutulduğunda ortaya
 * çıkacak hata sessizdir ve YIKICIDIR: kullanıcı ekranda 12 kişi görür,
 * "seçili tümü" der, toplu işlem 15 kişiyi siler. Kopya kaldırmak
 * burada bir "temizlik" değil, doğrudan bir güvenlik önlemidir.
 *
 * @return array{0:string,1:array<string,mixed>} [WHERE gövdesi, İSİMLİ parametreler]
 */
function subscriber_filter(string $search = '', string $status = ''): array
{
    $search = trim($search);
    $status = trim($status);

    /* "1=1" ile başlamak, sonraki her koşulu koşulsuzca " AND ..."
     * diye ekleyebilmeyi sağlar; "ilk koşul mu, değil mi?" kontrolünü
     * tamamen ortadan kaldırır. Sorgu planlayıcı bunu yok sayar. */
    $where  = '1=1';
    $params = [];

    if ($search !== '') {
        /* Aramanın kapsadığı sütunlar. LIKE '%…%' baştan joker
         * içerdiği için indeks kullanılamaz (ÖLÇÜM: 123.000 kayıtta
         * ~540 ms tam tablo taraması). Bu, örneğin sadeliği için
         * bilinçli bir tercihtir; gerçek bir üründe FULLTEXT indeks
         * veya ayrı bir arama motoru gerekir. */
        $where .= ' AND (name LIKE :s_name OR email LIKE :s_email OR segment LIKE :s_segment)';

        $pattern = '%' . escape_like($search) . '%';
        $params += [':s_name' => $pattern, ':s_email' => $pattern, ':s_segment' => $pattern];
    }

    if ($status !== '') {
        /* ÖLÇÜLEN SORUN: burada eskiden "geçerliyse ekle" mantığı vardı
         * ve GEÇERSİZ bir durum değeri SESSİZCE YOK SAYILIYORDU.
         * Ölçüm: status_filter=uydurma_deger ile bulk_preview → count 60,
         * yani 60 kayıtlık tablonun TAMAMI. Kullanıcı "sadece engellileri
         * hedefliyorum" sanırken filtre düşüyor ve kapsam tüm tabloya
         * genişliyordu — hata "açılma" (fail-open) yönünde çalışıyordu.
         * Artık tanınmayan değer isteği reddeder: kapsamı genişleten bir
         * sessizlik, gürültülü bir hatadan çok daha tehlikelidir. */
        if (!array_key_exists($status, SUBSCRIBER_STATUSES)) {
            json_error('Geçersiz durum filtresi.', 422);
        }

        $where .= ' AND status = :status';
        $params[':status'] = $status;
    }

    return [$where, $params];
}

/**
 * LIKE deseninde "%" ve "_" karakterleri jokerdir. Kullanıcı arama
 * kutusuna "%" yazdığında bunu JOKER değil, ARANAN METİN saymalıyız;
 * aksi hâlde tek karakterlik bir arama tüm tabloyu eşleştirir ve
 * "filtreye uyan tümünü sil" beklenmedik biçimde her şeyi kapsar.
 * Ters bölü (\) de kaçış karakteri olduğu için ilk sırada çoğaltılır.
 */
function escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/** Filtreye uyan kayıt sayısı. Koşulu subscriber_filter() üretir. */
function count_subscribers(PDO $db, string $search = '', string $status = ''): int
{
    [$where, $params] = subscriber_filter($search, $status);

    $stmt = $db->prepare("SELECT COUNT(*) FROM subscribers WHERE $where");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function format_date(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Exception $e) {
        return (string) $value;
    }
}
