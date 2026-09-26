<?php
// Router + security gate for the PHP built-in server:
//   php -S localhost:8000 router.php
//
// It denies direct HTTP access to data/, lib/, tools/, dotfiles, and raw
// .json/.txt/.md files, and only serves the known entry pages.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Block sensitive directories and file types from being served as static files.
if (preg_match('#^/(data|lib|tools|\.[^/]+)(/|$)#', $uri)
    || preg_match('#\.(json|txt|md|lock|ini)$#i', $uri)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$pages = [
    '/'            => '/index.php',
    '/index.php'   => '/index.php',
    '/login.php'       => '/login.php',
    '/logout.php'      => '/logout.php',
    '/recipes.php'     => '/recipes.php',
    '/ingredients.php' => '/ingredients.php',
    '/admin.php'       => '/admin.php',
];

if (isset($pages[$uri])) {
    require __DIR__ . $pages[$uri];
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
