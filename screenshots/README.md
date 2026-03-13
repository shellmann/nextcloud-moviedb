# Screenshots Directory

This directory should contain screenshots of the MovieDB app for display in the Nextcloud App Store.

## Required Screenshots

### 1. dashboard.png (Required)
- **Content**: Main dashboard/movie list view showing the app in action
- **Size**: Recommended 1200x800 or 1600x1000 pixels
- **Format**: PNG
- **Shows**: Movie grid/list with posters, filters, and navigation

### 2. movie-detail.png (Recommended)
- **Content**: Detail view of a single movie with rating, review, and metadata
- **Size**: Same as above
- **Format**: PNG
- **Shows**: Movie poster, description, cast, user rating, and review

### 3. settings.png (Recommended)
- **Content**: Settings page showing TMDB API configuration
- **Size**: Same as above
- **Format**: PNG
- **Shows**: API key configuration and language settings

## Guidelines

- Use a clean, professional appearance
- Show the app with sample data (not empty states)
- Ensure no sensitive information (real API keys) is visible
- Use light theme or dark theme consistently
- Make sure screenshots are recent and match current UI

## Taking Screenshots

1. Deploy the app to a test Nextcloud instance
2. Add some sample movie data
3. Use browser dev tools to set viewport to desired resolution
4. Take screenshots (Cmd+Shift+4 on Mac, PrintScreen on Windows/Linux)
5. Save as PNG files with the names above
6. Verify images are clear and properly sized

## After Adding Screenshots

Once screenshots are added to this directory:
1. Commit them to the repository
2. Push to GitHub
3. Verify they're accessible via the GitHub raw URLs in `appinfo/info.xml`
4. The App Store will automatically fetch and display them

Current screenshot URL in info.xml:
`https://raw.githubusercontent.com/shellmann/nextcloud-moviedb/main/screenshots/dashboard.png`
