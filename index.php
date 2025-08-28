<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpException;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteCollectorProxy;
use Slim\Middleware\BodyParsingMiddleware;
use Slim\Middleware\ErrorMiddleware;
use DI\Container;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\LazyOpenStream;

// Initialize application
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// CORS middleware
$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Expose-Headers', 'Content-Disposition');
});

// Error middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Database configuration
$container->set('db', function () {
    // Update these values with your cPanel PostgreSQL credentials
    $dbHost = '127.0.0.1'; // or your cPanel PostgreSQL host
    $dbName = 'ubmis_ngo';
    $dbUser = 'ubmis_mugisha';
    $dbPass = '23571113#dDarius';
    
    try {
        $dsn = "pgsql:host=$dbHost;dbname=$dbName";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException("Database connection failed: " . $e->getMessage());
    }
});


// Initialize database tables
function init_db(PDO $db) {
    try {
        // Drop diary_entries if exists
        $db->exec('DROP TABLE IF EXISTS diary_entries');
        
        // Create all tables
        $db->exec('
            CREATE TABLE IF NOT EXISTS donors (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                address TEXT,
                donor_type TEXT,
                notes TEXT,
                category TEXT DEFAULT \'one-time\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS products (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                buying_price REAL NOT NULL,
                selling_price REAL NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS services (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                price REAL NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS stock (
                id SERIAL PRIMARY KEY,
                product_name TEXT NOT NULL,
                product_type TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                price_per_unit REAL NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS bank_account (
                id SERIAL PRIMARY KEY,
                balance REAL NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS clients (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS assets (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                cost_price REAL NOT NULL,
                current_value REAL NOT NULL,
                quantity INTEGER NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS sales (
                id SERIAL PRIMARY KEY,
                client_name TEXT NOT NULL,
                items JSONB NOT NULL,
                total_amount REAL NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS expenses (
                id SERIAL PRIMARY KEY,
                date DATE NOT NULL,
                person TEXT NOT NULL,
                description TEXT NOT NULL,
                cost REAL NOT NULL,
                quantity INTEGER NOT NULL,
                total REAL NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS transactions (
                id SERIAL PRIMARY KEY,
                date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                type TEXT NOT NULL,
                amount REAL NOT NULL,
                purpose TEXT NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS notifications (
                id SERIAL PRIMARY KEY,
                message TEXT NOT NULL,
                type TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_read BOOLEAN DEFAULT FALSE
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS tasks (
                id SERIAL PRIMARY KEY,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                date TEXT NOT NULL,
                status TEXT NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE diary_entries (
                id SERIAL PRIMARY KEY,
                content TEXT NOT NULL,
                date TEXT NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS folders (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                parent_id TEXT REFERENCES folders(id) ON DELETE CASCADE
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS donations (
                id SERIAL PRIMARY KEY,
                donor_id INTEGER REFERENCES donors(id) ON DELETE SET NULL,  
                amount FLOAT NOT NULL,
                payment_method TEXT NOT NULL,
                date DATE NOT NULL,
                project TEXT,
                notes TEXT,
                status TEXT DEFAULT \'completed\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                size INTEGER NOT NULL,
                folder_id TEXT REFERENCES folders(id) ON DELETE CASCADE,
                path TEXT NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS projects (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                budget REAL NOT NULL,
                funding_source TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS activities (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                project_id INTEGER REFERENCES projects(id),
                description TEXT,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                budget REAL NOT NULL,
                status TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS budget_items (
                id SERIAL PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                activity_id INTEGER REFERENCES activities(id) ON DELETE CASCADE,
                item_name TEXT NOT NULL,
                description TEXT,
                quantity REAL NOT NULL,
                unit_price REAL NOT NULL,
                total REAL GENERATED ALWAYS AS (quantity * unit_price) STORED,
                category TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS employees (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                nin TEXT NOT NULL UNIQUE,
                dob DATE NOT NULL,
                qualification TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                address TEXT,
                status TEXT NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS deployments (
                id SERIAL PRIMARY KEY,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                activity_id INTEGER NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
                role TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS work_opportunities (
                id SERIAL PRIMARY KEY,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'open\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS opportunity_assignments (
                id SERIAL PRIMARY KEY,
                opportunity_id INTEGER NOT NULL REFERENCES work_opportunities(id) ON DELETE CASCADE,
                employee_id INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS payments (
                id SERIAL PRIMARY KEY,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                amount DECIMAL(12, 2) NOT NULL,
                payment_period VARCHAR(7) NOT NULL,
                description TEXT,
                payment_method VARCHAR(20) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT \'pending\',
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                approved_at TIMESTAMP,
                processed_by INTEGER REFERENCES employees(id)
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS reports (
                id SERIAL PRIMARY KEY,
                employee_id INTEGER REFERENCES employees(id),
                activity_id INTEGER REFERENCES activities(id),
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'submitted\',
                submitted_by INTEGER REFERENCES employees(id),
                approved_by INTEGER REFERENCES employees(id),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS report_attachments (
                id SERIAL PRIMARY KEY,
                report_id INTEGER REFERENCES reports(id) ON DELETE CASCADE,
                original_filename TEXT NOT NULL,
                stored_filename TEXT NOT NULL,
                file_type TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $db->exec('
            CREATE TABLE IF NOT EXISTS activity_approvals (
                id SERIAL PRIMARY KEY,
                activity_id INTEGER NOT NULL REFERENCES activities(id) ON DELETE CASCADE,
                requested_by INTEGER REFERENCES employees(id),
                requested_amount FLOAT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                comments TEXT,
                response_comments TEXT,
                approved_by INTEGER REFERENCES employees(id),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP,
                decision_date TIMESTAMP
            )
        ');
        $db->exec('
            CREATE TABLE IF NOT EXISTS program_areas (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                budget FLOAT DEFAULT 0,
                balance FLOAT DEFAULT 0
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS bank_accounts (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                account_number TEXT NOT NULL,
                balance FLOAT DEFAULT 0
            )
        ');
        
        // Insert initial data
        $programAreas = [
            ["Main Account", 0],
            ["Women Empowerment", 0],
            ["Vocational Education", 0],
            ["Climate Change", 0],
            ["Reproductive Health", 0]
        ];
        
        $stmt = $db->prepare('
            INSERT INTO program_areas (name, budget)
            VALUES (?, ?)
            ON CONFLICT (name) DO NOTHING
        ');
        
        foreach ($programAreas as $area) {
            $stmt->execute($area);
        }
        
        $db->exec("
            INSERT INTO bank_accounts (name, account_number, balance)
            VALUES ('Main Account', '****5580', 0)
            ON CONFLICT (name) DO NOTHING
        ");
        
        // Create root folder if not exists
        $stmt = $db->prepare('SELECT id FROM folders WHERE id = ?');
        $stmt->execute(['root']);
        if (!$stmt->fetch()) {
            $db->prepare('INSERT INTO folders (id, name) VALUES (?, ?)')
               ->execute(['root', 'Fundraising Documents']);
        }
        
        // Initialize bank account balance
        $stmt = $db->query('SELECT COUNT(*) FROM bank_account');
        if ($stmt->fetchColumn() == 0) {
            $db->exec('INSERT INTO bank_account (balance) VALUES (0)');
        }
        
    } catch (PDOException $e) {
        error_log("Error initializing database: " . $e->getMessage());
        throw $e;
    }
}

// Migrate database
function migrate_database(PDO $db) {
    try {
        // Check if employee_id column exists in reports table
        $stmt = $db->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name='reports' AND column_name='employee_id'
        ");
        
        if (!$stmt->fetch()) {
            $db->exec("
                ALTER TABLE reports 
                ADD COLUMN employee_id INTEGER REFERENCES employees(id)
            ");
            error_log("Added employee_id column to reports table");
        }
        
        // Check if submitted_by column exists in reports table
        $stmt = $db->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name='reports' AND column_name='submitted_by'
        ");
        
        if (!$stmt->fetch()) {
            $db->exec("
                ALTER TABLE reports 
                ADD COLUMN submitted_by INTEGER REFERENCES employees(id)
            ");
            error_log("Added submitted_by column to reports table");
        }
        
    } catch (PDOException $e) {
        error_log("Error migrating database: " . $e->getMessage());
        throw $e;
    }
}

// Initialize database on startup
try {
    $db = $container->get('db');
    init_db($db);
    migrate_database($db);
} catch (PDOException $e) {
    die("Failed to initialize database: " . $e->getMessage());
}

// Helper functions
function jsonResponse(Response $response, $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}

function getUploadDir(): string {
    $uploadDir = __DIR__ . '/uploads/fundraising';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    return $uploadDir;
}

// Product endpoints
$app->post('/products', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO products (name, type, buying_price, selling_price)
            VALUES (?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['name'],
            $data['type'],
            $data['buying_price'],
            $data['selling_price']
        ]);
        
        return jsonResponse($response, ['message' => 'Product added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding product: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add product'], 500);
    }
});

$app->get('/products', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM products');
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['products' => $products]);
    } catch (PDOException $e) {
        error_log("Error fetching products: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch products'], 500);
    }
});

$app->delete('/products/{product_name}/{product_type}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $productName = $args['product_name'];
    $productType = $args['product_type'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM products WHERE name = ? AND type = ?');
        $stmt->execute([$productName, $productType]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Product not found'], 404);
        }
        
        $db->prepare('DELETE FROM products WHERE name = ? AND type = ?')
           ->execute([$productName, $productType]);
        
        return jsonResponse($response, ['message' => 'Product deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting product: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete product'], 500);
    }
});

// Stock endpoints
$app->get('/total_stock', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT SUM(quantity * price_per_unit) FROM stock');
        $totalStock = $stmt->fetchColumn() ?: 0;
        return jsonResponse($response, ['total_stock' => $totalStock]);
    } catch (PDOException $e) {
        error_log("Error calculating total stock: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to calculate total stock'], 500);
    }
});

$app->post('/stock', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO stock (product_name, product_type, quantity, price_per_unit)
            VALUES (?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['product_name'],
            $data['product_type'],
            $data['quantity'],
            $data['price_per_unit']
        ]);
        
        return jsonResponse($response, ['message' => 'Stock added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding stock: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add stock'], 500);
    }
});

$app->get('/stock', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM stock');
        $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['stock' => $stock]);
    } catch (PDOException $e) {
        error_log("Error fetching stock: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch stock'], 500);
    }
});

$app->put('/stock/{product_name}/{product_type}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $productName = $args['product_name'];
    $productType = $args['product_type'];
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            UPDATE stock
            SET quantity = ?, price_per_unit = ?
            WHERE product_name = ? AND product_type = ?
        ');
        
        $stmt->execute([
            $data['quantity'],
            $data['price_per_unit'],
            $productName,
            $productType
        ]);
        
        return jsonResponse($response, ['message' => 'Stock updated successfully']);
    } catch (PDOException $e) {
        error_log("Error updating stock: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update stock'], 500);
    }
});

$app->delete('/stock/{product_name}/{product_type}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $productName = $args['product_name'];
    $productType = $args['product_type'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM stock WHERE product_name = ? AND product_type = ?');
        $stmt->execute([$productName, $productType]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Stock item not found'], 404);
        }
        
        $db->prepare('DELETE FROM stock WHERE product_name = ? AND product_type = ?')
           ->execute([$productName, $productType]);
        
        return jsonResponse($response, ['message' => 'Stock item deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting stock item: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete stock item'], 500);
    }
});

// Service endpoints
$app->post('/services', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO services (name, description, price)
            VALUES (?, ?, ?)
        ');
        
        $stmt->execute([
            $data['name'],
            $data['description'],
            $data['price']
        ]);
        
        return jsonResponse($response, ['message' => 'Service added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding service: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add service'], 500);
    }
});

$app->get('/services', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM services');
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['services' => $services]);
    } catch (PDOException $e) {
        error_log("Error fetching services: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch services'], 500);
    }
});

$app->delete('/services/{service_name}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $serviceName = $args['service_name'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM services WHERE name = ?');
        $stmt->execute([$serviceName]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Service not found'], 404);
        }
        
        $db->prepare('DELETE FROM services WHERE name = ?')
           ->execute([$serviceName]);
        
        return jsonResponse($response, ['message' => 'Service deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting service: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete service'], 500);
    }
});

// Bank Account endpoints
$app->get('/bank_account', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $balance = $stmt->fetchColumn();
        return jsonResponse($response, ['balance' => $balance]);
    } catch (PDOException $e) {
        error_log("Error fetching bank account balance: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch bank account balance'], 500);
    }
});

$app->post('/bank_account', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Get current balance
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $currentBalance = $stmt->fetchColumn();
        
        // Calculate new balance
        $newBalance = $currentBalance + $data['balance'];
        
        // Update bank account
        $db->prepare('UPDATE bank_account SET balance = ? WHERE id = 1')
           ->execute([$newBalance]);
        
        // Log transaction
        $transactionType = $data['balance'] > 0 ? 'deposit' : 'withdraw';
        $db->prepare('
            INSERT INTO transactions (type, amount, purpose)
            VALUES (?, ?, ?)
        ')->execute([
            $transactionType,
            abs($data['balance']),
            $data['purpose']
        ]);
        
        return jsonResponse($response, ['message' => 'Bank account updated and transaction logged successfully']);
    } catch (PDOException $e) {
        error_log("Error updating bank account: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update bank account'], 500);
    }
});

// Client endpoints
$app->post('/clients', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO clients (name, email, phone)
            VALUES (?, ?, ?)
        ');
        
        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['phone']
        ]);
        
        return jsonResponse($response, ['message' => 'Client added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding client: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add client'], 500);
    }
});

$app->get('/clients', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM clients');
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['clients' => $clients]);
    } catch (PDOException $e) {
        error_log("Error fetching clients: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch clients'], 500);
    }
});

$app->delete('/clients/{client_name}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $clientName = $args['client_name'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM clients WHERE name = ?');
        $stmt->execute([$clientName]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Client not found'], 404);
        }
        
        $db->prepare('DELETE FROM clients WHERE name = ?')
           ->execute([$clientName]);
        
        return jsonResponse($response, ['message' => 'Client deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting client: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete client'], 500);
    }
});

