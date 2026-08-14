<?php
/**
 * Fishing Tournament Manager - Standalone Web Installer
 * 
 * This script downloads the application from GitHub, extracts it,
 * configures the database, and runs the necessary setup commands.
 */

// Mock putenv for namespaces where it is called without a leading backslash
// to prevent Fatal Errors on hosts where putenv() is disabled.
namespace Composer\Util {
    if (!function_exists('Composer\Util\putenv')) {
        function putenv($var) {
            $parts = explode('=', $var, 2);
            if (count($parts) === 2) {
                $_ENV[$parts[0]] = $parts[1];
                $_SERVER[$parts[0]] = $parts[1];
            }
            return true;
        }
    }
}

namespace Composer {
    if (!function_exists('Composer\putenv')) {
        function putenv($var) {
            return \Composer\Util\putenv($var);
        }
    }
}

namespace Symfony\Component\Console {
    if (!function_exists('Symfony\Component\Console\putenv')) {
        function putenv($var) {
            return \Composer\Util\putenv($var);
        }
    }
}

namespace {
    session_start();
    set_time_limit(300); // 5 minutes max execution time

// The installer is in the public directory, so the project root is one level up
define('INSTALL_ROOT', dirname(__DIR__));

// Replace this URL with the URL to your pre-compiled ZIP release when publishing.
$repoUrl = "https://github.com/matteocisilino/fishing_peg_manager/archive/refs/heads/master.zip";
$zipFile = INSTALL_ROOT . '/fishing_peg_manager.zip';
$extractDir = INSTALL_ROOT;
$laravelDir = INSTALL_ROOT . '/fishing_peg_manager-master';

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

function checkRequirements() {
    $noPsrConflict = true;
    $psrExists = false;
    if (extension_loaded('psr')) {
        $psrExists = interface_exists('Psr\Log\LoggerInterface', true);
    } else {
        $psrExists = interface_exists('Psr\Log\LoggerInterface', false);
    }

    if ($psrExists) {
        try {
            $ref = new \ReflectionMethod('Psr\Log\LoggerInterface', 'emergency');
            $params = $ref->getParameters();
            if (count($params) > 0) {
                $type = $params[0]->getType();
                if ($type === null) {
                    $noPsrConflict = false;
                }
            }
        } catch (\Throwable $e) {
            $noPsrConflict = false;
        }
    }

    $reqs = [
        'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'cURL Extension' => extension_loaded('curl'),
        'ZipArchive Extension' => class_exists('ZipArchive'),
        'PDO Extension' => extension_loaded('pdo'),
        'Write Permissions (Root)' => is_writable(INSTALL_ROOT),
        'No conflicting global PSR extension' => $noPsrConflict,
    ];
    return $reqs;
}

function downloadFile($url, $path) {
    $ch = curl_init($url);
    $fp = fopen($path, 'w+');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Fishing-Tournament-Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT, 240); // 4 minutes timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $result = curl_exec($ch);
    if ($result === false) {
        $GLOBALS['curl_error'] = curl_error($ch);
    }
    curl_close($ch);
    fclose($fp);
    return $result && file_exists($path) && filesize($path) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'download') {
        // Step 1: Check for local ZIP first to avoid downloading it
        $localZip = '';
        $zips = glob(__DIR__ . '/*.zip');
        if (empty($zips)) {
            $zips = glob(INSTALL_ROOT . '/*.zip');
        }
        $zips = array_filter($zips, fn($f) => file_exists($f) && filesize($f) > 1024);
        if (!empty($zips)) {
            $localZip = reset($zips);
        }
        $useLocal = !empty($localZip);
        $zipPathToUse = $zipFile;

        if ($useLocal) {
            $zipPathToUse = $localZip;
        } else {
            // Download the repository ZIP using helper function
            if (!downloadFile($repoUrl, $zipFile)) {
                $error = "Failed to download the repository. Curl error: " . ($GLOBALS['curl_error'] ?? 'Unknown error');
            }
        }

        if (!$error && (!file_exists($zipPathToUse) || filesize($zipPathToUse) == 0)) {
            $error = "Failed to obtain the repository ZIP (both local and remote failed).";
        } elseif (!$error) {
            // Step 2: Extract
            $zip = new ZipArchive;
            $res = $zip->open($zipPathToUse);
            if ($res === TRUE) {
                $zip->extractTo($extractDir);
                $zip->close();
                
                // Cleanup ZIP files
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                if (file_exists($localZip)) {
                    unlink($localZip);
                }
                
                // Move contents from extracted folder to root
                if (is_dir($laravelDir)) {
                    $files = array_diff(scandir($laravelDir), ['.', '..']);
                    foreach ($files as $file) {
                        rename("$laravelDir/$file", "$extractDir/$file");
                    }
                    rmdir($laravelDir);
                }

                header("Location: ?step=2");
                exit;
            } else {
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                $error = "Failed to extract the ZIP file. ZipArchive::open returned error code: " . $res . ". (File: " . basename($zipPathToUse) . ", Size: " . filesize($zipPathToUse) . " bytes)";
            }
        }
    } elseif ($action === 'configure') {
        // Step 3: Configure .env
        $dbConnection = $_POST['db_connection'] ?? 'sqlite';
        $dbHost = $_POST['db_host'] ?? '127.0.0.1';
        $dbPort = $_POST['db_port'] ?? '3306';
        $dbDatabase = $_POST['db_database'] ?? 'fishing';
        $dbUsername = $_POST['db_username'] ?? 'root';
        $dbPassword = $_POST['db_password'] ?? '';
        $appName = $_POST['app_name'] ?? 'Fishing Tournament';
        $appUrl = $_POST['app_url'] ?? "http://".$_SERVER['HTTP_HOST'];

        if (!file_exists(INSTALL_ROOT . '/.env.example')) {
            $error = "The application files were not extracted properly. .env.example is missing.";
        } else {
            $envContent = file_get_contents(INSTALL_ROOT . '/.env.example');
            
            $envContent = preg_replace('/^#?\s*APP_NAME=.*/m', 'APP_NAME="' . $appName . '"', $envContent);
            $envContent = preg_replace('/^#?\s*APP_ENV=.*/m', 'APP_ENV=production', $envContent);
            $envContent = preg_replace('/^#?\s*APP_DEBUG=.*/m', 'APP_DEBUG=false', $envContent);
            $envContent = preg_replace('/^#?\s*APP_URL=.*/m', 'APP_URL=' . $appUrl, $envContent);
            
            $envContent = preg_replace('/^#?\s*DB_CONNECTION=.*/m', 'DB_CONNECTION=' . $dbConnection, $envContent);
            
            if ($dbConnection === 'sqlite') {
                $sqlitePath = INSTALL_ROOT . '/database/database.sqlite';
                if (!file_exists(INSTALL_ROOT . '/database')) {
                    mkdir(INSTALL_ROOT . '/database');
                }
                if (!file_exists($sqlitePath)) {
                    touch($sqlitePath);
                }
                $envContent = preg_replace('/^#?\s*DB_DATABASE=.*/m', 'DB_DATABASE=' . $sqlitePath, $envContent);
            } else {
                $envContent = preg_replace('/^#?\s*DB_HOST=.*/m', 'DB_HOST=' . $dbHost, $envContent);
                $envContent = preg_replace('/^#?\s*DB_PORT=.*/m', 'DB_PORT=' . $dbPort, $envContent);
                $envContent = preg_replace('/^#?\s*DB_DATABASE=.*/m', 'DB_DATABASE=' . $dbDatabase, $envContent);
                $envContent = preg_replace('/^#?\s*DB_USERNAME=.*/m', 'DB_USERNAME=' . $dbUsername, $envContent);
                $envContent = preg_replace('/^#?\s*DB_PASSWORD=.*/m', 'DB_PASSWORD="' . $dbPassword . '"', $envContent);

                // Try to create the database if it doesn't exist (MySQL/MariaDB)
                try {
                    $dsn = "mysql:host={$dbHost};port={$dbPort}";
                    $pdo = new \PDO($dsn, $dbUsername, $dbPassword, [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                    ]);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "``", $dbDatabase) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (\Exception $e) {
                    // Suppress error: if the database cannot be created due to permissions,
                    // we let it fail during the migration step below which will print a clear error.
                }
            }

            file_put_contents(INSTALL_ROOT . '/.env', $envContent);

            // Step 4: Run Composer Install if needed
            if (!is_dir(INSTALL_ROOT . '/vendor')) {
                // Raise memory limit to allow running Composer inside the PHP process if needed
                ini_set('memory_limit', '512M');
                
                $composerPhar = INSTALL_ROOT . '/composer.phar';
                $downloaded = false;
                
                if (!file_exists($composerPhar)) {
                    $downloaded = downloadFile('https://getcomposer.org/composer-stable.phar', $composerPhar);
                } else {
                    $downloaded = true;
                }

                if ($downloaded && file_exists($composerPhar)) {
                    $installed = false;
                    $programmaticError = '';
                    
                    // 1. Try running Composer programmatically using phar:// stream wrapper (works without exec)
                    try {
                        if (function_exists('putenv')) {
                            putenv('COMPOSER_HOME=' . INSTALL_ROOT . '/.composer');
                        }
                        $_ENV['COMPOSER_HOME'] = INSTALL_ROOT . '/.composer';
                        $_SERVER['COMPOSER_HOME'] = INSTALL_ROOT . '/.composer';
                        
                        // Register phar autoloader / bootstrap
                        require_once 'phar://' . $composerPhar . '/src/bootstrap.php';
                        
                        $application = new Composer\Console\Application();
                        $application->setAutoExit(false);
                        
                        $input = new Symfony\Component\Console\Input\ArrayInput([
                            'command' => 'install',
                            '--no-dev' => true,
                            '--optimize-autoloader' => true,
                            '--no-interaction' => true,
                            '--no-scripts' => true,
                        ]);
                        
                        // Capture the Console output using StreamOutput
                        $stream = fopen('php://temp', 'w+');
                        $outputClass = 'Symfony\Component\Console\Output\StreamOutput';
                        $output = new $outputClass($stream);
                        
                        chdir(INSTALL_ROOT);
                        $result = $application->run($input, $output);
                        
                        rewind($stream);
                        $composerOutput = stream_get_contents($stream);
                        fclose($stream);
                        
                        if ($result === 0) {
                            $installed = true;
                        } else {
                            $programmaticError = "Composer run returned status code " . $result . ".\nOutput:\n" . $composerOutput;
                        }
                    } catch (\Throwable $e) {
                        $programmaticError = $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
                    }
                    
                    // 2. Fallback to exec if programmatic install failed/errored and exec is available
                    if (!$installed && function_exists('exec')) {
                        chdir(INSTALL_ROOT);
                        exec("php composer.phar install --no-dev --optimize-autoloader --no-scripts 2>&1", $output, $result);
                        if ($result === 0) {
                            $installed = true;
                        } else {
                            $error = "Composer install via exec failed: " . implode("\n", $output);
                        }
                    }
                    
                    // Cleanup composer.phar after installation
                    if (file_exists($composerPhar)) {
                        unlink($composerPhar);
                    }
                    
                    if (!$installed && !$error) {
                        $error = "Composer install failed. Programmatic error: " . nl2br(htmlspecialchars($programmaticError));
                        if (!function_exists('exec')) {
                            $error .= "<br><em>(Shell execution 'exec' is also disabled)</em>";
                        }
                    }
                } else {
                    $error = "Failed to download composer.phar for dependency installation.";
                }
            }

            if (!$error) {
                $check = checkRequirements();
                if (!$check['No conflicting global PSR extension']) {
                    $error = "PHP extension conflict: The global PHP extension 'psr' is enabled on your server and has an outdated/incompatible version of Psr\\Log\\LoggerInterface. This causes a Fatal Error with Laravel and Monolog. Please disable the 'psr' extension in your PHP settings (e.g. ISPConfig / cPanel) to continue.";
                }
            }

            if (!$error) {
                // Bootstrap Laravel to run migrations and key generate
                if (file_exists(INSTALL_ROOT . '/vendor/autoload.php') && file_exists(INSTALL_ROOT . '/bootstrap/app.php')) {
                    require INSTALL_ROOT . '/vendor/autoload.php';
                    $app = require_once INSTALL_ROOT . '/bootstrap/app.php';
                    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                    
                    try {
                        // Discover packages programmatically in memory (bypasses proc_open limitations)
                        $kernel->call('package:discover');
                        
                        $kernel->call('key:generate', ['--force' => true]);
                        $kernel->call('migrate', ['--force' => true]);
                        
                        header("Location: ?step=3");
                        exit;
                    } catch (\Exception $e) {
                        $error = "Configuration error: " . $e->getMessage();
                    }
                } else {
                    $error = "Dependencies not found. Please ensure the 'vendor' directory exists.";
                }
            }
        }
    } elseif ($action === 'create_admin') {
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';

        if (!$adminName || !$adminEmail || !$adminPassword) {
            $error = "All fields are required.";
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $error = "Passwords do not match.";
        } elseif (strlen($adminPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Bootstrap Laravel
            if (file_exists(INSTALL_ROOT . '/vendor/autoload.php') && file_exists(INSTALL_ROOT . '/bootstrap/app.php')) {
                require INSTALL_ROOT . '/vendor/autoload.php';
                $app = require_once INSTALL_ROOT . '/bootstrap/app.php';
                
                try {
                    $userClass = 'App\\Models\\User';
                    if (class_exists($userClass)) {
                        if ($userClass::where('email', $adminEmail)->exists()) {
                            $error = "A user with this email address already exists.";
                        } else {
                            $userClass::create([
                                'name' => $adminName,
                                'email' => $adminEmail,
                                'password' => \Illuminate\Support\Facades\Hash::make($adminPassword),
                                'role' => 'admin',
                                'is_active' => true,
                            ]);
                            
                            header("Location: ?step=4");
                            exit;
                        }
                    } else {
                        $error = "Failed to load the User model.";
                    }
                } catch (\Exception $e) {
                    $error = "Failed to create administrator: " . $e->getMessage();
                }
            } else {
                $error = "Dependencies not found. Unable to create administrator.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Fishing Tournament Manager</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 2rem; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5); width: 100%; max-width: 600px; }
        h1 { margin-top: 0; font-size: 1.5rem; color: #38bdf8; border-bottom: 1px solid #334155; padding-bottom: 1rem; }
        .btn { display: inline-block; background: #0284c7; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .btn:hover { background: #0369a1; }
        .req-list { list-style: none; padding: 0; }
        .req-list li { padding: 0.5rem 0; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; }
        .success { color: #4ade80; }
        .fail { color: #f87171; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: #94a3b8; }
        input, select { width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; box-sizing: border-box; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1rem; background: #7f1d1d; color: #fca5a5; }
    </style>
    <script>
        function toggleDbFields() {
            var conn = document.getElementById('db_connection').value;
            document.getElementById('mysql_fields').style.display = conn === 'mysql' ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <div class="card">
        <h1>🎣 Tournament Manager Installer</h1>
        
        <?php if ($error): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <p>Welcome! This wizard will download and install the application directly onto this server.</p>
            
            <h3>System Requirements</h3>
            <ul class="req-list">
                <?php 
                $reqs = checkRequirements();
                $allPassed = true;
                foreach ($reqs as $name => $passed): 
                    if (!$passed) $allPassed = false;
                ?>
                    <li><span><?= $name ?></span> <span class="<?= $passed ? 'success' : 'fail' ?>"><?= $passed ? 'OK' : 'MISSING' ?></span></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($allPassed): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="download">
                    <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;" onclick="this.innerHTML='Downloading... Please wait';">Download & Extract</button>
                </form>
            <?php else: ?>
                <p style="color: #f87171; margin-top: 1rem;">Please fix the missing requirements above to continue.</p>
                <?php if (isset($reqs['No conflicting global PSR extension']) && !$reqs['No conflicting global PSR extension']): ?>
                    <div style="background: #7f1d1d; border-radius: 6px; padding: 1rem; color: #fca5a5; font-size: 0.875rem; margin-top: 1rem; line-height: 1.5;">
                        <strong>Conflict Detected:</strong> The PHP extension <code>psr</code> is globally enabled on your server and defines an outdated signature for <code>Psr\Log\LoggerInterface</code>. This causes a Fatal Error with Laravel and Monolog.<br><br>
                        <strong>How to fix:</strong> Open your hosting control panel (e.g., ISPConfig / cPanel), go to "Select PHP Version" / "PHP Settings", and disable the <code>psr</code> extension for this domain.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($step == 2): ?>
            <p>Application downloaded successfully! Now, configure your database.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="configure">
                
                <div class="form-group">
                    <label>Application Name</label>
                    <input type="text" name="app_name" value="Fishing Tournament" required>
                </div>

                <div class="form-group">
                    <label>Application URL</label>
                    <input type="text" name="app_url" value="<?= htmlspecialchars("http://".$_SERVER['HTTP_HOST']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Database Connection</label>
                    <select name="db_connection" id="db_connection" onchange="toggleDbFields()">
                        <option value="sqlite">SQLite (No setup required)</option>
                        <option value="mysql">MySQL / MariaDB</option>
                    </select>
                </div>

                <div id="mysql_fields" style="display: none;">
                    <div class="form-group"><label>DB Host</label><input type="text" name="db_host" value="127.0.0.1"></div>
                    <div class="form-group"><label>DB Port</label><input type="text" name="db_port" value="3306"></div>
                    <div class="form-group"><label>Database Name</label><input type="text" name="db_database" value="fishing"></div>
                    <div class="form-group"><label>Username</label><input type="text" name="db_username" value="root"></div>
                    <div class="form-group"><label>Password</label><input type="password" name="db_password" value=""></div>
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;" onclick="this.innerHTML='Configuring... Please wait';">Configure & Install</button>
            </form>

        <?php elseif ($step == 3): ?>
            <p>Database configured and migrated successfully! Now, create the first Administrator account.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_admin">
                
                <div class="form-group">
                    <label>Admin Name</label>
                    <input type="text" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Admin Email</label>
                    <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="admin_password" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="admin_password_confirm" required>
                </div>

                <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Create Account</button>
            </form>

        <?php elseif ($step == 4): ?>
            <h3 style="color: #4ade80;">Installation Complete! 🎉</h3>
            <p>The application has been successfully configured and the administrator account was created.</p>
            <p><strong>Note:</strong> For security reasons, please delete this <code>install.php</code> file from your server.</p>
            <a href="./" class="btn" style="width: 100%; text-align: center; box-sizing: border-box;">Go to Application</a>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
}
