<?php

use App\Database;
use App\User;
use App\TmdbService;
use App\ListModel;
use App\Title;
use App\ListItem;
use App\UserTitle;
use App\ResponseHelper;
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

// User context middleware - sets user info for all requests
$app->add(function (Request $request, $handler) {
    $request = $request->withAttribute('user', [
        'id' => $_SESSION['user_id'] ?? null,
        'is_logged_in' => isset($_SESSION['user_id']),
        'is_visitor' => !isset($_SESSION['user_id'])
    ]);
    return $handler->handle($request);
});

$app->get('/', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');
    
    if (!$user['is_visitor']) {
        $userModel = new User();
        $userData = $userModel->findById($user['id']);
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
        return ResponseHelper::jsonError($response, 'All fields are required', 400);
    }

    $user = new User();
    if ($user->register($email, $password, $name)) {
        $userData = $user->login($email, $password);
        $_SESSION['user_id'] = $userData['id'];
        return $response->withStatus(302)->withHeader('Location', '/');
    } else {
        return ResponseHelper::jsonError($response, 'Registration failed', 400);
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
        return ResponseHelper::jsonError($response, 'Invalid credentials', 400);
    }
});

$app->post('/logout', function (Request $request, Response $response) {
    session_destroy();
    return $response->withStatus(302)->withHeader('Location', '/');
});

$app->get('/api/search', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');
    
    // Allow both logged-in users and visitors to search
    $query = $request->getQueryParams()['q'] ?? '';
    if (empty($query)) {
        return ResponseHelper::jsonSuccess($response, ['results' => []]);
    }

    $tmdb = new TmdbService();
    $results = $tmdb->searchMulti($query);

    return ResponseHelper::jsonSuccess($response, $results);
});

$app->post('/api/titles/{tmdb_id}/{media_type}/add-to-list', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');
    
    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $tmdbId = (int)$args['tmdb_id'];
    $mediaType = $args['media_type'];
    $data = $request->getParsedBody();
    
    
    $listId = $data['list_id'] ?? null;
    $state = $data['state'] ?? 'want';
    $rating = $data['rating'] ?? null;
    $comment = $data['comment'] ?? '';

    if (!$listId) {
        return ResponseHelper::jsonError($response, 'List ID required', 400);
    }

    try {
        $listModel = new ListModel();
        if (!$listModel->isOwner($listId, $user['id'])) {
            return ResponseHelper::jsonError($response, 'Access denied', 403);
        }

        $tmdb = new TmdbService();
        $tmdbData = null;
        
        if ($mediaType === 'movie') {
            $tmdbData = $tmdb->getMovieDetails($tmdbId);
        } else {
            $tmdbData = $tmdb->getTvShowDetails($tmdbId);
        }

        if (!$tmdbData) {
            return ResponseHelper::jsonError($response, 'Title not found', 404);
        }

        $tmdbData['media_type'] = $mediaType;
        
        $title = new Title();
        $titleId = $title->createFromTmdb($tmdbData);

        // Add to list (simplified - just list membership)
        $listItem = new ListItem();
        $listSuccess = $listItem->addToList($listId, $titleId);

        // Set user title state (separate from list membership)
        $userTitle = new UserTitle();
        $stateSuccess = $userTitle->setState($user['id'], $titleId, $state, $rating, $comment);

        if ($listSuccess && $stateSuccess) {
            return ResponseHelper::jsonSuccess($response, ['success' => true, 'title_id' => $titleId]);
        } else {
            return ResponseHelper::jsonError($response, 'Failed to add to list or set state', 500);
        }

    } catch (\Exception $e) {
        error_log("Add to list error: " . $e->getMessage());
        return ResponseHelper::serverError($response);
    }
});

$app->post('/api/titles/update/{title_id}', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $titleId = (int)$args['title_id'];
    $data = $request->getParsedBody();
    
    $listId = $data['list_id'] ?? null;
    $state = $data['state'] ?? 'want';
    $rating = $data['rating'] ?? null;
    $comment = $data['comment'] ?? '';

    if (!$listId) {
        return ResponseHelper::jsonError($response, 'List ID required', 400);
    }

    try {
        $listModel = new ListModel();
        if (!$listModel->isOwner($listId, $user['id'])) {
            return ResponseHelper::jsonError($response, 'Access denied', 403);
        }

        // Update user title state (no longer tied to specific list)
        $userTitle = new UserTitle();
        $success = $userTitle->setState($user['id'], $titleId, $state, $rating, $comment);

        if ($success) {
            return ResponseHelper::jsonSuccess($response, ['success' => true]);
        } else {
            return ResponseHelper::jsonError($response, 'Failed to update title state', 500);
        }

    } catch (\Exception $e) {
        error_log("Update title error: " . $e->getMessage());
        return ResponseHelper::serverError($response);
    }
});

