<?php

/**
 * Fishing Peg Manager
 * 
 * @author      Matteo
 * @copyright   2026 Matteo
 * @license     GNU Affero General Public License v3 (AGPL-3.0)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

class InstallController extends Controller
{
    public function show()
    {
        // Se esiste il .env, proviamo a vedere se il DB è connesso e ha già le tabelle
        if (file_exists(base_path('.env'))) {
            try {
                if (DB::connection()->getPdo() && Schema::hasTable('users')) {
                    // App già installata
                    return redirect()->route('dashboard');
                }
            } catch (\Exception $e) {
                // Errore di connessione, permettiamo di vedere la pagina di installazione per correggere il .env
            }
        }

        return view('install');
    }

    public function process(Request $request)
    {
        $request->validate([
            'app_name' => 'required|string|max:255',
            'app_url' => 'required|url',
            'db_connection' => 'required|in:sqlite,mysql',
            'db_host' => 'required_if:db_connection,mysql',
            'db_port' => 'required_if:db_connection,mysql|numeric',
            'db_database' => 'required',
            'db_username' => 'required_if:db_connection,mysql',
            'db_password' => 'nullable'
        ]);

        // Test connessione DB
        try {
            if ($request->db_connection === 'sqlite') {
                $dbFilename = basename($request->db_database);
                $dbPath = database_path($dbFilename);
                if (!file_exists($dbPath)) {
                    touch($dbPath);
                }
                $pdo = new PDO("sqlite:" . $dbPath);
            } else {
                $dsn = "mysql:host={$request->db_host};port={$request->db_port};dbname={$request->db_database}";
                $pdo = new PDO($dsn, $request->db_username, $request->db_password);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            return back()->withInput()->with('error', 'Database connection failed: ' . $e->getMessage());
        }

        // Genera .env
        $envExamplePath = base_path('.env.example');
        $envPath = base_path('.env');

        if (!file_exists($envExamplePath)) {
            return back()->withInput()->with('error', 'The .env.example file is missing. Unable to proceed.');
        }

        $envContent = file_get_contents($envExamplePath);

        // Aggiorna i valori
        $appNameFormatted = '"' . str_replace('"', '', $request->app_name) . '"';
        $envContent = $this->updateEnv($envContent, 'APP_NAME', $appNameFormatted);
        $envContent = $this->updateEnv($envContent, 'APP_URL', $request->app_url);
        $envContent = $this->updateEnv($envContent, 'DB_CONNECTION', $request->db_connection);
        
        if ($request->db_connection === 'sqlite') {
            $envContent = $this->updateEnv($envContent, 'DB_DATABASE', database_path(basename($request->db_database)));
            // Pulisce le variabili mysql
            $envContent = preg_replace("/^DB_HOST=.*/m", "# DB_HOST=127.0.0.1", $envContent);
            $envContent = preg_replace("/^DB_PORT=.*/m", "# DB_PORT=3306", $envContent);
            $envContent = preg_replace("/^DB_USERNAME=.*/m", "# DB_USERNAME=root", $envContent);
            $envContent = preg_replace("/^DB_PASSWORD=.*/m", "# DB_PASSWORD=", $envContent);
        } else {
            $envContent = $this->updateEnv($envContent, 'DB_HOST', $request->db_host);
            $envContent = $this->updateEnv($envContent, 'DB_PORT', $request->db_port);
            $envContent = $this->updateEnv($envContent, 'DB_DATABASE', $request->db_database);
            $envContent = $this->updateEnv($envContent, 'DB_USERNAME', $request->db_username);
            $envContent = $this->updateEnv($envContent, 'DB_PASSWORD', $request->db_password ?? '');
        }

        file_put_contents($envPath, $envContent);

        // Ricarica la configurazione
        Artisan::call('config:clear');
        
        // Genera APP_KEY se non presente o vuota
        if (!env('APP_KEY')) {
            Artisan::call('key:generate', ['--force' => true]);
        }

        // Esegue le migrazioni
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Migrations failed: ' . $e->getMessage());
        }

        return redirect('/')->with('success', 'Installation completed successfully!');
    }

    private function updateEnv($envContent, $key, $value)
    {
        $sanitizedValue = str_replace(["\r", "\n"], '', (string)$value);
        $pattern = "/^#?\s*{$key}=.*/m";
        if (preg_match($pattern, $envContent)) {
            return preg_replace($pattern, "{$key}={$sanitizedValue}", $envContent);
        }
        return $envContent . "\n{$key}={$sanitizedValue}";
    }
}
