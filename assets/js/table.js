/* =====================================================================
 *  ÇOKLU SEÇİM VE TOPLU İŞLEM MOTORU
 *  cilginyazilim.com – Çoklu Seçim ve Toplu İşlem Tablosu
 * ---------------------------------------------------------------------
 *  BU DOSYANIN ÖĞRETTİĞİ ASIL KAVRAM: "SEÇİLİ" İKİ FARKLI ANLAMA
 *  GELEBİLİR.
 *
 *    1. "Şu belirli 7 kaydı seçtim"           → id listesi (scope=selected)
 *    2. "Bu aramaya uyan HERKESİ seçtim"       → filtre koşulu (scope=filtered)
 *
 *  Sunucu taraflı (server-side) bir DataTables'ta kullanıcı asla
 *  "tüm 50.000 satırı" aynı anda GÖRMEZ; yalnızca o anki sayfayı
 *  görür. "Tümünü seç" dediğinde 50.000 id'yi tarayıcıya indirip
 *  sonra sunucuya geri göndermek hem yavaştır hem de gereksizdir.
 *  Gmail'in "Bu aramaya uyan tüm 4.312 e-postayı seç" özelliği bu
 *  ikinci yaklaşımı kullanır: sunucuya sadece "ARAMA KOŞULUNU tekrar
 *  uygula, etkilediği HERKESE işlemi yap" denir.
 * ================================================================== */

/* global jQuery, bootstrap */

