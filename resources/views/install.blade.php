<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Fishing Tournament</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        function toggleDbFields() {
            const dbType = document.getElementById('db_connection').value;
            const mysqlFields = document.getElementById('mysql_fields');
            const sqliteFields = document.getElementById('sqlite_fields');
            
            if (dbType === 'mysql') {
                mysqlFields.classList.remove('hidden');
                sqliteFields.classList.add('hidden');
            } else {
                mysqlFields.classList.add('hidden');
                sqliteFields.classList.remove('hidden');
            }
        }
    </script>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen p-4 font-sans">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl overflow-hidden">
        <div class="bg-blue-600 p-6 text-white text-center">
            <h1 class="text-2xl font-bold">Installation Wizard</h1>
            <p class="text-blue-100 mt-2">Configure the environment and database</p>
        </div>
        
        <div class="p-8">
            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <form action="{{ url('/install') }}" method="POST">
                @csrf
                
                <h2 class="text-lg font-semibold border-b pb-2 mb-4 text-slate-700">General Settings</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="app_name">Application Name</label>
                        <input type="text" name="app_name" id="app_name" value="{{ old('app_name', 'Fishing Tournament') }}" required
                            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="app_url">Application URL</label>
                        <input type="url" name="app_url" id="app_url" value="{{ old('app_url', url('/')) }}" required
                            class="w-full rounded-md border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <h2 class="text-lg font-semibold border-b pb-2 mb-4 text-slate-700">Database</h2>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="db_connection">Database Type</label>
                    <select name="db_connection" id="db_connection" onchange="toggleDbFields()"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="sqlite" {{ old('db_connection') == 'sqlite' ? 'selected' : '' }}>SQLite (Recommended for development)</option>
                        <option value="mysql" {{ old('db_connection') == 'mysql' ? 'selected' : '' }}>MySQL / MariaDB</option>
                    </select>
                </div>

                <div id="sqlite_fields" class="{{ old('db_connection', 'sqlite') == 'sqlite' ? '' : 'hidden' }} bg-slate-50 p-4 rounded-md mb-6 border border-slate-200">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="db_database_sqlite">SQLite File Name</label>
                        <input type="text" id="db_database_sqlite" value="{{ old('db_database', 'database.sqlite') }}"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-slate-500 mt-1">The file will be created in the <code>database/</code> folder if it doesn't exist.</p>
                    </div>
                </div>

                <div id="mysql_fields" class="{{ old('db_connection') == 'mysql' ? '' : 'hidden' }} bg-slate-50 p-4 rounded-md mb-6 border border-slate-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1" for="db_host">Host</label>
                            <input type="text" name="db_host" id="db_host" value="{{ old('db_host', '127.0.0.1') }}"
                                class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1" for="db_port">Port</label>
                            <input type="number" name="db_port" id="db_port" value="{{ old('db_port', '3306') }}"
                                class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-1" for="db_database_mysql">Database Name</label>
                        <input type="text" id="db_database_mysql" value="{{ old('db_database', 'torneo_pesca') }}"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1" for="db_username">User</label>
                            <input type="text" name="db_username" id="db_username" value="{{ old('db_username', 'root') }}"
                                class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1" for="db_password">Password</label>
                            <input type="password" name="db_password" id="db_password"
                                class="w-full rounded-md border border-slate-300 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- Hidden input for db_database that is populated on submit -->
                <input type="hidden" name="db_database" id="real_db_database" value="">

                <div class="flex justify-end pt-4 border-t mt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                        Install and Migrate Database
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="mt-8 text-center text-slate-500 text-sm font-medium w-full absolute bottom-4 left-0">
        Powered by <a href="https://github.com/matteocisilino" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 underline">Matteo</a>
    </div>

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            const dbType = document.getElementById('db_connection').value;
            const realDbInput = document.getElementById('real_db_database');
            
            if (dbType === 'sqlite') {
                realDbInput.value = document.getElementById('db_database_sqlite').value;
            } else {
                realDbInput.value = document.getElementById('db_database_mysql').value;
            }
        });
    </script>
</body>
</html>
