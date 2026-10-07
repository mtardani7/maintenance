<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

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

## MIRA Telegram assistant

The optional `maintenance-telegram` Compose service long-polls Telegram and answers supported maintenance questions through Ollama. Ollama is called once to produce a structured intent, including a `supported` scope flag; unsupported questions receive a fixed scope message without querying the database. Laravel validates supported intents, uses only the `pgsql_mira` connection and fixed Query Builder operations, then formats the database result deterministically in Indonesian. Model output is never executed as SQL, and no model call is made after the query.

Add these variables to `backend/.env` before starting the service. Keep secrets out of source control:

```dotenv
OLLAMA_URL=http://host.docker.internal:11434
OLLAMA_MODEL=qwen3:0.6b
TELEGRAM_BOT_TOKEN=
TELEGRAM_MAINTENANCE_CHAT_ID=-5398792908
MIRA_DB_HOST=192.168.180.22
MIRA_DB_PORT=5432
MIRA_DB_DATABASE=maintenance
MIRA_DB_USERNAME=mira_readonly
MIRA_DB_PASSWORD=
```

Create `mira_readonly` in the Maintenance PostgreSQL database separately and grant it only the required `SELECT` privileges. The bot replies in the same private or configured maintenance group chat; messages from other groups are ignored. Role-based access control for private chats is not part of this initial POC.

On Linux, make the host Ollama service reachable from Docker at the host-gateway address and restrict access to the Docker network with the host firewall. Do not expose Ollama publicly. Install `qwen3:0.6b` on that existing Ollama instance; this integration does not start another model/service.

After configuration, validate with `php artisan mira:test` inside the backend container, then start `maintenance-telegram` with Docker Compose. If the bot already has a Telegram webhook configured, remove it before switching to long polling. Do not run two polling instances for the same bot token.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
