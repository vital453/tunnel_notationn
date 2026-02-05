<?php

/**
 * API pour la soumission d'un vote.
 * Version finale avec gestion des commentaires.
 * 1. Enregistre le vote et le commentaire.
 * 2. Calcule les scores globaux et détaillés.
 * 3. Envoie un email de notification COMPLET à l'université.
 * 4. Renvoie les scores au frontend.
 */

// On importe les classes de PHPMailer et on charge les configs
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'db_config.php';

// --- Définition des critères (pour les utiliser dans l'email) ---
$criteria_labels = [
    'reputation' => "Réputation institutionnelle et crédibilité académique",
    'training_offer' => "Qualité et diversité de l’offre de formation",
    'governance' => "Gouvernance, leadership et stratégie institutionnelle",
    'societal_impact' => "Contribution à la recherche, à l’employabilité et à l’impact sociétal",
    'student_experience' => "Attractivité et expérience étudiante"
];

// -----------------------------------------------------------------------------
// PARTIE 1 : GESTION DE LA REQUÊTE ET ENREGISTREMENT DU VOTE
// -----------------------------------------------------------------------------
header("Access-Control-Allow-Origin: https://form.actiiva.org");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['institution_id']) || !isset($data['ratings']) || !is_array($data['ratings'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Données invalides.']);
    exit();
}

$institution_id = $data['institution_id'];
$ratings = $data['ratings'];
// On récupère le commentaire (s'il existe et n'est pas vide)
$comment = isset($data['comment']) && !empty(trim($data['comment'])) ? trim($data['comment']) : null;
$university_data = null;

try {
    // On récupère les infos de l'université (nom et email)
    $stmt_uni = $pdo->prepare("SELECT name, email FROM institutions WHERE id = ?");
    $stmt_uni->execute([$institution_id]);
    $university_data = $stmt_uni->fetch(PDO::FETCH_ASSOC);

    if (!$university_data) {
        throw new Exception("Université non trouvée.");
    }

    // --- VERIFICATION DU NOMBRE DE VOTES PAR IP ---
    $ip_address = $_SERVER['REMOTE_ADDR'];

    // On compte le nombre TOTAL de lignes insérées paret IP
    // (1 vote complet = 5 lignes de critères)
    $stmt_check_ip = $pdo->prepare("SELECT COUNT(*) FROM ratings WHERE ip_address = ?");
    $stmt_check_ip->execute([$ip_address]);
    $total_entries = $stmt_check_ip->fetchColumn();

    // Limite = 3 votes * 5 critères = 15 entrées
    $limit_entries = 3 * count($criteria_labels);

    if ($total_entries >= $limit_entries) {
        http_response_code(403);
        echo json_encode(['error' => 'Vous avez atteint la limite de 3 évaluations autorisées.']);
        exit();
    }
    // ----------------------------------------------

    // On enregistre les 5 nouvelles notes et le commentaire
    $pdo->beginTransaction();
    $sql = "INSERT INTO ratings (institution_id, criterion_key, rating_value, comment, ip_address) VALUES (:institution_id, :criterion_key, :rating_value, :comment, :ip_address)";
    $stmt = $pdo->prepare($sql);

    // On insère le commentaire une seule fois, avec le premier critère, pour éviter la redondance
    $isFirst = true;
    foreach ($ratings as $key => $value) {
        $current_comment = $isFirst ? $comment : null;
        $stmt->execute([
            'institution_id' => $institution_id,
            'criterion_key' => $key,
            'rating_value' => $value,
            'comment' => $current_comment,
            'ip_address' => $ip_address
        ]);
        $isFirst = false;
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de l\'enregistrement du vote.', 'details' => $e->getMessage()]);
    exit();
}

// -----------------------------------------------------------------------------
// PARTIE 2 : CALCUL DES SCORES
// -----------------------------------------------------------------------------
$stmt_global = $pdo->prepare("SELECT COUNT(*) / 5 as voteCount, AVG(rating_value) * 20 as averageScore FROM ratings WHERE institution_id = ?");
$stmt_global->execute([$institution_id]);
$global_scores = $stmt_global->fetch(PDO::FETCH_ASSOC);

$stmt_detailed = $pdo->prepare("SELECT criterion_key, AVG(rating_value) as average_rating FROM ratings WHERE institution_id = ? GROUP BY criterion_key");
$stmt_detailed->execute([$institution_id]);
$detailed_scores_raw = $stmt_detailed->fetchAll(PDO::FETCH_KEY_PAIR);

$final_scores = [
    'voteCount'    => floor($global_scores['voteCount']),
    'averageScore' => $global_scores['averageScore'],
    'detailedScores' => $detailed_scores_raw
];

