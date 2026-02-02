<?php
/**
 * API pour l'ajout d'une nouvelle institution et l'envoi d'une notification par e-mail.
 * Inspiré par la structure du formulaire de contact Team Phénix.
 */

// On importe les classes de la bibliothèque PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// On charge les fichiers de la bibliothèque depuis le dossier 'PHPMailer'
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

// -----------------------------------------------------------------------------
// ÉTAPE 1 : CONFIGURATION DE SÉCURITÉ (CORS)
// -----------------------------------------------------------------------------
header("Access-Control-Allow-Origin: https://form.actiiva.org");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Réponse à la requête "preflight" OPTIONS du navigateur
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -----------------------------------------------------------------------------
// ÉTAPE 2 : RÉCUPÉRATION ET VALIDATION DES DONNÉES
// -----------------------------------------------------------------------------
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);

if (empty($data['name']) || empty($data['email']) || empty($data['type']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Veuillez remplir tous les champs correctement.']);
    exit;
}

// -----------------------------------------------------------------------------
// ÉTAPE 3 : ENREGISTREMENT DANS LA BASE DE DONNÉES
// -----------------------------------------------------------------------------
require 'db_config.php'; // On utilise notre fichier de configuration centralisé
$newInstitutionId = null;

try {
     $sql = "INSERT INTO institutions (name, type, email) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    
    $stmt->execute([
        $data['name'],
        $data['type'],
        $data['email'] // On enregistre l'e-mail
    ]);
    
    $newInstitutionId = $pdo->lastInsertId();

} catch (PDOException $e) {
    error_log("Erreur BDD Actiiva: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Impossible d\'enregistrer la nouvelle institution.']);
    exit;
}

// -----------------------------------------------------------------------------
// ÉTAPE 4 : ENVOI DE L'EMAIL DE NOTIFICATION VIA PHPMailer
// -----------------------------------------------------------------------------
$mail = new PHPMailer(true);

try {
    // --- Configuration du serveur SMTP de LWS pour actiiva.org ---
    $mail->isSMTP();
    $mail->Host       = 'mail.actiiva.org';      // ex: mail.actiiva.org
    $mail->SMTPAuth   = true;                          
    $mail->Username   = 'contact@actiiva.org';   // ex: contact@actiiva.org
    $mail->Password   = 'fA1-GAF3H!4BX5y';    // Le mot de passe de cette boite mail
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   
    $mail->Port       = 465;                         

    // --- Destinataires ---
    $mail->setFrom('contact@actiiva.org', 'Formulaire de Vote Actiiva');
    $mail->addAddress('mevivital@gmail.com');     // L'adresse où vous recevrez la notification
    $mail->addReplyTo($data['email'], $data['name']);

    // --- Contenu de l'email ---
    $mail->isHTML(true); 
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Nouvelle Institution Ajoutée : ' . htmlspecialchars($data['name']);
    
    $emailBody = "Une nouvelle institution a été soumise via le formulaire de vote :<br><br>" .
                 "<b>Nom :</b> " . htmlspecialchars($data['name']) . "<br>" .
                 "<b>Type :</b> " . htmlspecialchars($data['type']) . "<br>" .
                 "<b>Email de contact (fourni par l'utilisateur) :</b> " . htmlspecialchars($data['email']) . "<br><br>" .
                 "L'institution a été ajoutée à la base de données avec l'ID : " . $newInstitutionId . "<br>" .
                 "Vous pouvez la vérifier dans phpMyAdmin.";
                 
    $mail->Body = $emailBody;

    $mail->send();
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success', 
        'message' => 'Institution ajoutée et notification envoyée.',
        'newId' => $newInstitutionId // On renvoie le nouvel ID au frontend
    ]);

} catch (Exception $e) {
    error_log("PHPMailer Error Actiiva: {$mail->ErrorInfo}");
    
    // L'institution a été ajoutée, mais l'email n'est pas parti. On renvoie un succès partiel.
    http_response_code(200); // On renvoie 200 car l'opération principale (ajout BDD) a réussi
    echo json_encode([
        'status' => 'success_with_mail_error', 
        'message' => "L'institution a été ajoutée, mais l'email de notification a échoué.",
        'mail_error' => $mail->ErrorInfo,
        'newId' => $newInstitutionId
    ]);
}

exit();
?>