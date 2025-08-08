<?php
// Hata raporlamayı kapat (JSON çıktısını bozmasın)
error_reporting(0);
ini_set('display_errors', 0);

// Output buffering başlat (beklenmeyen çıktıları yakala)
ob_start();

// Session yönetimi - çakışma önleme
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Config dosyası path'ini esnek şekilde bulma
$config_paths = [
    __DIR__ . '/../config.php',
    __DIR__ . '/config.php',
    dirname(__DIR__) . '/config.php'
];

$config_loaded = false;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_loaded = true;
        break;
    }
}

if (!$config_loaded) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['error' => 'Config dosyası bulunamadı']));
}

// Security utils - opsiyonel
$security_paths = [
    __DIR__ . '/../utils/security.php',
    __DIR__ . '/utils/security.php'
];

foreach ($security_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Test modu - session kontrolü olmadan
// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
//     http_response_code(403);
//     echo json_encode(['error' => 'Yetkisiz erişim']);
//     exit;
// }

// Buffer'ı temizle ve header'ları ayarla
ob_clean();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS request için
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

// Database bağlantısını kur
$conn = db_connect();

switch ($action) {
    case 'list':
        // Coinleri listele
        $sql = "SELECT 
                    c.id, 
                    c.coin_adi, 
                    c.coin_kodu, 
                    c.current_price,
                    c.aciklama,
                    c.logo_url,
                    c.is_active,
                    c.created_at,
                    c.updated_at,
                    ck.kategori_adi
                FROM coins c
                LEFT JOIN coin_kategorileri ck ON c.kategori_id = ck.id
                ORDER BY c.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $coins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Artık fiyatlar zaten TL cinsinden saklanıyor
        foreach ($coins as &$coin) {
            $coin['current_price'] = floatval($coin['current_price']); // TL cinsinden
            $coin['currency'] = 'TRY';
        }
        
        echo json_encode(['success' => true, 'data' => $coins]);
        break;
        
    case 'add':
        // Debug: POST verilerini logla
        error_log("POST Data: " . print_r($_POST, true));
        error_log("FILES Data: " . print_r($_FILES, true));
        
        // Yeni coin ekle
        $coin_adi = $_POST['coin_adi'] ?? '';
        $coin_kodu = strtoupper($_POST['coin_kodu'] ?? '');
        $current_price = floatval($_POST['current_price'] ?? 0);
        $currency = $_POST['coin_currency'] ?? 'TRY'; // Para birimi (TRY veya USD)
        $aciklama = $_POST['aciklama'] ?? '';
        $kategori_id = $_POST['kategori_id'] ?? null;
        
        // Dinamik kur sistemi
        require_once __DIR__ . '/../utils/currency.php';
        
        // Eğer coin API aktifse (coingecko_id varsa) USD olarak sakla
        // Manuel coinlerse TL olarak sakla
        $coingecko_id = $_POST['coingecko_id'] ?? '';
        $api_aktif = !empty($coingecko_id) ? 1 : 0;
        
        if ($api_aktif) {
            // API coin - USD olarak sakla (API'den güncel fiyat gelecek)
            $current_price_to_save = $current_price; // USD cinsinden
            error_log("API Coin - USD fiyat saklanıyor: {$current_price_to_save}");
        } else {
            // Manuel coin - TL olarak girildi, USD'ye çevir ve sakla
            $usd_try_rate = getUsdTryRate();
            $current_price_to_save = $current_price / $usd_try_rate; // TL'den USD'ye çevir
            error_log("Manuel Coin - TL: {$current_price}, USD: {$current_price_to_save}, Kur: {$usd_try_rate}");
        }
        
        // Debug: Değişkenleri logla
        error_log("Coin Adi: " . $coin_adi);
        error_log("Coin Kodu: " . $coin_kodu);  
        error_log("Current Price: " . $current_price);
        error_log("Kategori ID: " . $kategori_id);
        
        // Validation
        if (empty($coin_adi) || empty($coin_kodu)) {
            echo json_encode(['error' => 'Coin adı ve kodu zorunludur']);
            exit;
        }
        
        if ($current_price <= 0) {
            echo json_encode(['error' => 'Geçerli bir fiyat giriniz']);
            exit;
        }
        
        // Sembolün benzersiz olup olmadığını kontrol et
        $check_sql = "SELECT COUNT(*) FROM coins WHERE coin_kodu = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$coin_kodu]);
        
        if ($check_stmt->fetchColumn() > 0) {
            echo json_encode(['error' => 'Bu coin kodu zaten mevcut']);
            exit;
        }
        
        // Logo yükleme işlemi
        $logo_path = null;
        if (isset($_FILES['coin_logo']) && $_FILES['coin_logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../coin_logos/';
            
            // Klasör yoksa oluştur
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES['coin_logo']['name']);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            
            if (!in_array(strtolower($file_info['extension']), $allowed_extensions)) {
                echo json_encode(['error' => 'Sadece JPG, PNG, GIF, SVG ve WebP dosyaları yüklenebilir']);
                exit;
            }
            
            // Dosya boyutu kontrolü (2MB)
            if ($_FILES['coin_logo']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['error' => 'Logo dosyası 2MB\'dan küçük olmalıdır']);
                exit;
            }
            
            // Güvenli dosya adı oluştur
            $safe_filename = strtolower($coin_kodu) . '_logo.' . strtolower($file_info['extension']);
            $upload_path = $upload_dir . $safe_filename;
            
            if (move_uploaded_file($_FILES['coin_logo']['tmp_name'], $upload_path)) {
                $logo_path = 'coin_logos/' . $safe_filename;
                error_log("Logo yüklendi: " . $logo_path);
            } else {
                error_log("Logo yükleme hatası");
                echo json_encode(['error' => 'Logo yüklenemedi']);
                exit;
            }
        }
        
        try {
            // Debug: SQL sorgusunu logla
            $sql = "INSERT INTO coins (coin_adi, coin_kodu, current_price, aciklama, kategori_id, logo_url, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, 1)";
            error_log("SQL Query: " . $sql);
            error_log("Parameters: " . json_encode([$coin_adi, $coin_kodu, $current_price, $aciklama, $kategori_id, $logo_path]));
            
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$coin_adi, $coin_kodu, $current_price_to_save, $aciklama, $kategori_id, $logo_path]);
            
            if ($result) {
                $coin_id = $conn->lastInsertId();
                error_log("Coin başarıyla eklendi, ID: " . $coin_id);
                
                // Admin log kaydı (Hata vermemesi için try-catch ekle)
                try {
                    $log_sql = "INSERT INTO admin_islem_loglari 
                               (admin_id, islem_tipi, hedef_id, islem_detayi) 
                               VALUES (?, 'coin_ekleme', ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->execute([
                        $_SESSION['user_id'] ?? 1, // Test modunda admin_id = 1
                        $coin_id, 
                        "Yeni coin eklendi: {$coin_adi} ({$coin_kodu}) - ₺{$current_price}" . ($logo_path ? " (Logo: {$logo_path})" : "")
                    ]);
                } catch (Exception $log_error) {
                    error_log("Log kaydı hatası: " . $log_error->getMessage());
                    // Log hatası coin eklemeyi etkilemesin
                }
                
                echo json_encode(['success' => true, 'message' => 'Coin başarıyla eklendi', 'id' => $coin_id, 'logo_path' => $logo_path]);
            } else {
                error_log("Coin insert failed");
                echo json_encode(['error' => 'Coin eklenemedi - Database insert failed']);
            }
        } catch (Exception $e) {
            error_log("Coin ekleme hatası: " . $e->getMessage());
            echo json_encode(['error' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
        break;
        
    case 'delete':
        // Coin sil
        $id = intval($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Geçersiz coin ID']);
            exit;
        }
        
        try {
            // Önce coin bilgilerini al
            $get_sql = "SELECT coin_adi, coin_kodu FROM coins WHERE id = ?";
            $get_stmt = $conn->prepare($get_sql);
            $get_stmt->execute([$id]);
            $coin = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$coin) {
                echo json_encode(['error' => 'Coin bulunamadı']);
                exit;
            }
            
            // Coin'in işlem geçmişi var mı kontrol et
            $check_sql = "SELECT COUNT(*) FROM coin_islemleri WHERE coin_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->execute([$id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                // İşlem geçmişi varsa pasif yap
                $update_sql = "UPDATE coins SET is_active = 0 WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $result = $update_stmt->execute([$id]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Coin işlem geçmişi nedeniyle pasif yapıldı']);
                } else {
                    echo json_encode(['error' => 'Coin pasif yapılamadı']);
                }
            } else {
                // İşlem geçmişi yoksa tamamen sil
                $delete_sql = "DELETE FROM coins WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $result = $delete_stmt->execute([$id]);
                
                if ($result) {
                    // Admin log kaydı
                    $log_sql = "INSERT INTO admin_islem_loglari 
                               (admin_id, islem_tipi, hedef_id, islem_detayi) 
                               VALUES (?, 'coin_silme', ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_stmt->execute([
                        $_SESSION['user_id'] ?? 1,
                        $id, 
                        "Coin silindi: {$coin['coin_adi']} ({$coin['coin_kodu']})"
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Coin başarıyla silindi']);
                } else {
                    echo json_encode(['error' => 'Coin silinemedi']);
                }
            }
        } catch (Exception $e) {
            echo json_encode(['error' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
        break;
        
    case 'update':
        // Coin güncelle
        $id = intval($_POST['id'] ?? 0);
        $coin_adi = $_POST['coin_adi'] ?? '';
        $coin_kodu = strtoupper($_POST['coin_kodu'] ?? '');
        $current_price = floatval($_POST['current_price'] ?? 0);
        $aciklama = $_POST['aciklama'] ?? '';
        $kategori_id = $_POST['kategori_id'] ?? null;
        
        if ($id <= 0 || empty($coin_adi) || empty($coin_kodu) || $current_price <= 0) {
            echo json_encode(['error' => 'Tüm gerekli alanları doldurunuz']);
            exit;
        }
        
        try {
            $sql = "UPDATE coins SET 
                        coin_adi = ?, 
                        coin_kodu = ?, 
                        current_price = ?, 
                        aciklama = ?, 
                        kategori_id = ?,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$coin_adi, $coin_kodu, $current_price, $aciklama, $kategori_id, $id]);
            
            if ($result) {
                // Admin log kaydı
                $log_sql = "INSERT INTO admin_islem_loglari 
                           (admin_id, islem_tipi, hedef_id, islem_detayi) 
                           VALUES (?, 'coin_duzenleme', ?, ?)";
                $log_stmt = $conn->prepare($log_sql);
                $log_stmt->execute([
                    $_SESSION['user_id'] ?? 1,
                    $id, 
                    "Coin güncellendi: {$coin_adi} ({$coin_kodu}) - ₺{$current_price}"
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Coin başarıyla güncellendi']);
            } else {
                echo json_encode(['error' => 'Coin güncellenemedi']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
        break;
        
    case 'detail':
        // Coin detayları
        $id = intval($_GET['coin_id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Geçersiz coin ID']);
            exit;
        }
        
        $sql = "SELECT 
                    c.*, 
                    ck.kategori_adi,
                    COUNT(ci.id) as islem_sayisi,
                    SUM(CASE WHEN ci.islem = 'al' THEN ci.miktar ELSE 0 END) as toplam_alim,
                    SUM(CASE WHEN ci.islem = 'sat' THEN ci.miktar ELSE 0 END) as toplam_satim
                FROM coins c
                LEFT JOIN coin_kategorileri ck ON c.kategori_id = ck.id
                LEFT JOIN coin_islemleri ci ON c.id = ci.coin_id
                WHERE c.id = ?
                GROUP BY c.id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $coin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$coin) {
            echo json_encode(['error' => 'Coin bulunamadı']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $coin]);
        break;
        
    default:
        echo json_encode(['error' => 'Geçersiz işlem']);
        break;
}
?>
