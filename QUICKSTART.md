# Quick Start Guide

## 1. Start FacturaScripts

```bash
make up
```

Wait for the containers to start (about 30 seconds).

## 2. Access FacturaScripts

Open your browser and go to:
```
http://localhost:8080
```

Login with:
- **Username:** `admin`
- **Password:** `admin`

## 3. Enable the Plugin

Go to **Admin Panel → Plugins** and enable **PluginTemplate**.

Or run:
```bash
make enable-plugin
```

## 4. Access the Example Pages

After enabling the plugin, you'll see new menu items:

- **Example Controller** - A basic controller example
- **Example Models** - A list view with CRUD operations

## 5. Make Changes

Edit any file in the plugin directory. Changes are reflected immediately.

After changing models or controllers, rebuild:
```bash
make rebuild
```

## 6. Stop FacturaScripts

```bash
make down
```

## Need Help?

Run `make help` to see all available commands.

Check the full [README.md](README.md) for detailed documentation.
