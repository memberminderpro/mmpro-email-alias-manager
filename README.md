# MMPRO Email Alias Manager

![MMPRO Email Alias Manager](assets/img/readme-banner.png)

MMPRO Email Alias Manager is a WordPress plugin that allows you to easily manage email aliases and forwarding rules through a simple admin interface. Combined with a Cloudflare Email Worker, it enables WordPress admins to manage email forwarding without needing access to the domain's email server or Cloudflare account.

## Overview

This solution consists of two parts:
1. **WordPress Plugin**: Manages aliases through a user-friendly interface and provides a JSON API
2. **Cloudflare Worker**: Fetches alias data from WordPress and handles email forwarding

The plugin automatically creates an API endpoint (`/api/aliases/`) on your WordPress site that outputs the email aliases as JSON. The Cloudflare Worker fetches this data and uses it to route incoming emails to their proper destinations.

## Features

- User-friendly interface for managing email aliases and destinations
- Automatic JSON API endpoint for Cloudflare integration
- Import/Export functionality (CSV and JSON formats)
- Built-in caching for optimal performance
- No external dependencies required
- Secure by design - respects WordPress security best practices
- Single Cloudflare Worker can manage multiple domains

## WordPress Plugin Installation

1. Upload the `mmpro-email-alias-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to the 'Email Aliases' menu item in your WordPress admin
4. Add your email aliases and forward destinations
5. Flush permalinks by going to Settings > Permalinks and clicking "Save Changes"

## Cloudflare Worker Setup

1. Log into your Cloudflare dashboard
2. Go to Workers & Pages
3. Create a new Worker
4. Copy and paste the following worker code:

```js
export default {
  // Cache for storing the routing map
  routingMapCache: null,
  routingMapExpiry: 0,
  cacheTTL: 3600000, // Cache TTL: 1 hour in milliseconds
  
  async email(message, env, ctx) {
    try {
      // Get site-specific API endpoint from environment variables
      // Fallback to the domain of the incoming email if not specified
      const apiDomain = env.API_DOMAIN || message.to.split('@')[1];
      
      // Get routing map from API with caching
      const routingMap = await this.getRoutingMap(apiDomain, env);
      
      // Extract local part and full email
      const [localPart, domain] = message.to.split("@");
      const fullEmail = message.to.toLowerCase();
      const localPartLower = localPart.toLowerCase();
      
      // Try multiple possible key formats
      const recipients = 
        routingMap[fullEmail] || 
        routingMap[localPartLower + "@" + domain] ||
        routingMap[localPartLower];
      
      // If no mapping found, log and drop
      if (!recipients || !recipients.length) {
        console.log(`MMPRO Email Forwarding: No mapping found for ${message.to}`);
        return;
      }
      
      // Forward to each recipient
      for (const recipient of recipients) {
        await message.forward(recipient);
      }
      
    } catch (error) {
      // Log error details for monitoring
      console.error(`MMPRO Email Forwarding error for ${message.to}: ${error.message}`);
      
      // Send alert if webhook is configured
      if (env.ALERT_WEBHOOK) {
        try {
          await fetch(env.ALERT_WEBHOOK, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              service: "MMPRO Email Forwarding",
              error: `Email routing error: ${error.message}`,
              email: message.to,
              timestamp: new Date().toISOString()
            })
          });
        } catch (webhookError) {
          console.error("MMPRO Email Forwarding: Failed to send alert:", webhookError);
        }
      }
      
      // Return without processing (email will be held in queue per Cloudflare behavior)
      return;
    }
  },
  
  async getRoutingMap(domain, env) {
    const now = Date.now();
    
    // Return cached map if it exists and hasn't expired
    if (this.routingMapCache && now < this.routingMapExpiry) {
      return this.routingMapCache;
    }
    
    // Configure API path - can be customized via environment variable
    const apiPath = env.API_PATH || '/api/aliases/';
    const apiUrl = `https://${domain}${apiPath}`;
    
    console.log(`MMPRO Email Forwarding: Fetching aliases from ${apiUrl}`);
    
    // Fetch the routing map from WordPress API
    const response = await fetch(apiUrl, {
      cf: {
        cacheTTL: 300,       // Cache for 5 minutes at Cloudflare edge
        cacheEverything: true
      }
    });
    
    if (!response.ok) {
      throw new Error(`API responded with status ${response.status}`);
    }
    
    // Parse the response as JSON
    const routingMap = await response.json();
    
    // Store in cache with expiry time
    this.routingMapCache = routingMap;
    this.routingMapExpiry = now + this.cacheTTL;
    
    return routingMap;
  }
}
```

5. Deploy the worker
6. Set up Email Routing for your domain in Cloudflare:
   - Go to Email > Email Routing
   - Set up a "Catch-all address" or specific email routes
   - Select "Send to a Worker" and choose your newly created worker

## WordPress Plugin Usage

### Managing Email Aliases

1. Navigate to 'Email Aliases' in your WordPress admin menu
2. Click 'Add New Alias' to create a new email alias
3. Enter the alias email address (e.g., `support@example.com`)
4. Add one or more destination email addresses
5. Click 'Save All Aliases' to save your changes

The aliases will be immediately available through the API endpoint.

### Testing API Endpoint

To verify that your API endpoint is working correctly, visit:
```
https://your-domain.com/api/aliases/
```

You should see a JSON output of your configured email aliases.

### Import/Export

The plugin supports importing and exporting email aliases in both CSV and JSON formats.

#### Export
1. Navigate to the 'Email Aliases' page
2. Click either "Export as JSON" or "Export as CSV"
3. Save the downloaded file

#### Import
1. Navigate to the 'Email Aliases' page
2. Click "Choose File" and select your import file
3. Select the appropriate format (JSON or CSV)
4. Click "Import"

##### CSV Format
```csv
Alias,Destinations
support@example.com,"help@company.com,admin@company.com"
info@example.com,contact@company.com
```

##### JSON Format
```json
{
  "support@example.com": [
    "help@company.com",
    "admin@company.com"
  ],
  "info@example.com": [
    "contact@company.com"
  ]
}
```

## Cloudflare Worker Configuration

The Cloudflare Worker supports these optional environment variables:

| Variable | Description | Default |
|----------|-------------|---------|
| `API_DOMAIN` | Override the domain for API requests | Domain of incoming email |
| `API_PATH` | Custom path to the API endpoint | `/api/aliases/` |
| `ALERT_WEBHOOK` | Webhook URL for error notifications | None |

### Setting Environment Variables

1. Go to your Worker in the Cloudflare dashboard
2. Click on "Settings" tab
3. Scroll down to "Environment Variables"
4. Add your variables as needed

## Developer Documentation

### WordPress Plugin File Structure

```
mmpro-email-alias-manager/
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
├── mmpro-email-alias-manager.php
└── uninstall.php
```

### REST API Endpoint

The plugin creates a REST API endpoint at:
```
/wp-json/mmpro/v1/aliases
```

And a rewrite rule to make it accessible at:
```
/api/aliases/
```

### Caching

The plugin uses WordPress transients to cache the alias data with a 1-hour expiration. The cache is automatically cleared when aliases are updated through the admin interface.

### Extending the Plugin

The plugin can be extended through WordPress filters:

```php
// Modify aliases before they are sent to the API
add_filter('mmpro_email_aliases_api_data', function($aliases) {
    // Modify $aliases array as needed
    return $aliases;
});

