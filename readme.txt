=== MMPRO Email Alias Manager ===
Contributors: memberminderpro
Tags: email, forwarding, aliases, cloudflare, api
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Easily manage email aliases and forwarding rules through WordPress with Cloudflare integration.

== Description ==

MMPRO Email Alias Manager allows WordPress administrators to manage email aliases and forwarding rules directly from the WordPress admin interface. Combined with a Cloudflare Email Worker, it enables complete control over email forwarding without requiring access to email servers or the Cloudflare dashboard.

This solution is perfect for agencies and developers who manage multiple WordPress sites and need to provide email forwarding functionality to their clients without sharing Cloudflare credentials.

= Key Features =

* User-friendly interface for managing email aliases and destinations
* Automatic JSON API endpoint for Cloudflare integration
* Import/Export functionality (CSV and JSON formats)
* Built-in caching for optimal performance
* No external dependencies required
* Secure by design - respects WordPress security best practices
* Single Cloudflare Worker can manage multiple domains

= How It Works =

1. Install the plugin on your WordPress site
2. Configure your email aliases in the WordPress admin
3. Set up the Cloudflare Worker (one-time setup)
4. Configure Cloudflare Email Routing to use the worker
5. Incoming emails are automatically forwarded to the specified destinations

= Use Cases =

* Manage email forwarding for your organization without changing DNS or email server settings
* Allow clients to manage their own email forwarding through WordPress
* Create temporary email aliases for events or projects
* Set up team email addresses that forward to multiple recipients

= Cloudflare Integration =

This plugin works seamlessly with Cloudflare's Email Routing service. A single Cloudflare Worker can handle email forwarding for all your domains - no need to create separate workers for each domain.

= Developer-Friendly =

Developers can extend the plugin's functionality through WordPress filters and actions. The API endpoint can be used for other integrations beyond the provided Cloudflare Worker.

== Installation ==

= WordPress Plugin Installation =

1. Upload the `mmpro-email-alias-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to the 'Email Aliases' menu item in your WordPress admin
4. Add your email aliases and forward destinations
5. Flush permalinks by going to Settings > Permalinks and clicking "Save Changes"

= Cloudflare Worker Setup =

1. Log into your Cloudflare dashboard
2. Go to Workers & Pages
3. Create a new Worker
4. Copy and paste the worker code from the plugin's documentation (see FAQ)
5. Deploy the worker
6. Set up Email Routing for your domain:
   - Go to Email > Email Routing
   - Set up a "Catch-all address" or specific email routes
   - Select "Send to a Worker" and choose your newly created worker

== Frequently Asked Questions ==

= Where do I find the Cloudflare Worker code? =

The Cloudflare Worker code can be found in the plugin's documentation or on our GitHub repository. Here's the basic worker code:

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
      console.error(`MMPRO Email Forwarding error for ${message.to}: ${error.message}`);
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

= Do I need to install the plugin on all my WordPress sites? =

Yes. To manage email aliases for a domain, install the plugin on the WordPress site for that domain.

= Can one Cloudflare Worker handle multiple domains? =

Yes. The same worker can handle email forwarding for all your domains - no configuration changes needed.

= How do I import existing email aliases? =

The plugin supports importing email aliases from CSV or JSON files. The import/export tools are available on the Email Aliases admin page.

Example CSV format:
```
Alias,Destinations
support@example.com,"help@company.com,admin@company.com"
info@example.com,contact@company.com
```

Example JSON format:
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

= What happens if the WordPress API is unavailable? =

If the API is unavailable, the worker will log an error and emails will be held in Cloudflare's queue according to their policy.

= How often are alias changes reflected in email forwarding? =

Changes made through the WordPress admin are immediately available in the API. The worker may continue using cached data for up to 1 hour.

= Is this plugin compatible with Cloudflare's Email Routing? =

Yes, this plugin is designed to work with Cloudflare's Email Routing service.

= How can I configure the Cloudflare Worker? =

The Cloudflare Worker supports these optional environment variables:

1. `API_DOMAIN` - Override the domain for API requests (default: domain of incoming email)
2. `API_PATH` - Custom path to the API endpoint (default: `/api/aliases/`)
3. `ALERT_WEBHOOK` - Webhook URL for error notifications

= Can I extend or modify the plugin? =

Yes, the plugin can be extended through WordPress filters:

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

= How secure is this solution? =

The plugin follows WordPress security best practices. The API endpoint is public but only returns email alias information (no sensitive data). For added security, you can add authentication to the API endpoint using WordPress hooks.

== Screenshots ==

1. Email Aliases management interface
2. Adding a new email alias
3. Import/Export functionality
4. Cloudflare Worker setup

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of MMPRO Email Alias Manager.

== Additional Resources ==

For more information, visit our [GitHub repository](https://github.com/memberminderpro/mmpro-email-alias-manager) or contact us at [support@memberminderpro.com](mailto:support@memberminderpro.com).