$app->get('/api/titles/{title_id}/status', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $titleId = (int)$args['title_id'];

    try {
        // Get user's title state and which lists contain this title
        $userTitle = new UserTitle();
        $userState = $userTitle->getUserTitleState($user['id'], $titleId);
        
        $stmt = Database::getConnection()->prepare(
            "SELECT l.name as list_name, l.id as list_id
             FROM list_items li
             JOIN lists l ON li.list_id = l.id
             JOIN list_owners lo ON l.id = lo.list_id
             WHERE li.title_id = ? AND lo.user_id = ?
             ORDER BY l.name"
        );
        $stmt->execute([$titleId, $user['id']]);
        $lists = $stmt->fetchAll();
        
        // Combine user state with list information
        $status = [];
        foreach ($lists as $list) {
            $status[] = [
                'list_id' => $list['list_id'],
                'list_name' => $list['list_name'],
                'state' => $userState['state'] ?? 'want',
                'rating' => $userState['rating'] ?? null,
                'comment' => $userState['comment'] ?? '',
                'is_watched_list' => 0 // No longer exists
            ];
        }

        return ResponseHelper::jsonSuccess($response, ['status' => $status]);
    } catch (\Exception $e) {
        error_log("Get title status error: " . $e->getMessage());
        return ResponseHelper::serverError($response);
    }
});

$app->delete('/api/titles/{title_id}/lists/{list_id}', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $titleId = (int)$args['title_id'];
    $listId = (int)$args['list_id'];

    try {
        $listModel = new ListModel();
        if (!$listModel->isOwner($listId, $user['id'])) {
            return ResponseHelper::jsonError($response, 'Access denied', 403);
        }

        $listItem = new ListItem();
        $success = $listItem->removeFromList($listId, $titleId);

        if ($success) {
            return ResponseHelper::jsonSuccess($response, ['success' => true]);
        } else {
            return ResponseHelper::jsonError($response, 'Failed to remove from list', 500);
        }
    } catch (\Exception $e) {
        error_log("Remove from list error: " . $e->getMessage());
        return ResponseHelper::serverError($response);
    }
});

$app->get('/api/lists', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $listModel = new ListModel();
    $lists = $listModel->getUserLists($user['id']);
    
    return ResponseHelper::jsonSuccess($response, ['lists' => $lists]);
});

$app->post('/api/lists', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $data = json_decode($request->getBody()->getContents(), true);
    
    if (empty($data['name'])) {
        return ResponseHelper::jsonError($response, 'Listnamn krävs', 400);
    }

    try {
        $listModel = new ListModel();
        $listId = $listModel->create(
            $data['name'],
            $user['id'],
            $data['description'] ?? '',
            $data['visibility'] ?? 'private',
            $data['is_default'] ?? false
        );

        return ResponseHelper::jsonSuccess($response, [
            'success' => true,
            'list_id' => $listId,
            'message' => 'Lista skapad'
        ], 201);
    } catch (\Exception $e) {
        error_log("Create list error: " . $e->getMessage());
        return ResponseHelper::jsonError($response, 'Kunde inte skapa listan', 500);
    }
});

$app->get('/api/lists/{list_id}/items', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $listId = (int)$args['list_id'];
    $state = $request->getQueryParams()['state'] ?? null;

    $listModel = new ListModel();
    if (!$listModel->canUserAccess($listId, $user['id'])) {
        return ResponseHelper::jsonError($response, 'Access denied', 403);
    }

    $listItem = new ListItem();
    $items = $listItem->getListItems($listId, $state, $user['id']);

    return ResponseHelper::jsonSuccess($response, ['items' => $items]);
});

// API endpoint to get user titles by state
$app->get('/api/user/titles', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return ResponseHelper::unauthorized($response);
    }

    $states = $request->getQueryParams()['states'] ?? 'watched,watching,stopped';
    $statesArray = explode(',', $states);
    
    try {
        $userTitle = new UserTitle();
        $allTitles = [];
        
        foreach ($statesArray as $state) {
            $titles = $userTitle->getUserTitlesByState($user['id'], trim($state), 100);
            $allTitles = array_merge($allTitles, $titles);
        }
        
        // Sort by most recently updated
        usort($allTitles, function($a, $b) {
            return strtotime($b['user_updated_at']) - strtotime($a['user_updated_at']);
        });
        
        return ResponseHelper::jsonSuccess($response, ['items' => $allTitles]);
    } catch (\Exception $e) {
        error_log("Get user titles error: " . $e->getMessage());
        return ResponseHelper::serverError($response);
    }
});