// Asset endpoints
$app->post('/assets', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO assets (name, type, cost_price, current_value, quantity)
            VALUES (?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['name'],
            $data['type'],
            $data['cost_price'],
            $data['current_value'],
            $data['quantity']
        ]);
        
        return jsonResponse($response, ['message' => 'Asset added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding asset: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add asset'], 500);
    }
});

$app->get('/assets', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM assets');
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['assets' => $assets]);
    } catch (PDOException $e) {
        error_log("Error fetching assets: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch assets'], 500);
    }
});

$app->delete('/assets/{asset_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $assetId = $args['asset_id'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Asset not found'], 404);
        }
        
        $db->prepare('DELETE FROM assets WHERE id = ?')
           ->execute([$assetId]);
        
        return jsonResponse($response, ['message' => 'Asset deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting asset: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete asset'], 500);
    }
});

// Total Investment endpoint
$app->get('/total_investment', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Get total assets
        $stmt = $db->query('SELECT SUM(cost_price * quantity) FROM assets');
        $totalAssets = $stmt->fetchColumn() ?: 0;
        
        // Get bank balance
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $bankBalance = $stmt->fetchColumn() ?: 0;
        
        // Get total stock
        $stmt = $db->query('SELECT SUM(quantity * price_per_unit) FROM stock');
        $totalStock = $stmt->fetchColumn() ?: 0;
        
        $totalInvestment = $totalAssets + $bankBalance + $totalStock;
        
        return jsonResponse($response, ['total_investment' => $totalInvestment]);
    } catch (PDOException $e) {
        error_log("Error calculating total investment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to calculate total investment'], 500);
    }
});

// Sales endpoints
$app->post('/sales', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Insert sale
        $stmt = $db->prepare('
            INSERT INTO sales (client_name, items, total_amount)
            VALUES (?, ?, ?)
        ');
        
        $itemsJson = json_encode($data['items']);
        $stmt->execute([
            $data['client_name'],
            $itemsJson,
            $data['total_amount']
        ]);
        
        // Create notification
        $notificationMsg = "New sale to {$data['client_name']} for UGX {$data['total_amount']}";
        $db->prepare('
            INSERT INTO notifications (message, type)
            VALUES (?, ?)
        ')->execute([$notificationMsg, 'sale']);
        
        // Update bank balance
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $currentBalance = $stmt->fetchColumn();
        $newBalance = $currentBalance + $data['total_amount'];
        $db->prepare('UPDATE bank_account SET balance = ? WHERE id = 1')
           ->execute([$newBalance]);
        
        // Update stock for products
        foreach ($data['items'] as $item) {
            if ($item['type'] === 'product') {
                $stmt = $db->prepare('SELECT * FROM stock WHERE product_name = ?');
                $stmt->execute([$item['name']]);
                $stockItem = $stmt->fetch();
                
                if (!$stockItem) {
                    throw new RuntimeException("Stock item {$item['name']} not found");
                }
                
                if ($stockItem['quantity'] < $item['quantity']) {
                    throw new RuntimeException("Insufficient stock for {$item['name']}");
                }
                
                $db->prepare('
                    UPDATE stock
                    SET quantity = quantity - ?
                    WHERE product_name = ?
                ')->execute([$item['quantity'], $item['name']]);
            }
        }
        
        return jsonResponse($response, ['message' => 'Sale created successfully']);
    } catch (Exception $e) {
        error_log("Error creating sale: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create sale'], 500);
    }
});

$app->get('/sales', function (Request $request, Response $response) {
    $db = $this->get('db');
    $date = $request->getQueryParams()['date'] ?? null;
    
    try {
        if ($date) {
            $stmt = $db->prepare('
                SELECT * FROM sales 
                WHERE DATE(created_at) = ?
                ORDER BY created_at DESC
            ');
            $stmt->execute([$date]);
        } else {
            $stmt = $db->query('SELECT * FROM sales ORDER BY created_at DESC');
        }
        
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['sales' => $sales]);
    } catch (PDOException $e) {
        error_log("Error fetching sales: " . $e->getMessage());
        // Return proper JSON error response
        return jsonResponse($response, [
            'error' => 'Failed to fetch sales',
            'details' => $e->getMessage()
        ], 500);
    }
});


$app->get('/sales/{sale_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $saleId = $args['sale_id'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        
        if (!$sale) {
            return jsonResponse($response, ['error' => 'Sale not found'], 404);
        }
        
        return jsonResponse($response, ['sale' => $sale]);
    } catch (PDOException $e) {
        error_log("Error fetching sale: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch sale'], 500);
    }
});

$app->delete('/sales/{sale_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $saleId = $args['sale_id'];
    
    try {
        $stmt = $db->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Sale not found'], 404);
        }
        
        $db->prepare('DELETE FROM sales WHERE id = ?')
           ->execute([$saleId]);
        
        return jsonResponse($response, ['message' => 'Sale deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting sale: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete sale'], 500);
    }
});

// Expense endpoints
$app->post('/expenses', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    $total = $data['cost'] * $data['quantity'];
    
    try {
        // Insert expense
        $stmt = $db->prepare('
            INSERT INTO expenses (date, person, description, cost, quantity, total)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['date'],
            $data['person'],
            $data['description'],
            $data['cost'],
            $data['quantity'],
            $total
        ]);
        
        // Update bank balance
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $currentBalance = $stmt->fetchColumn();
        $newBalance = $currentBalance - $total;
        $db->prepare('UPDATE bank_account SET balance = ? WHERE id = 1')
           ->execute([$newBalance]);
        
        return jsonResponse($response, ['message' => 'Expense added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding expense: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add expense'], 500);
    }
});

$app->get('/expenses', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM expenses ORDER BY date DESC');
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['expenses' => $expenses]);
    } catch (PDOException $e) {
        error_log("Error fetching expenses: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch expenses'], 500);
    }
});

$app->delete('/expenses/{expense_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $expenseId = $args['expense_id'];
    
    try {
        // Get expense details
        $stmt = $db->prepare('SELECT * FROM expenses WHERE id = ?');
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch();
        
        if (!$expense) {
            return jsonResponse($response, ['error' => 'Expense not found'], 404);
        }
        
        // Restore balance
        $stmt = $db->query('SELECT balance FROM bank_account WHERE id = 1');
        $currentBalance = $stmt->fetchColumn();
        $newBalance = $currentBalance + $expense['total'];
        $db->prepare('UPDATE bank_account SET balance = ? WHERE id = 1')
           ->execute([$newBalance]);
        
        // Delete expense
        $db->prepare('DELETE FROM expenses WHERE id = ?')
           ->execute([$expenseId]);
        
        return jsonResponse($response, ['message' => 'Expense deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting expense: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete expense'], 500);
    }
});

// Transactions endpoints
$app->get('/transactions', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT date, type, amount, purpose
            FROM transactions
            ORDER BY date DESC
        ');
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format dates
        $formattedTransactions = array_map(function($transaction) {
            $date = new DateTime($transaction['date']);
            $transaction['date'] = $date->format('Y-m-d H:i:s');
            return $transaction;
        }, $transactions);
        
        return jsonResponse($response, ['transactions' => $formattedTransactions]);
    } catch (PDOException $e) {
        error_log("Error fetching transactions: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch transactions'], 500);
    }
});

// Net Profit endpoint
$app->get('/net_profit', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Total sales revenue
        $stmt = $db->query('SELECT SUM(total_amount) FROM sales');
        $totalSalesRevenue = $stmt->fetchColumn() ?: 0;
        
        // COGS for products
        $stmt = $db->query('
            SELECT SUM((item->>\'quantity\')::int * p.buying_price)
            FROM sales, jsonb_array_elements(items) AS item
            JOIN products p ON p.name = item->>\'name\'
            WHERE item->>\'type\' = \'product\'
        ');
        $totalCogsProducts = $stmt->fetchColumn() ?: 0;
        
        // COGS for services
        $stmt = $db->query('
            SELECT SUM((item->>\'quantity\')::int * (item->>\'unit_price\')::float * 0.5)
            FROM sales, jsonb_array_elements(items) AS item
            WHERE item->>\'type\' = \'service\'
        ');
        $totalCogsServices = $stmt->fetchColumn() ?: 0;
        
        $totalCogs = $totalCogsProducts + $totalCogsServices;
        
        // Total expenses
        $stmt = $db->query('SELECT SUM(total) FROM expenses');
        $totalExpenses = $stmt->fetchColumn() ?: 0;
        
        $netProfit = ($totalSalesRevenue - $totalCogs) - $totalExpenses;
        
        return jsonResponse($response, ['net_profit' => $netProfit]);
    } catch (PDOException $e) {
        error_log("Error calculating net profit: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to calculate net profit'], 500);
    }
});

// Notification endpoints
$app->post('/notifications', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO notifications (message, type)
            VALUES (?, ?)
        ');
        
        $stmt->execute([
            $data['message'],
            $data['type']
        ]);
        
        return jsonResponse($response, ['message' => 'Notification created successfully']);
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create notification'], 500);
    }
});

$app->get('/notifications', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM notifications ORDER BY created_at DESC');
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['notifications' => $notifications]);
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch notifications'], 500);
    }
});

