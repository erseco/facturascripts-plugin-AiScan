# GitHub Actions Workflows

This directory contains automated workflows for testing and releasing the plugin.

## Workflows

### CI (Continuous Integration)
**File:** `workflows/ci.yml`

**Triggers:**
- Push to `main` or `develop` branches
- Pull requests

**What it does:**
1. Tests the plugin against multiple PHP versions (8.2, 8.3, 8.4)
2. Sets up a MySQL database
3. Installs FacturaScripts
4. Installs the plugin
5. Runs PHPUnit tests

**PHP Versions Tested:**
- PHP 8.2
- PHP 8.3
- PHP 8.4

### Release
**File:** `workflows/release.yml`

**Triggers:**
- When a new GitHub release is published

**What it does:**
1. Updates the version in `facturascripts.ini`
2. Creates a ZIP package with only production files
3. Uploads the ZIP to the GitHub release

**Files Excluded from Package:**
- Test files (`/Test/`)
- Development files (`Makefile`, `docker-compose.yml`, `README.md`, etc.)
- Git files (`.git*`, `.github/`)
- Dependencies (`vendor/`, `node_modules/`)
- Build artifacts (`dist/`)

## How to Use

### Running Tests Locally

Before pushing code, you can run tests locally using the Makefile (once you have FacturaScripts set up):

```bash
# Start the development environment
make up

# Run tests inside the container
# (You'll need to set up PHPUnit in your local FacturaScripts installation)
```

### Creating a Release

1. **Update your plugin version** in `facturascripts.ini`

2. **Commit and push your changes:**
   ```bash
   git add .
   git commit -m "Prepare release v1.0.0"
   git push origin main
   ```

3. **Create a GitHub release:**
   - Go to your repository on GitHub
   - Click "Releases" → "Create a new release"
   - Create a new tag (e.g., `v1.0.0`)
   - Fill in the release notes
   - Click "Publish release"

4. **The workflow will automatically:**
   - Update the version in `facturascripts.ini`
   - Create a ZIP package
   - Upload it to the release

5. **Download the ZIP** from the release and upload it to FacturaScripts.com if you want to publish it publicly

## Customizing for Your Plugin

When you rename your plugin from `PluginTemplate` to your actual plugin name, update these files:

1. **`.github/workflows/ci.yml`**
   - Line 55-56: Change `PluginTemplate` to your plugin name

2. **`.github/workflows/release.yml`**
   - Line 31 and 53: Change `PluginTemplate` to your plugin name

3. **`Test/main/install-plugins.txt`**
   - Change `PluginTemplate` to your plugin name

## Troubleshooting

### Tests Failing
- Check the "Actions" tab in your GitHub repository
- Look at the failed step in the workflow logs
- Common issues:
  - PHP syntax errors
  - Database connection issues
  - Missing dependencies

### Release Not Creating Package
- Ensure you created a "Release" not just a "Tag"
- Check that the workflow has run in the "Actions" tab
- Verify the ZIP was created in the workflow logs

## Notes

- The CI workflow uses GitHub's MySQL service container for database testing
- Tests run in isolation - each PHP version is tested independently
- The release workflow only runs when you publish a GitHub release, not on every tag
