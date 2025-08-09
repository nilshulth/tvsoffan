<?php

use App\Database;
use App\User;
use App\TmdbService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

session_start();

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get('/', function (Request $request, Response $response) {
    if (isset($_SESSION['user_id'])) {
        $user = new User();
        $userData = $user->findById($_SESSION['user_id']);
        $content = getDashboardHtml($userData);
    } else {
        $content = getLoginHtml();
    }
    
    $response->getBody()->write($content);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->post('/register', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $name = $data['name'] ?? '';

    if (empty($email) || empty($password) || empty($name)) {
        return $response->withStatus(400)->withJson(['error' => 'All fields are required']);
    }

    $user = new User();
    if ($user->register($email, $password, $name)) {
        $userData = $user->login($email, $password);
        $_SESSION['user_id'] = $userData['id'];
        return $response->withStatus(302)->withHeader('Location', '/');
    } else {
        return $response->withStatus(400)->withJson(['error' => 'Registration failed']);
    }
});

$app->post('/login', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    $user = new User();
    $userData = $user->login($email, $password);

    if ($userData) {
        $_SESSION['user_id'] = $userData['id'];
        return $response->withStatus(302)->withHeader('Location', '/');
    } else {
        return $response->withStatus(400)->withJson(['error' => 'Invalid credentials']);
    }
});

$app->post('/logout', function (Request $request, Response $response) {
    session_destroy();
    return $response->withStatus(302)->withHeader('Location', '/');
});

$app->get('/api/search', function (Request $request, Response $response) {
    if (!isset($_SESSION['user_id'])) {
        return $response->withStatus(401)->withJson(['error' => 'Unauthorized']);
    }

    $query = $request->getQueryParams()['q'] ?? '';
    if (empty($query)) {
        return $response->withJson(['results' => []]);
    }

    $tmdb = new TmdbService();
    $results = $tmdb->searchMulti($query);

    $response->getBody()->write(json_encode($results));
    return $response->withHeader('Content-Type', 'application/json');
});

function getLoginHtml(): string
{
    return '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>tvsoffan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-lg shadow">
        <div class="text-center">
            <h1 class="text-3xl font-bold text-gray-900">tvsoffan</h1>
            <p class="mt-2 text-gray-600">Social tittar-logg för film och tv</p>
        </div>
        
        <div x-data="{ tab: \'login\' }">
            <div class="flex border-b">
                <button @click="tab = \'login\'" :class="{ \'border-blue-500 text-blue-600\': tab === \'login\' }" 
                        class="flex-1 py-2 px-1 text-center border-b-2 font-medium text-sm">
                    Logga in
                </button>
                <button @click="tab = \'register\'" :class="{ \'border-blue-500 text-blue-600\': tab === \'register\' }" 
                        class="flex-1 py-2 px-1 text-center border-b-2 font-medium text-sm">
                    Registrera
                </button>
            </div>

            <div x-show="tab === \'login\'" class="mt-6">
                <form method="POST" action="/login" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">E-post</label>
                        <input type="email" id="email" name="email" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Lösenord</label>
                        <input type="password" id="password" name="password" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <button type="submit" 
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Logga in
                    </button>
                </form>
            </div>

            <div x-show="tab === \'register\'" class="mt-6">
                <form method="POST" action="/register" class="space-y-4">
                    <div>
                        <label for="reg_name" class="block text-sm font-medium text-gray-700">Namn</label>
                        <input type="text" id="reg_name" name="name" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="reg_email" class="block text-sm font-medium text-gray-700">E-post</label>
                        <input type="email" id="reg_email" name="email" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="reg_password" class="block text-sm font-medium text-gray-700">Lösenord</label>
                        <input type="password" id="reg_password" name="password" required 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <button type="submit" 
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        Registrera
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>';
}

function getDashboardHtml(array $user): string
{
    return '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>tvsoffan - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4">
        <header class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">tvsoffan</h1>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700">Hej, ' . htmlspecialchars($user['name']) . '!</span>
                <form method="POST" action="/logout" class="inline">
                    <button type="submit" class="text-sm text-red-600 hover:text-red-800">Logga ut</button>
                </form>
            </div>
        </header>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6" x-data="searchApp()">
            <h2 class="text-xl font-semibold mb-4">Sök filmer och TV-serier</h2>
            
            <div class="mb-4">
                <input 
                    type="text" 
                    x-model="searchQuery"
                    @input="search()"
                    placeholder="Sök efter filmer eller TV-serier..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            
            <div x-show="loading" class="text-center py-4">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
            
            <div x-show="results.length > 0" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <template x-for="item in results" :key="item.id">
                    <div class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex">
                            <img 
                                :src="item.poster_path ? `https://image.tmdb.org/t/p/w92${item.poster_path}` : `/placeholder.png`"
                                :alt="item.title || item.name"
                                class="w-16 h-24 object-cover rounded mr-4 flex-shrink-0"
                                onerror="this.src=\'/placeholder.png\'"
                            >
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-sm mb-1 truncate" x-text="item.title || item.name"></h3>
                                <p class="text-xs text-gray-500 mb-1" x-text="item.release_date || item.first_air_date || \'Okänt datum\'"></p>
                                <p class="text-xs text-gray-400 mb-2" x-text="item.media_type === \'movie\' ? \'Film\' : \'TV-serie\'"></p>
                                <button 
                                    class="text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700"
                                    @click="addToWatched(item)"
                                >
                                    Lägg till
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            
            <div x-show="searchQuery && results.length === 0 && !loading" class="text-center py-4 text-gray-500">
                Inga resultat hittades för "<span x-text="searchQuery"></span>"
            </div>
        </div>
    </div>

    <script>
        function searchApp() {
            return {
                searchQuery: \'\',
                results: [],
                loading: false,
                
                async search() {
                    if (this.searchQuery.length < 2) {
                        this.results = [];
                        return;
                    }
                    
                    this.loading = true;
                    try {
                        const response = await fetch(`/api/search?q=${encodeURIComponent(this.searchQuery)}`);
                        const data = await response.json();
                        this.results = data.results || [];
                    } catch (error) {
                        console.error(\'Search error:\', error);
                        this.results = [];
                    } finally {
                        this.loading = false;
                    }
                },
                
                addToWatched(item) {
                    alert(`Would add "${item.title || item.name}" to watched list`);
                }
            }
        }
    </script>
</body>
</html>';
}

$app->run();