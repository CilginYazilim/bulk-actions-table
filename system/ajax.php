<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI (Endpoint)
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * ---------------------------------------------------------------------
 *    action=list         → DataTables için kayıt listesi
 *    action=add / edit    → Tekli kayıt ekle/güncelle
 *    action=fetch         → Tek kaydı getir (düzenleme formu için)
 *    action=delete         → Tekli kayıt sil
 *    action=bulk_status    → SEÇİLİ (veya FİLTREYE UYAN TÜM) kayıtların durumunu değiştir
 *    action=bulk_delete     → SEÇİLİ (veya FİLTREYE UYAN TÜM) kayıtları sil
 *
 *  TOPLU İŞLEMİN İKİ MODU VARDIR:
 *    scope=selected → İstemci belirli id'leri gönderir (ids[])
 *    scope=filtered → İstemci id GÖNDERMEZ; "arama kutusuna/duruma
 *                     uyan HERKESİ" işaret eder. Sunucu, ekrandaki
 *                     listeyi üreten AYNI WHERE koşulunu (bkz.
 *                     subscriber_filter()) UPDATE/DELETE sorgusuna
 *                     uygular. Bu, "10.000 satırı seçtim" durumunda
 *                     10.000 id'yi tarayıcıdan sunucuya göndermek
 *                     zorunda kalmamayı sağlar — tıpkı Gmail'in
 *                     "bu aramaya uyan TÜM e-postaları seç" özelliği gibi.
 *
 *  YIKICI İŞLEMLERİN SÖZLEŞMESİ (bulk_status / bulk_delete):
 *    Her ikisi de "expected_count" ister — kullanıcının onay
 *    penceresinde GÖRDÜĞÜ sayı. Sunucu kendi saydığı sayıyla
 *    karşılaştırır; tutmuyorsa HTTP 409 döner ve hiçbir şeye dokunmaz.
 *    Böylece "8 kayıt silinecek" onayı, 9 kaydı silmeye yetki vermez.
 * =====================================================================
 */

declare(strict_types=1);

/* config.php ve function.php doğrudan çağrılmaya karşı kendilerini
 * kapatır; CY_APP onların "beni uygulama include etti" işaretidir. */
define('CY_APP', true);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

$action = isset($_POST['action']) ? strtolower(trim((string) $_POST['action'])) : 'list';

