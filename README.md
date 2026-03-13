# FacturaScripts Plugin Template

A modern, Docker-based template for building custom plugins for FacturaScripts. This template includes everything you need to start developing a FacturaScripts plugin with a complete development environment.

## Features

- Complete plugin structure following FacturaScripts best practices
- Docker-based development environment using `erseco/alpine-facturascripts`
- Example controller, model, and views
- Example unit tests with PHPUnit
- GitHub Actions for automated testing and releases
- Internationalization support (Spanish and English included)
- Make commands for easy development workflow
- Ready to use with `make up`

## Quick Start (Docker)

- **Requirements:** Docker Desktop 4+, Make
- **Start:** `make upd` then open `http://localhost:8080`
- **Run tests:** `make test`
- **Stop:** `make down`
- **Login credentials:** `admin` / `admin`

This template uses the `erseco/alpine-facturascripts:main` image, which includes FacturaScripts with composer and testing tools pre-installed.

## Project Structure

```text
PluginTemplate/
├── Assets/
│   ├── CSS/              # Stylesheets
│   ├── Images/           # Images
│   └── JS/               # JavaScript files
├── Controller/           # Controllers (pages)
│   ├── ExampleController.php
│   └── ListExampleModel.php
├── Extension/            # Extensions to core FacturaScripts
│   ├── Controller/       # Controller extensions
│   ├── Model/            # Model extensions
│   └── XMLView/          # View extensions
├── Model/                # Data models
│   └── ExampleModel.php
├── Table/                # Database table definitions (XML)
│   └── example_table.xml
├── Test/                 # Unit tests
│   └── main/
│       ├── install-plugins.txt
│       └── ExampleModelTest.php
├── Translation/          # Internationalization files
│   ├── es_ES.json
│   └── en_EN.json
├── View/                 # HTML views (Twig templates)
├── XMLView/              # XML view definitions
│   └── ListExampleModel.xml
├── .github/              # GitHub Actions workflows
│   └── workflows/
│       ├── ci.yml        # Automated testing
│       └── release.yml   # Automated releases
├── facturascripts.ini    # Plugin metadata
├── Init.php              # Plugin initialization
├── docker-compose.yml    # Docker development environment
├── Makefile              # Development helpers
└── README.md             # This file
```

## Useful Make Targets

### Docker Management
- `make up` / `make upd` - Run in foreground/background
- `make down` - Stop containers
- `make clean` - Stop and remove volumes
- `make fresh` - Clean start (removes all data)
- `make logs` - Tail container logs
- `make shell` - Shell into the facturascripts container
- `make ps` - Show container status

### Code Quality
- `make lint` - Check code style with PHP CodeSniffer
- `make format` - Auto-fix code style with PHP CS Fixer

### Testing
- `make test` - Run unit tests inside container

### Plugin Management
- `make enable-plugin` - Enable the plugin in FacturaScripts
- `make rebuild` - Rebuild FacturaScripts dynamic classes

### Packaging
- `make package VERSION=x.y.z` - Build a distributable ZIP

Run `make help` to see all available targets.

## Customizing the Template

### 1. Rename the Plugin

Replace all occurrences of `PluginTemplate` with your plugin name:

- In `facturascripts.ini`: Change the `name` field
- In `docker-compose.yml`: Update the volume mount path
- In all PHP files: Update the namespace from `FacturaScripts\Plugins\PluginTemplate` to `FacturaScripts\Plugins\YourPluginName`
- Rename the root directory to match your plugin name

### 2. Update Plugin Metadata

Edit `facturascripts.ini`:

```ini
name = 'YourPluginName'
description = 'Your plugin description'
version = 1.0
min_version = 2025
min_php = 8.2
```

### 3. Update Author Information

Replace the copyright and author information in all PHP files:

```php
/**
 * This file is part of YourPluginName plugin for FacturaScripts.
 * Copyright (C) 2025 Your Name <your@email.com>
 */
```

### 4. Customize the Example Code

The template includes example code to get you started:

- **Controller:** `Controller/ExampleController.php` - Basic page example
- **List Controller:** `Controller/ListExampleModel.php` - List view example
- **Model:** `Model/ExampleModel.php` - Data model example
- **Table:** `Table/example_table.xml` - Database schema
- **XMLView:** `XMLView/ListExampleModel.xml` - View definition

You can modify these files or delete them and create your own.

## Development Workflow

### 1. Start the Development Environment

```bash
make up
```

This will start FacturaScripts with your plugin mounted at `http://localhost:8080`.

### 2. Access FacturaScripts

Open your browser and go to `http://localhost:8080`. Login with:
- **Username:** `admin`
- **Password:** `admin`

### 3. Enable the Plugin

The plugin should be automatically available. Go to **Admin Panel → Plugins** to enable it if needed, or run:

```bash
make enable-plugin
```

### 4. Make Changes

Edit your plugin files in your favorite editor. The files are mounted as a volume, so changes are immediately reflected.

### 5. Rebuild Dynamic Classes

After making changes to models or controllers, rebuild FacturaScripts:

```bash
make rebuild
```

Or access `http://localhost:8080/deploy?action=rebuild` in your browser.

### 6. View Logs

```bash
make logs
```

## Creating New Components

### Creating a New Controller

1. Create a new file in `Controller/` directory:

```php
<?php
namespace FacturaScripts\Plugins\YourPlugin\Controller;

use FacturaScripts\Core\Template\Controller;

class MyController extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'my-controller';
        $data['icon'] = 'fa-solid fa-star';
        return $data;
    }

    public function run(): void
    {
        parent::run();
        // Your code here
    }
}
```

