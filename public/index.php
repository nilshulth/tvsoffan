<?php

use App\Database;
use App\User;
use App\TmdbService;
use App\ListModel;
use App\Title;
use App\ListItem;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

session_start();

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

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
        $response->getBody()->write(json_encode(['error' => 'All fields are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $user = new User();
    if ($user->register($email, $password, $name)) {
        $userData = $user->login($email, $password);
        $_SESSION['user_id'] = $userData['id'];
        return $response->withStatus(302)->withHeader('Location', '/');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Registration failed']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
        $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
});

$app->post('/logout', function (Request $request, Response $response) {
    session_destroy();
    return $response->withStatus(302)->withHeader('Location', '/');
});

$app->get('/api/search', function (Request $request, Response $response) {
    if (!isset($_SESSION['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $query = $request->getQueryParams()['q'] ?? '';
    if (empty($query)) {
        $response->getBody()->write(json_encode(['results' => []]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $tmdb = new TmdbService();
    $results = $tmdb->searchMulti($query);

    $response->getBody()->write(json_encode($results));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/titles/{tmdb_id}/{media_type}/add-to-list', function (Request $request, Response $response, array $args) {
    if (!isset($_SESSION['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $tmdbId = (int)$args['tmdb_id'];
    $mediaType = $args['media_type'];
    $data = $request->getParsedBody();
    
    
    $listId = $data['list_id'] ?? null;
    $state = $data['state'] ?? 'want';
    $rating = $data['rating'] ?? null;
    $comment = $data['comment'] ?? '';

    if (!$listId) {
        $response->getBody()->write(json_encode(['error' => 'List ID required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $listModel = new ListModel();
        if (!$listModel->isOwner($listId, $_SESSION['user_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Access denied']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $tmdb = new TmdbService();
        $tmdbData = null;
        
        if ($mediaType === 'movie') {
            $tmdbData = $tmdb->getMovieDetails($tmdbId);
        } else {
            $tmdbData = $tmdb->getTvShowDetails($tmdbId);
        }

        if (!$tmdbData) {
            $response->getBody()->write(json_encode(['error' => 'Title not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $tmdbData['media_type'] = $mediaType;
        
        $title = new Title();
        $titleId = $title->createFromTmdb($tmdbData);

        $listItem = new ListItem();
        $success = $listItem->addToList($listId, $titleId, $_SESSION['user_id'], $state, $rating, $comment);

        if ($success) {
            $response->getBody()->write(json_encode(['success' => true, 'title_id' => $titleId]));
            return $response->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write(json_encode(['error' => 'Failed to add to list']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

    } catch (\Exception $e) {
        error_log("Add to list error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => 'Server error']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

$app->get('/api/lists', function (Request $request, Response $response) {
    if (!isset($_SESSION['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $listModel = new ListModel();
    $lists = $listModel->getUserLists($_SESSION['user_id']);
    
    $response->getBody()->write(json_encode(['lists' => $lists]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/lists/{list_id}/items', function (Request $request, Response $response, array $args) {
    if (!isset($_SESSION['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $listId = (int)$args['list_id'];
    $state = $request->getQueryParams()['state'] ?? null;

    $listModel = new ListModel();
    if (!$listModel->canUserAccess($listId, $_SESSION['user_id'])) {
        $response->getBody()->write(json_encode(['error' => 'Access denied']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    $listItem = new ListItem();
    $items = $listItem->getListItems($listId, $state);

    $response->getBody()->write(json_encode(['items' => $items]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/lists/watched', function (Request $request, Response $response) {
    if (!isset($_SESSION['user_id'])) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    $user = new User();
    $userData = $user->findById($_SESSION['user_id']);
    
    $listModel = new ListModel();
    $watchedList = $listModel->getUserWatchedList($_SESSION['user_id']);
    
    if (!$watchedList) {
        $response->getBody()->write('<h1>Sett lista hittades inte</h1>');
        return $response->withHeader('Content-Type', 'text/html');
    }

    $content = getWatchedListHtml($userData, $watchedList);
    $response->getBody()->write($content);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/placeholder.png', function (Request $request, Response $response) {
    // Return a simple 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
    $response->getBody()->write($png);
    return $response->withHeader('Content-Type', 'image/png');
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
    <div class="max-w-6xl mx-auto py-8 px-4" x-data="{ ...searchApp(), ...listsApp() }" x-init="loadLists()">
        <header class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">tvsoffan</h1>
            <div class="flex items-center space-x-4 flex-1 max-w-md mx-4">
                <input 
                    type="text" 
                    x-model="searchQuery"
                    @input="search()"
                    placeholder="Sök filmer och TV-serier..."
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                >
            </div>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900 focus:outline-none">
                    <span class="text-sm">' . htmlspecialchars($user['name']) . '</span>
                    <svg class="w-4 h-4 transform transition-transform" :class="{ \'rotate-180\': open }" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                    <a href="/lists/watched" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Sett lista</a>
                    <form method="POST" action="/logout" class="block">
                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                            Logga ut ' . htmlspecialchars($user['name']) . '
                        </button>
                    </form>
                </div>
            </div>
        </header>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div x-show="searchQuery && results.length > 0" class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Sökresultat</h2>
            
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
            </div>
            
            <div :class="searchQuery && results.length > 0 ? \'lg:col-span-1\' : \'lg:col-span-3\'">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Mina listor</h2>
                    
                    <div x-show="loading" class="text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    </div>
                    
                    <div x-show="!loading" class="space-y-3">
                        <template x-for="list in lists.filter(l => l.is_default == 1 && l.is_watched_list == 0)" :key="list.id">
                            <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors cursor-pointer" @click="toggleListItems(list.id)">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-gray-900 truncate" x-text="list.name"></h3>
                                        <div class="flex items-center mt-1 text-sm text-gray-500">
                                            <span x-text="list.item_count + \' objekt\'"></span>
                                            <span x-show="list.is_default == 1" class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                Standard
                                            </span>
                                            <span x-show="list.is_watched_list == 1" class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                Sett
                                            </span>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <svg class="w-4 h-4 text-gray-400 transform transition-transform" :class="{ \'rotate-90\': expandedLists.includes(list.id) }" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                </div>
                                
                                <div x-show="expandedLists.includes(list.id)" class="mt-4 space-y-2">
                                    <template x-for="item in listItems[list.id] || []" :key="item.id">
                                        <div class="flex items-center space-x-3 p-2 bg-gray-50 rounded">
                                            <img 
                                                :src="item.poster_path ? `https://image.tmdb.org/t/p/w92${item.poster_path}` : `/placeholder.png`"
                                                :alt="item.title"
                                                class="w-8 h-12 object-cover rounded flex-shrink-0"
                                                onerror="this.src=\'/placeholder.png\'"
                                            >
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 truncate" x-text="item.title"></p>
                                                <p class="text-xs text-gray-500" x-text="getYear(item.release_date)"></p>
                                                <div class="flex items-center mt-1">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" 
                                                          :class="{
                                                              \'bg-yellow-100 text-yellow-800\': item.state === \'want\',
                                                              \'bg-blue-100 text-blue-800\': item.state === \'watching\',
                                                              \'bg-green-100 text-green-800\': item.state === \'watched\',
                                                              \'bg-red-100 text-red-800\': item.state === \'stopped\'
                                                          }"
                                                          x-text="getStateText(item.state)">
                                                    </span>
                                                    <span x-show="item.rating" class="ml-2 text-xs text-gray-500">
                                                        ⭐ <span x-text="item.rating"></span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!listItems[list.id] || listItems[list.id].length === 0" class="text-center py-4 text-gray-500 text-sm">
                                        Inga objekt i listan
                                    </div>
                                </div>
                            </div>
                        </template>
                        
                        <div x-show="lists.filter(l => l.is_default == 1 && l.is_watched_list == 0).length === 0" class="text-center py-8 text-gray-500">
                            Du har ingen standardlista ännu
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function searchApp() {
            return {
                searchQuery: \'\',
                results: [],
                loading: false,
                userLists: null,
                
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
                
                async addToWatched(item) {
                    if (!this.userLists) {
                        await this.loadUserLists();
                    }
                    
                    const defaultList = this.userLists.find(list => list.is_default == 1);
                    
                    if (!defaultList) {
                        alert(\'Ingen standardlista hittades\');
                        return;
                    }
                    
                    await this.addToList(item, defaultList.id, \'want\', defaultList.id);
                },
                
                async loadUserLists() {
                    try {
                        const response = await fetch(\'/api/lists\');
                        const data = await response.json();
                        this.userLists = data.lists || [];
                    } catch (error) {
                        console.error(\'Failed to load lists:\', error);
                        this.userLists = [];
                    }
                },
                
                async addToList(item, listId, state = \'want\', targetListId = null) {
                    const requestBody = {
                        list_id: listId,
                        state: state
                    };
                    
                    try {
                        const response = await fetch(`/api/titles/${item.id}/${item.media_type}/add-to-list`, {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        const data = await response.json();
                        
                        if (response.ok) {
                            alert((item.title || item.name) + \' lades till i listan!\');
                            // Reload lists to update count
                            this.loadLists();
                            // Refresh the specific list that was updated if it\'s expanded
                            if (targetListId && this.expandedLists.includes(targetListId)) {
                                delete this.listItems[targetListId];
                                this.loadListItems(targetListId);
                            }
                        } else {
                            alert(\'Fel: \' + (data.error || \'Kunde inte lägga till i listan\'));
                        }
                    } catch (error) {
                        console.error(\'Add to list error:\', error);
                        alert(\'Något gick fel när titeln skulle läggas till\');
                    }
                }
            }
        }
        
        function listsApp() {
            return {
                lists: [],
                loading: true,
                expandedLists: [],
                listItems: {},
                
                async loadLists() {
                    try {
                        const response = await fetch(\'/api/lists\');
                        const data = await response.json();
                        this.lists = data.lists || [];
                    } catch (error) {
                        console.error(\'Failed to load lists:\', error);
                    } finally {
                        this.loading = false;
                    }
                },
                
                async toggleListItems(listId) {
                    if (this.expandedLists.includes(listId)) {
                        this.expandedLists = this.expandedLists.filter(id => id !== listId);
                    } else {
                        this.expandedLists.push(listId);
                        await this.loadListItems(listId);
                    }
                },
                
                async loadListItems(listId) {
                    if (this.listItems[listId]) return;
                    
                    try {
                        const response = await fetch(`/api/lists/${listId}/items`);
                        const data = await response.json();
                        this.listItems[listId] = data.items || [];
                    } catch (error) {
                        console.error(\'Failed to load list items:\', error);
                        this.listItems[listId] = [];
                    }
                },
                
                getStateText(state) {
                    const stateTexts = {
                        \'want\': \'Vill se\',
                        \'watching\': \'Tittar\',
                        \'watched\': \'Sett\',
                        \'stopped\': \'Slutat\'
                    };
                    return stateTexts[state] || state;
                },
                
                getYear(releaseDate) {
                    if (!releaseDate) return \'Okänt år\';
                    try {
                        const year = new Date(releaseDate).getFullYear();
                        return isNaN(year) ? \'Okänt år\' : year.toString();
                    } catch (e) {
                        return \'Okänt år\';
                    }
                }
            }
        }
    </script>
</body>
</html>';
}

function getWatchedListHtml(array $user, array $watchedList): string
{
    return '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sett lista - tvsoffan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-4">
        <header class="flex justify-between items-center mb-8">
            <div class="flex items-center space-x-4">
                <a href="/" class="text-3xl font-bold text-gray-900 hover:text-gray-700">tvsoffan</a>
                <span class="text-gray-400">/</span>
                <h1 class="text-2xl font-semibold text-gray-800">' . htmlspecialchars($watchedList['name']) . '</h1>
            </div>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900 focus:outline-none">
                    <span class="text-sm">' . htmlspecialchars($user['name']) . '</span>
                    <svg class="w-4 h-4 transform transition-transform" :class="{ \'rotate-180\': open }" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                    <form method="POST" action="/logout" class="block">
                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                            Logga ut ' . htmlspecialchars($user['name']) . '
                        </button>
                    </form>
                </div>
            </div>
        </header>
        
        <div class="bg-white rounded-lg shadow" x-data="watchedListApp(' . $watchedList['id'] . ')" x-init="loadItems()">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">Sett titlar</h2>
                        <p class="text-sm text-gray-500 mt-1" x-text="items.length + \' titlar\'"></p>
                    </div>
                </div>
                
                <div x-show="loading" class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                </div>
                
                <div x-show="!loading && items.length === 0" class="text-center py-12 text-gray-500">
                    Du har inte sett några titlar ännu
                </div>
                
                <div x-show="!loading && items.length > 0" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <template x-for="item in items" :key="item.id">
                        <div class="flex items-start space-x-4 p-4 bg-gray-50 rounded-lg">
                            <img 
                                :src="item.poster_path ? `https://image.tmdb.org/t/p/w92${item.poster_path}` : `/placeholder.png`"
                                :alt="item.title"
                                class="w-16 h-24 object-cover rounded flex-shrink-0"
                                onerror="this.src=\'/placeholder.png\'"
                            >
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-gray-900 mb-1" x-text="item.title"></h3>
                                <p class="text-sm text-gray-500 mb-2" x-text="getYear(item.release_date)"></p>
                                <div class="flex items-center space-x-2 mb-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Sett
                                    </span>
                                    <span x-show="item.rating" class="text-sm text-gray-600">
                                        ⭐ <span x-text="item.rating"></span>
                                    </span>
                                </div>
                                <p x-show="item.comment" class="text-sm text-gray-700 italic" x-text="item.comment"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <script>
        function watchedListApp(listId) {
            return {
                listId: listId,
                items: [],
                loading: true,
                
                async loadItems() {
                    try {
                        const response = await fetch(`/api/lists/${this.listId}/items?state=watched`);
                        const data = await response.json();
                        this.items = data.items || [];
                    } catch (error) {
                        console.error(\'Failed to load watched items:\', error);
                        this.items = [];
                    } finally {
                        this.loading = false;
                    }
                },
                
                getYear(releaseDate) {
                    if (!releaseDate) return \'Okänt år\';
                    try {
                        const year = new Date(releaseDate).getFullYear();
                        return isNaN(year) ? \'Okänt år\' : year.toString();
                    } catch (e) {
                        return \'Okänt år\';
                    }
                }
            }
        }
    </script>
</body>
</html>';
}

$app->run();