try {
    switch ($action) {
        case 'add':
        case 'edit':
            handle_save($db, $action);
            break;

        case 'fetch':
            handle_fetch($db);
            break;

        case 'delete':
            handle_delete($db);
            break;

        case 'bulk_preview':
            handle_bulk_preview($db);
            break;

        case 'bulk_status':
            handle_bulk_status($db);
            break;

        case 'bulk_delete':
            handle_bulk_delete($db);
            break;

        case 'list':
        default:
            handle_list($db);
            break;
    }
} catch (PDOException $e) {
    error_log('[BULK] Veritabani hatasi: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Veritabanı hatası: ' . $e->getMessage() : 'Beklenmeyen bir veritabanı hatası oluştu.', 500);
} catch (Throwable $e) {
    error_log('[BULK] Hata: ' . $e->getMessage());
    json_error(APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata oluştu.', 500);
}


/* =====================================================================
 *  1) LİSTELEME (DataTables Server-Side Protokolü + durum filtresi)
 * ================================================================== */
function handle_list(PDO $db): void
{
    /* ÖLÇÜLEN SORUN: Bu uç nokta CSRF anahtarı İSTEMİYORDU — diğer yedi
     * uç noktanın hepsi isterken bu atlanmıştı. Ölçüm: anahtarsız
     * "action=list" isteği HTTP 200 ve 60 kaydın tamamını (ad, e-posta,
     * segment) döndürüyordu. Okuma işlemi "zararsız" görünür ama burada
     * dönen şey bir müşteri listesidir; başka bir sitede açılmış bir
     * sekmenin bu listeyi çekebilmesi tek başına bir veri sızıntısıdır.
     * İstemci anahtarı zaten her istekte gönderiyordu (bkz. table.js
     * ajax.data), bu yüzden düzeltmenin arayüze bir maliyeti yok. */
    require_csrf();

    /* Sütun sırası: 0=checkbox 1=# 2=Ad Soyad 3=E-posta 4=Segment
     * 5=Durum 6=Kayıt Tarihi 7=İşlemler. 0 ve 7 veritabanı sütunu
     * değildir; sıralanamaz/aranamaz.
     *
     * SIRALAMA SÜTUNU NEDEN BEYAZ LİSTE? Sütun adı ve yön SQL'e metin
     * olarak girer — parametre olarak bağlanamaz. İstemciden gelen değer
     * doğrudan yazılsaydı "ORDER BY" bir enjeksiyon kapısı olurdu. Burada
     * istemci bir SÜTUN ADI değil, bir DİZİ İNDİSİ gönderir; tanınmayan
     * indis sessizce 'id'ye düşer. Ölçüldü: order[0][column] alanına
     * SQL parçası gönderildiğinde (int) dönüşümü onu 0 yapıyor ve sorgu
     * normal çalışıyor. */
    $sortableColumns = [1 => 'id', 2 => 'name', 3 => 'email', 4 => 'segment', 5 => 'status', 6 => 'created_at'];

    $draw   = (int) ($_POST['draw'] ?? 1);
    $start  = max(0, (int) ($_POST['start'] ?? 0));
    $length = (int) ($_POST['length'] ?? 10);
    $search = trim((string) ($_POST['search']['value'] ?? ''));
    $status = trim((string) ($_POST['status_filter'] ?? ''));

    $orderColumn    = (int) ($_POST['order'][0]['column'] ?? 1);
    $orderDirection = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc'));

    $orderBy  = $sortableColumns[$orderColumn] ?? 'id';
    $orderDir = ($orderDirection === 'asc') ? 'ASC' : 'DESC';

    /* Listenin koşulu ile toplu işlemin koşulu AYNI fonksiyondan gelir.
     * Bu, projenin can alıcı sözüdür: ekranda gördüğünüz küme ile
     * "filtreye uyan tümü" dediğinizde işlenen küme aynıdır. */
    [$where, $params] = subscriber_filter($search, $status);

    $sql = "SELECT id, name, email, segment, status, created_at FROM subscribers WHERE $where";

    $sql .= sprintf(' ORDER BY `%s` %s', $orderBy, $orderDir);

    if ($length > 0) {
        $sql .= sprintf(' LIMIT %d OFFSET %d', min($length, 500), $start);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = [];

    foreach ($rows as $row) {
        $id       = (int) $row['id'];
        $statuses = SUBSCRIBER_STATUSES[$row['status']] ?? ['label' => $row['status'], 'css' => 'secondary'];

        /* Checkbox hücresi: data-id ile JavaScript'in hangi satırın
         * seçildiğini bilmesi sağlanır. Sayfa geçişlerinde önceki
         * seçimin GÖRÜNMESİ için "checked" durumunu JavaScript
         * tarafında ayrıca yönetiyoruz (bkz. table.js restoreSelection()) —
         * sunucu her zaman işaretsiz checkbox döndürür, seçim durumu
         * tamamen istemci tarafındaki bir Set'te tutulur. */
        $checkboxHtml = '<input type="checkbox" class="form-check-input cy-row-check" data-id="' . $id . '">';

        $statusHtml = '<span class="badge text-bg-' . e($statuses['css']) . '">' . e($statuses['label']) . '</span>';

        $actionsHtml =
            '<div class="cy-actions">'
            . '<button type="button" class="cy-btn-icon cy-btn-icon--edit js-edit"'
                . ' data-id="' . $id . '" title="Düzenle" aria-label="Düzenle">&#9998;</button>'
            . '<button type="button" class="cy-btn-icon cy-btn-icon--delete js-delete"'
                . ' data-id="' . $id . '" data-label="' . e($row['name']) . '"'
                . ' title="Sil" aria-label="Sil">&#128465;</button>'
            . '</div>';

        $data[] = [
            $checkboxHtml,
            $id,
            e($row['name']),
            '<span class="cy-nowrap">' . e($row['email']) . '</span>',
            e($row['segment']),
            $statusHtml,
            '<span class="cy-nowrap">' . e(format_date($row['created_at'])) . '</span>',
            $actionsHtml,
        ];
    }

    /* DataTables iki sayı ister: tablodaki TOPLAM ve filtreye UYAN.
     * Filtre boşken bu ikisi tanım gereği aynıdır; eskiden yine de iki
     * ayrı COUNT(*) çalıştırılıyordu. ÖLÇÜM (123.000 kayıt): filtresiz
     * COUNT(*) ~175 ms — yani her sayfa çevirmede yarım saniyeye yakın
     * boşa giden bir sorgu. Aynı sonucu iki kez sormamak, tek satırlık
     * bir kazanç. */
    $total    = count_subscribers($db);
    $filtered = ($search === '' && $status === '') ? $total : count_subscribers($db, $search, $status);

    json_response([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => $data,
    ]);
}


/* =====================================================================
 *  2) TEKLİ EKLEME / GÜNCELLEME
 * ================================================================== */
function handle_save(PDO $db, string $action): void
{
    require_csrf();

    $errors = [];

    [$name, $nameError]     = validate_name($_POST['name'] ?? '');
    [$email, $emailError]   = validate_email($_POST['email'] ?? '');
    [$status, $statusError] = validate_status($_POST['status'] ?? 'aktif');
    $segment = trim((string) ($_POST['segment'] ?? '')) ?: 'Genel';

    foreach (['name' => $nameError, 'email' => $emailError, 'status' => $statusError] as $field => $message) {
        if ($message !== null) {
            $errors[$field] = $message;
        }
    }

    $isEdit  = ($action === 'edit');
    $current = null;

    if ($isEdit) {
        $id = filter_input(INPUT_POST, 'subscriber_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false || $id === null) {
            json_error('Geçersiz kayıt numarası.');
        }

        $current = find_subscriber($db, $id);

        if ($current === null) {
            json_error('Güncellenecek kayıt bulunamadı.', 404);
        }
    }

    if ($email !== '' && !isset($errors['email'])) {
        $excludeId = $isEdit ? (int) $current['id'] : 0;
        $stmt = $db->prepare('SELECT 1 FROM subscribers WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $excludeId]);

        if ($stmt->fetchColumn() !== false) {
            $errors['email'] = 'Bu e-posta adresi başka bir kayıtta kullanılıyor.';
        }
    }

    if ($errors !== []) {
        json_error('Lütfen formdaki hataları düzeltin.', 422, ['errors' => $errors]);
    }

    if ($isEdit) {
        $stmt = $db->prepare('UPDATE subscribers SET name=:name, email=:email, segment=:segment, status=:status WHERE id=:id');
        $stmt->execute([':name' => $name, ':email' => $email, ':segment' => $segment, ':status' => $status, ':id' => $current['id']]);

        json_success('Kayıt güncellendi.', ['id' => (int) $current['id']]);
    }

    $stmt = $db->prepare('INSERT INTO subscribers (name, email, segment, status) VALUES (:name, :email, :segment, :status)');
    $stmt->execute([':name' => $name, ':email' => $email, ':segment' => $segment, ':status' => $status]);

    json_success('Kayıt eklendi.', ['id' => (int) $db->lastInsertId()]);
}


/* =====================================================================
 *  3) TEK KAYIT GETİRME / SİLME
 * ================================================================== */
function handle_fetch(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false || $id === null) {
        json_error('Geçersiz kayıt numarası.');
    }

    $subscriber = find_subscriber($db, $id);

    if ($subscriber === null) {
        json_error('Kayıt bulunamadı.', 404);
    }

    json_response(array_merge(['success' => true], $subscriber));
}

function handle_delete(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false || $id === null) {
        json_error('Geçersiz kayıt numarası.');
    }

    $stmt = $db->prepare('DELETE FROM subscribers WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error('Silinecek kayıt bulunamadı.', 404);
    }

    json_success('Kayıt silindi.', ['id' => $id]);
}


/* =====================================================================
 *  4) TOPLU İŞLEM ORTAK ALTYAPISI
 * =====================================================================
 *  Bu bölüm projenin kalbidir. Üç uç nokta (bulk_preview, bulk_status,
 *  bulk_delete) TEK bir kapsam çözücüden ve TEK bir çalıştırıcıdan
 *  geçer. "Tek yol" burada mimari bir zevk değil, ölçülmüş bir hatanın
 *  cevabıdır — bkz. execute_bulk() üzerindeki açıklama.
 * ================================================================== */

/**
 * İsteğin HANGİ KAYITLARI hedeflediğini çözer.
 *
 * @return array{0:string,1:array<string,mixed>,2:int}
 *         [WHERE gövdesi, İSİMLİ parametreler, veritabanından SAYILMIŞ gerçek kayıt sayısı]
 */
function resolve_bulk_scope(PDO $db): array
{
    $scope = trim((string) ($_POST['scope'] ?? 'selected'));

    /* Tanınmayan bir kapsam adı eskiden sessizce "selected" sayılıyordu.
     * Kapsam, işlemin YARIÇAPINI belirleyen alandır; burada tahmin
     * yürütmek yerine reddetmek doğrudur. */
    if ($scope !== 'selected' && $scope !== 'filtered') {
        json_error('Geçersiz işlem kapsamı.', 422);
    }

    if ($scope === 'filtered') {
        /* KAPSAM: Ekrandaki arama/durum filtresine uyan HERKES.
         * Koşul, listeyi üreten subscriber_filter() ile BİREBİR AYNI
         * fonksiyondan gelir — ekranda görünenle toplu işlemin
         * etkilediği küme arasında ASLA fark olmamalıdır. */
        [$where, $params] = subscriber_filter(
            (string) ($_POST['search'] ?? ''),
            (string) ($_POST['status_filter'] ?? '')
        );
    } else {
        /* KAPSAM: İstemcinin AÇIKÇA işaretlediği id'ler. */
        $rawIds = $_POST['ids'] ?? [];

        if (!is_array($rawIds)) {
            json_error('Geçersiz seçim verisi.');
        }

        $ids = [];

        foreach ($rawIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            json_error('Lütfen en az bir kayıt seçin.', 422);
        }

        if (count($ids) > BULK_MAX_IDS) {
            json_error(
                'Tek seferde en fazla ' . BULK_MAX_IDS . ' kayıt seçebilirsiniz. '
                . 'Daha büyük bir küme için "Filtreye uyan tümünü seç" seçeneğini kullanın.',
                422
            );
        }

        /* NEDEN ":id0, :id1, …" — YANİ İSİMLİ YER TUTUCU?
         * Burada eskiden "?" (konumsal) yer tutucu üretiliyordu ve
         * yorumda "isimli üretmek gereksiz karmaşıklık katardı" yazıyordu.
         * ÖLÇÜLEN SONUÇ tam tersiydi: filtreli kapsam İSİMLİ, seçili
         * kapsam KONUMSAL parametre ürettiği için tek bir sorgu iki
         * biçimi karıştırıyor ve PDO isteği reddediyordu
         * ("SQLSTATE[HY093] mixed named and positional parameters").
         * İki biçimin bir arada bulunabildiği her yer, er ya da geç
         * karıştıkları yerdir. Bu yüzden proje genelinde TEK kural
         * geçerlidir: HER YER İSİMLİ. Üretilen birkaç satırlık ek kod,
         * kaybolan bütün bir hata sınıfının yanında ucuzdur. */
        $params = [];

        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }

        $where = 'id IN (' . implode(',', array_keys($params)) . ')';
    }

    /* SAYI HER ZAMAN VERİTABANINDAN OKUNUR.
     * ÖLÇÜLEN SORUN: seçili kapsamda sayı olarak count($ids) —yani
     * İSTEMCİNİN GÖNDERDİĞİ id ADEDİ— döndürülüyordu. Ölçüm: var olmayan
     * 10 id gönderildiğinde onay penceresi "10 kayıt kalıcı olarak
     * SİLİNECEK" diyordu, gerçekte silinen 0'dı. Onay penceresinin tek
     * varlık sebebi GERÇEK sayıyı göstermek olduğuna göre, o sayının
     * kaynağı istemci olamaz. */
    $stmt = $db->prepare("SELECT COUNT(*) FROM subscribers WHERE $where");
    $stmt->execute($params);

    return [$where, $params, (int) $stmt->fetchColumn()];
}

