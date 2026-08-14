<?php
/**
 * Fishing Tournament Manager - Standalone Web Installer
 * 
 * This script downloads the application from GitHub, extracts it,
 * configures the database, and runs the necessary setup commands.
 */

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
    $reqs = [
        'PHP >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'cURL Extension' => extension_loaded('curl'),
        'ZipArchive Extension' => class_exists('ZipArchive'),
        'PDO Extension' => extension_loaded('pdo'),
        'Write Permissions (Root)' => is_writable(INSTALL_ROOT),
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
    $result = curl_exec($ch);
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
        if (!empty($zips)) {
            $localZip = $zips[0];
        }
        $useLocal = !empty($localZip) && file_exists($localZip) && filesize($localZip) > 0;
        $zipPathToUse = $zipFile;

        if ($useLocal) {
            $zipPathToUse = $localZip;
        } else {
            // Download the repository ZIP using helper function
            if (!downloadFile($repoUrl, $zipFile)) {
                $error = "Failed to download the repository.";
            }
        }

        if (!file_exists($zipPathToUse) || filesize($zipPathToUse) == 0) {
            $error = "Failed to obtain the repository ZIP (both local and remote failed).";
        } else {
            // Step 2: Extract
            $zip = new ZipArchive;
            if ($zip->open($zipPathToUse) === TRUE) {
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
                $error = "Failed to extract the ZIP file.";
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
            
            $envContent = preg_replace('/APP_NAME=.*/', 'APP_NAME="' . $appName . '"', $envContent);
            $envContent = preg_replace('/APP_ENV=.*/', 'APP_ENV=production', $envContent);
            $envContent = preg_replace('/APP_DEBUG=.*/', 'APP_DEBUG=false', $envContent);
            $envContent = preg_replace('/APP_URL=.*/', 'APP_URL=' . $appUrl, $envContent);
            
            $envContent = preg_replace('/DB_CONNECTION=.*/', 'DB_CONNECTION=' . $dbConnection, $envContent);
            
            if ($dbConnection === 'sqlite') {
                $sqlitePath = INSTALL_ROOT . '/database/database.sqlite';
                if (!file_exists(INSTALL_ROOT . '/database')) {
                    mkdir(INSTALL_ROOT . '/database');
                }
                if (!file_exists($sqlitePath)) {
                    touch($sqlitePath);
                }
                $envContent = preg_replace('/# DB_DATABASE=.*/', 'DB_DATABASE=' . $sqlitePath, $envContent);
                $envContent = preg_replace('/DB_DATABASE=.*/', 'DB_DATABASE=' . $sqlitePath, $envContent);
            } else {
                $envContent = preg_replace('/DB_HOST=.*/', 'DB_HOST=' . $dbHost, $envContent);
                $envContent = preg_replace('/DB_PORT=.*/', 'DB_PORT=' . $dbPort, $envContent);
                $envContent = preg_replace('/DB_DATABASE=.*/', 'DB_DATABASE=' . $dbDatabase, $envContent);
                $envContent = preg_replace('/DB_USERNAME=.*/', 'DB_USERNAME=' . $dbUsername, $envContent);
                $envContent = preg_replace('/DB_PASSWORD=.*/', 'DB_PASSWORD="' . $dbPassword . '"', $envContent);
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
                    
                    // 1. Try running Composer programmatically using phar:// stream wrapper (works without exec)
                    try {
                        putenv('COMPOSER_HOME=' . INSTALL_ROOT . '/.composer');
                        
                        // Register phar autoloader / bootstrap
                        require_once 'phar://' . $composerPhar . '/src/bootstrap.php';
                        
                        $application = new Composer\Console\Application();
                        $application->setAutoExit(false);
                        
                        $input = new Symfony\Component\Console\Input\ArrayInput([
                            'command' => 'install',
                            '--no-dev' => true,
                            '--optimize-autoloader' => true,
                            '--no-interaction' => true,
                        ]);
                        
                        chdir(INSTALL_ROOT);
                        $result = $application->run($input);
                        
                        if ($result === 0) {
                            $installed = true;
                        }
                    } catch (\Exception $e) {
                        // Log programmatic error and try fallback if exec is available
                    }
                    
                    // 2. Fallback to exec if programmatic install failed/errored and exec is available
                    if (!$installed && function_exists('exec')) {
                        chdir(INSTALL_ROOT);
                        exec("php composer.phar install --no-dev --optimize-autoloader 2>&1", $output, $result);
                        if ($result === 0) {
                            $installed = true;
                        } else {
                            $error = "Composer install failed: " . implode("\n", $output);
                        }
                    }
                    
                    // Cleanup composer.phar after installation
                    if (file_exists($composerPhar)) {
                        unlink($composerPhar);
                    }
                    
                    if (!$installed && !$error) {
                        $error = "Composer install failed: Programmatic installation failed and shell execution is disabled.";
                    }
                } else {
                    $error = "Failed to download composer.phar for dependency installation.";
                }
            }

            if (!$error) {
                // Bootstrap Laravel to run migrations and key generate
                if (file_exists(INSTALL_ROOT . '/vendor/autoload.php') && file_exists(INSTALL_ROOT . '/bootstrap/app.php')) {
                    require INSTALL_ROOT . '/vendor/autoload.php';
                    $app = require_once INSTALL_ROOT . '/bootstrap/app.php';
                    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
                    
                    try {
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
            <h3 style="color: #4ade80;">Installation Complete! 🎉</h3>
            <p>The application has been successfully configured and the database is ready.</p>
            <p><strong>Note:</strong> For security reasons, please delete this <code>install.php</code> file from your server.</p>
            <a href="./" class="btn" style="width: 100%; text-align: center; box-sizing: border-box;">Go to Application</a>
        <?php endif; ?>
    </div>
</body>
</html>