var CyBulkTable = (function ($) {
    'use strict';

    var config = {
        endpoint:  'system/ajax.php',
        csrfToken: '',
        statuses:  {}
    };

    var dataTable        = null;
    var deleteModal       = null;
    var bulkConfirmModal   = null;

    var pendingDeleteId   = null;

    /* { type: 'delete'|'status', newStatus?: string, count: number }
     * count = onay penceresinde KULLANICIYA GÖSTERİLEN sayı. İşlem
     * isteğiyle birlikte sunucuya geri gönderilir (expected_count);
     * sunucu kendi saydığıyla tutmuyorsa işlemi hiç yapmaz. Yani onay
     * penceresindeki sayı bir süs değil, işlemin sözleşmesidir. */
    var pendingBulkAction  = null;

    /* --- Seçim durumu ---
     * selectedIds     : Set<number> — açıkça işaretlenmiş id'ler.
     * selectAllMatching: boolean    — "filtreye uyan HERKES" modu.
     * lastFilteredTotal: son list() yanıtındaki recordsFiltered; kaç
     *                    kayıt olduğunu "tümünü seç" bağlantısında
     *                    göstermek için saklanır. */
    var selectedIds        = new Set();
    var selectAllMatching   = false;
    var lastFilteredTotal   = 0;
    var lastPageIds         = []; // Şu an ekranda görünen satırların id'leri.

    var searchTimer = null;

    /* Shift ile ARALIK SEÇİMİ için son tıklanan satırın id'si.
     * Yalnızca AYNI sayfa içinde anlamlıdır; sayfa değişince
     * (restoreCheckboxState) sıfırlanır, çünkü aralık "ekranda
     * gördüğün iki satır arası" demektir. */
    var lastCheckedId = null;

    /* Mobilde <thead> gizlendiği için sütun başlıkları her hücrenin
     * SOLUNDA data-label olarak yeniden yazdırılır (bkz. style.css
     * "MOBİL KART GÖRÜNÜMÜ"). Sıra, index.php'deki <th> sırasıdır. */
    var COLUMN_LABELS = ['', '#', 'Ad Soyad', 'E-posta', 'Segment', 'Durum', 'Kayıt Tarihi', 'İşlemler'];


    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    function notify(message, type) {
        var $toast = $(
            '<div class="toast cy-toast cy-toast--' + (type || 'success') + '"' +
                 ' role="alert" aria-live="assertive" aria-atomic="true">' +
                '<div class="d-flex">' +
                    '<div class="toast-body"></div>' +
                    '<button type="button" class="btn-close btn-close-white me-2 m-auto"' +
                          ' data-bs-dismiss="toast" aria-label="Kapat"></button>' +
                '</div>' +
            '</div>'
        );

        $toast.find('.toast-body').text(message);
        $('#toast_container').append($toast);

        var toast = new bootstrap.Toast($toast[0], { delay: 4000 });
        $toast.on('hidden.bs.toast', function () { $toast.remove(); });
        toast.show();
    }

    function post(data) {
        data.csrf_token = config.csrfToken;
        return $.ajax({ url: config.endpoint, method: 'POST', dataType: 'json', data: data });
    }

    /** Şu anki arama/durum filtresini okur. */
    function currentFilters() {
        return {
            search:        $('#search_input').val() || '',
            status_filter: $('#status_filter').val() || ''
        };
    }

    /**
     * Toplu işlem isteğine eklenecek "kapsam" (scope) parametrelerini
     * üretir. Bu fonksiyon, resolve_bulk_scope() ile SUNUCU tarafında
     * birebir eşleşen bir mantık izler (bkz. system/ajax.php).
     */
    function scopeParams() {
        if (selectAllMatching) {
            return $.extend({ scope: 'filtered' }, currentFilters());
        }

        return { scope: 'selected', ids: Array.from(selectedIds) };
    }


    /* =================================================================
     *  SEÇİM DURUMU YÖNETİMİ
     * ============================================================== */

    /* ÖLÇÜLEN SORUN: "Seçimi Temizle" iç durumu boşaltıyor ve çubuğu
     * gizliyordu ama EKRANDAKİ kutucuklar işaretli kalıyordu — seçim
     * temizlendikten sonra tablo hâlâ "10 satır seçili" gibi
     * görünüyordu. Kutucukları da eşitlemek bu tutarsızlığı kapatır. */
    function resetSelection() {
        selectedIds.clear();
        selectAllMatching = false;
        lastCheckedId     = null;

        syncPageCheckboxes();
        updateBulkBar();
    }

    /**
     * Ekrandaki kutucukların GÖRÜNÜMÜNÜ iç duruma (selectedIds /
     * selectAllMatching) göre eşitler. Sayfa listesini (lastPageIds)
     * YENİDEN KURMAZ ve aralık seçiminin çıpasını (lastCheckedId)
     * bozmaz — bu yüzden ardışık Shift tıklamalarında güvenle
     * çağrılabilir.
     */
    function syncPageCheckboxes() {
        $('.cy-row-check').each(function () {
            var id       = $(this).data('id');
            var isPicked = selectAllMatching || selectedIds.has(id);

            $(this)
                .prop('checked', isPicked)
                .prop('disabled', selectAllMatching);

            /* Satırın kendisini de işaretle: masaüstünde ince bir zemin
             * rengi, MOBİLDE ise kartın sol kenarındaki renkli şerit
             * "bu kart seçili" bilgisini kutucuğa bakmadan verir. */
            $(this).closest('tr').toggleClass('cy-row-selected', isPicked);
        });

        var allPageChecked = lastPageIds.length > 0 && lastPageIds.every(function (id) {
            return selectAllMatching || selectedIds.has(id);
        });

        /* Masaüstündeki başlık kutucuğu ve mobildeki karşılığı aynı
         * sınıfı taşır; ikisi de tek seferde güncellenir. */
        $('.js-select-all-page')
            .prop('checked', allPageChecked)
            .prop('disabled', selectAllMatching);
    }

    /** Bir sayfa DataTables'tan gelince checkbox'ların işaretli
     * durumunu, İÇ DURUMA (selectedIds / selectAllMatching) göre
     * yeniden kurar. Sunucu HER ZAMAN işaretsiz checkbox döndürür;
     * "hangi satır seçili?" bilgisi tamamen istemcide yaşar. */
    function restoreCheckboxState() {
        lastPageIds = [];

        $('.cy-row-check').each(function () {
            lastPageIds.push($(this).data('id'));
        });

        syncPageCheckboxes();

        /* Aralık seçimi yalnızca ekrandaki sayfa içinde geçerlidir. */
        lastCheckedId = null;

        updateBulkBar();
    }

    /**
     * Toplu işlem çubuğunu ve "filtreye uyan tümünü seç" bağlantısını
     * mevcut duruma göre günceller.
     */
    function updateBulkBar() {
        var count = selectAllMatching ? lastFilteredTotal : selectedIds.size;

        $('#bulk_bar').toggleClass('d-none', count === 0);
        $('#bulk_count').text(count);

        /* Mobilde toplu işlem çubuğu ekranın ALTINA sabitlenir; sayfanın
         * son satırı çubuğun altında kalmasın diye <body>'ye alt boşluk
         * eklenir (bkz. style.css body.cy-bulk-active). */
        $('body').toggleClass('cy-bulk-active', count > 0);

        $('.cy-bulk-bar__count').toggleClass('d-none', count === 0);

        /* "Filtreye uyan TÜMÜNÜ seç" bağlantısı YALNIZCA şu koşulda
         * gösterilir: kullanıcı bu SAYFADAKİ tüm satırları işaretledi
         * VE filtreye uyan DAHA FAZLA kayıt var (aksi hâlde zaten
         * hepsi seçilidir, bağlantının bir anlamı kalmaz). */
        var pageFullySelected = lastPageIds.length > 0 && lastPageIds.every(function (id) {
            return selectedIds.has(id);
        });

        var showMatchingLink = !selectAllMatching && pageFullySelected && lastFilteredTotal > selectedIds.size;

        $('#select_all_matching')
            .toggleClass('d-none', !showMatchingLink)
            .text('Filtreye uyan tüm ' + lastFilteredTotal + ' kaydı seç');
    }


    /* =================================================================
     *  DATATABLES KURULUMU
     * ============================================================== */

    function initTable() {
        dataTable = $('#subscriber_table').DataTable({
            processing: true,
            serverSide: true,
            order: [[1, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],

            // 'f' yok: kendi arama kutumuz var. l/i/p tek alt çubukta
            // toplanır (bkz. style.css .cy-bottom-bar) — excel-import-export
            // projesindeki aynı desenin tekrarıdır; oradaki gizli
            // ".dataTables_length" satır bölünme hatasından ders alınarak
            // baştan doğru kuruldu.
            dom: 'rt<"cy-bottom-bar"<"cy-bottom-bar__length"l><"cy-bottom-bar__info"i><"cy-bottom-bar__pagination"p>>',

            ajax: {
                url: config.endpoint,
                type: 'POST',
                data: function (d) {
                    d.action        = 'list';
                    d.csrf_token    = config.csrfToken;
                    d.status_filter = $('#status_filter').val() || '';
                },
                error: function () {
                    notify('Kayıtlar yüklenirken bir hata oluştu.', 'danger');
                }
            },

            /* Her sütuna kendi sınıfı verilir. Masaüstünde bu sınıfların
             * çoğu görsel olarak bir şey yapmaz; MOBİLDE ise satırı bir
             * karta dönüştüren yerleşimin tutamaklarıdır (ad başlığa,
             * durum rozeti sağ üste, işlemler alta taşınır). */
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'text-center cy-cell-check', width: '36px' },
                { targets: 1, className: 'cy-id cy-cell-id' },
                { targets: 2, className: 'cy-cell-name' },
                { targets: 3, className: 'cy-cell-email' },
                { targets: 4, className: 'cy-cell-segment' },
                { targets: 5, className: 'cy-cell-status' },
                { targets: 6, className: 'cy-cell-date' },
                { targets: 7, orderable: false, searchable: false, className: 'text-center cy-cell-actions' }
            ],

            rowCallback: function (row) {
                $(row).children('td').each(function (index) {
                    this.setAttribute('data-label', COLUMN_LABELS[index] || '');
                });
            },

            drawCallback: function (settings) {
                var json = settings.json;

                $('#total_records').text(json ? json.recordsTotal : 0);
                lastFilteredTotal = json ? json.recordsFiltered : 0;

                restoreCheckboxState();
            },

            language: {
                emptyTable: 'Henüz abone bulunmuyor.',
                info: '_TOTAL_ kayıttan _START_ – _END_ arası gösteriliyor',
                infoEmpty: 'Gösterilecek kayıt yok',
                infoFiltered: '(toplam _MAX_ kayıt içinden filtrelendi)',
                lengthMenu: 'Sayfada _MENU_ kayıt göster',
                loadingRecords: 'Yükleniyor…',
                processing: 'İşleniyor…',
                zeroRecords: 'Aramanızla eşleşen kayıt bulunamadı.',
                paginate: { first: 'İlk', last: 'Son', next: 'Sonraki', previous: 'Önceki' },
                aria: {
                    sortAscending: ': artan sırada sıralamak için etkinleştir',
                    sortDescending: ': azalan sırada sıralamak için etkinleştir'
                }
            }
        });
    }


    /* =================================================================
     *  FORM VE TEKLİ İŞLEMLER
     * ============================================================== */

    function clearErrors() {
        var $form = $('#subscriber_form');
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $('#form_alert').addClass('d-none').text('');
    }

    function showErrors(errors, general) {
        clearErrors();

        if (general) { $('#form_alert').removeClass('d-none').text(general); }

        $.each(errors || {}, function (field, message) {
            $('#' + field).addClass('is-invalid');
            $('[data-error-for="' + field + '"]').text(message);
        });
    }

    function setLoading(isLoading) {
        $('#submit_button').prop('disabled', isLoading);
        $('#submit_spinner').toggleClass('d-none', !isLoading);
        $('#submit_label').text(isLoading ? 'Kaydediliyor…' : 'Kaydet');
    }

    function resetForm() {
        $('#subscriber_form')[0].reset();
        $('#subscriber_id').val('');
        clearErrors();
    }


    /* =================================================================
     *  TEMA (AÇIK / KOYU)
     * -----------------------------------------------------------------
     *  Tasarım sistemi (cilginyazilim.css) iki yoldan koyu temaya
     *  geçer: `prefers-color-scheme` medya sorgusu (İŞLETİM SİSTEMİ
     *  ayarı) ve <html data-cy-theme="dark"> özniteliği (KULLANICI
     *  tercihi). Buradaki düğme yalnızca ikincisini yazar; hiç
     *  dokunulmadığında karar işletim sistemine aittir.
     *
     *  İlk uygulama BURADA DEĞİL, index.php'nin <head> bölümündeki
     *  satır içi betikte yapılır — aksi hâlde sayfa bir kare boyunca
     *  yanlış temada çizilirdi.
     * ============================================================== */

    function effectiveTheme() {
        var attr = document.documentElement.getAttribute('data-cy-theme');

        if (attr === 'dark' || attr === 'light') {
            return attr;
        }

        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark'
            : 'light';
    }

    function paintThemeToggle(theme) {
        $('#theme_toggle_icon').text(theme === 'dark' ? '☀' : '🌙');
        $('#theme_toggle').attr({
            'title':         theme === 'dark' ? 'Açık temaya geç' : 'Koyu temaya geç',
            'aria-label':    theme === 'dark' ? 'Açık temaya geç' : 'Koyu temaya geç',
            'aria-pressed':  theme === 'dark' ? 'true' : 'false'
        });
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-cy-theme', theme);

        try {
            localStorage.setItem('cy-theme', theme);
        } catch (e) { /* Gizli sekmede yazma engellenebilir; tema yine de uygulanır. */ }

        paintThemeToggle(theme);
    }


    /* =================================================================
     *  OLAY BAĞLAMA
     * ============================================================== */
    function bindEvents() {
        var subscriberModal = new bootstrap.Modal(document.getElementById('subscriberModal'));
        deleteModal          = new bootstrap.Modal(document.getElementById('deleteModal'));
        bulkConfirmModal       = new bootstrap.Modal(document.getElementById('bulkConfirmModal'));

        /* --- Arama / durum filtresi: filtre değişince seçim SIFIRLANIR.
         * "Filtreye uyan tümünü seç" belirli bir filtreye bağlıdır;
         * filtre değişirse o seçimin ne anlama geldiği belirsizleşir. */
        $('#search_input').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                resetSelection();
                dataTable.ajax.reload();
            }, 300);
        });

        $('#status_filter').on('change', function () {
            resetSelection();
            dataTable.ajax.reload();
        });

        /* --- Satır seçimi ---
         * NEDEN 'change' DEĞİL 'click'? Shift ile aralık seçimi için
         * olayın shiftKey bilgisine ihtiyacımız var; 'change' olayı bu
         * bilgiyi taşımaz. 'click' anında checkbox'ın .checked değeri
         * ZATEN yeni değerdir, dolayısıyla mantık aynen çalışır. */
        $('#subscriber_table').on('click', '.cy-row-check', function (event) {
            var id      = $(this).data('id');
            var checked = this.checked;

            /* SHIFT + TIK = ARALIK SEÇİMİ.
             * 40 satırlık bir sayfada 30 satırı tek tek işaretlemek
             * kullanıcıyı yorar ve yanlış tıklamaya davet eder. Shift
             * ile "buradan buraya" demek, tablo arayüzlerinin yerleşik
             * beklentisidir (dosya yöneticileri, e-posta istemcileri).
             * Aralık, EKRANDAKİ sıraya göre hesaplanır: lastPageIds
             * dizisi satırların görünen sırasını tutar. */
            if (event.shiftKey && lastCheckedId !== null && lastCheckedId !== id) {
                var from = lastPageIds.indexOf(lastCheckedId);
                var to   = lastPageIds.indexOf(id);

                if (from !== -1 && to !== -1) {
                    var start = Math.min(from, to);
                    var end   = Math.max(from, to);

                    for (var i = start; i <= end; i++) {
                        if (checked) {
                            selectedIds.add(lastPageIds[i]);
                        } else {
                            selectedIds.delete(lastPageIds[i]);
                        }
                    }

                    lastCheckedId = id;

                    /* Aralıktaki kutucukların görünümünü tazele. Burada
                     * restoreCheckboxState() ÇAĞRILMAZ: o fonksiyon
                     * lastCheckedId'yi sıfırlar ve ardışık shift
                     * tıklamalarını bozardı. */
                    syncPageCheckboxes();
                    updateBulkBar();
                    return;
                }
            }

            if (checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }

            lastCheckedId = id;

            syncPageCheckboxes();
            updateBulkBar();
        });

        /* Masaüstünde başlıktaki, mobilde tablonun üstündeki kutucuk —
         * ikisi de .js-select-all-page sınıfını taşır. */
        $('.js-select-all-page').on('change', function () {
            var checked = this.checked;

            lastPageIds.forEach(function (id) {
                if (checked) { selectedIds.add(id); } else { selectedIds.delete(id); }
            });

            restoreCheckboxState();
        });

        /* --- Mobil sıralama açılır listesi ---
         * "2|asc" biçimindeki değer, DataTables'ın order() çağrısına
         * çevrilir. Masaüstündeki başlık tıklaması ile AYNI yoldan
         * geçer; iki farklı sıralama mantığı yoktur. */
        $('#mobile_sort').on('change', function () {
            var parts = String($(this).val()).split('|');
            dataTable.order([parseInt(parts[0], 10), parts[1]]).draw(false);
        });

        /* Masaüstünde başlığa tıklanınca mobil listenin de aynı şeyi
         * göstermesi için sıralama değişimini geri yansıt. */
        dataTable.on('order.dt', function () {
            var order = dataTable.order();

            if (order && order.length) {
                var value  = order[0][0] + '|' + order[0][1];
                var $sort  = $('#mobile_sort');

                /* Listede karşılığı olmayan bir sıralama (örn. tarih
                 * sütunu artan) seçilmişse listeyi zorlamayız. */
                if ($sort.find('option[value="' + value + '"]').length) {
                    $sort.val(value);
                }
            }
        });

        $('#select_all_matching').on('click', function () {
            selectAllMatching = true;
            restoreCheckboxState();
        });

        $('#clear_selection').on('click', resetSelection);

        /* --- Tema düğmesi --- */
        paintThemeToggle(effectiveTheme());

        $('#theme_toggle').on('click', function () {
            applyTheme(effectiveTheme() === 'dark' ? 'light' : 'dark');
        });

        /* --- Esc: seçimi bırak ---
         * Toplu işlem modundan çıkmanın en hızlı yolu. Bir modal
         * açıkken Esc'i Bootstrap'e bırakırız; iki davranışın
         * çakışması "pencereyi kapatmak istedim, seçimim de gitti"
         * şaşkınlığına yol açar. */
        $(document).on('keydown', function (event) {
            if (event.key !== 'Escape') { return; }
            if ($('.modal.show').length) { return; }

            if (selectedIds.size > 0 || selectAllMatching) {
                resetSelection();
            }
        });

        /* --- Toplu durum değiştirme --- */
        $('.js-bulk-status').on('click', function (event) {
            event.preventDefault();

            var status = $(this).data('status');

            pendingBulkAction = { type: 'status', newStatus: status };
            openBulkConfirm();
        });

        /* --- Toplu silme --- */
        $('#bulk_delete_button').on('click', function () {
            pendingBulkAction = { type: 'delete' };
            openBulkConfirm();
        });

        $('#bulk_confirm_button').on('click', executeBulkAction);

        /* --- Yeni kayıt --- */
        $('#add_button').on('click', function () {
            resetForm();
            $('#form_action').val('add');
            $('#subscriberModalLabel').text('Yeni Abone');
            subscriberModal.show();
        });

        /* --- Düzenle --- */
        $('#subscriber_table').on('click', '.js-edit', function () {
            var id = $(this).data('id');

            post({ action: 'fetch', id: id }).done(function (data) {
                resetForm();
                $('#form_action').val('edit');
                $('#subscriber_id').val(data.id);
                $('#name').val(data.name);
                $('#email').val(data.email);
                $('#segment').val(data.segment);
                $('#status').val(data.status);
                $('#subscriberModalLabel').text('Aboneyi Düzenle');
                subscriberModal.show();
            }).fail(function (xhr) {
                var res = xhr.responseJSON || {};
                notify(res.description || 'Kayıt getirilemedi.', 'danger');
            });
        });

        $('#subscriber_form').on('submit', function (event) {
            event.preventDefault();
            clearErrors();
            setLoading(true);

            post($(this).serializeArray().reduce(function (acc, field) {
                acc[field.name] = field.value;
                return acc;
            }, {}))
            .done(function (response) {
                subscriberModal.hide();
                notify(response.description, 'success');
                dataTable.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                showErrors(res.errors, res.description || 'İşlem tamamlanamadı.');
            })
            .always(function () { setLoading(false); });
        });

        /* --- Tekli silme --- */
        $('#subscriber_table').on('click', '.js-delete', function () {
            pendingDeleteId = $(this).data('id');
            $('#delete_label').text($(this).data('label') || '#' + pendingDeleteId);
            deleteModal.show();
        });

        $('#confirm_delete').on('click', function () {
            if (pendingDeleteId === null) { return; }

            var $button = $(this).prop('disabled', true);

            post({ action: 'delete', id: pendingDeleteId })
                .done(function (response) {
                    notify(response.description, 'success');
                    selectedIds.delete(pendingDeleteId);
                    dataTable.ajax.reload(null, false);
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    notify(res.description || 'Kayıt silinemedi.', 'danger');
                })
                .always(function () {
                    $button.prop('disabled', false);
                    deleteModal.hide();
                    pendingDeleteId = null;
                });
        });
    }

    /**
     * Toplu işlem onay modalını, GERÇEK etkilenecek kayıt sayısıyla
     * doldurur. Bu ekstra sunucu isteği (bulk_preview) olmadan,
     * kullanıcı "42 kaydı sildim" sanıp aslında "filtreye uyan 900
     * kaydı" silebilirdi — sayıyı ÖNCEDEN göstermek bu riski kapatır.
     */
    function openBulkConfirm() {
        post($.extend({ action: 'bulk_preview' }, scopeParams()))
            .done(function (response) {
                var count = response.count;
                var isDelete = pendingBulkAction.type === 'delete';

                // Gösterilen sayıyı sakla: işlem isteğinde expected_count
                // olarak geri gider (bkz. execute_bulk, system/ajax.php).
                pendingBulkAction.count = count;

                var text = isDelete
                    ? count + ' kayıt kalıcı olarak SİLİNECEK.'
                    : count + ' kaydın durumu "' + config.statuses[pendingBulkAction.newStatus].label + '" olarak DEĞİŞTİRİLECEK.';

                $('#bulk_confirm_text').text(text);
                $('#bulk_confirm_icon').text(isDelete ? '!' : '↻')
                    .toggleClass('delete-icon--danger', isDelete);

                $('#bulk_confirm_button')
                    .removeClass('cy-btn--primary btn-danger')
                    .addClass(isDelete ? 'btn-danger' : 'cy-btn--primary')
                    .text(isDelete ? 'Evet, Sil' : 'Evet, Değiştir');

                bulkConfirmModal.show();
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                notify(res.description || 'İşlem önizlemesi alınamadı.', 'danger');
            });
    }

    function executeBulkAction() {
        if (!pendingBulkAction) { return; }

        var $button = $('#bulk_confirm_button').prop('disabled', true);

        var payload = $.extend(
            {
                action:         pendingBulkAction.type === 'delete' ? 'bulk_delete' : 'bulk_status',
                expected_count: pendingBulkAction.count
            },
            scopeParams()
        );

        if (pendingBulkAction.type === 'status') {
            payload.new_status = pendingBulkAction.newStatus;
        }

        /* Pencereyi kapatır, butonu serbest bırakır ve bekleyen işlemi
         * temizler. 409 durumunda pencere AÇIK KALIR (bkz. aşağıda). */
        function finish() {
            $button.prop('disabled', false);
            bulkConfirmModal.hide();
            pendingBulkAction = null;
        }

        post(payload)
            .done(function (response) {
                notify(response.description, 'success');
                resetSelection();
                dataTable.ajax.reload();
                finish();
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};

                /* 409 = "liste siz onayladıktan sonra değişti".
                 * Bu bir hata değil, bir FRENDİR: kullanıcı 8 kayıt için
                 * onay verdiyse 9 kaydın silinmesine izin verilmez
                 * (sunucu işlemi geri aldı, bkz. execute_bulk).
                 * Doğru davranış kullanıcıyı uyarıyla baş başa bırakmak
                 * değil, GÜNCEL sayıyla onayı yeniden istemektir.
                 * Pencere bilerek kapatılmaz: kapatıp yeniden açmak
                 * Bootstrap'in gizleme animasyonuyla yarışır ve pencere
                 * bazen hiç görünmez. openBulkConfirm() zaten açık olan
                 * pencerenin metnini tazeler. */
                if (xhr.status === 409) {
                    notify(res.description || 'Liste değişti, işlem yapılmadı.', 'danger');
                    dataTable.ajax.reload(null, false);

                    $button.prop('disabled', false);
                    openBulkConfirm();
                    return;
                }

                notify(res.description || 'Toplu işlem tamamlanamadı.', 'danger');
                finish();
            });
    }


    /* =================================================================
     *  BAŞLANGIÇ
     * ============================================================== */
    function init(options) {
        $.extend(config, options || {});

        $(function () {
            initTable();
            bindEvents();
        });
    }

    return { init: init };

})(jQuery);
