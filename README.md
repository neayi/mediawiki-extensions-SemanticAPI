# SemanticAPI Extension

A MediaWiki extension that provides REST API endpoints for reading and writing Semantic MediaWiki (SMW) properties on pages.

## Overview

The SemanticAPI extension allows external applications to interact with Semantic MediaWiki data through a modern REST API interface. You can read existing semantic property values from pages and write new values programmatically.

## Features

- 📖 **Read SMW Properties**: Retrieve semantic property values from any page via GET requests
- ✏️ **Write SMW Properties**: Add or update semantic property values via POST requests
- 🔒 **Permission-based Access**: Respects MediaWiki's permission system (edit rights required for writing)
- 📦 **Multiple Input Formats**: Supports both JSON and form data for POST requests
- 🛡️ **Robust Error Handling**: Comprehensive validation and meaningful error messages
- 🌐 **RESTful Design**: Clean, standard REST API endpoints

## Requirements

- **MediaWiki**: >= 1.43
- **Semantic MediaWiki**: >= 6.0
- **PHP**: >= 8.0 (MediaWiki 1.43 requirement)

## Installation

### Prerequisites

**Semantic MediaWiki must be installed first!** This extension requires SMW to be installed and configured.

1. **Install Semantic MediaWiki** (if not already installed):
   - Follow the [SMW installation guide](https://www.semantic-mediawiki.org/wiki/Help:Installation)
   - Ensure SMW is working properly before proceeding

2. **Download or clone** this extension to your MediaWiki extensions directory:
   ```bash
   cd extensions/
   git clone https://github.com/neayi/mediawiki-extensions-SemanticAPI.git SemanticAPI
   ```

3. **Add to LocalSettings.php** (after SMW is loaded):
   ```php
   // Make sure SMW is loaded first
   wfLoadExtension( 'SemanticMediaWiki' );
   enableSemantics( 'your-domain.org' );
   
   // Then load SemanticAPI
   wfLoadExtension( 'SemanticAPI' );
   ```

4. **Update database** (if needed):
   ```bash
   php maintenance/update.php
   ```

## API Endpoints

### Base URLs
```
https://your-wiki.org/w/rest.php/semanticproperty/{title}           # GET, POST
https://your-wiki.org/w/rest.php/semanticproperty/{title}/{property} # DELETE
```

### GET - Read All Properties

Retrieve all semantic properties from a page.

**Endpoint**: `GET /semanticproperty/{title}`

**Parameters**:
- `{title}`: Page title (in URL path)

**Example**:
```bash
curl -X GET "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example"
```

**Response**:
```json
{
  "title": "Page:Example",
  "properties": {
    "HasValue": {
      "label": "HasValue",
      "type": "quantity",
      "type_id": "_qty",      
      "values": ["42", "99"],
      "count": 2
    },
    "Has_geographic_coordinates": {
      "label": "Has_geographic_coordinates",
      "type": "geographic",
      "type_id": "_geo",
      "values": [
            {
                "latitude": 48.284152,
                "longitude": 1.021355,
                "altitude": null,
                "display": "48.284152,1.021355"
            }
        ],
      "count": 1
     },
  },
  "total_properties": 2
}
```

### POST - Write Property Values

Add or update semantic property values on a page.

**Endpoint**: `POST /semanticproperty/{title}`

**Authentication**: Requires session authentication with edit permissions.

**Parameters**:
- `{title}`: Page title (in URL path)
- `property` (required): SMW property name (in request body)
- `value` (required): Property value to set (in request body)

#### JSON Format (Recommended)
```bash
curl -X POST "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example" \
  -H "Content-Type: application/json" \
  -b cookies.txt \
  -d '{"property":"HasValue","value":"42"}'
```

#### Form Data Format
```bash
curl -X POST "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example" \
  -b cookies.txt \
  -d "property=HasValue&value=42"
```

**Success Response**:
```json
{
  "result": "success",
  "title": "Page:Example",
  "property": "HasValue",
  "property_key": "HasValue",
  "property_label": "HasValue",
  "value": "42"
}
```

### DELETE - Remove Property Values

Remove all values for a specific semantic property from a page.

**Endpoint**: `DELETE /semanticproperty/{title}/{property}`

**Authentication**: Requires session authentication with edit permissions.

**Parameters**:
- `{title}`: Page title (in URL path)
- `{property}`: SMW property name to delete (in URL path)

#### Example
```bash
curl -X DELETE "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example/HasValue" \
  -b cookies.txt
```

**Success Response**:
```json
{
  "result": "success",
  "title": "Page:Example",
  "property": "HasValue",
  "message": "Property deleted successfully"
}
```

## Error Responses

The API returns appropriate HTTP status codes and error messages:

### Common Error Responses

**400 Bad Request** - Invalid parameters:
```json
{
  "error": "Missing required parameters: title or property"
}
```

**403 Forbidden** - Permission denied:
```json
{
  "error": "Permission denied"
}
```

**404 Not Found** - Page doesn't exist:
```json
{
  "error": "Page does not exist: NonExistentPage"
}
```

**500 Internal Server Error** - SMW error:
```json
{
  "error": "SMW error: Invalid property type"
}
```

## Authentication

### Session Authentication (Required)

MediaWiki 1.43 REST API requires session-based authentication. You need to login via the Action API first, then use the session cookies for REST calls.

#### Step 1: Get Login Token
```bash
curl -c cookies.txt "https://your-wiki.org/w/api.php?action=query&meta=tokens&type=login&format=json"
```

#### Step 2: Login with BotPassword
```bash
curl -b cookies.txt -c cookies.txt -d "action=login&lgname=YourBot@AppName&lgpassword=BotPassword&lgtoken=TOKEN&format=json" "https://your-wiki.org/w/api.php"
```

#### Step 3: Use Session Cookies for REST API
```bash
curl -b cookies.txt -X POST -H "Content-Type: application/json" -d '{"property":"HasValue","value":"42"}' "https://your-wiki.org/w/rest.php/semanticproperty/Page:Example"
```

### BotPassword Setup

1. **Create a BotPassword**:
   - Go to `Special:BotPasswords` on your wiki
   - Create a new bot password with "Edit existing pages" permission
   - Note the username format: `Username@AppName`
   - Save the generated password

## Code Examples

### PHP Example

```php
<?php
// Read all properties from a page
$response = file_get_contents(
    "https://your-wiki.org/w/rest.php/semanticproperty/" . urlencode('Page:Example')
);
$data = json_decode($response, true);

// Write a property (requires session authentication)
// First, login to get session cookies
$loginToken = getLoginToken();
login($loginToken, 'YourBot@AppName', 'BotPassword');

// Then make authenticated request
$postData = json_encode([
    'property' => 'HasValue',
    'value' => '42'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Content-Type: application/json',
            'Cookie: ' . getCookies()
        ],
        'content' => $postData
    ]
]);

$response = file_get_contents('https://your-wiki.org/w/rest.php/semanticproperty/Page:Example', false, $context);
?>
```

### JavaScript Example

```javascript
// Read all properties from a page
const response = await fetch(
    'https://your-wiki.org/w/rest.php/semanticproperty/Page:Example'
);
const data = await response.json();

// Write a property (requires session authentication)
// First authenticate via Action API, then use cookies for REST calls
const writeResponse = await fetch('https://your-wiki.org/w/rest.php/semanticproperty/Page:Example', {
    method: 'POST',
    credentials: 'include', // Include cookies
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        property: 'HasValue',
        value: '42'
    })
});

// Delete a property
const deleteResponse = await fetch('https://your-wiki.org/w/rest.php/semanticproperty/Page:Example/HasValue', {
    method: 'DELETE',
    credentials: 'include'
});
```

### Python Example

```python
import requests
import json

# Create session to maintain cookies
session = requests.Session()

# Step 1: Get login token
token_response = session.get(
    'https://your-wiki.org/w/api.php',
    params={'action': 'query', 'meta': 'tokens', 'type': 'login', 'format': 'json'}
)
login_token = token_response.json()['query']['tokens']['logintoken']

# Step 2: Login
session.post(
    'https://your-wiki.org/w/api.php',
    data={
        'action': 'login',
        'lgname': 'YourBot@AppName',
        'lgpassword': 'BotPassword',
        'lgtoken': login_token,
        'format': 'json'
    }
)

# Step 3: Read all properties from a page
response = session.get(
    'https://your-wiki.org/w/rest.php/semanticproperty/Page:Example'
)
data = response.json()

# Step 4: Write a property
response = session.post(
    'https://your-wiki.org/w/rest.php/semanticproperty/Page:Example',
    json={'property': 'HasValue', 'value': '42'}
)

# Step 5: Delete a property
response = session.delete(
    'https://your-wiki.org/w/rest.php/semanticproperty/Page:Example/HasValue'
)
```

## Property Types

The extension supports all standard SMW property types:

- **Text**: Simple text values
- **Number**: Numeric values  
- **Date**: Date values (various formats)
- **Boolean**: Yes/No values
- **Page**: Links to other pages
- **URL**: Web addresses
- **Email**: Email addresses
- **And more**: All SMW data types are supported

## Troubleshooting

### Common Issues

1. **"Permission denied" errors**:
   - Ensure your bot has edit permissions
   - Check that BotPassword is configured correctly
   - Verify the page isn't protected

2. **"Invalid property" errors**:
   - Make sure the property exists in SMW
   - Check property name spelling and case sensitivity
   - Ensure it's not a system property

3. **"Page does not exist" errors**:
   - Create the page first, or check the title format
   - Remember to include namespace prefixes (e.g., "Page:Example")

### Debug Mode

Add debugging to your requests by checking HTTP response codes and error messages in the JSON response.

## Security Considerations

- Always use HTTPS in production
- Use BotPasswords instead of regular passwords
- Limit bot permissions to only what's needed
- Validate all input data before making API calls
- Consider rate limiting for high-volume usage

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## Support

- **Issues**: Report bugs on the GitHub issue tracker
- **Documentation**: MediaWiki.org extension page
- **Community**: MediaWiki and SMW community forums

## Changelog

### Version 1.0.0
- **RESTful URL Structure**: Title in URL path for all operations (`/semanticproperty/{title}`)
- **Complete CRUD Operations**: GET (read all properties), POST (create/update), DELETE (remove)
- **Session Authentication**: Compatible with MediaWiki 1.43 REST API authentication
- **Clean Response Format**: Normalized property names, structured JSON responses
- **Comprehensive Property Support**: All SMW property types supported
- **Error Handling**: Detailed error messages and appropriate HTTP status codes
- **Multiple Input Formats**: JSON and form data support for POST/DELETE operations