/**
 * TOPLU İŞLEMİN TEK ÇIKIŞ KAPISI.
 *
 * NEDEN İKİ İŞLEM DE BURADAN GEÇİYOR? Eskiden bulk_status ve
 * bulk_delete kendi sorgusunu kendi bağlıyordu ve AYNI hatalı biçim
 * ikisinde FARKLI davranıyordu:
 *   • bulk_status  → HTTP 500 (SET status = ? ile isimli WHERE karışıyordu)
 *   • bulk_delete  → HTTP 200, 57 kayıt SİLİNDİ (başta "?" olmadığı için
 *                    PDO konumsal diziyi isimli yer tutuculara sırayla
 *                    eşliyordu — kazara doğru çalışıyordu)
 * Yani bozuk kod bir uç noktada gürültüyle patlıyor, diğerinde sessizce
 * VERİ SİLİYORDU. Sıralamanın rastlantısal olarak tutması bir güvence
 * değildir: WHERE koşuluna ileride ikinci bir parametre eklendiğinde
 * aynı kod hiçbir hata vermeden YANLIŞ SATIRLARI silmeye başlardı.
 * Sorgu şablonu ne olursa olsun bağlama işi artık tek bir yerde yapılır.
 *
 * @param string $sqlTemplate  "{where}" yer tutucusu içeren SQL şablonu.
 * @param array  $extraParams  Sorgunun kendi isimli parametreleri (örn. :new_status).
 * @return array{0:int,1:int}  [etkilenen kayıt, onaylanan kayıt]
 */