$app->get('/notifications/unread', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT COUNT(*) FROM notifications WHERE is_read = FALSE');
        $unreadCount = $stmt->fetchColumn();
        return jsonResponse($response, ['unread_count' => $unreadCount]);
    } catch (PDOException $e) {
        error_log("Error fetching unread notifications: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch unread notifications'], 500);
    }
});

$app->put('/notifications/mark_as_read', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $db->exec('UPDATE notifications SET is_read = TRUE WHERE is_read = FALSE');
        return jsonResponse($response, ['message' => 'All notifications marked as read']);
    } catch (PDOException $e) {
        error_log("Error marking notifications as read: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to mark notifications as read'], 500);
    }
});

// Task endpoints
$app->post('/tasks', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO tasks (title, content, date, status)
            VALUES (?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $data['title'],
            $data['content'],
            $data['date'],
            $data['status']
        ]);
        
        return jsonResponse($response, ['message' => 'Task added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding task: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add task'], 500);
    }
});

$app->get('/tasks', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM tasks');
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['tasks' => $tasks]);
    } catch (PDOException $e) {
        error_log("Error fetching tasks: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch tasks'], 500);
    }
});

$app->put('/tasks/{task_id}/status', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $taskId = $args['task_id'];
    $data = $request->getParsedBody();
    
    if (!in_array($data['status'], ['pending', 'ongoing', 'completed'])) {
        return jsonResponse($response, ['error' => 'Invalid status'], 400);
    }
    
    try {
        $stmt = $db->prepare('
            UPDATE tasks
            SET status = ?
            WHERE id = ?
        ');
        
        $stmt->execute([
            $data['status'],
            $taskId
        ]);
        
        return jsonResponse($response, ['message' => 'Task status updated successfully']);
    } catch (PDOException $e) {
        error_log("Error updating task status: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update task status'], 500);
    }
});

// Diary endpoints
$app->post('/diary', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    // Validate date format
    try {
        $date = new DateTime($data['date']);
    } catch (Exception $e) {
        return jsonResponse($response, ['error' => 'Invalid date format'], 400);
    }
    
    try {
        $stmt = $db->prepare('
            INSERT INTO diary_entries (content, date)
            VALUES (?, ?)
        ');
        
        $stmt->execute([
            $data['content'],
            $data['date']
        ]);
        
        return jsonResponse($response, ['message' => 'Diary entry added successfully']);
    } catch (PDOException $e) {
        error_log("Error adding diary entry: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to add diary entry'], 500);
    }
});

$app->get('/diary', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('SELECT * FROM diary_entries ORDER BY date DESC');
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, ['entries' => $entries]);
    } catch (PDOException $e) {
        error_log("Error fetching diary entries: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch diary entries'], 500);
    }
});