$app->get('/lists/watched', function (Request $request, Response $response) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    $userModel = new User();
    $userData = $userModel->findById($user['id']);
    
    // Get all titles with watched, watching, or stopped status
    $userTitle = new UserTitle();
    $watchedTitles = [];
    
    // Get titles for each relevant state
    $states = ['watched', 'watching', 'stopped'];
    foreach ($states as $state) {
        $titles = $userTitle->getUserTitlesByState($user['id'], $state, 100);
        $watchedTitles = array_merge($watchedTitles, $titles);
    }
    
    // Sort by most recently updated
    usort($watchedTitles, function($a, $b) {
        return strtotime($b['user_updated_at']) - strtotime($a['user_updated_at']);
    });

    $content = getWatchedListHtml($userData, $watchedTitles);
    $response->getBody()->write($content);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/title/{id}', function (Request $request, Response $response, array $args) {
    $user = $request->getAttribute('user');

    if ($user['is_visitor']) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    $titleId = (int)$args['id'];
    
    $userModel = new User();
    $userData = $userModel->findById($user['id']);
    
    $title = new Title();
    $titleData = $title->findById($titleId);
    
    if (!$titleData) {
        $response->getBody()->write('<h1>Titel hittades inte</h1>');
        return $response->withHeader('Content-Type', 'text/html');
    }

    $content = getTitleDetailHtml($userData, $titleData);
    $response->getBody()->write($content);
    return $response->withHeader('Content-Type', 'text/html');
});

$app->get('/placeholder.png', function (Request $request, Response $response) {
    // Return a simple 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
    $response->getBody()->write($png);
    return $response->withHeader('Content-Type', 'image/png');
});

function getSharedHeaderHtml(array $user, bool $showSearch = true): string
{
    $searchSection = '';
    if ($showSearch) {
        $searchSection = '
                <div class="relative" x-data="searchApp()" x-init="loadUserLists()">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input 
                            type="text" 
                            x-model="searchQuery" 
                            @input="search()" 
                            @keydown.escape="clearSearch()"
                            placeholder="Sök film eller TV-serie..." 
                            class="pl-10 pr-4 py-2 w-80 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                        <button x-show="searchQuery" @click="clearSearch()" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div x-show="searchQuery && results.length > 0" class="absolute top-full left-0 mt-2 w-full bg-white rounded-lg shadow-lg border border-gray-200 max-h-96 overflow-y-auto z-50">
                        <template x-for="item in results" :key="item.id">
                            <div @click="viewTitle(item)" class="flex items-center space-x-3 p-3 hover:bg-gray-50 border-b border-gray-100 last:border-b-0 cursor-pointer">
                                <img 
                                    :src="item.poster_path ? `https://image.tmdb.org/t/p/w92${item.poster_path}` : `/placeholder.png`"
                                    :alt="item.title || item.name"
                                    class="w-12 h-18 object-cover rounded flex-shrink-0"
                                    onerror="this.src=\'/placeholder.png\'"
                                >
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate" x-text="item.title || item.name"></p>
                                    <div class="flex items-center space-x-2 text-xs text-gray-500">
                                        <span x-text="item.release_date ? new Date(item.release_date).getFullYear() : (item.first_air_date ? new Date(item.first_air_date).getFullYear() : \'Okänt år\')"></span>
                                        <span>•</span>
                                        <span x-text="item.media_type === \'movie\' ? \'Film\' : \'TV-serie\'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>';
    }
    
    return '
        <header class="flex justify-between items-center mb-8">
            <div class="flex items-center space-x-6">
                <a href="/" class="text-3xl font-bold text-gray-900 hover:text-gray-700">tvsoffan</a>
                ' . $searchSection . '
            </div>
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900 focus:outline-none">
                    <span class="text-sm">' . htmlspecialchars($user['name']) . '</span>
                    <svg class="w-4 h-4 transform transition-transform" :class="{ \'rotate-180\': open }" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                    <a href="/lists/watched" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Historik</a>
                    <form method="POST" action="/logout" class="block">
                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                            Logga ut ' . htmlspecialchars($user['name']) . '
                        </button>
                    </form>
                </div>
            </div>
        </header>';
}

