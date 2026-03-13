# Makefile to facilitate the use of Docker for FacturaScripts plugin development

.PHONY: help up upd down pull build shell clean package enable-plugin rebuild lint format test logs ps fresh check-docker

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Detect the operating system
ifeq ($(OS),Windows_NT)
    ifdef MSYSTEM
        SYSTEM_OS := unix
    else ifdef CYGWIN
        SYSTEM_OS := unix
    else
        SYSTEM_OS := windows
    endif
else
    SYSTEM_OS := unix
endif

# Check if Docker is running
check-docker:
ifeq ($(SYSTEM_OS),windows)
	@echo "Detected system: Windows (cmd, powershell)"
	@docker version > NUL 2>&1 || (echo. & echo Error: Docker is not running. Please make sure Docker is installed and running. & echo. & exit 1)
else
	@echo "Detected system: Unix (Linux/macOS/Cygwin/MinGW)"
	@docker version > /dev/null 2>&1 || (echo "" && echo "Error: Docker is not running. Please make sure Docker is installed and running." && echo "" && exit 1)
endif

# Start Docker containers in interactive mode
up: check-docker
	docker compose up --remove-orphans

# Start Docker containers in background mode/daemon
upd: check-docker
	docker compose up --detach --remove-orphans

# Stop and remove Docker containers
down: check-docker
	docker compose down

# Pull the latest images from the registry
pull: check-docker
	docker compose -f docker-compose.yml pull

# Build or rebuild Docker containers
build: check-docker
	docker compose build

# Open a shell inside the facturascripts container
shell: check-docker
	docker compose exec facturascripts sh

# Clean up and stop Docker containers, removing volumes and orphan containers
clean: check-docker
	docker compose down -v --remove-orphans

# Generate the PluginTemplate-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: VERSION not specified. Use 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	@echo "Updating version to $(VERSION) in facturascripts.ini..."
	$(SED_INPLACE) 's/^\(version[[:space:]]*=[[:space:]]*\).*$$/\1$(VERSION)/' facturascripts.ini
	@echo "Creating ZIP archive: PluginTemplate-$(VERSION).zip..."
	@mkdir -p dist
	@zip -r dist/PluginTemplate-$(VERSION).zip . \
		-x "*.git*" \
		-x "*examples/*" \
		-x "*dist/*" \
		-x "*vendor/*" \
		-x "*node_modules/*" \
		-x "*.DS_Store" \
		-x "*Makefile" \
		-x "*docker-compose.yml" \
		-x "*.md"
	@echo "Restoring version in facturascripts.ini..."
	$(SED_INPLACE) 's/^\(version[[:space:]]*=[[:space:]]*\).*$$/\11.0/' facturascripts.ini
	@echo "Package created: dist/PluginTemplate-$(VERSION).zip"

# Enable the plugin in FacturaScripts
enable-plugin: check-docker
	@echo "Enabling PluginTemplate plugin..."
	@docker compose exec facturascripts sh -c "cd /var/www/html && php84 index.php"
	@echo "Plugin enabled! Access FacturaScripts at http://localhost:8080"
	@echo "Login with admin/admin"

# Rebuild FacturaScripts dynamic classes
rebuild: check-docker
	@echo "Rebuilding FacturaScripts..."
	@docker compose exec facturascripts sh -c "curl -s http://localhost:8080/deploy?action=rebuild > /dev/null"
	@echo "Rebuild complete!"

# Run PHP CodeSniffer to check code style
lint: check-docker upd
	@echo "Running PHP CodeSniffer..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing phpcs if needed..." && if [ ! -f vendor/bin/phpcs ]; then php84 /usr/local/bin/composer require --dev squizlabs/php_codesniffer --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && php84 vendor/bin/phpcs --standard=Plugins/PluginTemplate/phpcs.xml Plugins/PluginTemplate --colors'
	@echo ""
	@echo "✅ Lint check completed!"

# Run PHP CS Fixer to automatically fix code style
format: check-docker upd
	@echo "Running PHP CS Fixer..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing php-cs-fixer if needed..." && if [ ! -f vendor/bin/php-cs-fixer ]; then php84 /usr/local/bin/composer require --dev friendsofphp/php-cs-fixer --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html/Plugins/PluginTemplate && php84 /var/www/html/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --verbose'
	@echo ""
	@echo "✅ Code formatting completed!"

# Run unit tests inside container
test: check-docker upd
	@echo "Running unit tests..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing PHPUnit if needed..." && if [ ! -f vendor/bin/phpunit ]; then php84 /usr/local/bin/composer require --dev phpunit/phpunit --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Setting up test environment..." && mkdir -p Test/Plugins && cp -r Plugins/PluginTemplate/Test/main/* Test/Plugins/ 2>/dev/null || true && cp Plugins/PluginTemplate/Test/bootstrap.php Test/bootstrap.php 2>/dev/null || true && cp Plugins/PluginTemplate/Test/install-plugins.php Test/install-plugins.php 2>/dev/null || true'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && test -f Test/Plugins/install-plugins.txt || (echo "❌ Error: No tests found in Test/main/" && exit 1)'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing test plugins..." && php84 Test/install-plugins.php'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && test -f phpunit-plugins.xml || echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><phpunit bootstrap=\"Test/bootstrap.php\" colors=\"true\"><testsuites><testsuite name=\"PluginTests\"><directory>Test/Plugins</directory></testsuite></testsuites></phpunit>" > phpunit-plugins.xml'
	@echo "→ Running PHPUnit tests..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && php84 vendor/bin/phpunit -c phpunit-plugins.xml'
	@echo ""
	@echo "✅ Tests completed!"

# View logs
logs:
	docker compose logs -f --tail=200

# Show container status
ps:
	docker compose ps

# Fresh start (clean and start)
fresh: clean upd

# Display help with available commands
help:
	@echo ""
	@echo "Usage: make <command>"
	@echo ""
	@echo "Docker management:"
	@echo "  up                - Start containers in interactive mode"
	@echo "  upd               - Start containers in background mode (detached)"
	@echo "  down              - Stop and remove containers"
	@echo "  build             - Build or rebuild containers"
	@echo "  pull              - Pull the latest images from the registry"
	@echo "  clean             - Stop containers and remove volumes"
	@echo "  fresh             - Clean volumes and start again (fresh DB)"
	@echo "  shell             - Open a shell inside the facturascripts container"
	@echo "  logs              - Tail container logs"
	@echo "  ps                - Show container status"
	@echo ""
	@echo "Code Quality:"
	@echo "  lint              - Run PHP CodeSniffer to check code style"
	@echo "  format            - Run PHP CS Fixer to automatically fix code style"
	@echo ""
	@echo "Testing:"
	@echo "  test              - Run unit tests inside container"
	@echo ""
	@echo "Plugin management:"
	@echo "  enable-plugin     - Enable the plugin in FacturaScripts"
	@echo "  rebuild           - Rebuild FacturaScripts dynamic classes"
	@echo ""
	@echo "Packaging:"
	@echo "  package           - Generate a .zip package of the plugin with version tag"
	@echo "                      Usage: make package VERSION=1.2.3"
	@echo ""
	@echo "Other:"
	@echo "  help              - Show this help message"
	@echo ""

# Set help as the default goal if no target is specified
.DEFAULT_GOAL := help