// Gross Profit endpoint
$app->get('/gross_profit', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Total sales revenue
        $stmt = $db->query('SELECT SUM(total_amount) FROM sales');
        $totalSalesRevenue = $stmt->fetchColumn() ?: 0;
        
        // COGS for products
        $stmt = $db->query('
            SELECT SUM((item->>\'quantity\')::int * p.buying_price)
            FROM sales, jsonb_array_elements(items) AS item
            JOIN products p ON p.name = item->>\'name\'
            WHERE item->>\'type\' = \'product\'
        ');
        $totalCogsProducts = $stmt->fetchColumn() ?: 0;
        
        // COGS for services
        $stmt = $db->query('
            SELECT SUM((item->>\'quantity\')::int * (item->>\'unit_price\')::float * 0.5)
            FROM sales, jsonb_array_elements(items) AS item
            WHERE item->>\'type\' = \'service\'
        ');
        $totalCogsServices = $stmt->fetchColumn() ?: 0;
        
        $totalCogs = $totalCogsProducts + $totalCogsServices;
        $grossProfit = $totalSalesRevenue - $totalCogs;
        
        return jsonResponse($response, ['gross_profit' => $grossProfit]);
    } catch (PDOException $e) {
        error_log("Error calculating gross profit: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to calculate gross profit'], 500);
    }
});

// File and Folder endpoints
$app->post('/folders', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    $folderId = Uuid::uuid4()->toString();
    
    try {
        if (!empty($data['parent_id'])) {
            // Check if parent exists
            $stmt = $db->prepare('SELECT id FROM folders WHERE id = ?');
            $stmt->execute([$data['parent_id']]);
            
            if (!$stmt->fetch()) {
                return jsonResponse($response, ['error' => 'Parent folder not found'], 404);
            }
            
            $stmt = $db->prepare('
                INSERT INTO folders (id, name, parent_id)
                VALUES (?, ?, ?)
                RETURNING id, name, parent_id
            ');
            
            $stmt->execute([
                $folderId,
                $data['name'],
                $data['parent_id']
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO folders (id, name)
                VALUES (?, ?)
                RETURNING id, name, parent_id
            ');
            
            $stmt->execute([
                $folderId,
                $data['name']
            ]);
        }
        
        $folder = $stmt->fetch();
        return jsonResponse($response, [
            'id' => $folder['id'],
            'name' => $folder['name'],
            'parent_id' => $folder['parent_id']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating folder: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create folder'], 500);
    }
});

$app->get('/folders/{folder_id}/contents', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $folderId = $args['folder_id'] ?? 'root';
    
    try {
        // Get subfolders
        if ($folderId === 'root') {
            $stmt = $db->query('SELECT id, name, parent_id FROM folders WHERE parent_id IS NULL');
        } else {
            $stmt = $db->prepare('SELECT id, name, parent_id FROM folders WHERE parent_id = ?');
            $stmt->execute([$folderId]);
        }
        
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get files
        if ($folderId === 'root') {
            $stmt = $db->query('SELECT id, name, type, size FROM files WHERE folder_id IS NULL');
        } else {
            $stmt = $db->prepare('SELECT id, name, type, size FROM files WHERE folder_id = ?');
            $stmt->execute([$folderId]);
        }
        
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return jsonResponse($response, [
            'folders' => $folders ?: [], // Ensure we always return an array
            'files' => $files ?: []      // Ensure we always return an array
        ]);
    } catch (PDOException $e) {
        error_log("Error getting folder contents: " . $e->getMessage());
        return jsonResponse($response, [
            'folders' => [],
            'files' => [],
            'error' => 'Failed to get folder contents'
        ], 500);
    }
});

$app->post('/upload', function (Request $request, Response $response) {
    $db = $this->get('db');
    $uploadedFiles = $request->getUploadedFiles();
    $data = $request->getParsedBody();
    $folderId = $data['folder_id'] ?? null;
    
    error_log("Received upload request with folder_id: " . $folderId);
    error_log("Uploaded files count: " . count($uploadedFiles['files'] ?? []));

    if (empty($uploadedFiles['files'])) {
        error_log("No files were uploaded");
        return jsonResponse($response, ['error' => 'No files uploaded'], 400);
    }
    
    $uploadDir = getUploadDir();
    $uploaded = [];
    
    try {
        foreach ($uploadedFiles['files'] as $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                error_log("File upload error: " . $file->getError());
                continue;
            }
            
            $fileId = Uuid::uuid4()->toString();
            $fileExt = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            $fileName = $fileId . ($fileExt ? '.' . $fileExt : '');
            $filePath = $uploadDir . '/' . $fileName;
            
            error_log("Attempting to move file to: " . $filePath);
            $file->moveTo($filePath);
            
            if (!file_exists($filePath)) {
                error_log("File move failed - file doesn't exist at target path");
                continue;
            }

            error_log("Inserting file record into database: " . $file->getClientFilename());
            $stmt = $db->prepare('
                INSERT INTO files (id, name, type, size, folder_id, path)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $fileId,
                $file->getClientFilename(),
                $file->getClientMediaType(),
                $file->getSize(),
                $folderId === 'root' ? null : $folderId, // Handle root folder case
                $filePath
            ]);
            
            $uploaded[] = [
                'id' => $fileId,
                'name' => $file->getClientFilename(),
                'type' => $file->getClientMediaType(),
                'size' => $file->getSize()
            ];
            
            error_log("Successfully uploaded file: " . $file->getClientFilename());
        }
        
        return jsonResponse($response, ['uploadedFiles' => $uploaded]);
    } catch (Exception $e) {
        error_log("Error uploading files: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to upload files: ' . $e->getMessage()], 500);
    }
});

$app->get('/files/{file_id}/download', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $fileId = $args['file_id'];
    
    try {
        $stmt = $db->prepare('SELECT name, path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $fileData = $stmt->fetch();
        
        if (!$fileData) {
            return jsonResponse($response, ['error' => 'File not found'], 404);
        }
        
        if (!file_exists($fileData['path'])) {
            return jsonResponse($response, ['error' => 'File not found on server'], 404);
        }
        
        $fileStream = new LazyOpenStream($fileData['path'], 'r');
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileData['name'] . '"')
            ->withBody($fileStream);
    } catch (Exception $e) {
        error_log("Error downloading file: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to download file'], 500);
    }
});

$app->put('/folders/{folder_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $folderId = $args['folder_id'];
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            UPDATE folders
            SET name = ?
            WHERE id = ?
            RETURNING id, name, parent_id
        ');
        
        $stmt->execute([
            $data['name'],
            $folderId
        ]);
        
        $updatedFolder = $stmt->fetch();
        
        if (!$updatedFolder) {
            return jsonResponse($response, ['error' => 'Folder not found'], 404);
        }
        
        return jsonResponse($response, [
            'id' => $updatedFolder['id'],
            'name' => $updatedFolder['name'],
            'parent_id' => $updatedFolder['parent_id']
        ]);
    } catch (PDOException $e) {
        error_log("Error renaming folder: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to rename folder'], 500);
    }
});

$app->delete('/folders/{folder_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $folderId = $args['folder_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM folders WHERE id = ?');
        $stmt->execute([$folderId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Folder not found'], 404);
        }
        
        $db->prepare('DELETE FROM folders WHERE id = ?')
           ->execute([$folderId]);
        
        return jsonResponse($response, ['message' => 'Folder deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting folder: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete folder'], 500);
    }
});

$app->put('/files/{file_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $fileId = $args['file_id'];
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            UPDATE files
            SET name = ?
            WHERE id = ?
            RETURNING id, name, type, size, folder_id
        ');
        
        $stmt->execute([
            $data['name'],
            $fileId
        ]);
        
        $updatedFile = $stmt->fetch();
        
        if (!$updatedFile) {
            return jsonResponse($response, ['error' => 'File not found'], 404);
        }
        
        return jsonResponse($response, [
            'id' => $updatedFile['id'],
            'name' => $updatedFile['name'],
            'type' => $updatedFile['type'],
            'size' => $updatedFile['size'],
            'folder_id' => $updatedFile['folder_id']
        ]);
    } catch (PDOException $e) {
        error_log("Error renaming file: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to rename file'], 500);
    }
});

$app->delete('/files/{file_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $fileId = $args['file_id'];
    
    try {
        // Get file path before deleting
        $stmt = $db->prepare('SELECT path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $fileData = $stmt->fetch();
        
        if (!$fileData) {
            return jsonResponse($response, ['error' => 'File not found'], 404);
        }
        
        // Delete from database
        $db->prepare('DELETE FROM files WHERE id = ?')
           ->execute([$fileId]);
        
        // Delete physical file
        if (file_exists($fileData['path'])) {
            unlink($fileData['path']);
        }
        
        return jsonResponse($response, ['message' => 'File deleted successfully']);
    } catch (Exception $e) {
        error_log("Error deleting file: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete file'], 500);
    }
});

$app->get('/files/{file_id}/preview', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $fileId = $args['file_id'];
    
    try {
        $stmt = $db->prepare('SELECT name, path, type FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $fileData = $stmt->fetch();
        
        if (!$fileData) {
            return jsonResponse($response, ['error' => 'File not found'], 404);
        }
        
        if (!file_exists($fileData['path'])) {
            return jsonResponse($response, ['error' => 'File not found on server'], 404);
        }
        
        $previewableTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        
        if (in_array($fileData['type'], $previewableTypes)) {
            $fileStream = new LazyOpenStream($fileData['path'], 'r');
            return $response
                ->withHeader('Content-Type', $fileData['type'])
                ->withHeader('Content-Disposition', 'inline; filename="' . $fileData['name'] . '"')
                ->withBody($fileStream);
        } else {
            $fileStream = new LazyOpenStream($fileData['path'], 'r');
            return $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $fileData['name'] . '"')
                ->withBody($fileStream);
        }
    } catch (Exception $e) {
        error_log("Error previewing file: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to preview file'], 500);
    }
});

$app->post('/donations', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // First find or create the donor
        $donorId = null;
        if (!empty($data['donor_name'])) {
            $stmt = $db->prepare('SELECT id FROM donors WHERE name = ?');
            $stmt->execute([$data['donor_name']]);
            $donor = $stmt->fetch();
            
            if (!$donor) {
                // Create new donor if not found
                $stmt = $db->prepare('
                    INSERT INTO donors (name, category)
                    VALUES (?, ?)
                    RETURNING id
                ');
                $stmt->execute([$data['donor_name'], 'one-time']);
                $donorId = $stmt->fetchColumn();
            } else {
                $donorId = $donor['id'];
            }
        }

        // Start transaction
        $db->beginTransaction();

        // Insert donation
        $stmt = $db->prepare('
            INSERT INTO donations (donor_id, amount, payment_method, date, project, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id, donor_id, amount, payment_method, date, project, notes, status, created_at
        ');
        
        $stmt->execute([
            $donorId,
            $data['amount'],
            $data['payment_method'],
            $data['date'],
            $data['project'] ?? null,
            $data['notes'] ?? null,
            $data['status'] ?? 'completed'
        ]);
        
        $newDonation = $stmt->fetch();
        
        // Update program area balance if project matches a program area
        if (!empty($data['project'])) {
            $stmt = $db->prepare('
                UPDATE program_areas 
                SET balance = balance + ?
                WHERE name = ?
            ');
            $stmt->execute([$data['amount'], $data['project']]);
        }
        
        // Also update main account balance
        $stmt = $db->prepare('
            UPDATE bank_accounts 
            SET balance = balance + ?
            WHERE name = \'Main Account\'
        ');
        $stmt->execute([$data['amount']]);
        
        // Commit transaction
        $db->commit();
        
        // Get donor name for response
        $donorName = $data['donor_name'];
        if (!$donorName && $donorId) {
            $stmt = $db->prepare('SELECT name FROM donors WHERE id = ?');
            $stmt->execute([$donorId]);
            $donor = $stmt->fetch();
            $donorName = $donor['name'];
        }
        
        return jsonResponse($response, [
            'id' => $newDonation['id'],
            'donor_name' => $donorName,
            'amount' => $newDonation['amount'],
            'payment_method' => $newDonation['payment_method'],
            'date' => $newDonation['date'],
            'project' => $newDonation['project'],
            'notes' => $newDonation['notes'],
            'status' => $newDonation['status'],
            'created_at' => $newDonation['created_at']
        ]);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error creating donation: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create donation'], 500);
    }
});
$app->get('/donations', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT d.id, COALESCE(dn.name, \'Anonymous\') as donor_name, 
                   d.amount, d.payment_method, d.date, 
                   d.project, d.notes, d.status, d.created_at
            FROM donations d
            LEFT JOIN donors dn ON d.donor_id = dn.id
            ORDER BY d.date DESC
        ');
        
        $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $donations);
    } catch (PDOException $e) {
        error_log("Error fetching donations: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donations'], 500);
    }
});

$app->get('/program-areas', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT id, name, budget, balance
            FROM program_areas
            ORDER BY name
        ');
        
        $programAreas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $programAreas);
    } catch (PDOException $e) {
        error_log("Error fetching program areas: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch program areas'], 500);
    }
});

$app->get('/bank-accounts', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT id, name, account_number, balance
            FROM bank_accounts
            ORDER BY name
        ');
        
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $accounts);
    } catch (PDOException $e) {
        error_log("Error fetching bank accounts: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch bank accounts'], 500);
    }
});

$app->get('/dashboard-summary', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Total donations
        $stmt = $db->query('SELECT COALESCE(SUM(amount), 0) FROM donations');
        $totalDonationsRaw = $stmt->fetchColumn() ?: 0;
        $totalDonations = number_format($totalDonationsRaw); // Format with commas

        // Program area balances
        $stmt = $db->query('SELECT name, balance FROM program_areas');
        $programBalances = [];
        while ($row = $stmt->fetch()) {
            $programBalances[$row['name']] = number_format($row['balance']);
        }

        // Main account balance
        $stmt = $db->query("SELECT balance FROM bank_accounts WHERE name = 'Main Account'");
        $mainBalanceRaw = $stmt->fetchColumn() ?: 0;
        $mainBalance = number_format($mainBalanceRaw);

        return jsonResponse($response, [
            'total_donations' => "{$totalDonations}",
            'program_balances' => array_map(fn($val) => "{$val}", $programBalances),
            'main_account_balance' => "{$mainBalance}"
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching dashboard summary: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch dashboard summary'], 500);
    }
});


$app->delete('/donations/{donation_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donationId = $args['donation_id'];
    
    try {
        $stmt = $db->prepare('DELETE FROM donations WHERE id = ? RETURNING id');
        $stmt->execute([$donationId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Donation not found'], 404);
        }
        
        return jsonResponse($response, ['message' => 'Donation deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting donation: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete donation'], 500);
    }
});

// Donor endpoints
$app->get('/donors', function (Request $request, Response $response) {
    $db = $this->get('db');
    $search = $request->getQueryParams()['search'] ?? null;
    
    try {
        if ($search) {
            $stmt = $db->prepare('
                SELECT DISTINCT ON (id) id, name, email, phone, address, donor_type, notes, category, created_at
                FROM donors
                WHERE name ILIKE ? OR email ILIKE ? OR phone ILIKE ?
                ORDER BY id, name
            ');
            $searchTerm = "%$search%";
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        } else {
            $stmt = $db->query('
                SELECT DISTINCT ON (id) id, name, email, phone, address, donor_type, notes, category, created_at
                FROM donors
                ORDER BY id, name
            ');
        }
        
        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $donors);
    } catch (PDOException $e) {
        error_log("Error fetching donors: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donors'], 500);
    }
});
$app->delete('/donors/{donor_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donorId = $args['donor_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM donors WHERE id = ?');
        $stmt->execute([$donorId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Donor not found'], 404);
        }
        
        $db->prepare('DELETE FROM donors WHERE id = ?')
           ->execute([$donorId]);
        
        return jsonResponse($response, ['message' => 'Donor deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting donor: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete donor'], 500);
    }
});

$app->get('/donors/{donor_id}/donations', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donorId = $args['donor_id'];
    
    try {
        // Get donor name
        $stmt = $db->prepare('SELECT name FROM donors WHERE id = ?');
        $stmt->execute([$donorId]);
        $donor = $stmt->fetch();
        
        if (!$donor) {
            return jsonResponse($response, ['error' => 'Donor not found'], 404);
        }
        
        // Get donations for this donor
        $stmt = $db->prepare('
            SELECT id, date, amount, payment_method, project, notes, status
            FROM donations
            WHERE donor_id = ?
            ORDER BY date DESC
        ');
        $stmt->execute([$donorId]);
        
        $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return jsonResponse($response, [
            'donor_id' => $donorId,
            'donor_name' => $donor['name'],
            'donations' => $donations,
            'total_donations' => array_sum(array_column($donations, 'amount')),
            'donation_count' => count($donations)
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching donor donations: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donor donations'], 500);
    }
});

$app->post('/donors', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    // Validate donor type and category
    if (!empty($data['donor_type']) && !in_array($data['donor_type'], ['individual', 'corporate', 'foundation', 'other'])) {
        return jsonResponse($response, ['error' => 'Invalid donor type'], 400);
    }
    
    if (!empty($data['category']) && !in_array($data['category'], ['regular', 'one-time'])) {
        return jsonResponse($response, ['error' => 'Invalid donor category'], 400);
    }
    
    try {
        $stmt = $db->prepare('
            INSERT INTO donors (name, email, phone, address, donor_type, notes, category)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id, name, email, phone, address, donor_type, notes, category, created_at
        ');
        
        $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['donor_type'] ?? null,
            $data['notes'] ?? null,
            $data['category'] ?? 'one-time'
        ]);
        
        $newDonor = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newDonor['id'],
            'name' => $newDonor['name'],
            'email' => $newDonor['email'],
            'phone' => $newDonor['phone'],
            'address' => $newDonor['address'],
            'donor_type' => $newDonor['donor_type'],
            'notes' => $newDonor['notes'],
            'category' => $newDonor['category'],
            'created_at' => $newDonor['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating donor: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create donor'], 500);
    }
});

$app->put('/donors/{donor_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donorId = $args['donor_id'];
    $data = $request->getParsedBody();
    
    // Validate donor type and category
    if (!empty($data['donor_type']) && !in_array($data['donor_type'], ['individual', 'corporate', 'foundation', 'other'])) {
        return jsonResponse($response, ['error' => 'Invalid donor type'], 400);
    }
    
    if (!empty($data['category']) && !in_array($data['category'], ['regular', 'one-time'])) {
        return jsonResponse($response, ['error' => 'Invalid donor category'], 400);
    }
    
    try {
        $stmt = $db->prepare('
            UPDATE donors
            SET name = ?, email = ?, phone = ?, address = ?, 
                donor_type = ?, notes = ?, category = ?
            WHERE id = ?
            RETURNING id, name, email, phone, address, donor_type, notes, category, created_at
        ');
        
        $stmt->execute([
            $data['name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['donor_type'] ?? null,
            $data['notes'] ?? null,
            $data['category'] ?? 'one-time',
            $donorId
        ]);
        
        $updatedDonor = $stmt->fetch();
        
        if (!$updatedDonor) {
            return jsonResponse($response, ['error' => 'Donor not found'], 404);
        }
        
        return jsonResponse($response, [
            'id' => $updatedDonor['id'],
            'name' => $updatedDonor['name'],
            'email' => $updatedDonor['email'],
            'phone' => $updatedDonor['phone'],
            'address' => $updatedDonor['address'],
            'donor_type' => $updatedDonor['donor_type'],
            'notes' => $updatedDonor['notes'],
            'category' => $updatedDonor['category'],
            'created_at' => $updatedDonor['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error updating donor: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update donor'], 500);
    }
});

$app->get('/projects', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT id, name, description, start_date, end_date, budget, funding_source, status, created_at
            FROM projects
            ORDER BY created_at DESC
        ');
        
        $projects = [];
        while ($row = $stmt->fetch()) {
            $projects[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'budget' => $row['budget'],
                'funding_source' => $row['funding_source'],
                'status' => $row['status'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, ['projects' => $projects]);
    } catch (PDOException $e) {
        error_log("Error fetching projects: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch projects'], 500);
    }
});

$app->get('/projects/{project_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $projectId = $args['project_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT id, name, description, start_date, end_date, budget, funding_source, status, created_at
            FROM projects
            WHERE id = ?
        ');
        
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        
        if (!$project) {
            return jsonResponse($response, ['error' => 'Project not found'], 404);
        }
        
        return jsonResponse($response, [
            'id' => $project['id'],
            'name' => $project['name'],
            'description' => $project['description'],
            'start_date' => $project['start_date'],
            'end_date' => $project['end_date'],
            'budget' => $project['budget'],
            'funding_source' => $project['funding_source'],
            'status' => $project['status'],
            'created_at' => $project['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching project: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch project'], 500);
    }
});

$app->delete('/projects/{project_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $projectId = $args['project_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Project not found'], 404);
        }
        
        $db->prepare('DELETE FROM projects WHERE id = ?')
           ->execute([$projectId]);
        
        return jsonResponse($response, ['message' => 'Project deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting project: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete project'], 500);
    }
});

// Activity endpoints
$app->post('/activities', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Verify project exists
        $stmt = $db->prepare('SELECT name FROM projects WHERE id = ?');
        $stmt->execute([$data['project_id']]);
        $project = $stmt->fetch();
        
        if (!$project) {
            return jsonResponse($response, ['error' => 'Project not found'], 404);
        }
        
        $projectName = $project['name'];
        
        $stmt = $db->prepare('
            INSERT INTO activities (name, project_id, description, start_date, end_date, budget, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id, name, project_id, description, start_date, end_date, budget, status, created_at
        ');
        
        $stmt->execute([
            $data['name'],
            $data['project_id'],
            $data['description'] ?? null,
            $data['start_date'],
            $data['end_date'],
            $data['budget'],
            $data['status'] ?? 'planned'
        ]);
        
        $newActivity = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newActivity['id'],
            'name' => $newActivity['name'],
            'project_id' => $newActivity['project_id'],
            'project_name' => $projectName,
            'description' => $newActivity['description'],
            'start_date' => $newActivity['start_date'],
            'end_date' => $newActivity['end_date'],
            'budget' => $newActivity['budget'],
            'status' => $newActivity['status'],
            'created_at' => $newActivity['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating activity: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create activity'], 500);
    }
});

$app->get('/activities', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT a.id, a.name, a.project_id, p.name as project_name, 
                   a.description, a.start_date, a.end_date, 
                   a.budget, a.status, a.created_at
            FROM activities a
            JOIN projects p ON a.project_id = p.id
            ORDER BY a.created_at DESC
        ');
        
        $activities = [];
        while ($row = $stmt->fetch()) {
            $activities[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'project_id' => $row['project_id'],
                'project_name' => $row['project_name'],
                'description' => $row['description'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'budget' => $row['budget'],
                'status' => $row['status'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $activities);
    } catch (PDOException $e) {
        error_log("Error fetching activities: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch activities'], 500);
    }
});

$app->get('/activities/{activity_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $activityId = $args['activity_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT a.id, a.name, a.project_id, p.name as project_name, 
                   a.description, a.start_date, a.end_date, 
                   a.budget, a.status, a.created_at
            FROM activities a
            JOIN projects p ON a.project_id = p.id
            WHERE a.id = ?
        ');
        
        $stmt->execute([$activityId]);
        $activity = $stmt->fetch();
        
        if (!$activity) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        return jsonResponse($response, [
            'id' => $activity['id'],
            'name' => $activity['name'],
            'project_id' => $activity['project_id'],
            'project_name' => $activity['project_name'],
            'description' => $activity['description'],
            'start_date' => $activity['start_date'],
            'end_date' => $activity['end_date'],
            'budget' => $activity['budget'],
            'status' => $activity['status'],
            'created_at' => $activity['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching activity: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch activity'], 500);
    }
});

$app->put('/activities/{activity_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $activityId = $args['activity_id'];
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            UPDATE activities
            SET name = COALESCE(?, name),
                project_id = COALESCE(?, project_id),
                description = COALESCE(?, description),
                start_date = COALESCE(?, start_date),
                end_date = COALESCE(?, end_date),
                budget = COALESCE(?, budget),
                status = COALESCE(?, status)
            WHERE id = ?
            RETURNING *
        ');
        
        $stmt->execute([
            $data['name'] ?? null,
            $data['project_id'] ?? null,
            $data['description'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['budget'] ?? null,
            $data['status'] ?? null,
            $activityId
        ]);
        
        $updatedActivity = $stmt->fetch();
        
        if (!$updatedActivity) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        return jsonResponse($response, $updatedActivity);
    } catch (PDOException $e) {
        error_log("Error updating activity: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update activity'], 500);
    }
});

$app->delete('/activities/{activity_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $activityId = $args['activity_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM activities WHERE id = ?');
        $stmt->execute([$activityId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        $db->prepare('DELETE FROM activities WHERE id = ?')
           ->execute([$activityId]);
        
        return jsonResponse($response, ['message' => 'Activity deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting activity: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete activity'], 500);
    }
});

// Budget Item endpoints
$app->post('/budget-items', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Verify project exists
        $stmt = $db->prepare('SELECT id FROM projects WHERE id = ?');
        $stmt->execute([$data['project_id']]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Project not found'], 404);
        }
        
        $stmt = $db->prepare('
            INSERT INTO budget_items (project_id, item_name, description, quantity, unit_price, category)
            VALUES (?, ?, ?, ?, ?, ?)
            RETURNING id, project_id, item_name, description, quantity, unit_price, total, category, created_at
        ');
        
        $stmt->execute([
            $data['project_id'],
            $data['item_name'],
            $data['description'] ?? null,
            $data['quantity'],
            $data['unit_price'],
            $data['category']
        ]);
        
        $newItem = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newItem['id'],
            'project_id' => $newItem['project_id'],
            'item_name' => $newItem['item_name'],
            'description' => $newItem['description'],
            'quantity' => $newItem['quantity'],
            'unit_price' => $newItem['unit_price'],
            'total' => $newItem['total'],
            'category' => $newItem['category'],
            'created_at' => $newItem['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating budget item: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create budget item'], 500);
    }
});

