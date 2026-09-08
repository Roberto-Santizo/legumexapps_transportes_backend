<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Requisitos de entorno — Legumex Transportes

Este proyecto **exige PostgreSQL con la extensión PostGIS**, tanto en desarrollo como para correr los tests. Desde SPEC 08 (zonas geográficas) la suite dejó de correr sobre SQLite en memoria: las zonas se guardan como `geography(Polygon,4326)` y se consultan con `ST_Contains`.

Antes de `php artisan test`:

1. Levantar PostgreSQL con PostGIS (la imagen `postgis/postgis` ya lo trae).
2. Crear la base de tests y habilitar la extensión en ella — PostGIS se instala **por base de datos**, no por servidor:

   ```sql
   CREATE DATABASE legumexapps_transportes_testing;
   \c legumexapps_transportes_testing
   CREATE EXTENSION IF NOT EXISTS postgis;
   ```

3. La conexión de tests está fijada en `phpunit.xml` (`DB_CONNECTION=pgsql`, base `legumexapps_transportes_testing`); ajusta host, puerto y credenciales si tu servidor local difiere.

`CREATE EXTENSION` requiere un rol privilegiado. En entornos gestionados (RDS, Cloud SQL, Laravel Cloud) el usuario de la aplicación normalmente no puede crearla: hay que habilitarla una vez a mano antes del primer `php artisan migrate`.

Otro requisito de entorno, de SPEC 05: `upload_max_filesize` y `post_max_size` ≥ 4M para la subida de imágenes.

Desde SPEC 26 hay un **tercer requisito, y es un proceso permanente**: el seguimiento en vivo de los viajes se emite por websocket con Laravel Reverb, así que en cada entorno donde se quiera ver el mapa moverse tiene que estar corriendo

```bash
php artisan reverb:start
```

Sin ese proceso **la API sigue funcionando entera**: el piloto reporta su posición, la fila se guarda en `trip_positions` y el `POST` responde 201 igual. Lo único que se pierde es el aviso en vivo — el fallo del broadcast se registra en el log y no revienta la petición —, así que «el mapa no se mueve» es un síntoma de servidor caído, no de código.

Requiere las claves `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT` y `REVERB_SCHEME` en el `.env` (ver `.env.example`) y que `REVERB_PORT` sea **alcanzable desde el navegador**; detrás de HTTPS el websocket tiene que salir por `wss` a través del proxy. La suite de tests no levanta nada: `phpunit.xml` mantiene `BROADCAST_CONNECTION=null`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
