# MovieDB - Personal Movie & TV Database for Nextcloud

Track all the movies and TV shows you've watched with rich metadata from TMDB.

## Features

- **Movie Tracking**: Log movies with platform (Netflix, Disney+, etc.), language, and date watched
- **TV Show Tracking**: Track series at the episode level — season progress, "up next" card, and bulk-mark seasons as watched
- **Rewatch Support**: Log multiple viewings with separate ratings, platforms, and dates
- **TMDB Integration**: Automatically fetch movie and TV posters, cast, plot, and episode data
- **Personal Ratings**: Rate movies and shows 1–10 and write your own reviews
- **Shared Libraries**: Create named libraries and share your collection with other Nextcloud users — assign viewer or editor roles, manage members, and switch between libraries
- **Watchlist**: Mixed movie + TV watchlist with priorities and a random picker
- **Statistics**: Dashboard with viewing stats by genre, platform, episodes watched, and total runtime
- **Multilingual**: Full internationalization support (German, Spanish, French, Italian, Dutch)

## Requirements

- Nextcloud 32-35
- PHP 8.0 or higher
- A free TMDB API key ([Get one here](https://www.themoviedb.org/settings/api))

## Installation

### From App Store (Recommended)
1. Go to your Nextcloud Admin settings
2. Navigate to Apps
3. Search for "MovieDB"
4. Click Install

### Manual Installation
1. Clone this repository into your Nextcloud apps directory:
   ```bash
   cd /path/to/nextcloud/apps
   git clone https://github.com/yourusername/nextcloud-moviedb.git moviedb
   ```

2. Install PHP dependencies:
   ```bash
   cd moviedb
   composer install --no-dev
   ```

3. Install JavaScript dependencies and build:
   ```bash
   npm install
   npm run build
   ```

4. Enable the app in Nextcloud:
   ```bash
   occ app:enable moviedb
   ```

### Docker Installation (Pre-built)

If you've built the app locally and want to deploy to a Docker-based Nextcloud:

1. Create the deployment tarball (on your development machine):
   ```bash
   cd /path/to/parent-of-repo
   tar -czvf moviedb.tar.gz \
     --exclude='node_modules' \
     --exclude='.git' \
     --exclude='package-lock.json' \
     --exclude='*.map' \
     nextcloud-moviedb
   ```

2. Transfer to your server:
   ```bash
   scp moviedb.tar.gz user@your-server:/tmp/
   ```

3. Install on the server (adjust paths for your setup):
   ```bash
   # Find your Nextcloud container
   docker ps | grep nextcloud

   # Copy tarball into container
   docker cp /tmp/moviedb.tar.gz CONTAINER_ID:/tmp/

   # Extract to custom_apps (adjust path as needed)
   docker exec -it CONTAINER_ID bash -c "cd /var/www/html/custom_apps && tar -xzvf /tmp/moviedb.tar.gz && mv nextcloud-moviedb moviedb && chown -R www-data:www-data moviedb"

   # Enable the app
   docker exec -u www-data CONTAINER_ID php /var/www/html/occ app:enable moviedb
   ```

   **Alternative:** If your Nextcloud data is mounted from the host:
   ```bash
   # Extract directly on host
   cd /opt/containers/nextcloud/app/custom_apps  # adjust path
   sudo tar -xzvf /tmp/moviedb.tar.gz
   sudo mv nextcloud-moviedb moviedb
   sudo chown -R www-data:www-data moviedb

   # Enable via Docker
   docker exec -u www-data CONTAINER_ID php /var/www/html/occ app:enable moviedb
   ```

### Quick Deploy to Pi Server

For development workflow when deploying from Mac to a Raspberry Pi:

```bash
# 1. Build on Mac
cd /path/to/nextcloud-moviedb
npm run build

# 2. Create tarball
cd /path/to/parent-of-repo
tar -czvf moviedb.tar.gz \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='package-lock.json' \
  --exclude='*.map' \
  nextcloud-moviedb

# 3. Transfer to Pi
scp moviedb.tar.gz pi@pi-server:/tmp/

# 4. SSH to Pi and install
ssh pi@pi-server
sudo rm -rf /opt/containers/nextcloud/app/custom_apps/moviedb
cd /opt/containers/nextcloud/app/custom_apps
sudo tar -xzvf /tmp/moviedb.tar.gz
sudo mv nextcloud-moviedb moviedb
sudo chown -R www-data:www-data moviedb
```

## Development

### Setup
```bash
# Install dependencies
composer install
npm install

# Watch for changes during development
npm run watch
```

### Build for Production
```bash
npm run build
```

### Running CI Locally

Run the same checks that GitHub Actions runs:

```bash
# ESLint
npm run lint

# PHP syntax check
find lib/ -name "*.php" -print0 | xargs -0 -n1 php -l

# Build
npm run build
```

To auto-fix ESLint issues:

```bash
npm run lint:fix
```

### Testing

#### JavaScript Tests (Quick - Run Anywhere)
```bash
npm test              # Run all tests once
npm run test:watch    # Watch mode for development
npm run test:coverage # Generate coverage report
```

**Status:** ✅ 37 tests passing (formatters, stores, components)

#### PHP Unit Tests (Requires Nextcloud Environment)
```bash
# Inside Nextcloud installation at custom_apps/moviedb/
composer test         # Run all PHP tests
./vendor/bin/phpunit --testdox  # Detailed output
```

**Status:** ✅ 39 tests written (services, controllers)

**Note:** PHP tests must run inside a Nextcloud installation. See [TESTING.md](TESTING.md) for complete setup instructions including:
- Local Nextcloud setup with symlinks
- Docker container configuration
- CI/CD integration examples
- How other Nextcloud apps handle testing

**Quick Setup:**
```bash
# Option 1: Symlink for easy development
ln -s /path/to/your/dev/moviedb /path/to/nextcloud/custom_apps/moviedb

# Option 2: Copy to Nextcloud
cp -r . /path/to/nextcloud/custom_apps/moviedb
```

Then enable the app and run tests:
```bash
cd /path/to/nextcloud
php occ app:enable moviedb
cd custom_apps/moviedb
composer test
```

## Configuration

1. Open the MovieDB app in Nextcloud
2. Go to Settings
3. Enter your TMDB API key (Read Access Token)
4. Select your preferred language for movie metadata

## API

The app exposes the following REST API endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/movies` | GET | List all movies |
| `/api/movies` | POST | Add a new movie |
| `/api/movies/{id}` | GET | Get movie details |
| `/api/movies/{id}` | PUT | Update a movie |
| `/api/movies/{id}` | DELETE | Delete a movie |
| `/api/watchlist` | GET | Get watchlist |
| `/api/watchlist` | POST | Add to watchlist |
| `/api/watchlist/{id}/watched` | POST | Move to watched |
| `/api/tmdb/search` | GET | Search TMDB |
| `/api/stats` | GET | Get statistics |

## License

AGPL-3.0-or-later

## Credits

- Movie data provided by [The Movie Database (TMDB)](https://www.themoviedb.org/)
- Built with [Vue 3](https://vuejs.org/), [Pinia](https://pinia.vuejs.org/), and [Nextcloud Vue](https://github.com/nextcloud/nextcloud-vue)
