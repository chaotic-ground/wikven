<?php

/**
 * Make `wikven serve` answer what a static host answers.
 *
 * PHP's built-in server serves the file a URL names and nothing else, and where it finds no file it
 * hands back the site's own index page with a 200. A preview built on that is wrong twice over: the
 * addresses a published site really serves -- /Development, from Development.html -- answer 404, so
 * a contributor cannot check a link with it; and every address it does not serve answers 200, so a
 * broken link cannot be found with it either.
 *
 * What a host does, measured against GitHub Pages serving this project's own documentation:
 *
 *   /Development      200, the same bytes as /Development.html (same ETag)
 *   /Development/     404 -- the trailing-slash form of a page is not served
 *   /Configuration    200, Configuration.html, though a Configuration/ directory sits beside it
 *   /citizen          301 to /citizen/, because that directory has an index.html
 *   /assets/          404 -- a directory without an index is not listed
 *   /Nope             404
 *
 * Netlify and Cloudflare Pages answer the extension-less form the same way; the redirect and the
 * refusal to list are what every static host does. So this aims at the common behaviour rather than
 * at one host's full rule set, and the order below is the whole of it: a file as asked for, then
 * the .html a host would have found, then a directory's index.
 *
 * Returning false hands the request back to the built-in server, which is what keeps a file's
 * Content-Type PHP's own answer rather than a guess made here.
 */

$root = rtrim((string)( $_SERVER['DOCUMENT_ROOT'] ?? '' ), '/');
$path = (string)parse_url((string)( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH);
$path = rawurldecode($path);

/** Answer a request this script rather than the server is finishing. */
$answer = static function (int $status, string $body = ''): bool {
	http_response_code($status);
	header('Content-Type: text/html; charset=UTF-8');
	if (( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'HEAD') {
		echo $body;
	}
	return true;
};

// A path climbing out of the served directory names nothing in the site. Checked on the segments
// rather than on the resolved path: what a reader typed is what a host answers, and a host does not
// resolve ".." into a directory it then refuses.
if (str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
	return $answer(400, "<h1>400 Bad Request</h1>\n");
}

$full = $root . $path;
$directory = str_ends_with($path, '/');

// A file exactly as asked for is the server's to send, Content-Type and all.
if (!$directory && is_file($full)) {
	return false;
}

// The address a static host serves a page at, which is the one this exists for.
if (!$directory && is_file("$full.html")) {
	$body = (string)file_get_contents("$full.html");
	http_response_code(200);
	header('Content-Type: text/html; charset=UTF-8');
	header('Content-Length: ' . strlen($body));
	if (( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'HEAD') {
		echo $body;
	}
	return true;
}

if (is_dir($full)) {
	if (!is_file(rtrim($full, '/') . '/index.html')) {
		return $answer(404, "<h1>404 Not Found</h1>\n");
	}
	// Without the trailing slash every relative link on the index page resolves one level too high,
	// so a host redirects rather than serving it where it was asked for.
	if (!$directory) {
		http_response_code(301);
		header('Location: ' . $path . '/');
		return true;
	}
	return false;
}

return $answer(404, "<h1>404 Not Found</h1>\n");
