<?php
declare(strict_types=1);

/** Assert a condition and include the UI route in any failure. */
function ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Fetch a local page or asset through Apache. */
function ui_fetch(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $content = curl_exec($handle);
    if ($content === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Could not fetch ' . $url . ': ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    return ['status' => $status, 'content_type' => $contentType, 'content' => $content];
}

$baseUrl = rtrim(getenv('SMART_GYM_BASE_URL') ?: 'http://localhost/gymproject', '/');
$pages = [
    'index.html',
    'login.html',
    'register.html',
    'member.html',
    'classes.html',
    'staff.html',
    'trainer.html',
    'admin.html',
];
$checks = 0;

foreach ($pages as $page) {
    try {
        $response = ui_fetch($baseUrl . '/' . $page);
        ui_assert($response['status'] === 200, $page . ' returned HTTP ' . $response['status'] . '.');
        ui_assert(str_contains(strtolower($response['content_type']), 'text/html'), $page . ' did not return HTML.');

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($response['content'], LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        ui_assert($loaded, $page . ' could not be parsed as HTML.');
        ui_assert($document->getElementsByTagName('title')->length > 0, $page . ' is missing a document title.');
        $viewportFound = false;
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'viewport') {
                $viewportFound = true;
                break;
            }
        }
        ui_assert($viewportFound, $page . ' is missing a responsive viewport declaration.');
        echo 'PASS: ' . $page . ' responds with titled, responsive HTML' . PHP_EOL;
        $checks++;

        foreach (['href', 'src'] as $attribute) {
            foreach ($document->getElementsByTagName('*') as $element) {
                if (!$element->hasAttribute($attribute)) {
                    continue;
                }
                $reference = trim($element->getAttribute($attribute));
                if ($reference === '' || $reference[0] === '#' || str_starts_with($reference, '//')) {
                    continue;
                }
                $scheme = parse_url($reference, PHP_URL_SCHEME);
                if (is_string($scheme) && $scheme !== '') {
                    continue;
                }
                $path = parse_url($reference, PHP_URL_PATH);
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $targetUrl = str_starts_with($path, '/')
                    ? preg_replace('~/[^/]*$~', '', $baseUrl) . $path
                    : $baseUrl . '/' . ltrim($path, '/');
                $target = ui_fetch($targetUrl);
                ui_assert(
                    $target['status'] === 200,
                    $page . ' references ' . $reference . ', which returned HTTP ' . $target['status'] . '.'
                );
                $checks++;
            }
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$protectedPaths = [
    'backend/config/db.php',
    'backend/database/schema.sql',
    'backend/helpers/response.php',
    'backend/storage/',
    'tests/integration-operations.php',
];
foreach ($protectedPaths as $path) {
    try {
        $response = ui_fetch($baseUrl . '/' . $path);
        ui_assert($response['status'] === 403, $path . ' must be denied by Apache; received HTTP ' . $response['status'] . '.');
        echo 'PASS: direct web access to ' . $path . ' is denied' . PHP_EOL;
        $checks++;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo PHP_EOL . sprintf("%d UI HTTP smoke checks passed.\n", $checks);
