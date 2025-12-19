# Laravel Docker Stack

Complete development environment for Laravel with PostgreSQL, Redis, and Nginx. Auto-syncing code changes.

## Quick Start

```bash
# Navigate to stack
cd stack

# Start containers
docker-compose up -d

# Install dependencies
docker-compose exec app composer install

# Generate key & run migrations
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate

# Fix permissions
docker-compose exec app chmod -R 777 /app/storage /app/bootstrap/cache
```

**Access**: http://localhost | **API**: http://localhost/api

---

## Services & Access

| Service | Type | Port | Host | Credentials |
|---------|------|------|------|-------------|
| Web App | Nginx | 80 | localhost | - |
| API | PHP-FPM | 9000 | localhost/api | - |
| Database | PostgreSQL | 5432 | localhost | `laravel_user` / `laravel_password` |
| Cache | Redis | 6379 | localhost | - |

---

## Common Commands

### Laravel Artisan
```bash
# Models, Controllers, Migrations
docker-compose exec app php artisan make:model Post
docker-compose exec app php artisan make:controller PostController
docker-compose exec app php artisan make:migration create_posts_table

# Database
docker-compose exec app php artisan migrate
docker-compose exec app php artisan migrate:rollback
docker-compose exec app php artisan db:seed

# Tinker REPL
docker-compose exec app php artisan tinker
```

### Composer
```bash
docker-compose exec app composer install
docker-compose exec app composer require vendor/package
docker-compose exec app composer update
```

### Docker
```bash
docker-compose ps              # View containers
docker-compose logs -f app     # View app logs
docker-compose restart         # Restart services
docker-compose down            # Stop all
docker-compose down -v         # Stop & remove volumes
```

### Database Access
```bash
# From container
docker-compose exec db psql -U laravel_user -d laravel

# From host (requires psql)
psql -h localhost -U laravel_user -d laravel
```

---

## File Structure

```
stack/
├── backend/               # Laravel app (auto-synced)
├── docker-compose.yml     # Services config
├── Dockerfile             # PHP 8.2 image
├── nginx.conf             # Nginx config
├── .env                   # Environment variables
└── README.md              # This file
```

---

## Environment Variables

Edit `.env` to customize:

```env
APP_NAME=Laravel
APP_DEBUG=true
DB_HOST=db
DB_USERNAME=laravel_user
DB_PASSWORD=laravel_password
REDIS_HOST=redis
```

---

## Features

✅ **Live Code Sync** - Changes in `backend/` auto-reflect in container  
✅ **PostgreSQL** - Persistent data with volume  
✅ **Redis** - Caching & sessions  
✅ **PHP 8.2-FPM** - Latest stable  
✅ **Nginx** - Reverse proxy on port 80  
✅ **Hot Reload** - No restart needed  

---

## Troubleshooting

```bash
# Clean & rebuild
docker-compose down -v && docker-compose build --no-cache && docker-compose up -d

# Check logs
docker-compose logs app

# Fix permissions
docker-compose exec app chmod -R 777 /app/storage /app/bootstrap/cache

# Verify database
docker-compose exec app php artisan tinker --execute="echo DB::table('users')->count();"
```

---

## Production Notes

This is development-only. For production: set `APP_DEBUG=false`, use strong passwords, enable backups, configure SSL/HTTPS.

**Docs**: [Laravel](https://laravel.com/docs) | [Docker Compose](https://docs.docker.com/compose/)