$app->get('/budget-items/{project_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $projectId = $args['project_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT id, project_id, item_name, description, quantity, unit_price, total, category, created_at
            FROM budget_items
            WHERE project_id = ?
            ORDER BY created_at DESC
        ');
        
        $stmt->execute([$projectId]);
        
        $items = [];
        while ($row = $stmt->fetch()) {
            $items[] = [
                'id' => $row['id'],
                'project_id' => $row['project_id'],
                'item_name' => $row['item_name'],
                'description' => $row['description'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'total' => $row['total'],
                'category' => $row['category'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $items);
    } catch (PDOException $e) {
        error_log("Error fetching budget items: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch budget items'], 500);
    }
});

$app->delete('/budget-items/{item_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $itemId = $args['item_id'];
    
    try {
        $stmt = $db->prepare('DELETE FROM budget_items WHERE id = ? RETURNING id');
        $stmt->execute([$itemId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Budget item not found'], 404);
        }
        
        return jsonResponse($response, ['message' => 'Budget item deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting budget item: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete budget item'], 500);
    }
});

// Employee endpoints
$app->post('/employees', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Validate date format
        $dob = new DateTime($data['dob']);
        
        $stmt = $db->prepare('
            INSERT INTO employees (name, nin, dob, qualification, email, phone, address, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id, name, nin, dob, qualification, email, phone, address, status, created_at
        ');
        
        $stmt->execute([
            $data['name'],
            $data['nin'],
            $dob->format('Y-m-d'),
            $data['qualification'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['status'] ?? 'active'
        ]);
        
        $newEmployee = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newEmployee['id'],
            'name' => $newEmployee['name'],
            'nin' => $newEmployee['nin'],
            'dob' => $newEmployee['dob'],
            'qualification' => $newEmployee['qualification'],
            'email' => $newEmployee['email'],
            'phone' => $newEmployee['phone'],
            'address' => $newEmployee['address'],
            'status' => $newEmployee['status'],
            'created_at' => $newEmployee['created_at']
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'employees_nin_key') !== false) {
            return jsonResponse($response, ['error' => 'NIN already exists'], 400);
        }
        
        error_log("Error creating employee: " . $e->getMessage());
        return jsonResponse($response, [
            'error' => 'Failed to create employee',
            'details' => $e->getMessage()
        ], 500);
    } catch (Exception $e) {
        return jsonResponse($response, ['error' => 'Invalid date format'], 400);
    }
});

$app->get('/employees', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT id, name, nin, dob, qualification, email, phone, address, status, created_at
            FROM employees
            ORDER BY created_at DESC
        ');
        
        $employees = [];
        while ($row = $stmt->fetch()) {
            $employees[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'nin' => $row['nin'],
                'dob' => $row['dob'],
                'qualification' => $row['qualification'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'status' => $row['status'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $employees);
    } catch (PDOException $e) {
        error_log("Error fetching employees: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch employees'], 500);
    }
});

$app->delete('/employees/{employee_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $employeeId = $args['employee_id'];
    
    try {
        $stmt = $db->prepare('DELETE FROM employees WHERE id = ? RETURNING id');
        $stmt->execute([$employeeId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Employee not found'], 404);
        }
        
        return jsonResponse($response, ['message' => 'Employee deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting employee: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete employee'], 500);
    }
});

// Deployment endpoints
$app->post('/deployments', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Verify employee exists
        $stmt = $db->prepare('SELECT name FROM employees WHERE id = ?');
        $stmt->execute([$data['employee_id']]);
        $employee = $stmt->fetch();
        
        if (!$employee) {
            return jsonResponse($response, ['error' => 'Employee not found'], 404);
        }
        $employeeName = $employee['name'];
        
        // Verify activity exists and get project name
        $stmt = $db->prepare('
            SELECT a.name, p.name 
            FROM activities a
            JOIN projects p ON a.project_id = p.id
            WHERE a.id = ?
        ');
        $stmt->execute([$data['activity_id']]);
        $activity = $stmt->fetch();
        
        if (!$activity) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        $activityName = $activity['name'];
        $projectName = $activity['name'];
        
        $stmt = $db->prepare('
            INSERT INTO deployments (employee_id, activity_id, role)
            VALUES (?, ?, ?)
            RETURNING id, employee_id, activity_id, role, created_at
        ');
        
        $stmt->execute([
            $data['employee_id'],
            $data['activity_id'],
            $data['role']
        ]);
        
        $newDeployment = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newDeployment['id'],
            'employee_id' => $newDeployment['employee_id'],
            'employee_name' => $employeeName,
            'activity_id' => $newDeployment['activity_id'],
            'activity_name' => $activityName,
            'project_name' => $projectName,
            'role' => $newDeployment['role'],
            'created_at' => $newDeployment['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating deployment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create deployment'], 500);
    }
});

$app->get('/deployments', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT d.id, d.employee_id, e.name as employee_name, 
                   d.activity_id, a.name as activity_name, p.name as project_name,
                   d.role, d.created_at
            FROM deployments d
            JOIN employees e ON d.employee_id = e.id
            JOIN activities a ON d.activity_id = a.id
            JOIN projects p ON a.project_id = p.id
            ORDER BY d.created_at DESC
        ');
        
        $deployments = [];
        while ($row = $stmt->fetch()) {
            $deployments[] = [
                'id' => $row['id'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name'],
                'project_name' => $row['project_name'],
                'role' => $row['role'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $deployments);
    } catch (PDOException $e) {
        error_log("Error fetching deployments: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch deployments'], 500);
    }
});

$app->delete('/deployments/{deployment_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $deploymentId = $args['deployment_id'];
    
    try {
        $stmt = $db->prepare('DELETE FROM deployments WHERE id = ? RETURNING id');
        $stmt->execute([$deploymentId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Deployment not found'], 404);
        }
        
        return jsonResponse($response, ['message' => 'Deployment deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting deployment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete deployment'], 500);
    }
});

// Work Opportunity endpoints
$app->post('/work-opportunities', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO work_opportunities (title, description, status)
            VALUES (?, ?, ?)
            RETURNING id, title, description, status, created_at
        ');
        
        $stmt->execute([
            $data['title'],
            $data['description'],
            $data['status'] ?? 'open'
        ]);
        
        $newOpportunity = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newOpportunity['id'],
            'title' => $newOpportunity['title'],
            'description' => $newOpportunity['description'],
            'status' => $newOpportunity['status'],
            'created_at' => $newOpportunity['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating work opportunity: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create work opportunity'], 500);
    }
});

$app->get('/work-opportunities', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT id, title, description, status, created_at
            FROM work_opportunities
            ORDER BY created_at DESC
        ');
        
        $opportunities = [];
        while ($row = $stmt->fetch()) {
            $opportunities[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'status' => $row['status'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $opportunities);
    } catch (PDOException $e) {
        error_log("Error fetching work opportunities: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch work opportunities'], 500);
    }
});

// Opportunity Assignment endpoints
$app->post('/opportunity-assignments', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Verify opportunity exists
        $stmt = $db->prepare('SELECT title FROM work_opportunities WHERE id = ?');
        $stmt->execute([$data['opportunity_id']]);
        $opportunity = $stmt->fetch();
        
        if (!$opportunity) {
            return jsonResponse($response, ['error' => 'Opportunity not found'], 404);
        }
        $opportunityTitle = $opportunity['title'];
        
        // Verify employee exists
        $stmt = $db->prepare('SELECT name FROM employees WHERE id = ?');
        $stmt->execute([$data['employee_id']]);
        $employee = $stmt->fetch();
        
        if (!$employee) {
            return jsonResponse($response, ['error' => 'Employee not found'], 404);
        }
        $employeeName = $employee['name'];
        
        $stmt = $db->prepare('
            INSERT INTO opportunity_assignments (opportunity_id, employee_id)
            VALUES (?, ?)
            RETURNING id, opportunity_id, employee_id, created_at
        ');
        
        $stmt->execute([
            $data['opportunity_id'],
            $data['employee_id']
        ]);
        
        $newAssignment = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newAssignment['id'],
            'opportunity_id' => $newAssignment['opportunity_id'],
            'opportunity_title' => $opportunityTitle,
            'employee_id' => $newAssignment['employee_id'],
            'employee_name' => $employeeName,
            'created_at' => $newAssignment['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating opportunity assignment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create opportunity assignment'], 500);
    }
});