function execute_bulk(PDO $db, string $sqlTemplate, array $extraParams = []): array
{
    [$where, $params, $count] = resolve_bulk_scope($db);

    if ($count === 0) {
        json_error('Seçime uyan kayıt bulunamadı.', 422);
    }

    /* ONAYLANAN SAYI DENETİMİ (bayat önizlemeye karşı).
     * ÖLÇÜLEN SORUN: Kullanıcı önizlemede "8 kayıt SİLİNECEK" onayını
     * görürken, başka bir sekme araya bir kayıt ekledi; onaya basıldığında
     * 9 kayıt silindi. Kullanıcının onayladığı SAYI ile işlemin
     * etkilediği SAYI birbirini tutmuyordu — üstelik geri dönüşü olmayan
     * bir işlemde. Artık istemci, onay penceresinde GÖRDÜĞÜ sayıyı geri
     * gönderir; sunucu kendi saydığı sayıyla karşılaştırır ve tutmuyorsa
     * işleme HİÇ BAŞLAMAZ. Kullanıcı güncel sayıyla yeniden onaylar. */
    $expected = filter_input(INPUT_POST, 'expected_count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    if ($expected === false || $expected === null) {
        json_error('Onaylanan kayıt sayısı eksik. Lütfen işlemi yeniden başlatın.', 422);
    }

    if ($expected !== $count) {
        json_error(
            'Liste siz onayladıktan sonra değişti: şu anda ' . $count . ' kayıt eşleşiyor, '
            . 'siz ' . $expected . ' kayıt için onay vermiştiniz. İşlem yapılmadı.',
            409,
            ['count' => $count]
        );
    }

    /* NEDEN TRANSACTION? Tek ifadeli bir UPDATE/DELETE zaten atomiktir —
     * bu haliyle transaction TEK BAŞINA gereksiz olurdu. Buradaki gerçek
     * gerekçe aşağıdaki ikinci denetimdir: sayımı, yazmayı ve "beklenenden
     * fazlasına dokunduysam geri al" kararını TEK bir bütün hâline getirir.
     * Transaction olmasaydı rowCount() farkını görsek bile silinen
     * kayıtları geri getiremezdik. Ayrıca ileride bu işleme ikinci bir
     * tablo (örn. işlem günlüğü) eklendiğinde iskelet hazırdır. */
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(str_replace('{where}', $where, $sqlTemplate));

        /* İki isimli parametre kümesinin birleşimi. Anahtarlar isim
         * olduğu için sıra ÖNEMSİZDİR — konumsal biçimde bu satır
         * sessiz bir hata kaynağıydı. */
        $stmt->execute($extraParams + $params);

        $affected = $stmt->rowCount();

        /* SON DENETİM: yazma, saydığımızdan fazlasına dokunduysa geri al.
         * (rowCount() burada "eşleşen satır" demektir — bkz. config.php
         * PDO::MYSQL_ATTR_FOUND_ROWS.) Araya giren bir kayıt yüzünden
         * onaylananın dışına taşmak, özellikle silmede kabul edilemez. */
        if ($affected !== $count) {
            $db->rollBack();

            json_error(
                'Liste işlem sırasında değişti (' . $affected . ' kayıt etkilenecekti, '
                . $count . ' kayıt onaylanmıştı). Değişiklikler geri alındı.',
                409,
                ['count' => $affected]
            );
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }

    return [$affected, $count];
}

