<?php
/**
 * =====================================================================
 *  ANA SAYFA (Sunum Katmanı)
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * =====================================================================
 */

declare(strict_types=1);

/* Bkz. system/config.php – dosyaların doğrudan çağrılmasını engelleyen işaret. */
define('CY_APP', true);

require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP ve DataTables ile çoklu seçim ve toplu işlem örneği: seçili kayıtları veya filtreye uyan tüm kayıtları toplu güncelleyin/silin.">

    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <title>Çoklu Seçim ve Toplu İşlem | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body class="cy-app">

    <div class="cy-topbar"></div>

    <div class="container py-4 py-lg-5">

        <div class="cy-card">

            <div class="cy-card__header">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                        <span class="cy-brand__mark">
                            <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                        </span>
                        <div>
                            <h1 class="cy-brand__title">Çoklu Seçim ve Toplu İşlem</h1>
                            <p class="cy-brand__subtitle">
                                Sunucu taraflı DataTables &middot; Toplu güncelle/sil &middot; cilginyazilim.com
                            </p>
                        </div>
                    </a>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="cy-badge cy-badge--glass">
                            Toplam <strong id="total_records">0</strong> abone
                        </span>
                        <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                            <span aria-hidden="true">＋</span> Yeni Abone
                        </button>
                    </div>
                </div>
            </div>

            <div class="cy-card__body">

                <!-- ---------- Araç çubuğu: arama + durum filtresi ---------- -->
                <div class="cy-toolbar">
                    <input type="search" id="search_input" class="form-control cy-toolbar__search"
                           placeholder="Ad, e-posta, segment ara…" aria-label="Kayıtlarda ara">

                    <select id="status_filter" class="form-select cy-toolbar__status" aria-label="Duruma göre filtrele">
                        <option value="">Tüm durumlar</option>
                        <?php foreach (SUBSCRIBER_STATUSES as $value => $meta): ?>
                            <option value="<?= e($value) ?>"><?= e($meta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ================================================================
                     TOPLU İŞLEM ÇUBUĞU
                     ----------------------------------------------------------------
                     Hiçbir satır seçili değilken GİZLİDİR (d-none). Bir satır
                     işaretlenir işaretlenmez görünür olur; bu, "seçtiğim bir
                     şey var, ne yapabilirim?" sorusunun cevabını kullanıcının
                     gözünün önüne getirir — ayrı bir menüde aramaz.
                     ================================================================ -->
                <div id="bulk_bar" class="cy-bulk-bar d-none">
                    <span class="cy-bulk-bar__count">
                        <strong id="bulk_count">0</strong> kayıt seçili
                    </span>

                    <!--
                        "Filtreye uyan TÜMÜNÜ seç" bağlantısı: kullanıcı bu
                        sayfadaki tüm kutucukları işaretlediğinde VE daha
                        fazla eşleşen kayıt olduğunda görünür (bkz. table.js
                        updateBulkBar()). Tıklanınca seçim modu "scope=filtered"
                        olur; id listesi yerine arama koşulu sunucuya gider.
                    -->
                    <button type="button" id="select_all_matching" class="btn btn-link btn-sm p-0 d-none"></button>

                    <div class="cy-bulk-bar__actions">
                        <div class="dropdown">
                            <button type="button" class="btn cy-btn cy-btn--outline cy-btn--sm dropdown-toggle"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                Durumu Değiştir
                            </button>
                            <ul class="dropdown-menu">
                                <?php foreach (SUBSCRIBER_STATUSES as $value => $meta): ?>
                                    <li>
                                        <a class="dropdown-item js-bulk-status" href="#" data-status="<?= e($value) ?>">
                                            <?= e($meta['label']) ?> yap
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <button type="button" id="bulk_delete_button" class="btn btn-danger cy-btn cy-btn--sm">
                            Seçilenleri Sil
                        </button>

                        <button type="button" id="clear_selection" class="btn btn-outline-secondary cy-btn cy-btn--sm">
                            Seçimi Temizle
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="subscriber_table" class="table cy-table w-100">
                        <thead>
                            <tr>
                                <th scope="col">
                                    <input type="checkbox" class="form-check-input" id="select_all_page"
                                           aria-label="Bu sayfadaki tümünü seç">
                                </th>
                                <th scope="col">#</th>
                                <th scope="col">Ad Soyad</th>
                                <th scope="col">E-posta</th>
                                <th scope="col">Segment</th>
                                <th scope="col">Durum</th>
                                <th scope="col">Kayıt Tarihi</th>
                                <th scope="col" class="text-center">İşlemler</th>
                            </tr>
                        </thead>
                    </table>
                </div>

                <div id="length_control" class="cy-length-bar"></div>
            </div>

            <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
                <span>Sunucu taraflı DataTables &middot; CSRF korumalı AJAX &middot; Filtreye göre toplu işlem</span>
                <span>PHP <?= e(PHP_VERSION) ?></span>
            </div>
        </div>

        <div class="cy-footer-note mt-4">
            <p class="mb-1">
                Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
                tarafından geliştirilmiştir. MIT lisanslıdır.
            </p>
            <p class="mb-0">
                Kaynak kod:
                <a href="https://github.com/CilginYazilim/bulk-actions-table"
                   target="_blank" rel="noopener">github.com/CilginYazilim/bulk-actions-table</a>
            </p>
        </div>
    </div>


    <!-- ================================================================
         MODAL 1 – EKLEME / DÜZENLEME
         ================================================================ -->
    <div class="modal fade cy-modal" id="subscriberModal" tabindex="-1" aria-labelledby="subscriberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" id="subscriber_form" novalidate>
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6 mb-0" id="subscriberModalLabel">Yeni Abone</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="form_alert" role="alert"></div>

                        <div class="mb-3">
                            <label for="name" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" maxlength="150">
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="email" class="form-control" maxlength="190">
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="segment" class="form-label">Segment</label>
                                <input type="text" name="segment" id="segment" class="form-control" maxlength="60" placeholder="Genel">
                                <div class="invalid-feedback" data-error-for="segment"></div>
                            </div>
                            <div class="col-sm-6">
                                <label for="status" class="form-label">Durum</label>
                                <select name="status" id="status" class="form-select">
                                    <?php foreach (SUBSCRIBER_STATUSES as $value => $meta): ?>
                                        <option value="<?= e($value) ?>"><?= e($meta['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback" data-error-for="status"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary cy-btn" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" id="submit_button" class="btn cy-btn cy-btn--primary">
                            <span class="spinner-border spinner-border-sm me-1 d-none" id="submit_spinner" role="status" aria-hidden="true"></span>
                            <span id="submit_label">Kaydet</span>
                        </button>
                    </div>
                    <input type="hidden" name="action" id="form_action" value="add">
                    <input type="hidden" name="subscriber_id" id="subscriber_id" value="">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================
         MODAL 2 – TEKLİ SİLME ONAYI
         ================================================================ -->
    <div class="modal fade cy-modal" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="deleteModalLabel">Aboneyi Sil</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="delete-icon" aria-hidden="true">!</div>
                    <p class="mb-0"><strong id="delete_label"></strong> kaydı <u>kalıcı olarak</u> silinecek.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn btn-danger cy-btn btn-sm" id="confirm_delete">Evet, Sil</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         MODAL 3 – TOPLU İŞLEM ONAYI
         ----------------------------------------------------------------
         Hem toplu silme hem toplu durum değiştirme İÇİN ORTAK modal.
         Başlık ve metin JavaScript tarafından doldurulur; buton rengi
         işleme göre değişir (silme = kırmızı, durum değiştirme = marka
         mavisi). Gerçek sayıyı (bkz. bulk_preview) GÖSTERMEK, "emin
         misiniz?" yerine "42 kayıt SİLİNECEK, emin misiniz?" demeyi
         sağlar — yanlışlıkla tüm tabloyu silme riskini azaltır.
         ================================================================ -->
    <div class="modal fade cy-modal" id="bulkConfirmModal" tabindex="-1" aria-labelledby="bulkConfirmLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6 mb-0" id="bulkConfirmLabel">Toplu İşlemi Onayla</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="delete-icon" id="bulk_confirm_icon" aria-hidden="true">!</div>
                    <p class="mb-0" id="bulk_confirm_text"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary cy-btn btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn cy-btn cy-btn--sm" id="bulk_confirm_button">Onayla</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container cy-toast-container position-fixed top-0 end-0 p-3" id="toast_container"></div>

    <script src="assets/js/jquery-3.7.0.js"></script>
    <script src="assets/js/bootstrap.bundle.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/table.js?v=<?= filemtime(__DIR__ . '/assets/js/table.js') ?>"></script>
    <script>
        CyBulkTable.init({
            endpoint:  'system/ajax.php',
            csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>,
            statuses:  <?= json_encode(SUBSCRIBER_STATUSES, JSON_UNESCAPED_UNICODE) ?>
        });
    </script>
</body>
</html>
