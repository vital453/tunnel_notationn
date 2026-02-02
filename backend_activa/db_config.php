<?php
$servername = "91.216.107.186"; // Ex: localhost ou 127.0.0.1
$dbname     = "actii2652708";
$username   = "actii2652708";
$password   = "u6qohagh5t";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // En cas d'erreur de connexion, on arrête tout
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à la base de données.']);
    exit();
}