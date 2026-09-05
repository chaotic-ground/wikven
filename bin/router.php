<?php

/**
 * Make `wikven serve` stop answering for an address the site does not have.
 *
 * PHP's built-in server hands back the site's own index page, with a 200, for any address it cannot
 * resolve: /Nope and /Nope/deeper both render the front page. A preview built on that cannot be used
 * to find a broken link, because every link looks like a working one.
 *
 * What this does not do is add addresses. Some hosts serve /Getting_Started for Getting_Started.html
 * and some do not, and the ones that do disagree about which form is canonical; a preview that
 * followed any of them would be imitating a vendor rather than serving the export, with no line to
 * stop at. Every link wikven writes carries its .html, so the export needs none of it. See the
 * Deploying page for what each host does with an address.
 *
 * The one thing here that is not "serve the file" is the trailing slash on a directory, and that is
 * not a convention borrowed from anywhere. RFC 3986 resolves a relative reference against the base
 * path up to its last "/", so a directory's index served at /citizen resolves the page's own
 * "./assets/x.css" to /assets/x.css -- a file that exists, from a different skin's copy of the site.
 * The preview would be quietly showing the wrong stylesheet. Redirecting is the only correct answer,
 * and the built-in server does not: it serves that address with a 200.
 *
 * Returning false hands the request back to the built-in server, which is what keeps a file's
 * Content-Type PHP's own answer rather than a guess made here.
 */

$root = rtrim((string)( $_SERVER['DOCUMENT_ROOT'] ?? '' ), '/');
$path = (string)parse_url((string)( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH);
$path = rawurldecode($path);

/** Answer a request this script rather than the server is finishing. */
$answer = static function (int $status, string $body): bool {
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

// A file exactly as asked for is the server's to send, Content-Type and all.
if (is_file($full)) {
	return false;
}

if (is_dir($full)) {
	if (!is_file(rtrim($full, '/') . '/index.html')) {
		return $answer(404, "<h1>404 Not Found</h1>\n");
	}
	if (!str_ends_with($path, '/')) {
		http_response_code(301);
		header('Location: ' . $path . '/');
		return true;
	}
	return false;
}

return $answer(404, "<h1>404 Not Found</h1>\n");
