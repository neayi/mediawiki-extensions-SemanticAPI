<?php
/**
 * Example of modifying an SMW property via MediaWiki REST API using session authentication
 * MediaWiki >= 1.43, SemanticMediaWiki >= 6
 */

// Configuration
$baseUrl = "https://wiki.yourwiki.org"; // Base wiki URL
$apiUrl = "$baseUrl/api.php";                       // Action API endpoint  
$restUrl = "$baseUrl/rest.php/semanticproperty";    // REST API endpoint
$username = "Test user@Robotname";                  // BotPassword username
$password = "t3d0hjj8giii5bqeq8qkqg5es4t1n9cr";     // BotPassword password

$title = "Maraîchage";
$property = "Foo";
$value = "Bar";

// Cookie jar to maintain session across requests
$cookieJar = tempnam(sys_get_temp_dir(), 'mediawiki_cookies');

/**
 * Step 1: Get a login token via Action API
 */
echo "=== STEP 1: Getting login token ===\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl . '?action=query&meta=tokens&type=login&format=json',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$data = json_decode($response, true);
$loginToken = $data['query']['tokens']['logintoken'] ?? null;

if (!$loginToken) {
    die("Failed to get login token: $response\n");
}

echo "Login token obtained: " . substr($loginToken, 0, 10) . "...\n";
curl_close($ch);

/**
 * Step 2: Login via Action API with bot password
 */
echo "\n=== STEP 2: Logging in with bot password ===\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'action' => 'login',
        'lgname' => $username,
        'lgpassword' => $password,
        'lgtoken' => $loginToken,
        'format' => 'json'
    ]),
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$loginData = json_decode($response, true);

if (curl_errno($ch)) {
    die("cURL Error during login: " . curl_error($ch) . "\n");
}

echo "Login response: $response\n";

if ($loginData['login']['result'] !== 'Success') {
    die("Login failed: " . $loginData['login']['result'] . "\n");
}

echo "Successfully logged in!\n";
curl_close($ch);

/**
 * Step 3: Make REST API call with session cookies
 */
echo "\n=== STEP 3: Making REST API PUT request ===\n";

$putUrl = "$baseUrl/rest.php/semanticproperty/" . urlencode($title);
$restData = json_encode([
    'property' => $property,
    'value' => $value
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $putUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $restData,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_COOKIEFILE => $cookieJar,  // Use session cookies
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP Code: $httpCode\n";
    echo "REST Response:\n$response\n";
}

curl_close($ch);

/**
 * Step 4: Wait a moment for SMW to process, then test GET request for all properties
 */
echo "\n=== STEP 4: Testing GET request for all properties ===\n";
sleep(2); // Give SMW time to process

$getUrl = "$baseUrl/rest.php/semanticproperty/" . urlencode($title);

$ch_get = curl_init();
curl_setopt_array($ch_get, [
    CURLOPT_URL => $getUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);

$getResponse = curl_exec($ch_get);

if (curl_errno($ch_get)) {
    echo "cURL GET Error: " . curl_error($ch_get) . "\n";
} else {
    $httpCode = curl_getinfo($ch_get, CURLINFO_HTTP_CODE);
    echo "GET HTTP Code: $httpCode\n";
    echo "GET Response:\n$getResponse\n";
}

curl_close($ch_get);

/**
 * Step 5: Test DELETE request to remove the property
 */
echo "\n=== STEP 5: Testing DELETE request ===\n";

$deleteUrl = "$baseUrl/rest.php/semanticproperty/" . urlencode($title) . "/" . urlencode($property);

$ch_delete = curl_init();
curl_setopt_array($ch_delete, [
    CURLOPT_URL => $deleteUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'DELETE',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_COOKIEFILE => $cookieJar,  // Use session cookies
    CURLOPT_SSL_VERIFYPEER => false,
]);

$deleteResponse = curl_exec($ch_delete);

if (curl_errno($ch_delete)) {
    echo "cURL DELETE Error: " . curl_error($ch_delete) . "\n";
} else {
    $httpCode = curl_getinfo($ch_delete, CURLINFO_HTTP_CODE);
    echo "DELETE HTTP Code: $httpCode\n";
    echo "DELETE Response:\n$deleteResponse\n";
}

curl_close($ch_delete);

/**
 * Step 6: Verify property was deleted by getting all properties again
 */
echo "\n=== STEP 6: Verifying property deletion ===\n";

$ch_verify = curl_init();
curl_setopt_array($ch_verify, [
    CURLOPT_URL => "$baseUrl/rest.php/semanticproperty/" . urlencode($title),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_SSL_VERIFYPEER => false,
]);

$verifyResponse = curl_exec($ch_verify);

if (curl_errno($ch_verify)) {
    echo "cURL VERIFY Error: " . curl_error($ch_verify) . "\n";
} else {
    $httpCode = curl_getinfo($ch_verify, CURLINFO_HTTP_CODE);
    echo "VERIFY HTTP Code: $httpCode\n";
    echo "VERIFY Response:\n$verifyResponse\n";
}

curl_close($ch_verify);

// Clean up cookie file
unlink($cookieJar);

/*
=== Session-Based Authentication Examples ===

This example demonstrates the proper way to authenticate with MediaWiki 1.43 REST API:

1. First get a login token via Action API:
   GET /w/api.php?action=query&meta=tokens&type=login&format=json

2. Login with bot password via Action API:
   POST /w/api.php
   - action=login
   - lgname=UserName@AppName  
   - lgpassword=BotPassword
   - lgtoken=<token from step 1>

3. Use session cookies for REST API calls:
   POST /w/rest.php/semanticproperty
   - Include session cookies from login
   - JSON or form data as usual

=== Alternative: Direct cURL Commands ===

# Step 1: Get login token
curl -c cookies.txt "https://your-wiki.org/w/api.php?action=query&meta=tokens&type=login&format=json"

# Step 2: Login with bot password
curl -b cookies.txt -c cookies.txt -d "action=login&lgname=BotName@AppName&lgpassword=BotPassword&lgtoken=TOKEN&format=json" "https://your-wiki.org/w/api.php"

# Step 3: Make REST API call to set property (title in URL)
curl -b cookies.txt -X PUT -H "Content-Type: application/json" -d '{"property":"HasValue","value":"99"}' "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example"

# Read all properties for a page (no auth needed)
curl "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example"

# Delete a property (requires auth, title and property in URL)
curl -b cookies.txt -X DELETE "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example/HasValue"
*/