// -----------------------------------------------------------------------------
// PARTIE 3 : ENVOI DE L'EMAIL DE NOTIFICATION AVEC LE COMMENTAIRE
// -----------------------------------------------------------------------------
if ($university_data && !empty($university_data['email'])) {
    $mail = new PHPMailer(true);
    try {
        // --- Configuration SMTP ---
        $mail->isSMTP();
        $mail->Host       = 'mail.actiiva.org';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'contact@actiiva.org';
        $mail->Password   = 'fA1-GAF3H!4BX5y';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // --- Destinataires ---
        $mail->setFrom('contact@actiiva.org', 'Plateforme Actiiva Awards');
        $mail->addAddress('fernand@urban-technology.net'); // Pour vos tests
        // $mail->addAddress($university_data['email'], $university_data['name']); // Ligne de production
        $mail->addBCC('mevivital453@gmail.com');

        // --- Contenu de l'email ---
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $comment ? 'Nouvel Avis (avec commentaire) pour votre Établissement' : 'Nouvel Avis pour votre Établissement sur Actiiva';

        $ratings_html = '';
        foreach ($ratings as $key => $value) {
            $label = isset($criteria_labels[$key]) ? $criteria_labels[$key] : ucfirst($key);
            $stars = str_repeat('⭐', $value) . str_repeat('⚪', 5 - $value);
            $ratings_html .= '<tr style="border-bottom: 1px solid #eeeeee;"><td style="padding: 12px 0; color: #555555;">' . htmlspecialchars($label) . '</td><td style="padding: 12px 0; text-align: right; font-size: 18px;" title="' . $value . '/5">' . $stars . '</td></tr>';
        }

        $comment_html = '';
        if ($comment) {
            $comment_html = '
            <h2 style="color:#3d3d3d;font-size:18px;border-bottom:2px solid #f39c12;padding-bottom:10px;margin-top:30px;">Commentaire laissé par le participant</h2>
            <div style="background-color:#f8f9fa;padding:15px;border-radius:5px;border-left:4px solid #f39c12;margin-top:15px;">
                <p style="color:#555555;line-height:1.6;font-size:16px;margin:0;font-style:italic;">"' . nl2br(htmlspecialchars($comment)) . '"</p>
            </div>';
        }

        $emailBody = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap");
                body { font-family: "Poppins", sans-serif; margin: 0; padding: 0; background-color: #f4f7f6; }
            </style>
        </head>
        <body style="font-family: \'Poppins\', sans-serif; margin: 0; padding: 0; background-color: #f4f7f6;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f7f6;">
                <tr>
                    <td align="center">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                            <tr>
                                <td style="background-color: #005a9c; padding: 30px; text-align: center;">
                                    <a href="https://actiiva.org" target="_blank">
                                        <img src="https://actiiva.org/wp-content/uploads/2024/09/Logo-ACTiiVA-Fond-transp-1.png" alt="Logo Actiiva" style="max-width: 180px;">
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px;">
                                    <h1 style="color: #005a9c; font-size: 22px;">Votre Score a été Mis à Jour !</h1>
                                    <p style="color: #555555; line-height: 1.6; font-size: 16px;">
                                        Bonjour,<br><br>
                                        Votre établissement, <strong>' . htmlspecialchars($university_data['name']) . '</strong>, a reçu un nouvel avis. Voici votre situation globale actualisée :
                                    </p>
                                    
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 25px; margin-bottom: 25px; background-color: #f8f9fa; border-radius: 5px; padding: 20px; text-align: center;">
                                        <tr>
                                            <td>
                                                <p style="color: #555555; font-size: 16px; margin: 0 0 5px 0;">Score global actuel de votre établissement</p>
                                                <div style="font-size: 36px; font-weight: 700; color: #005a9c; margin-bottom: 5px;">' . number_format($final_scores['averageScore'], 1, ',', '') . ' / 100</div>
                                                <p style="color: #7f8c8d; font-size: 14px; margin: 0;">Basé sur ' . $final_scores['voteCount'] . ' évaluation(s)</p>
                                            </td>
                                        </tr>
                                    </table>

                                    <h2 style="color: #3d3d3d; font-size: 18px; border-bottom: 2px solid #f39c12; padding-bottom: 10px;">Détail du dernier avis reçu</h2>
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top: 15px;">' . $ratings_html . '</table>
                                    
                                    ' . $comment_html . '

                                    <div style="text-align: center;">
                                        <a href="https://form.actiiva.org" target="_blank" style="display: inline-block; background-color: #f39c12; color: #ffffff !important; padding: 12px 25px; border-radius: 5px; text-decoration: none; font-weight: 600; margin-top: 30px;">Voir le classement en direct</a>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="background-color: #3d3d3d; color: #cccccc; padding: 20px; text-align: center; font-size: 12px;">
                                    &copy; ' . date("Y") . ' Actiiva. Tous droits réservés.<br>
                                    Vous recevez cet e-mail car votre établissement est listé sur notre plateforme d\'évaluation.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ';

        $mail->Body = $emailBody;
        $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error (to university): {$mail->ErrorInfo}");
    }
}

// -----------------------------------------------------------------------------
// PARTIE 4 : RÉPONSE AU FRONTEND
// -----------------------------------------------------------------------------
echo json_encode(['status' => 'success', 'scores' => $final_scores]);
exit();
