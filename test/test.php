<?php
/**
 * Exemple de modification d'une propriété SMW via REST API MediaWiki
 * MediaWiki >= 1.43, SemanticMediaWiki >= 6
 */

$restUrl = "https://tonwiki.org/w/rest.php/semanticproperty"; // REST endpoint
$username = "MonBot";     // Nom utilisateur du BotPassword
$password = "BotPassword"; // Mot de passe du BotPassword

$title = "Page:Exemple";
$property = "HasValue";
$value = "42";

// 1. Créer la session cURL
$ch = curl_init();

// 2. Préparer les données JSON
$data = json_encode([
    'title' => $title,
    'property' => $property,
    'value' => $value
]);

// 3. Authentification HTTP Basic (BotPassword)
curl_setopt_array($ch, [
    CURLOPT_URL => $restUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_USERPWD => "$username:$password", // BotPassword
    CURLOPT_SSL_VERIFYPEER => false, // optionnel si certificat SSL auto-signé
]);

// 4. Exécuter la requête REST
$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "Erreur cURL : " . curl_error($ch) . "\n";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP Code : $httpCode\n";
    echo "Réponse REST :\n$response\n";
}

curl_close($ch);

/*
curl -X GET "https://tonwiki.org/w/rest.php/semanticproperty?title=Page:Exemple&property=HasValue"

curl -X POST "https://tonwiki.org/w/rest.php/semanticproperty" \
  -H "Content-Type: application/json" \
  -u MonBot:BotPassword \
  -d '{"title":"Page:Exemple","property":"HasValue","value":"99"}'

  
*/