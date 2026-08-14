#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

echo "====================================================="
echo "    Fishing Peg Manager - Initialization Script      "
echo "====================================================="
echo ""

# Function to check if a command exists
check_dependency() {
    if ! command -v "$1" &> /dev/null; then
        echo "❌ Error: '$1' is required but not installed."
        echo "Please install '$1' and run this script again."
        exit 1
    fi
}

echo "🔍 Checking dependencies..."
check_dependency "php"
check_dependency "composer"
check_dependency "npm"
echo "✅ All dependencies found."
echo ""

echo "📦 [1/6] Installing PHP dependencies (Composer)..."
composer install --no-interaction

echo ""
echo "📝 [2/6] Setting up environment file (.env)..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "✅ .env created from .env.example."
    
    echo ""
    read -p "Do you want to configure database connection now? [y/N]: " configure_db
    
    if [[ "$configure_db" =~ ^[Yy]$ ]]; then
        read -p "Database Connection (sqlite/mysql) [sqlite]: " db_connection
        db_connection=${db_connection:-sqlite}
        
        # Update DB_CONNECTION
        sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=$db_connection/" .env
        
        if [ "$db_connection" == "mysql" ]; then
            read -p "Database Host [127.0.0.1]: " db_host
            db_host=${db_host:-127.0.0.1}
            read -p "Database Port [3306]: " db_port
            db_port=${db_port:-3306}
            read -p "Database Name [torneo_pesca]: " db_name
            db_name=${db_name:-torneo_pesca}
            read -p "Database User [root]: " db_user
            db_user=${db_user:-root}
            read -s -p "Database Password []: " db_pass
            echo ""
            
            # Uncomment lines if they are commented out and set values
            sed -i "s/^# DB_HOST=.*/DB_HOST=$db_host/" .env
            sed -i "s/^DB_HOST=.*/DB_HOST=$db_host/" .env
            
            sed -i "s/^# DB_PORT=.*/DB_PORT=$db_port/" .env
            sed -i "s/^DB_PORT=.*/DB_PORT=$db_port/" .env
            
            sed -i "s/^# DB_DATABASE=.*/DB_DATABASE=$db_name/" .env
            sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$db_name/" .env
            
            sed -i "s/^# DB_USERNAME=.*/DB_USERNAME=$db_user/" .env
            sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$db_user/" .env
            
            sed -i "s/^# DB_PASSWORD=.*/DB_PASSWORD=$db_pass/" .env
            sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$db_pass/" .env
        fi
        echo "✅ Database configuration updated in .env."
    else
        echo "⏭️  Skipping database configuration. You can edit .env manually or use the web installer."
    fi
else
    echo "⏭️  .env already exists, skipping generation."
fi

echo ""
echo "🔑 [3/6] Generating application key..."
php artisan key:generate --no-interaction

echo ""
echo "🗄️  [4/6] Setting up database and running migrations..."
if grep -q "^DB_CONNECTION=sqlite" .env; then
    if [ ! -f database/database.sqlite ]; then
        touch database/database.sqlite
        echo "✅ Created database/database.sqlite file."
    fi
fi

# Run migrations, do not exit script if it fails (e.g. bad credentials)
set +e
echo "Running migrations..."
php artisan migrate --force
if [ $? -ne 0 ]; then
    echo "⚠️  Migrations failed! You can configure .env manually or via web installer and run 'php artisan migrate' later."
fi
set -e

echo ""
echo "📦 [5/6] Installing frontend dependencies (NPM)..."
npm install

echo ""
echo "🏗️  [6/6] Building frontend assets (Vite)..."
npm run build

echo ""
echo "====================================================="
echo " 🎉 Initialization completed!                        "
echo "====================================================="
echo ""
echo " If you haven't configured the database yet, you can"
echo " access the web installer by running:"
echo " -> php artisan serve"
echo " And navigating to http://localhost:8000/install"
echo ""
echo "====================================================="
