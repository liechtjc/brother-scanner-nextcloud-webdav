# Brother Scanner → Nextcloud WebDAV

A PHP shim that enables Brother MFC scanners to scan directly to Nextcloud using the printer's built-in **Scan to SharePoint** feature.

## The Problem

Brother's "Scan to SharePoint" uses Microsoft WebDAV extensions (MS-WDVME). Pointing it directly at Nextcloud's WebDAV endpoint fails because:

1. Nextcloud crashes with a `TypeError` in `HookConnector` when receiving Brother's bare PUT requests
2. Brother uses a LOCK/PUT/UNLOCK sequence inherited from SharePoint that Nextcloud doesn't support natively

## The Solution

A lightweight PHP shim that acts as a fake SharePoint server for Brother, forwarding only the actual scan data to Nextcloud WebDAV.

## How Brother's Protocol Works

### Connection Test sequence
```
1. PROPFIND /folder/            → check folder exists (expects 207)
2. PROPFIND /folder/_TEST_FILE  → check folder writable (expects 404)
3. PUT      /folder/_TEST_FILE  → empty PUT (create test file)
4. DELETE   /folder/_TEST_FILE  → clean up test file
```

### Real Scan sequence
```
1. PROPFIND /folder/             → check folder exists (expects 207)
2. PROPFIND /folder/_TEST_FILE   → check folder writable (expects 404)
3. PUT      /folder/filename.pdf → empty PUT, 0 bytes (reserve filename)
4. LOCK     /folder/filename.pdf → lock file (Microsoft WebDAV Class 2)
5. PUT      /folder/filename.pdf → real scan data with If: <lock-token> header
6. UNLOCK   /folder/filename.pdf → release lock
```

### What the shim does with each request

```
Brother                    scan.php shim              Nextcloud
   |                            |                          |
   |-- PROPFIND /folder/ ------>|                          |
   |<- 207 OK ------------------|                          |
   |                            |                          |
   |-- PROPFIND /_TEST_FILE --->|                          |
   |<- 404 (writable) ----------|                          |
   |                            |                          |
   |-- PUT (0 bytes) ---------->|                          |
   |<- 201 (fake ok) -----------|   ← swallowed, ignored  |
   |                            |                          |
   |-- LOCK ------------------->|                          |
   |<- 200 (fake lock) ---------|   ← fake lock token     |
   |                            |                          |
   |-- PUT (45761 bytes) ------>|-- PUT filename.pdf ----->|
   |                            |<- 201 Created -----------|
   |<- 201 Created -------------|                          |
   |                            |                          |
   |-- UNLOCK ----------------->|                          |
   |<- 204 (fake ok) -----------|   ← swallowed, ignored  |
```

## Authentication Flow

Brother sends no credentials by default. The shim issues a `WWW-Authenticate: Basic` challenge on first contact, which causes Brother to retry with the credentials configured in its profile. Those credentials are forwarded directly to Nextcloud — **no passwords are stored on the server**.

```
Brother                    scan.php shim                   Nextcloud
   |                            |                               |
   |-- PROPFIND (no auth) ----->|                               |
   |<- 401 WWW-Authenticate ----|                               |
   |                            |                               |
   |-- PROPFIND (Basic auth) -->|                               |
   |<- 207 OK ------------------|                               |
   |                            |                               |
   |-- PUT (real data) -------->|-- PUT + Authorization: ------>|
   |                            |                    Nextcloud auth middleware
   |                            |                    ← checks credentials
   |                            |                    Nextcloud filesystem layer
   |                            |                    ← enforces permissions
   |                            |<- 201 Created ----------------|
   |<- 201 Created -------------|                               |
```

Compared to normal Nextcloud WebDAV access:

```
Regular client (browser/app/desktop sync)
    |
    |-- HTTPS ------------------>  Apache
                                      |
                                   Nextcloud .htaccess rewrites
                                      |
                                   /remote.php/webdav/
                                      |
                                   Nextcloud auth middleware
                                      |  ← checks session/token/password
                                   Nextcloud filesystem layer
                                      |  ← enforces permissions
                                   /var/www/html/data/username/
```

The shim does not bypass Nextcloud's auth or permission stack — it simply bridges Brother's SharePoint protocol to standard WebDAV, passing credentials through transparently.

## Requirements

- Apache with `mod_php` (PHP 8.x)
- Nextcloud instance with WebDAV enabled
- Brother MFC scanner with Scan to SharePoint feature (tested on MFC-L9570CDW)

## Installation

### 1. Create the scan destination folder in Nextcloud

Create a folder (e.g. `scans`) in your Nextcloud instance via the web interface.

### 2. Create a Nextcloud App Password

In Nextcloud: **Settings → Security → Devices & Sessions → Create new app password**

### 3. Deploy the PHP shim

```bash
mkdir /var/www/html/brother
cp scan.php /var/www/html/brother/scan.php
chown www-data:www-data /var/www/html/brother/scan.php
```

### 4. Configure Apache vhost

Add to your SSL vhost (`/etc/apache2/sites-enabled/your-site-ssl.conf`) before `</VirtualHost>`:

```apache
<Location /brother>
    RewriteEngine Off
</Location>
```

### 5. Configure Nextcloud `.htaccess`

Add before the catch-all `RewriteRule . index.php` line in `/var/www/html/.htaccess`:

```apache
RewriteRule ^brother/ - [L]
```

### 6. Configure Brother printer

In the Brother Web UI (open `http://printer-ip` in browser):

- Go to **Scan → Scan to FTP/SFTP/Network/SharePoint**
- Select **SharePoint** tab
- Set the URL to: `https://your-nextcloud.com/brother/scan.php?folder=scans`
- Set SSL: enabled, port 443
- Set Authentication: **Basic**
- Enter your Nextcloud username and app password

## Multi-folder Support

Create one Brother profile per destination folder:

| Profile    | URL                                                                      |
|------------|--------------------------------------------------------------------------|
| Scans      | `https://your-nextcloud.com/brother/scan.php?folder=scans`      |
| Accounting | `https://your-nextcloud.com/brother/scan.php?folder=accounting` |
| HR         | `https://your-nextcloud.com/brother/scan.php?folder=hr`         |

Each folder must exist in Nextcloud beforehand. Add folder names to the `$allowed` array in `scan.php`.

## Security

- Brother sends no credentials by default — the shim issues a `WWW-Authenticate: Basic` challenge, causing Brother to send credentials on retry
- Credentials are forwarded directly to Nextcloud — **no passwords stored on the server**
- Nextcloud's own auth and permission stack validates every write
- The `$allowed` whitelist prevents writing to arbitrary Nextcloud folders
- Recommended: restrict `/brother` to your printer's IP in Apache:

```apache
<Location /brother>
    RewriteEngine Off
    Require ip YOUR_PRINTER_IP
</Location>
```

## Tested On

- Brother MFC-L9570CDW (firmware with filename in URL)
- Nextcloud 29.x
- Apache 2.4 / PHP 8.1
- Ubuntu 22.04

## License

MIT
