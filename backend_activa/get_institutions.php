<?php
// On autorise les requêtes venant de notre front-end
header("Access-Control-Allow-Origin: https://form.actiiva.org");

// L'EN-TÊTE MANQUANT EST ICI :
header('Content-Type: application/json');

require 'db_config.php';

$stmt = $pdo->query("SELECT id, name, type, logo FROM institutions ORDER BY name ASC");
$institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($institutions);