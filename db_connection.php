<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=url_shortener', 'root', 'P@ssword123');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
