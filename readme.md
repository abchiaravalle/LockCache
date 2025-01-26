# LockCache

LockCache is a WordPress plugin designed to cache unlocked password-protected posts while skipping admin users. The plugin enhances site performance by serving cached content when possible and denying direct public access to the cache directory for security.

---

## Features

- **Static Caching**: Caches unlocked password-protected posts for faster delivery.
- **Cache Management**: Includes an admin panel for managing and viewing cached files and logs.
- **Preloading**: Provides an option to preload cache for all password-protected posts.
- **Security**: Denies direct access to the cache directory using `.htaccess`.
- **Debug Logging**: Maintains detailed debug logs for cache-related actions.
- **Admin-Only Access**: Top-level menu for admin users to manage the plugin.

---

## Installation

1. Download the plugin files and place them in the `/wp-content/plugins/lockcache` directory.
2. Navigate to your WordPress admin dashboard.
3. Go to **Plugins > Installed Plugins** and activate "LockCache."

---

## How It Works

### General Workflow

1. **Detect Password-Protected Posts**:
   - The plugin identifies posts that are password-protected.
   - If the post is still locked, it sets `no-cache` headers to prevent caching.

2. **Serve Cached Content**:
   - If the post is unlocked and cached, the plugin serves the static HTML file.
   - Admin users are excluded to avoid conflicts with admin-specific elements like the admin bar.

3. **Cache New Content**:
   - For unlocked posts not already cached, the plugin captures the rendered output and saves it as a static HTML file.
   - The cache files are stored in the `/wp-content/pp-static-cache/` directory with `0600` permissions.

4. **Admin Panel**:
   - Accessible under a top-level menu in the WordPress admin dashboard.
   - Allows admins to:
     - Clear all cached files.
     - Clear cache for individual posts.
     - Preload cache for all password-protected posts.
     - View debug logs.

---

## Usage

### Cache Management

- Navigate to **LockCache** in your WordPress admin dashboard.
- Use the buttons provided to:
  - **Clear All Cache**: Removes all cached files.
  - **Preload All**: Generates cache for all unlocked password-protected posts.
  - **Clear Cache for Individual Posts**: Clears cache for specific posts via the provided post list.

### Debug Logs

- View debug logs in the admin panel to monitor caching activity.
- Logs are stored in `pp-static-cache/ppsc-debug.log` and include timestamps for each action.

---

## Security

- The cache directory (`/wp-content/pp-static-cache/`) is secured with a `.htaccess` file to deny direct public access.
- Cache files and logs are created with restricted permissions (`0600`).

---

## Requirements

- WordPress 5.0 or higher.
- PHP 7.4 or higher.

---

## Contributing

Contributions are welcome! To contribute:

1. Fork the repository.
2. Create a new branch for your feature or bug fix.
3. Submit a pull request with a detailed explanation of your changes.

---

## License

This plugin is open-source and distributed under the MIT License. See the `LICENSE` file for details.

---

## Troubleshooting

- **Cache Not Serving:** Ensure the cache directory (`/wp-content/pp-static-cache/`) is writable.
- **Debug Logs Empty:** Check file permissions for `ppsc-debug.log`.
- **Preloading Fails:** Ensure your site uses shared or consistent passwords for password-protected posts.

---

## FAQ

### 1. Why isn’t a post being cached?
- The post may still be locked (password form present).
- The current user may have admin privileges.

### 2. How do I view cached files?
- Cached files are stored in `/wp-content/pp-static-cache/` with filenames like `cache-<post_id>.html`.

### 3. Can I disable the plugin’s cache for specific posts?
- Currently, no. Cache behavior is automated based on password status.