function getSharedScriptJs(): string
{
    return '<script>
        // Shared utility functions
        const TvSoffanUtils = {
            getStateText(state) {
                const stateTexts = {
                    \'want\': \'Vill se\',
                    \'watching\': \'Tittar\',
                    \'watched\': \'Sett\',
                    \'stopped\': \'Slutat titta\'
                };
                return stateTexts[state] || state;
            }
        };

        function searchApp() {
            return {
                searchQuery: \'\',
                results: [],
                loading: false,
                userLists: null,
                
                async loadUserLists() {
                    try {
                        const response = await fetch(\'/api/lists\');
                        const data = await response.json();
                        this.userLists = data.lists || [];
                    } catch (error) {
                        console.error(\'Failed to load user lists:\', error);
                    }
                },
                
                async search() {
                    if (!this.searchQuery || this.searchQuery.length < 2) {
                        this.results = [];
                        return;
                    }
                    
                    this.loading = true;
                    
                    try {
                        const response = await fetch(\'/api/search?q=\' + encodeURIComponent(this.searchQuery));
                        const data = await response.json();
                        const rawResults = data.results || [];
                        
                        // Filter out results without year or poster
                        this.results = rawResults.filter(item => {
                            const hasYear = item.release_date || item.first_air_date;
                            const hasPoster = item.poster_path;
                            return hasYear && hasPoster;
                        });
                    } catch (error) {
                        console.error(\'Search error:\', error);
                        this.results = [];
                    } finally {
                        this.loading = false;
                    }
                },
                
                clearSearch() {
                    this.searchQuery = \'\';
                    this.results = [];
                },
                
                async viewTitle(item) {
                    if (!this.userLists) {
                        await this.loadUserLists();
                    }
                    
                    const defaultList = this.userLists.find(list => list.is_default == 1);
                    if (!defaultList) {
                        alert(\'Du har ingen standardlista för att lägga till titlar\');
                        return;
                    }
                    
                    const requestBody = {
                        list_id: defaultList.id,
                        state: \'want\'
                    };
                    
                    try {
                        const response = await fetch(\'/api/titles/\' + item.id + \'/\' + (item.media_type || \'movie\') + \'/add-to-list\', {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        const data = await response.json();
                        
                        if (response.ok && data.title_id) {
                            window.location.href = \'/title/\' + data.title_id;
                        } else {
                            alert(\'Kunde inte visa titel: \' + (data.error || \'Okänt fel\'));
                        }
                    } catch (error) {
                        console.error(\'View title error:\', error);
                        alert(\'Något gick fel när titeln skulle visas\');
                    }
                },
                
                async addToWatched(item) {
                    if (!this.userLists) {
                        await this.loadUserLists();
                    }
                    
                    const defaultList = this.userLists.find(list => list.is_default == 1);
                    if (!defaultList) {
                        alert(\'Du har ingen standardlista för att lägga till titlar\');
                        return;
                    }
                    
                    const requestBody = {
                        tmdb_id: item.id,
                        media_type: item.media_type || \'movie\',
                        list_id: defaultList.id,
                        state: \'want\',
                        rating: null,
                        comment: \'\'
                    };
                    
                    try {
                        const response = await fetch(\'/api/titles\', {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        const data = await response.json();
                        
                        if (response.ok) {
                            this.clearSearch();
                        } else {
                            alert(\'Fel: \' + (data.error || \'Kunde inte lägga till titeln\'));
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
                visibleLists: [],
                loading: true,
                activeTabId: null,
                listItems: {},
                showNewListModal: false,
                newListName: \'\',
                newListDescription: \'\',
                newListVisibility: \'private\',
                
                async loadLists() {
                    try {
                        const response = await fetch(\'/api/lists\');
                        const data = await response.json();
                        
                        if (data.error && data.error === \'Unauthorized\') {
                            console.log(\'User not authenticated, redirecting to login\');
                            window.location.href = \'/login\';
                            return;
                        }
                        
                        this.lists = data.lists || [];
                        // No more watched list filtering needed - all lists are regular lists
                        this.visibleLists = this.lists;
                        
                        // Set the first visible list as active tab
                        if (this.visibleLists.length > 0) {
                            await this.setActiveTab(this.visibleLists[0].id);
                        }
                    } catch (error) {
                        console.error(\'Failed to load lists:\', error);
                    } finally {
                        this.loading = false;
                    }
                },
                
                async setActiveTab(listId) {
                    this.activeTabId = listId;
                    await this.loadListItems(listId);
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
                
                getStateText: TvSoffanUtils.getStateText,
                
                getYear(releaseDate) {
                    if (!releaseDate) return \'Okänt år\';
                    try {
                        const year = new Date(releaseDate).getFullYear();
                        return isNaN(year) ? \'Okänt år\' : year.toString();
                    } catch (e) {
                        return \'Okänt år\';
                    }
                },
                
                async createNewList() {
                    if (!this.newListName.trim()) return;
                    
                    const requestBody = {
                        name: this.newListName.trim(),
                        description: this.newListDescription.trim(),
                        visibility: this.newListVisibility,
                        is_default: false
                    };
                    
                    try {
                        const response = await fetch(\'/api/lists\', {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        if (response.ok) {
                            this.cancelNewList();
                            await this.loadLists(); // Reload lists to show the new one
                        } else {
                            const data = await response.json();
                            alert(\'Fel: \' + (data.error || \'Kunde inte skapa listan\'));
                        }
                    } catch (error) {
                        console.error(\'Create list error:\', error);
                        alert(\'Något gick fel när listan skulle skapas\');
                    }
                },
                
                cancelNewList() {
                    this.showNewListModal = false;
                    this.newListName = \'\';
                    this.newListDescription = \'\';
                    this.newListVisibility = \'private\';
                }
            }
        }
    </script>';
}

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
        ' . getSharedHeaderHtml($user, true) . '
        
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
                                <div class="space-y-1">
                                    <button 
                                        class="text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700 w-full"
                                        @click="addToWatched(item)"
                                    >
                                        Lägg till
                                    </button>
                                    <button 
                                        class="text-xs bg-gray-600 text-white px-2 py-1 rounded hover:bg-gray-700 w-full"
                                        @click="viewTitle(item)"
                                    >
                                        Visa
                                    </button>
                                </div>
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
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    
                    <div x-show="loading" class="text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    </div>
                    
                    <div x-show="!loading && visibleLists.length > 0">
                        <!-- Tab Navigation -->
                        <div class="border-b border-gray-200">
                            <nav class="flex justify-between items-center px-6">
                                <div class="flex space-x-8 overflow-x-auto">
                                    <template x-for="list in visibleLists" :key="list.id">
                                        <button @click="setActiveTab(list.id)" 
                                                class="flex-shrink-0 py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap"
                                                :class="activeTabId === list.id ? \'border-blue-500 text-blue-600\' : \'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300\'">
                                            <span x-text="list.name"></span>
                                            <span class="ml-2 bg-gray-100 text-gray-600 py-0.5 px-2 rounded-full text-xs" 
                                                  :class="activeTabId === list.id ? \'bg-blue-100 text-blue-600\' : \'bg-gray-100 text-gray-600\'"
                                                  x-text="list.item_count"></span>
                                        </button>
                                    </template>
                                </div>
                                <button @click="showNewListModal = true" class="flex-shrink-0 py-2 px-4 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium text-sm">
                                    + Ny lista
                                </button>
                            </nav>
                        </div>
                        
                        <!-- Tab Content -->
                        <div class="p-6">
                            <template x-for="list in visibleLists" :key="list.id">
                                <div x-show="activeTabId === list.id" class="space-y-2">
                                    <template x-for="item in listItems[list.id] || []" :key="item.id">
                                        <a :href="\'/title/\' + item.title_id" class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer">
                                            <img 
                                                :src="item.poster_path ? `https://image.tmdb.org/t/p/w92${item.poster_path}` : `/placeholder.png`"
                                                :alt="item.title"
                                                class="w-10 h-15 object-cover rounded flex-shrink-0"
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
                                        </a>
                                    </template>
                                    <div x-show="!listItems[list.id] || listItems[list.id].length === 0" class="text-center py-8 text-gray-500 text-sm">
                                        Inga objekt i listan
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <div x-show="!loading && visibleLists.length === 0" class="text-center py-8 text-gray-500">
                        Du har inga listor ännu
                    </div>
                </div>
                
                <!-- New List Modal -->
                <div x-show="showNewListModal" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click="showNewListModal = false">
                    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4" @click.stop>
                        <h3 class="text-lg font-semibold mb-4">Skapa ny lista</h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Listnamn:</label>
                                <input x-model="newListName" type="text" placeholder="Min nya lista..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Beskrivning (valfritt):</label>
                                <textarea x-model="newListDescription" rows="3" placeholder="Vad handlar listan om?" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Synlighet:</label>
                                <select x-model="newListVisibility" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="private">Privat (endast du)</option>
                                    <option value="public">Offentlig (alla kan se)</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex space-x-3 mt-6">
                            <button @click="createNewList" :disabled="!newListName.trim()" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50 disabled:cursor-not-allowed">
                                Skapa lista
                            </button>
                            <button @click="cancelNewList" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                                Avbryt
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    ' . getSharedScriptJs() . '
</body>
</html>';
}

function getWatchedListHtml(array $user, array $watchedTitles): string
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
        ' . getSharedHeaderHtml($user, true) . '
        
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-gray-800">Historik</h1>
        </div>
        
        <div class="bg-white rounded-lg shadow" x-data="{ ...searchApp(), ...watchedListApp() }" x-init="loadUserLists(); loadItems()">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">Historik</h2>
                        <p class="text-sm text-gray-500 mt-1" x-text="items.length + \' titlar (sett, tittar, eller slutat)\'"></p>
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
                                <a :href="\'/title/\' + item.title_id" class="font-semibold text-gray-900 mb-1 hover:text-blue-600 cursor-pointer block" x-text="item.title"></a>
                                <p class="text-sm text-gray-500 mb-2" x-text="getYear(item.release_date)"></p>
                                <div class="flex items-center space-x-2 mb-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" 
                                          :class="{
                                              \'bg-green-100 text-green-800\': item.state === \'watched\',
                                              \'bg-blue-100 text-blue-800\': item.state === \'watching\',
                                              \'bg-red-100 text-red-800\': item.state === \'stopped\'
                                          }"
                                          x-text="getStateText(item.state)">
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
        function watchedListApp() {
            return {
                items: [],
                loading: true,
                
                async loadItems() {
                    try {
                        const response = await fetch(\'/api/user/titles?states=watched,watching,stopped\');
                        const data = await response.json();
                        this.items = data.items || [];
                    } catch (error) {
                        console.error(\'Failed to load user titles:\', error);
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
                },
                
                getStateText: TvSoffanUtils.getStateText
            }
        }
    </script>
    ' . getSharedScriptJs() . '
</body>
</html>';
}

function getTitleDetailHtml(array $user, array $titleData): string
{
    $year = $titleData['release_date'] ? date('Y', strtotime($titleData['release_date'])) : 'Okänt år';
    $posterUrl = $titleData['poster_path'] ? 'https://image.tmdb.org/t/p/w500' . $titleData['poster_path'] : '/placeholder.png';
    
    return '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($titleData['title']) . ' - tvsoffan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4" x-data="{ ...searchApp(), ...titleDetailApp(' . $titleData['id'] . ') }" x-init="loadUserLists(); loadUserStatus();">
        ' . getSharedHeaderHtml($user, true) . '
        
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="md:flex">
                <div class="md:w-1/3">
                    <img src="' . $posterUrl . '" alt="' . htmlspecialchars($titleData['title']) . '" class="w-full h-96 md:h-full object-cover" onerror="this.src=\'/placeholder.png\'">
                </div>
                <div class="md:w-2/3 p-6">
                    <div class="mb-6">
                        <h2 class="text-3xl font-bold text-gray-900 mb-2">' . htmlspecialchars($titleData['title']) . '</h2>
                        <div class="flex items-center space-x-4 text-sm text-gray-600 mb-4">
                            <span>' . $year . '</span>
                            <span class="capitalize">' . ucfirst($titleData['media_type']) . '</span>
                        </div>
                    </div>
                    
                    <!-- User Rating and State Section -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-1">
                                <template x-for="star in 5" :key="star">
                                    <button @click="setRating(star)" class="text-3xl hover:scale-110 transition-transform focus:outline-none">
                                        <span :class="star <= userRating ? \'text-yellow-500\' : \'text-gray-300\'" x-text="\'★\'"></span>
                                    </button>
                                </template>
                                <span x-show="userRating > 0" class="ml-2 text-sm text-gray-600" x-text="userRating + \'/5\'"></span>
                            </div>
                            <!-- State Button -->
                            <div>
                                <button @click="showStateModal = true" class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-800 rounded-lg hover:bg-blue-200 font-medium">
                                    <span x-text="currentStateText || \'Har sett\'"></span>
                                    <svg class="ml-2 w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Comment Section -->
                    <div class="mb-6">
                        <!-- Show comment button if no comment exists -->
                        <div x-show="!userComment || userComment.trim().length === 0" class="flex items-center space-x-2">
                            <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                            </svg>
                            <button @click="showCommentEdit = true" class="text-sm text-gray-600 hover:text-gray-800 underline">
                                Kommentera
                            </button>
                        </div>
                        
                        <!-- Show existing comment -->
                        <div x-show="userComment && userComment.trim().length > 0 && !showCommentEdit" @click="showCommentEdit = true" class="cursor-pointer hover:bg-gray-50 rounded-lg p-2 -m-2 transition-colors">
                            <div class="flex items-start space-x-2">
                                <svg class="w-5 h-5 text-gray-400 mt-1 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                                </svg>
                                <div class="flex-1">
                                    <div class="p-3 bg-gray-50 rounded-lg border-l-4 border-blue-500">
                                        <p class="text-sm text-gray-700 italic">
                                            <span class="text-gray-500">"</span><span x-text="userComment"></span><span class="text-gray-500">"</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Comment edit box -->
                        <div x-show="showCommentEdit" class="flex items-start space-x-2">
                            <svg class="w-5 h-5 text-gray-400 mt-1 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="flex-1">
                                <textarea x-model="userComment" placeholder="Lägg till en snabb kommentar..." class="w-full p-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none" rows="2"></textarea>
                                <div class="flex space-x-2 mt-2">
                                    <button @click="saveComment" class="px-3 py-1 bg-blue-500 text-white text-sm rounded hover:bg-blue-600">Spara</button>
                                    <button @click="cancelComment" class="px-3 py-1 bg-gray-300 text-gray-700 text-sm rounded hover:bg-gray-400">Avbryt</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Title Description -->
                    <div class="mb-6">
                        <p class="text-gray-700 leading-relaxed">' . htmlspecialchars($titleData['overview']) . '</p>
                    </div>
                    
                    <!-- Lists Section -->
                    <div x-show="userStatus.length > 0" class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-3">Med i lista:</h3>
                        <div class="space-y-2">
                            <template x-for="status in userStatus" :key="status.list_id">
                                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                                    <a :href="\'/lists/\' + status.list_id" class="text-blue-600 hover:text-blue-800 font-medium cursor-pointer" x-text="status.list_name"></a>
                                    <button @click="removeFromList(status.list_id)" class="text-red-500 hover:text-red-700 font-bold text-lg">×</button>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Add to List Button -->
                    <button @click="showAddListModal = true" class="inline-flex items-center px-6 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 font-medium">
                        + Lägg till i lista
                    </button>
                </div>
            </div>
            
            <!-- State Modal -->
            <div x-show="showStateModal" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click="showStateModal = false">
                <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4" @click.stop>
                    <h3 class="text-lg font-semibold mb-4">Ändra status</h3>
                    <div class="space-y-2">
                        <button @click="updateState(\'want\')" class="w-full text-left px-3 py-2 rounded hover:bg-gray-100">Vill se</button>
                        <button @click="updateState(\'watching\')" class="w-full text-left px-3 py-2 rounded hover:bg-gray-100">Tittar</button>
                        <button @click="updateState(\'watched\')" class="w-full text-left px-3 py-2 rounded hover:bg-gray-100">Sett</button>
                        <button @click="updateState(\'stopped\')" class="w-full text-left px-3 py-2 rounded hover:bg-gray-100">Slutat titta</button>
                    </div>
                    <button @click="showStateModal = false" class="mt-4 w-full px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Stäng</button>
                </div>
            </div>
            
            <!-- Add to List Modal -->
            <div x-show="showAddListModal" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click="showAddListModal = false">
                <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4" @click.stop>
                    <h3 class="text-lg font-semibold mb-4">Välj lista</h3>
                    <div class="space-y-2 max-h-60 overflow-y-auto">
                        <template x-for="list in availableLists" :key="list.id">
                            <button @click="addToList(list.id)" class="w-full text-left px-3 py-2 rounded hover:bg-gray-100" x-text="list.name"></button>
                        </template>
                    </div>
                    <div x-show="availableLists.length === 0" class="text-center py-4 text-gray-500">
                        Inga tillgängliga listor
                    </div>
                    <button @click="showAddListModal = false" class="mt-4 w-full px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Stäng</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function titleDetailApp(titleId) {
            return {
                titleId: titleId,
                userStatus: [],
                userRating: 0,
                userComment: \'\',
                originalComment: \'\',
                currentStateText: \'\',
                showStateModal: false,
                showAddListModal: false,
                showCommentEdit: false,
                availableLists: [],
                
                async loadUserStatus() {
                    try {
                        const response = await fetch(\'/api/titles/\' + this.titleId + \'/status\');
                        const data = await response.json();
                        this.userStatus = data.status || [];
                        
                        // Set current rating and comment from any entry with data
                        const entryWithRating = this.userStatus.find(s => s.rating);
                        if (entryWithRating) {
                            this.userRating = parseInt(entryWithRating.rating) || 0;
                        }
                        
                        const entryWithComment = this.userStatus.find(s => s.comment && s.comment.trim() !== \'\');
                        if (entryWithComment) {
                            this.userComment = entryWithComment.comment;
                            this.originalComment = entryWithComment.comment;
                        }
                        
                        // Set current state text - use the user title state
                        if (this.userStatus.length > 0) {
                            // All lists now have the same state (from user_titles table)
                            const currentStatus = this.userStatus[0];
                            this.currentStateText = this.getStateText(currentStatus.state);
                        }
                        
                        await this.loadAvailableLists();
                    } catch (error) {
                        console.error(\'Failed to load user status:\', error);
                    }
                },
                
                async loadAvailableLists() {
                    try {
                        const response = await fetch(\'/api/lists\');
                        const data = await response.json();
                        const allLists = data.lists || [];
                        
                        // Filter out lists the title is already in  
                        const currentListIds = this.userStatus.map(s => s.list_id);
                        this.availableLists = allLists.filter(list => 
                            !currentListIds.includes(list.id)
                        );
                    } catch (error) {
                        console.error(\'Failed to load available lists:\', error);
                    }
                },
                
                async setRating(rating) {
                    this.userRating = rating;
                    
                    // Update user title state (no longer tied to specific list)
                    if (this.userStatus.length > 0) {
                        const currentStatus = this.userStatus[0];
                        await this.updateTitleInList(currentStatus.list_id, currentStatus.state, rating, this.userComment);
                    }
                },
                
                async saveComment() {
                    // Update user title state (no longer tied to specific list)
                    if (this.userStatus.length > 0) {
                        const currentStatus = this.userStatus[0];
                        await this.updateTitleInList(currentStatus.list_id, currentStatus.state, this.userRating, this.userComment);
                        this.originalComment = this.userComment;
                        this.showCommentEdit = false;
                    }
                },
                
                cancelComment() {
                    this.userComment = this.originalComment;
                    this.showCommentEdit = false;
                },
                
                async updateState(newState) {
                    this.showStateModal = false;
                    
                    // Update user title state (no longer tied to specific list)
                    if (this.userStatus.length > 0) {
                        const currentStatus = this.userStatus[0];
                        await this.updateTitleInList(currentStatus.list_id, newState, this.userRating, this.userComment);
                    }
                },
                
                async updateTitleInList(listId, state, rating = null, comment = \'\') {
                    const requestBody = {
                        list_id: listId,
                        state: state,
                        rating: rating || null,
                        comment: comment || \'\'
                    };
                    
                    try {
                        const response = await fetch(\'/api/titles/update/\' + this.titleId, {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        if (response.ok) {
                            await this.loadUserStatus();
                        } else {
                            const data = await response.json();
                            alert(\'Fel: \' + (data.error || \'Kunde inte uppdatera\'));
                        }
                    } catch (error) {
                        console.error(\'Update error:\', error);
                        alert(\'Något gick fel vid uppdatering\');
                    }
                },
                
                async addToList(listId) {
                    this.showAddListModal = false;
                    
                    const requestBody = {
                        list_id: listId,
                        state: \'want\',
                        rating: this.userRating || null,
                        comment: this.userComment || \'\'
                    };
                    
                    try {
                        const response = await fetch(\'/api/titles/update/\' + this.titleId, {
                            method: \'POST\',
                            headers: {
                                \'Content-Type\': \'application/json\',
                            },
                            body: JSON.stringify(requestBody)
                        });
                        
                        if (response.ok) {
                            await this.loadUserStatus();
                        } else {
                            const data = await response.json();
                            alert(\'Fel: \' + (data.error || \'Kunde inte lägga till i listan\'));
                        }
                    } catch (error) {
                        console.error(\'Add to list error:\', error);
                        alert(\'Något gick fel när titeln skulle läggas till\');
                    }
                },
                
                async removeFromList(listId) {
                    try {
                        const response = await fetch(\'/api/titles/\' + this.titleId + \'/lists/\' + listId, {
                            method: \'DELETE\'
                        });
                        
                        if (response.ok) {
                            await this.loadUserStatus();
                        } else {
                            const data = await response.json();
                            alert(\'Fel: \' + (data.error || \'Kunde inte ta bort från listan\'));
                        }
                    } catch (error) {
                        console.error(\'Remove from list error:\', error);
                        alert(\'Något gick fel vid borttagning\');
                    }
                },
                
                getStateText(state) {
                    const stateTexts = {
                        \'want\': \'Vill se\',
                        \'watching\': \'Tittar\',
                        \'watched\': \'Sett\',
                        \'stopped\': \'Slutat titta\'
                    };
                    return stateTexts[state] || state;
                }
            }
        }
    </script>
    ' . getSharedScriptJs() . '
</body>
</html>';
}

$app->run();