<?php
/**
 * generate_sitemap.php
 *
 * Responsive Bootstrap interface that crawls https://www.merchantservicesmx.com,
 * overwrites sitemap.xml in the web‑root, and displays a live history log on the page
 * with the full list of URLs indexed on the latest run. Added ability to download the
 * generated sitemap directly from the UI.
 *
 * 2025‑07‑20 – v3:  ✔ “Download Sitemap” button + direct file download handler
 *                   ✔ Card layout tweaked for stacked buttons on mobile
 *
 * Place this file in your document root and visit it in a browser.
 */

// --------------------------------------------------
// Config
// --------------------------------------------------
$startUrl    = 'https://www.merchantservicesmx.com';
$logFile     = __DIR__ . '/sitemap_log.csv';
$sitemapFile = __DIR__ . '/sitemap.xml';
$debug       = false;                // set to true for verbose error banners
$timeout     = 15;                   // cURL timeout per request (seconds)

// --------------------------------------------------
// Direct download handler (must run before any output)
// --------------------------------------------------
if (isset($_GET['download']) && file_exists($sitemapFile)) {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="sitemap.xml"');
    header('Content-Length: ' . filesize($sitemapFile));
    readfile($sitemapFile);
    exit;
}

// --------------------------------------------------
// Polyfills for PHP < 8
// --------------------------------------------------
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

// --------------------------------------------------
// Helper: Fetch a URL safely using cURL (returns string|false)
// --------------------------------------------------
function fetch_url(string $url, int $timeout = 10)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'SitemapGenerator/1.0 (+https://www.merchantservicesmx.com)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $data = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status >= 200 && $status < 400) ? $data : false;
}

// --------------------------------------------------
// Crawl the site breadth‑first and return an array of unique internal URLs.
// --------------------------------------------------
function crawl_site(string $startUrl, int $timeout): array
{
    libxml_use_internal_errors(true); // suppress HTML warnings

    $parsedStart = parse_url($startUrl);
    $baseHost    = $parsedStart['host'] ?? '';
    $baseScheme  = $parsedStart['scheme'] ?? 'https';

    $queue   = [$startUrl];
    $visited = [];
    $urls    = [];

    while ($queue) {
        $url = array_shift($queue);
        if (isset($visited[$url])) {
            continue;
        }
        $visited[$url] = true;

        $html = fetch_url($url, $timeout);
        if ($html === false) {
            continue; // skip unreachable pages
        }
        $urls[] = $url;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            // Ignore mailto:, tel:, javascript:
            if (preg_match('/^(mailto:|tel:|javascript:)/i', $href)) {
                continue;
            }
            // Convert to absolute URL
            if (str_starts_with($href, '//')) {
                $href = $baseScheme . ':' . $href;
            } elseif (!preg_match('/^https?:\/\//i', $href)) {
                // relative path
                $href = rtrim($startUrl, '/') . '/' . ltrim($href, '/');
            }
            // Same host only
            $parts = parse_url($href);
            if (($parts['host'] ?? '') !== $baseHost) {
                continue;
            }
            // Drop fragment
            $href = strtok($href, '#');
            if (!isset($visited[$href])) {
                $queue[] = $href;
            }
        }
    }
    sort($urls);
    return $urls;
}

// --------------------------------------------------
// Generate sitemap.xml
// --------------------------------------------------
function generate_sitemap(array $urls, string $dest): void
{
    $today = date('Y-m-d');
    $xml   = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset></urlset>');
    $xml->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    foreach ($urls as $u) {
        $url = $xml->addChild('url');
        $url->addChild('loc', htmlspecialchars($u, ENT_QUOTES | ENT_XML1));
        $url->addChild('lastmod', $today);
    }
    $xml->asXML($dest); // overwrite existing sitemap
}

// --------------------------------------------------
// Log run to CSV
// --------------------------------------------------
function log_run(string $logFile, int $count): void
{
    $entry = sprintf("%s,%d\n", date('c'), $count);
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// --------------------------------------------------
// Controller
// --------------------------------------------------
$message = '';
$error   = '';
$count   = 0;
$urls    = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        $urls  = crawl_site($startUrl, $timeout);
        if (!$urls) {
            throw new RuntimeException('No URLs were discovered. The target site may be offline or blocking requests.');
        }
        $count = count($urls);
        generate_sitemap($urls, $sitemapFile);
        log_run($logFile, $count);
        $message = "Generated sitemap.xml with $count URL" . ($count === 1 ? '' : 's');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($debug) {
            $error .= "\n" . $e->getTraceAsString();
        }
    }
}

// Read log for display
$logEntries = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        [$ts, $cnt] = explode(',', $line, 2);
        $logEntries[] = [
            'datetime' => $ts,
            'count'    => (int) $cnt,
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sitemap Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .url-table { max-height: 50vh; overflow: auto; }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
    <main class="container py-5 flex-fill">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h1 class="h4 mb-4">Sitemap Generator</h1>
                        <?php if ($message): ?>
                            <div class="alert alert-success" role="alert">
                                <?= htmlspecialchars($message) ?>
                            </div>
                        <?php elseif ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= nl2br(htmlspecialchars($error)) ?>
                            </div>
                        <?php endif; ?>
                        <form method="post">
                            <button type="submit" name="generate" class="btn btn-primary btn-lg w-100">Create Sitemap</button>
                        </form>
                        <?php if (file_exists($sitemapFile)): ?>
                            <a href="?download=1" class="btn btn-success btn-lg w-100 mt-2">Download Sitemap</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Run History -->
                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Run History</h2>
                        <?php if ($logEntries): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Date &amp; Time</th>
                                            <th scope="col" class="text-end">Pages Indexed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($logEntries) as $entry): ?>
                                            <tr>
                                                <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($entry['datetime']))) ?></td>
                                                <td class="text-end"><?= $entry['count'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No runs logged yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- URLs List -->
                <?php if ($urls): ?>
                    <div class="card shadow-sm mt-4">
                        <div class="card-body">
                            <h2 class="h6 mb-3">Indexed URLs (<?= $count ?>)</h2>
                            <div class="url-table table-responsive">
                                <table class="table table-sm table-hover small mb-0">
                                    <tbody>
                                        <?php foreach ($urls as $u): ?>
                                            <tr>
                                                <td><a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($u) ?></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="text-center mt-3 text-muted small">
                    Target: <?= htmlspecialchars($startUrl) ?><br>
                    Sitemap path: <code><?= basename($sitemapFile) ?></code>
                </p>
            </div>
        </div>
    </main>
    <footer class="text-center text-muted py-3 small">&copy; <?= date('Y') ?> Sitemap Generator</footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
