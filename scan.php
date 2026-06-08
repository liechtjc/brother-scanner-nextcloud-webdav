<?php
/**
 * Brother Scanner → Nextcloud WebDAV shim
 *
 * Acts as a fake SharePoint server for Brother MFC scanners,
 * forwarding scan data to Nextcloud WebDAV.
 *
 * Usage: https://your-nextcloud.com/brother/scan.php?folder=scans
 *
 * See README.md for full installation instructions.
 */

// Add your allowed Nextcloud destination folders here
$allowed = ['scans', 'accounting', 'hr', 'legal'];

// Your Nextcloud base URL
$nextcloud_url = 'https://your-nextcloud.com';

// Parse folder and subpath from query string
// Brother appends filename as: ?folder=scans/filename.pdf
$raw = $_GET['folder'] ?? 'scans';
$parts = explode('/', $raw, 2);
$folder = $parts[0];
$subpath = isset($parts[1]) ? $parts[1] : '';

// Validate folder
if (!in_array($folder, $allowed)) {
    http_response_code(403);
    exit('Forbidden');
}

// Require HTTP Basic Auth - challenge Brother to send credentials
// Brother responds to 401 by retrying with credentials from its profile
$auth = $_SERVER['HTTP_AUTHORIZATION']
     ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
     ?? null;

// Second PUT carries If: <lock-token> header instead of Authorization
// This is the real scan data PUT - allow it through
$headers = getallheaders();
$hasLockToken = isset($headers['If']) && strpos($headers['If'], 'brother-lock-token') !== false;

if (!$auth && !$hasLockToken) {
    header('WWW-Authenticate: Basic realm="Brother Scanner"');
    http_response_code(401);
    exit();
}

// --- Request handlers ---

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/pdf';

    // Use Brother's filename if provided, otherwise generate one
    $filename = !empty($subpath) ? basename($subpath) : 'scan_' . date('Ymd_His') . '.pdf';
    $target = $nextcloud_url . '/remote.php/webdav/' . $folder . '/' . $filename;

    // Buffer body to temp file (handles chunked encoding)
    $tmpfile = tempnam(sys_get_temp_dir(), 'brother_');
    $in = fopen('php://input', 'rb');
    $out = fopen($tmpfile, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    $filesize = filesize($tmpfile);

    // First PUT is always empty (Brother reserves the filename before locking)
    // Just acknowledge it and wait for the real PUT after LOCK
    if ($filesize === 0) {
        unlink($tmpfile);
        http_response_code(201);
        return;
    }

    // Second PUT contains real scan data - forward to Nextcloud
    $ch = curl_init($target);
    $fh = fopen($tmpfile, 'rb');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . $contentType,
        'Authorization: ' . $auth,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    unlink($tmpfile);
    http_response_code($httpcode);

} elseif ($_SERVER['REQUEST_METHOD'] === 'PROPFIND') {
    $uri = $_SERVER['REQUEST_URI'];
    if (!empty($subpath)) {
        // Brother probing test file or checking filename availability
        // Return 404 = file doesn't exist, folder is writable
        http_response_code(404);
    } else {
        // Brother checking folder exists - return valid WebDAV collection response
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        header('DAV: 1, 3');
        echo '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:">'
            . '<d:response>'
            . '<d:href>' . htmlspecialchars($uri) . '</d:href>'
            . '<d:propstat>'
            . '<d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status>'
            . '</d:propstat>'
            . '</d:response>'
            . '</d:multistatus>';
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'LOCK') {
    // Return a fake lock token - Brother needs this to proceed with the real PUT
    http_response_code(200);
    header('Lock-Token: <urn:uuid:brother-lock-token>');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0"?>'
        . '<d:prop xmlns:d="DAV:">'
        . '<d:lockdiscovery>'
        . '<d:activelock>'
        . '<d:locktype><d:write/></d:locktype>'
        . '<d:lockscope><d:exclusive/></d:lockscope>'
        . '<d:depth>0</d:depth>'
        . '<d:timeout>Second-3600</d:timeout>'
        . '<d:locktoken><d:href>urn:uuid:brother-lock-token</d:href></d:locktoken>'
        . '</d:activelock>'
        . '</d:lockdiscovery>'
        . '</d:prop>';

} elseif ($_SERVER['REQUEST_METHOD'] === 'UNLOCK') {
    http_response_code(204);

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Brother sends DELETE on connection test or rollback - just acknowledge
    http_response_code(204);

} elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('DAV: 1, 3');
    header('Allow: OPTIONS, PUT, PROPFIND, LOCK, UNLOCK, DELETE');
    http_response_code(200);
}