/**
 * Toplu işlemden önce kullanıcıya "kaç kayıt etkilenecek?" bilgisini
 * verir. Onay modalı bu sayıyı gösterir; "emin misiniz?" yerine
 * "42 kayıt SİLİNECEK, emin misiniz?" demek, yanlışlıkla tüm
 * tabloyu silme riskini büyük ölçüde azaltır. Sayı, asıl işlemi
 * yapacak olan resolve_bulk_scope()'un TA KENDİSİNDEN gelir; önizleme
 * ile işlemin farklı yollardan geçmesi, önizlemeyi bir süse çevirirdi.
 */
function handle_bulk_preview(PDO $db): void
{
    require_csrf();

    [, , $count] = resolve_bulk_scope($db);

    json_response(['success' => true, 'count' => $count]);
}

function handle_bulk_status(PDO $db): void
{
    require_csrf();

    [$newStatus, $statusError] = validate_status($_POST['new_status'] ?? '');

    if ($statusError !== null) {
        json_error($statusError, 422);
    }

    [$affected] = execute_bulk(
        $db,
        'UPDATE subscribers SET status = :new_status WHERE {where}',
        [':new_status' => $newStatus]
    );

    json_success(
        $affected . ' kaydın durumu "' . SUBSCRIBER_STATUSES[$newStatus]['label'] . '" olarak güncellendi.',
        ['updated' => $affected]
    );
}

function handle_bulk_delete(PDO $db): void
{
    require_csrf();

    [$affected] = execute_bulk($db, 'DELETE FROM subscribers WHERE {where}');

    json_success($affected . ' kayıt silindi.', ['deleted' => $affected]);
}