2. Add translations for the title in `Translation/es_ES.json` and `Translation/en_EN.json`
3. Rebuild: `make rebuild`

### Creating a New Model

1. Create the model in `Model/` directory
2. Create the table definition in `Table/` directory (XML)
3. Optionally create a list controller and XMLView
4. Rebuild: `make rebuild`

### Extending Core Functionality

To extend existing FacturaScripts controllers or models:

1. Create the extension in `Extension/Controller/` or `Extension/Model/`
2. Load it in `Init.php`:

```php
public function init(): void
{
    $this->loadExtension(new Extension\Controller\EditCliente());
    $this->loadExtension(new Extension\Model\Cliente());
}
```

3. Rebuild: `make rebuild`

## Packaging Your Plugin

To create a distributable ZIP file of your plugin:

```bash
make package VERSION=1.0.0
```

This will:
1. Update the version in `facturascripts.ini`
2. Create a ZIP file in the `dist/` directory
3. Restore the version back to 1.0

The ZIP file will exclude development files (examples, git files, tests, etc.).

## Testing Your Plugin

### Unit Tests

The template includes example unit tests in the `Test/main/` directory.

**Running tests locally with Docker:**

```bash
# 1. Start the environment
make upd

# 2. Run the tests
make test

# 3. Stop when done
make down
```

**What happens when you run `make test`:**
1. Copies your tests to FacturaScripts' Test directory
2. Runs the plugin installation script
3. Executes PHPUnit tests (already installed in the container)
4. Shows results with colors

**Alternative: Automatic testing via GitHub Actions:**

```bash
# Simply push your code
git push origin main

# Tests run automatically on PHP 8.2, 8.3, and 8.4
# Check the "Actions" tab on GitHub
```

**To add new tests:**

1. Create a new PHP file in `Test/main/` ending with `Test.php`
2. Extend `PHPUnit\Framework\TestCase`
3. Use the namespace `FacturaScripts\Test\Plugins`
4. Create test methods starting with `test`

**Example test:**

```php
<?php
namespace FacturaScripts\Test\Plugins;

use PHPUnit\Framework\TestCase;
use FacturaScripts\Plugins\YourPlugin\Model\YourModel;

final class YourModelTest extends TestCase
{
    public function testCreate(): void
    {
        $model = new YourModel();
        $model->name = 'Test';
        $this->assertTrue($model->save());
        $this->assertTrue($model->delete());
    }
}
```

**Update `Test/main/install-plugins.txt`:**

Make sure your plugin name is listed here. If your plugin depends on other plugins, add them separated by commas:

```
YourPlugin,DependencyPlugin1,DependencyPlugin2
```

### GitHub Actions

The template includes two automated workflows:

**CI (Continuous Integration):**
- Runs automatically on push to `main` or `develop`
- Tests against PHP 8.2, 8.3, and 8.4
- Sets up FacturaScripts and MySQL
- Runs all unit tests

**Release:**
- Runs when you publish a GitHub release
- Creates a production-ready ZIP package
- Uploads the ZIP to the release

See [.github/README.md](.github/README.md) for more details.

## Environment Variables

You can customize the development environment by editing `docker-compose.yml`:

### Database
- `DB_HOST`: Database host (default: `mariadb`)
- `DB_NAME`: Database name (default: `facturascripts`)
- `DB_USER`: Database user (default: `facturascripts`)
- `DB_PASSWORD`: Database password (default: `facturascripts`)

### FacturaScripts
- `FS_INITIAL_USER`: Admin username (default: `admin`)
- `FS_INITIAL_PASS`: Admin password (default: `admin`)
- `FS_LANG`: Interface language (default: `es_ES`)
- `FS_TIMEZONE`: Timezone (default: `Europe/Madrid`)
- `FS_DEBUG`: Enable debug mode (default: `true`)

## Troubleshooting

### Plugin not showing up

1. Make sure the plugin is mounted correctly in `docker-compose.yml`
2. Check the volume path matches your plugin name
3. Run `make rebuild`

### Database errors

1. Check the database credentials in `docker-compose.yml`
2. Ensure MariaDB is running: `make ps`
3. Try a fresh start: `make fresh`

### Permission errors

The container runs as the `nobody` user. If you encounter permission issues:

```bash
make shell
# Inside the container:
ls -la /var/www/html/Plugins/PluginTemplate
```

### Changes not reflected

1. Rebuild dynamic classes: `make rebuild`
2. Clear browser cache
3. Check debug mode is enabled: `FS_DEBUG: "true"`

## Requirements

- FacturaScripts 2025 or later
- PHP 8.2+ for development
- Docker Desktop 4+
- Make (usually pre-installed on macOS/Linux)

## Documentation

- [FacturaScripts Official Documentation](https://facturascripts.com/publicaciones/creacion-de-plugins-210)
- [Plugin Development Guide](https://facturascripts.com/publicaciones/los-controladores-410)
- [alpine-facturascripts Container](https://github.com/erseco/alpine-facturascripts)

## Contributing

This is a template repository. Feel free to fork it and customize it for your needs.

## License

This template is released under the GNU LGPLv3 license. See [LICENSE](LICENSE) for details.

Your plugin built with this template can use any license you prefer.

## Author

Created by [Ernesto Serrano](https://github.com/erseco)

Based on the [alpine-facturascripts](https://github.com/erseco/alpine-facturascripts) Docker image.

---

**Happy coding!** If you have questions or need help, check the [FacturaScripts community](https://facturascripts.com/comunidad).