$app->get('/opportunity-assignments/{opportunity_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $opportunityId = $args['opportunity_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT oa.id, oa.opportunity_id, w.title as opportunity_title,
                   oa.employee_id, e.name as employee_name, oa.created_at
            FROM opportunity_assignments oa
            JOIN work_opportunities w ON oa.opportunity_id = w.id
            JOIN employees e ON oa.employee_id = e.id
            WHERE oa.opportunity_id = ?
            ORDER BY oa.created_at DESC
        ');
        
        $stmt->execute([$opportunityId]);
        
        $assignments = [];
        while ($row = $stmt->fetch()) {
            $assignments[] = [
                'id' => $row['id'],
                'opportunity_id' => $row['opportunity_id'],
                'opportunity_title' => $row['opportunity_title'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $assignments);
    } catch (PDOException $e) {
        error_log("Error fetching opportunity assignments: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch opportunity assignments'], 500);
    }
});

$app->delete('/opportunity-assignments/{assignment_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $assignmentId = $args['assignment_id'];
    
    try {
        $stmt = $db->prepare('DELETE FROM opportunity_assignments WHERE id = ? RETURNING id');
        $stmt->execute([$assignmentId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Assignment not found'], 404);
        }
        
        return jsonResponse($response, ['message' => 'Assignment deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting opportunity assignment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete opportunity assignment'], 500);
    }
});

// Payment endpoints
$app->post('/payments/request', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Verify employee exists
        $stmt = $db->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->execute([$data['employee_id']]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Employee not found'], 404);
        }
        
        // Insert payment request
        $stmt = $db->prepare('
            INSERT INTO payments (
                employee_id, amount, payment_period, 
                description, payment_method, status
            ) 
            VALUES (?, ?, ?, ?, ?, \'pending\')
            RETURNING id
        ');
        
        $stmt->execute([
            $data['employee_id'],
            $data['amount'],
            $data['payment_period'],
            $data['description'] ?? null,
            $data['payment_method']
        ]);
        
        $paymentId = $stmt->fetchColumn();
        
        // Return the created payment with all required fields
        $stmt = $db->prepare('
            SELECT 
                p.id, 
                p.employee_id, 
                e.name as employee_name,
                p.amount, 
                p.payment_period, 
                p.description,
                p.payment_method,
                p.status, 
                p.remarks,
                p.created_at, 
                p.approved_at,
                p.processed_by
            FROM payments p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.id = ?
        ');
        
        $stmt->execute([$paymentId]);
        $paymentData = $stmt->fetch();
        
        return jsonResponse($response, $paymentData);
    } catch (PDOException $e) {
        error_log("Error creating payment request: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create payment request'], 500);
    }
});

$app->post('/payments/approve', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Get the payment
        $stmt = $db->prepare('
            SELECT p.*, e.name as employee_name 
            FROM payments p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.id = ?
        ');
        
        $stmt->execute([$data['payment_id']]);
        $paymentData = $stmt->fetch();
        
        if (!$paymentData) {
            return jsonResponse($response, ['error' => 'Payment not found'], 404);
        }
        
        if ($paymentData['status'] !== 'pending') {
            return jsonResponse($response, ['error' => 'Payment is not pending approval'], 400);
        }
        
        // Update payment status
        $status = $data['approved'] ? 'approved' : 'rejected';
        $stmt = $db->prepare('
            UPDATE payments 
            SET status = ?, 
                remarks = ?, 
                approved_at = CURRENT_TIMESTAMP,
                processed_by = 1  -- In a real app, this would be the logged-in user ID
            WHERE id = ?
            RETURNING *
        ');
        
        $stmt->execute([
            $status,
            $data['remarks'] ?? null,
            $data['payment_id']
        ]);
        
        $updatedPayment = $stmt->fetch();
        
        return jsonResponse($response, $updatedPayment);
    } catch (PDOException $e) {
        error_log("Error processing payment approval: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to process payment approval'], 500);
    }
});

$app->get('/payments/pending', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT 
                p.id, 
                p.employee_id, 
                e.name as employee_name,
                p.amount, 
                p.payment_period, 
                p.description,
                p.payment_method,
                p.status, 
                p.remarks,
                p.created_at, 
                p.approved_at,
                p.processed_by
            FROM payments p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.status = \'pending\'
            ORDER BY p.created_at DESC
        ');
        
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $payments);
    } catch (PDOException $e) {
        error_log("Error fetching pending payments: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch pending payments'], 500);
    }
});

$app->get('/payments/history', function (Request $request, Response $response) {
    $db = $this->get('db');
    $status = $request->getQueryParams()['status'] ?? null;
    
    try {
        $query = '
            SELECT 
                p.id, 
                p.employee_id, 
                e.name as employee_name,
                p.amount, 
                p.payment_period, 
                p.description,
                p.payment_method,
                p.status, 
                p.remarks,
                p.created_at, 
                p.approved_at,
                p.processed_by
            FROM payments p
            JOIN employees e ON p.employee_id = e.id
        ';
        
        $params = [];
        
        if ($status) {
            $query .= ' WHERE p.status = ?';
            $params[] = $status;
        }
        
        $query .= ' ORDER BY p.created_at DESC';
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $payments);
    } catch (PDOException $e) {
        error_log("Error fetching payment history: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch payment history'], 500);
    }
});

$app->get('/payments/employee/{employee_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $employeeId = $args['employee_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT p.*, e.name as employee_name 
            FROM payments p
            JOIN employees e ON p.employee_id = e.id
            WHERE p.employee_id = ?
            ORDER BY p.created_at DESC
        ');
        
        $stmt->execute([$employeeId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return jsonResponse($response, $payments);
    } catch (PDOException $e) {
        error_log("Error fetching employee payments: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch employee payments'], 500);
    }
});

// Report endpoints
$app->post('/reports', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();
    
    try {
        // Verify employee exists
        $stmt = $db->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->execute([$data['employee_id']]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Employee not found'], 404);
        }
        
        // Verify activity exists
        $stmt = $db->prepare('SELECT id FROM activities WHERE id = ?');
        $stmt->execute([$data['activity_id']]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        // Save report to database
        $stmt = $db->prepare('
            INSERT INTO reports (employee_id, activity_id, title, content, status, submitted_by)
            VALUES (?, ?, ?, ?, \'submitted\', ?)
            RETURNING id
        ');
        
        $stmt->execute([
            $data['employee_id'],
            $data['activity_id'],
            $data['title'],
            $data['content'],
            $data['employee_id']
        ]);
        
        $reportId = $stmt->fetchColumn();
        
        // Handle file attachments
        if (!empty($uploadedFiles['attachments'])) {
            $uploadDir = getUploadDir();
            
            foreach ($uploadedFiles['attachments'] as $file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    continue;
                }
                
                $fileId = Uuid::uuid4()->toString();
                $fileExt = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
                $fileName = $fileId . ($fileExt ? '.' . $fileExt : '');
                $filePath = $uploadDir . '/' . $fileName;
                
                $file->moveTo($filePath);
                
                $stmt = $db->prepare('
                    INSERT INTO report_attachments (report_id, original_filename, stored_filename, file_type)
                    VALUES (?, ?, ?, ?)
                ');
                
                $stmt->execute([
                    $reportId,
                    $file->getClientFilename(),
                    $filePath,
                    $file->getClientMediaType()
                ]);
            }
        }
        
        return jsonResponse($response, [
            'id' => $reportId,
            'employee_id' => $data['employee_id'],
            'activity_id' => $data['activity_id'],
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => 'submitted'
        ]);
    } catch (Exception $e) {
        error_log("Error creating report: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create report'], 500);
    }
});

$app->get('/reports', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        $stmt = $db->query('
            SELECT r.id, r.title, r.content, r.status, r.created_at,
                   a.id as activity_id, a.name as activity_name,
                   COALESCE(r.employee_id, 0) as employee_id,
                   COALESCE(e.name, \'Unknown\') as employee_name,
                   COALESCE(r.submitted_by, 0) as submitted_by,
                   COALESCE(submitter.name, \'Unknown\') as submitted_by_name
            FROM reports r
            LEFT JOIN activities a ON r.activity_id = a.id
            LEFT JOIN employees e ON r.employee_id = e.id
            LEFT JOIN employees submitter ON r.submitted_by = submitter.id
            ORDER BY r.created_at DESC
        ');
        
        $reports = [];
        while ($row = $stmt->fetch()) {
            $reports[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'content' => $row['content'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'submitted_by' => $row['submitted_by'],
                'submitted_by_name' => $row['submitted_by_name']
            ];
        }
        
        return jsonResponse($response, ['reports' => $reports]);
    } catch (PDOException $e) {
        error_log("Error fetching reports: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch reports'], 500);
    }
});

$app->get('/reports/export', function (Request $request, Response $response) {
    $db = $this->get('db');
    $queryParams = $request->getQueryParams();
    
    try {
        $query = "
            SELECT r.id, r.title, a.name as activity, e.name as employee,
                   r.status, r.created_at, r.director_comments
            FROM reports r
            JOIN activities a ON r.activity_id = a.id
            JOIN employees e ON r.employee_id = e.id
        ";
        
        $conditions = [];
        $params = [];
        
        if (!empty($queryParams['status'])) {
            $conditions[] = "r.status = ?";
            $params[] = $queryParams['status'];
        }
        
        if (!empty($queryParams['activity_id'])) {
            $conditions[] = "r.activity_id = ?";
            $params[] = $queryParams['activity_id'];
        }
        
        if (!empty($queryParams['search'])) {
            $conditions[] = "(r.title ILIKE ? OR r.content ILIKE ?)";
            $searchTerm = "%{$queryParams['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($queryParams['start_date']) && !empty($queryParams['end_date'])) {
            $conditions[] = "r.created_at BETWEEN ? AND ?";
            $params[] = $queryParams['start_date'];
            $params[] = $queryParams['end_date'];
        }
        
        if ($conditions) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $query .= " ORDER BY r.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        // Generate CSV
        $csvData = "ID,Title,Activity,Employee,Status,Created At,Director Comments\n";
        
        while ($row = $stmt->fetch()) {
            $csvData .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $row['id'],
                $row['title'],
                $row['activity'],
                $row['employee'],
                $row['status'],
                $row['created_at'],
                $row['director_comments'] ?? ''
            );
        }
        
        // Return CSV file
        $response->getBody()->write($csvData);
        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename=reports_export_' . date('Y-m-d') . '.csv');
    } catch (PDOException $e) {
        error_log("Error exporting reports: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to export reports'], 500);
    }
});

$app->put('/reports/{report_id}/status', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $reportId = $args['report_id'];
    $data = $request->getParsedBody();
    
    try {
        // Verify report exists
        $stmt = $db->prepare('SELECT id FROM reports WHERE id = ?');
        $stmt->execute([$reportId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Report not found'], 404);
        }
        
        // Update status
        $stmt = $db->prepare('
            UPDATE reports 
            SET status = ?, 
                approved_by = ?,
                approved_at = CURRENT_TIMESTAMP
            WHERE id = ?
            RETURNING id
        ');
        
        $stmt->execute([
            $data['status'],
            $data['director_id'] ?? null,
            $reportId
        ]);
        
        // Update comments if provided
        if (!empty($data['comments'])) {
            $stmt = $db->prepare('
                UPDATE reports
                SET director_comments = ?
                WHERE id = ?
            ');
            
            $stmt->execute([
                $data['comments'],
                $reportId
            ]);
        }
        
        return jsonResponse($response, ['message' => 'Report status updated successfully']);
    } catch (PDOException $e) {
        error_log("Error updating report status: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update report status'], 500);
    }
});

$app->get('/director/reports', function (Request $request, Response $response) {
    $db = $this->get('db');
    $queryParams = $request->getQueryParams();
    
    $status = $queryParams['status'] ?? 'submitted';
    $page = max(1, (int)($queryParams['page'] ?? 1));
    $perPage = max(1, (int)($queryParams['per_page'] ?? 10));
    $activityId = $queryParams['activity_id'] ?? null;
    $search = $queryParams['search'] ?? null;
    $startDate = $queryParams['start_date'] ?? null;
    $endDate = $queryParams['end_date'] ?? null;
    
    try {
        // Base query
        $query = "
            SELECT r.id, r.title, r.content, r.status, r.created_at,
                   a.id as activity_id, a.name as activity_name,
                   e.id as employee_id, e.name as employee_name,
                   r.submitted_by, submitter.name as submitted_by_name,
                   COUNT(ra.id) as attachments_count
            FROM reports r
            JOIN activities a ON r.activity_id = a.id
            JOIN employees e ON r.employee_id = e.id
            LEFT JOIN employees submitter ON r.submitted_by = submitter.id
            LEFT JOIN report_attachments ra ON r.id = ra.report_id
        ";
        
        // Where conditions
        $conditions = ["r.status = ?"];
        $params = [$status];
        
        if ($activityId) {
            $conditions[] = "r.activity_id = ?";
            $params[] = $activityId;
        }
        
        if ($search) {
            $conditions[] = "(r.title ILIKE ? OR r.content ILIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($startDate && $endDate) {
            $conditions[] = "r.created_at BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        }
        
        if ($conditions) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
        
        // Group by and pagination
        $query .= "
            GROUP BY r.id, a.id, e.id, submitter.id
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $reports = [];
        while ($row = $stmt->fetch()) {
            $reports[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'content' => $row['content'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'submitted_by' => $row['submitted_by'],
                'submitted_by_name' => $row['submitted_by_name'],
                'attachments_count' => $row['attachments_count']
            ];
        }
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) FROM reports r";
        if ($conditions) {
            $countQuery .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $stmt = $db->prepare($countQuery);
        $stmt->execute(array_slice($params, 0, -2)); // Exclude LIMIT params
        $totalReports = $stmt->fetchColumn();
        
        return jsonResponse($response, [
            'reports' => $reports,
            'total' => $totalReports,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($totalReports / $perPage)
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching director reports: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch reports'], 500);
    }
});

$app->delete('/reports/{report_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $reportId = $args['report_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM reports WHERE id = ?');
        $stmt->execute([$reportId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Report not found'], 404);
        }
        
        $db->prepare('DELETE FROM reports WHERE id = ?')
           ->execute([$reportId]);
        
        return jsonResponse($response, ['message' => 'Report deleted successfully']);
    } catch (PDOException $e) {
        error_log("Error deleting report: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to delete report'], 500);
    }
});

// Activity Budget Items endpoints
$app->post('/activities/{activity_id}/budget-items', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $activityId = $args['activity_id'];
    $data = $request->getParsedBody();
    
    try {
        // Verify activity exists and get project_id
        $stmt = $db->prepare('SELECT project_id FROM activities WHERE id = ?');
        $stmt->execute([$activityId]);
        $activity = $stmt->fetch();
        
        if (!$activity) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        $projectId = $activity['project_id'];
        
        // Create the budget item linked to both project and activity
        $stmt = $db->prepare('
            INSERT INTO budget_items (project_id, activity_id, item_name, description, quantity, unit_price, category)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id, project_id, activity_id, item_name, description, quantity, unit_price, total, category, created_at
        ');
        
        $stmt->execute([
            $projectId,
            $activityId,
            $data['item_name'],
            $data['description'] ?? null,
            $data['quantity'],
            $data['unit_price'],
            $data['category']
        ]);
        
        $newItem = $stmt->fetch();
        
        return jsonResponse($response, [
            'id' => $newItem['id'],
            'project_id' => $newItem['project_id'],
            'activity_id' => $newItem['activity_id'],
            'item_name' => $newItem['item_name'],
            'description' => $newItem['description'],
            'quantity' => $newItem['quantity'],
            'unit_price' => $newItem['unit_price'],
            'total' => $newItem['total'],
            'category' => $newItem['category'],
            'created_at' => $newItem['created_at']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating activity budget item: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create activity budget item'], 500);
    }
});

$app->get('/activities/{activity_id}/budget-items', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $activityId = $args['activity_id'];
    
    try {
        $stmt = $db->prepare('SELECT id FROM activities WHERE id = ?');
        $stmt->execute([$activityId]);
        
        if (!$stmt->fetch()) {
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        $stmt = $db->prepare('
            SELECT id, project_id, activity_id, item_name, description, quantity, unit_price, total, category, created_at
            FROM budget_items
            WHERE activity_id = ?
            ORDER BY created_at DESC
        ');
        
        $stmt->execute([$activityId]);
        
        $items = [];
        while ($row = $stmt->fetch()) {
            $items[] = [
                'id' => $row['id'],
                'project_id' => $row['project_id'],
                'activity_id' => $row['activity_id'],
                'item_name' => $row['item_name'],
                'description' => $row['description'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'total' => $row['total'],
                'category' => $row['category'],
                'created_at' => $row['created_at']
            ];
        }
        
        return jsonResponse($response, $items);
    } catch (PDOException $e) {
        error_log("Error fetching activity budget items: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch budget items'], 500);
    }
});
// Activity Approval Endpoints
$app->get('/activity-approvals', function (Request $request, Response $response) {
    $db = $this->get('db');
    $queryParams = $request->getQueryParams();
    $status = $queryParams['status'] ?? 'pending';
    
    try {
        $query = '
            SELECT aa.id, aa.activity_id, a.name as activity_name, 
                   aa.requested_amount, aa.status, aa.comments,
                   aa.response_comments, aa.created_at, aa.updated_at,
                   e.id as employee_id, e.name as employee_name,
                   aa.requested_by, requester.name as requested_by_name,
                   aa.approved_by, approver.name as approved_by_name
            FROM activity_approvals aa
            JOIN activities a ON aa.activity_id = a.id
            LEFT JOIN employees e ON a.id = e.id
            LEFT JOIN employees requester ON aa.requested_by = requester.id
            LEFT JOIN employees approver ON aa.approved_by = approver.id
            WHERE aa.status = ?
            ORDER BY aa.created_at DESC
        ';
        
        $stmt = $db->prepare($query);
        $stmt->execute([$status]);
        
        $approvals = [];
        while ($row = $stmt->fetch()) {
            $approvals[] = [
                'id' => $row['id'],
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name'],
                'requested_amount' => $row['requested_amount'],
                'status' => $row['status'],
                'comments' => $row['comments'],
                'response_comments' => $row['response_comments'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'requested_by' => $row['requested_by'],
                'requested_by_name' => $row['requested_by_name'],
                'approved_by' => $row['approved_by'],
                'approved_by_name' => $row['approved_by_name']
            ];
        }
        
        return jsonResponse($response, $approvals);
    } catch (PDOException $e) {
        error_log("Error fetching activity approvals: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch activity approvals'], 500);
    }
});

$app->post('/activity-approvals', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        $stmt = $db->prepare('
            INSERT INTO activity_approvals (activity_id, requested_by, requested_amount, comments)
            VALUES (?, ?, ?, ?)
            RETURNING id
        ');
        
        $stmt->execute([
            $data['activity_id'],
            $data['requested_by'],
            $data['requested_amount'],
            $data['comments'] ?? null
        ]);
        
        $approvalId = $stmt->fetchColumn();
        
        // Update activity status to 'pending approval'
        $db->prepare('UPDATE activities SET status = ? WHERE id = ?')
           ->execute(['pending approval', $data['activity_id']]);
        
        return jsonResponse($response, [
            'id' => $approvalId,
            'message' => 'Approval request submitted successfully'
        ]);
    } catch (PDOException $e) {
        error_log("Error creating activity approval: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create activity approval'], 500);
    }
});

$app->put('/activity-approvals/{approval_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $approvalId = $args['approval_id'];
    $data = $request->getParsedBody();
    
    try {
        // Get current approval
        $stmt = $db->prepare('SELECT activity_id FROM activity_approvals WHERE id = ?');
        $stmt->execute([$approvalId]);
        $approval = $stmt->fetch();
        
        if (!$approval) {
            return jsonResponse($response, ['error' => 'Approval not found'], 404);
        }
        
        $activityId = $approval['activity_id'];
        
        // Update approval
        $stmt = $db->prepare('
            UPDATE activity_approvals
            SET status = ?,
                response_comments = ?,
                approved_by = ?,
                decision_date = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
            RETURNING *
        ');
        
        $stmt->execute([
            $data['decision'],
            $data['response_comments'] ?? null,
            $data['approved_by'] ?? null,
            $approvalId
        ]);
        
        $updatedApproval = $stmt->fetch();
        
        // Update activity status based on decision
        $newStatus = $data['decision'] === 'approved' ? 'approved' : 'rejected';
        $db->prepare('UPDATE activities SET status = ? WHERE id = ?')
           ->execute([$newStatus, $activityId]);
        
        return jsonResponse($response, $updatedApproval);
    } catch (PDOException $e) {
        error_log("Error updating activity approval: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to update activity approval'], 500);
    }
});
// Add this endpoint to your backend
$app->get('/pending-activities', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Get pending activities
        $stmt = $db->prepare('
            SELECT a.id, a.name, a.project_id, p.name as project_name,
                   a.description, a.start_date, a.end_date, 
                   a.budget, a.status, a.created_at
            FROM activities a
            JOIN projects p ON a.project_id = p.id
            WHERE a.status = \'pending approval\'
            ORDER BY a.created_at DESC
        ');
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each activity, get its budget items
        foreach ($activities as &$activity) {
            $stmt = $db->prepare('
                SELECT id, item_name, description, quantity, unit_price, total, category
                FROM budget_items
                WHERE activity_id = ?
            ');
            $stmt->execute([$activity['id']]);
            $activity['budget_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return jsonResponse($response, $activities);
    } catch (PDOException $e) {
        error_log("Error fetching pending activities: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch pending activities'], 500);
    }
});
$app->get('/donors/stats', function (Request $request, Response $response) {
    $db = $this->get('db');
    
    try {
        // Get donor statistics
        $stmt = $db->query('
            SELECT 
                d.id as donor_id,
                COUNT(dn.id) as donation_count,
                COALESCE(SUM(dn.amount), 0) as total_donated,
                MIN(dn.date) as first_donation,
                MAX(dn.date) as last_donation
            FROM donors d
            LEFT JOIN donations dn ON d.id = dn.donor_id
            GROUP BY d.id
        ');
        
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['donor_id']] = [
                'donation_count' => (int)$row['donation_count'],
                'total_donated' => (float)$row['total_donated'],
                'first_donation' => $row['first_donation'],
                'last_donation' => $row['last_donation']
            ];
        }
        
        return jsonResponse($response, ['donor_stats' => $stats]);
    } catch (PDOException $e) {
        error_log("Error fetching donor stats: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donor statistics'], 500);
    }
});
$app->get('/donors/{donor_id}/stats', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donorId = $args['donor_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT 
                COUNT(d.id) as donation_count,
                COALESCE(SUM(d.amount), 0) as total_donations,
                MIN(d.date) as first_donation,
                MAX(d.date) as last_donation
            FROM donations d
            WHERE d.donor_id = ?
        ');
        
        $stmt->execute([$donorId]);
        $stats = $stmt->fetch();
        
        if (!$stats) {
            return jsonResponse($response, [
                'total_donations' => 0,
                'donation_count' => 0,
                'first_donation' => null,
                'last_donation' => null
            ]);
        }
        
        return jsonResponse($response, [
            'total_donations' => (float)$stats['total_donations'],
            'donation_count' => (int)$stats['donation_count'],
            'first_donation' => $stats['first_donation'],
            'last_donation' => $stats['last_donation']
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching donor stats: " . $e->getMessage());
        return jsonResponse($response, [
            'total_donations' => 0,
            'donation_count' => 0,
            'first_donation' => null,
            'last_donation' => null
        ]);
    }
});
$app->get('/donations/{donation_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donationId = $args['donation_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT d.id, COALESCE(dn.name, \'Anonymous\') as donor_name, 
                   d.amount, d.payment_method, d.date, 
                   d.project, d.notes, d.status, d.created_at
            FROM donations d
            LEFT JOIN donors dn ON d.donor_id = dn.id
            WHERE d.id = ?
        ');
        
        $stmt->execute([$donationId]);
        $donation = $stmt->fetch();
        
        if (!$donation) {
            return jsonResponse($response, ['error' => 'Donation not found'], 404);
        }
        
        return jsonResponse($response, $donation);
    } catch (PDOException $e) {
        error_log("Error fetching donation: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donation'], 500);
    }
});
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $payload = ['error' => $exception->getMessage()];
    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
});
$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});
// Add PHPMailer for email functionality
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php'; // Make sure this path is correct

// Contact form endpoint
$app->post('/send-message', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    
    // Validate input
    if (empty($data['name']) || empty($data['email']) || empty($data['message'])) {
        return jsonResponse($response, ['success' => false, 'error' => 'All fields are required'], 400);
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return jsonResponse($response, ['success' => false, 'error' => 'Invalid email format'], 400);
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Use your SMTP host
        $mail->SMTPAuth = true;
        $mail->Username = 'dariusmumbere@gmail.com'; // Your email
        $mail->Password = '23571113'; // Your email password or app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom($data['email'], $data['name']);
        $mail->addAddress('dariusmumbere@gmail.com'); // Your email
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'New Contact Form Submission from ' . $data['name'];
        $mail->Body = "
            <h2>New Message from Website Contact Form</h2>
            <p><strong>Name:</strong> {$data['name']}</p>
            <p><strong>Email:</strong> {$data['email']}</p>
            <p><strong>Message:</strong></p>
            <p>{$data['message']}</p>
        ";
        
        $mail->send();
        return jsonResponse($response, ['success' => true]);
    } catch (Exception $e) {
        error_log("Error sending email: " . $mail->ErrorInfo);
        return jsonResponse($response, [
            'success' => false, 
            'error' => 'Failed to send message. Please try again later.'
        ], 500);
    }
});
$app->post('/projects', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    // Validate required fields
    $requiredFields = ['name', 'description', 'start_date', 'end_date', 'budget', 'funding_source', 'status'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            return jsonResponse($response, ['error' => "Field $field is required"], 400);
        }
    }

    try {
        $stmt = $db->prepare('
            INSERT INTO projects (name, description, start_date, end_date, budget, funding_source, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            RETURNING id, name, description, start_date, end_date, budget, funding_source, status, created_at
        ');
        
        $stmt->execute([
            $data['name'],
            $data['description'],
            $data['start_date'],
            $data['end_date'],
            $data['budget'],
            $data['funding_source'],
            $data['status']
        ]);
        
        $newProject = $stmt->fetch();
        
        return jsonResponse($response, $newProject, 201);
    } catch (PDOException $e) {
        error_log("Error creating project: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to create project'], 500);
    }
});
$app->get('/donors/{donor_id}', function (Request $request, Response $response, array $args) {
    $db = $this->get('db');
    $donorId = $args['donor_id'];
    
    try {
        $stmt = $db->prepare('
            SELECT id, name, email, phone, address, donor_type, notes, category, created_at
            FROM donors
            WHERE id = ?
        ');
        
        $stmt->execute([$donorId]);
        $donor = $stmt->fetch();
        
        if (!$donor) {
            return jsonResponse($response, ['error' => 'Donor not found'], 404);
        }
        
        return jsonResponse($response, $donor);
    } catch (PDOException $e) {
        error_log("Error fetching donor: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to fetch donor'], 500);
    }
});
// Add this endpoint to handle activity payments
$app->post('/activity-payments', function (Request $request, Response $response) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    
    try {
        // Validate required fields
        if (empty($data['activity_id']) || empty($data['amount']) || empty($data['payment_method']) || empty($data['program_card'])) {
            return jsonResponse($response, ['error' => 'Missing required fields'], 400);
        }
        
        // Begin transaction
        $db->beginTransaction();
        
        // 1. Verify activity exists and get details
        $stmt = $db->prepare('SELECT id, name, budget, project_id FROM activities WHERE id = ?');
        $stmt->execute([$data['activity_id']]);
        $activity = $stmt->fetch();
        
        if (!$activity) {
            $db->rollBack();
            return jsonResponse($response, ['error' => 'Activity not found'], 404);
        }
        
        // 2. Verify program area exists and has sufficient balance
        $stmt = $db->prepare('SELECT id, name, balance FROM program_areas WHERE name = ? FOR UPDATE');
        $stmt->execute([$data['program_card']]);
        $programArea = $stmt->fetch();
        
        if (!$programArea) {
            $db->rollBack();
            return jsonResponse($response, ['error' => 'Program area not found'], 404);
        }
        
        if ($programArea['balance'] < $data['amount']) {
            $db->rollBack();
            return jsonResponse($response, ['error' => 'Insufficient funds in program area'], 400);
        }
        
        // 3. Verify main bank account has sufficient balance
        $stmt = $db->prepare('SELECT id, balance FROM bank_accounts WHERE name = ? FOR UPDATE');
        $stmt->execute(['Main Account']);
        $mainAccount = $stmt->fetch();
        
        if (!$mainAccount) {
            $db->rollBack();
            return jsonResponse($response, ['error' => 'Main bank account not found'], 404);
        }
        
        if ($mainAccount['balance'] < $data['amount']) {
            $db->rollBack();
            return jsonResponse($response, ['error' => 'Insufficient funds in main account'], 400);
        }
        
        // 4. Deduct from program area
        $stmt = $db->prepare('UPDATE program_areas SET balance = balance - ? WHERE id = ?');
        $stmt->execute([$data['amount'], $programArea['id']]);
        
        // 5. Deduct from main bank account
        $stmt = $db->prepare('UPDATE bank_accounts SET balance = balance - ? WHERE id = ?');
        $stmt->execute([$data['amount'], $mainAccount['id']]);
        
        // 6. Record the transaction
        $stmt = $db->prepare('
            INSERT INTO transactions (type, amount, purpose)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([
            'debit',
            $data['amount'],
            "Payment for activity: {$activity['name']} (Program: {$programArea['name']})"
        ]);
        
        // 7. Update activity status if fully paid
        if ($data['amount'] >= $activity['budget']) {
            $stmt = $db->prepare('UPDATE activities SET status = ? WHERE id = ?');
            $stmt->execute(['completed', $activity['id']]);
        }
        
        // Commit transaction
        $db->commit();
        
        return jsonResponse($response, [
            'message' => 'Payment processed successfully',
            'program_balance' => $programArea['balance'] - $data['amount'],
            'main_account_balance' => $mainAccount['balance'] - $data['amount']
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error processing activity payment: " . $e->getMessage());
        return jsonResponse($response, ['error' => 'Failed to process payment'], 500);
    }
});
// Run the application
$app->run();
