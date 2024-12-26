<?php
// Подключение к базе данных
require 'db_connection.php';


// Проверка на наличие укороченного кода в URL
$path = trim(str_replace('/url_shortener/', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$host = $_SERVER['HTTP_HOST']; // Получаем домен из запроса

if (!empty($path) && $path !== 'api.php') {
    try {
        // Ищем запись в базе по short_code и домену
        $stmt = $pdo->prepare("SELECT long_url, clicks, active FROM links WHERE short_code = ? AND domain = ?");
        $stmt->execute([$path, $host]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($link) {
            if (!$link['active']) {
                // Если ссылка заблокирована
                echo "This link has been disabled by the administrator.";
                http_response_code(403); // HTTP статус: Доступ запрещён
                exit;
            }

            // Увеличиваем счётчик переходов
            $updateStmt = $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE short_code = ?");
            $updateStmt->execute([$path]);

            // Перенаправляем на оригинальный URL
            header("Location: " . $link['long_url']);
            exit;
        } else {
            echo "Link not found.";
            http_response_code(404); // HTTP статус: Не найдено
            exit;
        }
    } catch (Exception $e) {
        echo "An error occurred: " . $e->getMessage();
        http_response_code(500); // HTTP статус: Внутренняя ошибка сервера
        exit;
    }
}


header("Content-Type: application/json");

// Получение параметра action
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        try {
            // Получение данных из POST-запроса
            $data = json_decode(file_get_contents("php://input"), true);
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($username) || empty($password)) {
                echo json_encode(['status' => 'error', 'message' => 'Имя пользователя и пароль обязательны']);
                exit;
            }

            // Проверка пользователя в базе данных
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                echo json_encode(['status' => 'success', 'message' => 'Успешный вход', 'user_id' => $user['id']]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Неверные имя пользователя или пароль']);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'get_links':
        try {
            $user_id = $_GET['user_id'] ?? null;

            if (!$user_id) {
                echo json_encode(['status' => 'error', 'message' => 'ID пользователя обязателен']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM links WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user_id]);
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'links' => $links]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

        case 'shorten':
            try {
                $data = json_decode(file_get_contents("php://input"), true);
                $user_id = $data['user_id'] ?? null;
                $long_url = $data['long_url'] ?? '';
                $domain = $data['domain'] ?? '';
                
    
                if (!$user_id || empty($long_url)|| empty($domain)) {
                    echo json_encode(['status' => 'error', 'message' => 'ID пользователя и длинная ссылка обязательны']);
                    exit;
                }
    
                $short_code = generateShortCode();
                while (!isUniqueShortCode($pdo, $short_code)) {
                    $short_code = generateShortCode();
                }
    
                $stmt = $pdo->prepare("INSERT INTO links (user_id, long_url, short_code, domain) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $long_url, $short_code, $domain]);
    
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Ссылка успешно сокращена',
                    'short_url' => "http://$domain/$short_code"
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;
    
        case 'get_all_links':
            try {
                $stmt = $pdo->query("SELECT * FROM links ORDER BY created_at DESC");
                $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'links' => $links]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;
            
        case 'get_domains':
            try {
                $stmt = $pdo->query("SELECT * FROM domains ORDER BY id ASC");
                $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
                echo json_encode(['status' => 'success', 'domains' => $domains]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;
            
            
        case 'toggle_link':
            try {
                $data = json_decode(file_get_contents("php://input"), true);
                $id = $data['id'] ?? null;
                $active = $data['active'] ?? 1;
    
                if (!$id) {
                    echo json_encode(['status' => 'error', 'message' => 'Link ID is required']);
                    exit;
                }
    
                $stmt = $pdo->prepare("UPDATE links SET active = ? WHERE id = ?");
                $stmt->execute([$active, $id]);
    
                echo json_encode(['status' => 'success', 'message' => 'Link status updated']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;
    
        case 'add_domain':
            try {
                $data = json_decode(file_get_contents("php://input"), true);
                $domain = $data['domain'] ?? '';
    
                if (empty($domain)) {
                    echo json_encode(['status' => 'error', 'message' => 'Domain is required']);
                    exit;
                }
    
                $stmt = $pdo->prepare("INSERT INTO domains (domain_name) VALUES (?)");
                $stmt->execute([$domain]);
    
                echo json_encode(['status' => 'success', 'message' => 'Domain added successfully']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;

        case 'toggle_domain':
            try {
                $data = json_decode(file_get_contents("php://input"), true);
                $domain_id = $data['domain_id'] ?? null;
                $new_status = $data['active'] ?? null;
        
                if ($domain_id === null || $new_status === null) {
                    echo json_encode(['status' => 'error', 'message' => 'Domain ID and new status are required']);
                    exit;
                }
        
                $stmt = $pdo->prepare("UPDATE domains SET active = ? WHERE id = ?");
                $stmt->execute([$new_status, $domain_id]);
        
                // Перегенерация конфигурационного файла Apache
                $stmt = $pdo->query("SELECT * FROM domains");
                $all_domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
                $config_path = "/etc/apache2/sites-available/url_shortener_domains.conf";
                $config_content = "";
        
                foreach ($all_domains as $domain) {
                    if ($domain['active'] == 1) { // Добавляем только активные домены
                        $config_content .= "
        <VirtualHost *:80>
            ServerName {$domain['domain_name']}
        
            DocumentRoot /var/www/html
            <Directory /var/www/html>
                Options Indexes FollowSymLinks
                AllowOverride All
                Require all granted
            </Directory>
        
            ErrorLog /var/log/apache2/{$domain['domain_name']}_error.log
            CustomLog /var/log/apache2/{$domain['domain_name']}_access.log combined
        </VirtualHost>
        ";
                    }
                }
        
                file_put_contents($config_path, $config_content);
        
                // Перезагрузка Apache
                exec("sudo systemctl reload apache2");
        
                echo json_encode(['status' => 'success', 'message' => 'Domain status updated']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            break;
            

        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
            break;
}

// Функция для генерации короткого кода
function generateShortCode($length = 6) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $short_code = '';
    for ($i = 0; $i < $length; $i++) {
        $short_code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $short_code;
}

// Функция для проверки уникальности короткого кода
function isUniqueShortCode($pdo, $short_code) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ?");
    $stmt->execute([$short_code]);
    return $stmt->fetchColumn() == 0;
}
?>