// Change the cache expiration time (in seconds)
add_filter('mmpro_email_aliases_cache_expiration', function($expiration) {
    return 7200; // 2 hours
});
```

### Cloudflare Worker Technical Details

The worker:
1. Receives incoming emails
2. Extracts the domain from the email address
3. Fetches the alias data from the WordPress API
4. Caches the data to minimize API calls
5. Forwards the email to the appropriate destination(s)

The worker includes three levels of caching:
1. In-memory caching (1 hour)
2. Cloudflare edge caching (5 minutes)
3. WordPress transient caching (1 hour)

## Frequently Asked Questions

### Do I need to install the plugin on all my WordPress sites?

Yes. To manage email aliases for a domain, install the plugin on the WordPress site for that domain.

### Can one Cloudflare Worker handle multiple domains?

Yes. The same worker can handle email forwarding for all your domains - no configuration changes needed.

### What happens if the WordPress API is unavailable?

If the API is unavailable, the worker will log an error and emails will be held in Cloudflare's queue according to their policy.

### How often are alias changes reflected in email forwarding?

Changes made through the WordPress admin are immediately available in the API. The worker may continue using cached data for up to 1 hour.

### Is this plugin compatible with Cloudflare's Email Routing?

Yes, this plugin is designed to work with Cloudflare's Email Routing service.

## License

MMPRO Email Alias Manager is licensed under the GPL v2 or later.

This plugin is provided by [Member Minder Pro, LLC](https://memberminderpro.com).